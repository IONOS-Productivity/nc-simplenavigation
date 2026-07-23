<?php

declare(strict_types=1);

namespace Listener;

use OCA\Simplenavigation\AppInfo\Application;
use OCA\Simplenavigation\Listener\BeforeTemplateRenderedListener;
use OCP\AppFramework\Http\Events\BeforeTemplateRenderedEvent;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\EventDispatcher\Event;
use OCP\IConfig;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;
use OCP\Util;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class BeforeTemplateRenderedListenerTest extends TestCase {
	/**
	 * The listener resolves this class from the server container at runtime
	 * instead of injecting it, because user_oidc is an optional app.
	 */
	private const USER_OIDC_BACKEND = 'OCA\UserOIDC\User\Backend';

	private IInitialState&MockObject $initialState;
	private IURLGenerator&MockObject $urlGenerator;
	private IUserSession&MockObject $userSession;
	private IConfig&MockObject $config;
	private BeforeTemplateRenderedListener $listener;

	/** @var array<string, mixed> system config, keyed by config key */
	private array $systemConfig;

	/** @var array<string, mixed> initial state captured from the mock */
	private array $providedState;

	protected function setUp(): void {
		parent::setUp();

		$this->systemConfig = [
			'ionos_peer_products' => ['ionos_webmail_target_link' => 'https://email.example.com/'],
			'ionos_security_target_link' => 'https://example.com/security',
			'ionos_help_target_link' => 'https://example.com/help',
			'available_products_claim' => '',
		];
		$this->providedState = [];

		$this->initialState = $this->createMock(IInitialState::class);
		$this->initialState->method('provideInitialState')
			->willReturnCallback(function (string $key, mixed $data): void {
				$this->providedState[$key] = $data;
			});

		$this->urlGenerator = $this->createMock(IURLGenerator::class);
		$this->urlGenerator->method('linkTo')
			->willReturnCallback(static fn (string $app, string $file): string => $app === '' ? '/' . $file : '/' . $app . '/' . $file);
		$this->urlGenerator->method('linkToRoute')
			->willReturnCallback(static fn (string $route): string => '/route/' . $route);

		$this->userSession = $this->createMock(IUserSession::class);

		$this->config = $this->createMock(IConfig::class);
		$this->config->method('getSystemValue')
			->willReturnCallback(fn (string $key, mixed $default = '') => $this->systemConfig[$key] ?? $default);
		$this->config->method('getSystemValueString')
			->willReturnCallback(fn (string $key, string $default = ''): string => (string)($this->systemConfig[$key] ?? $default));

		$this->listener = new BeforeTemplateRenderedListener(
			$this->initialState,
			$this->urlGenerator,
			$this->userSession,
			$this->config,
		);

		// Default to "user_oidc is not installed", which is how CI runs.
		$this->registerUserOidcBackend(null);
	}

	protected function tearDown(): void {
		$this->registerUserOidcBackend(null);

		parent::tearDown();
	}

	public function testIgnoresUnrelatedEvents(): void {
		$this->listener->handle(new Event());

		$this->assertSame([], $this->providedState);
	}

	/**
	 * Anonymous rendering still gets the logo, so homeUrl and the login flag are
	 * provided; only the user menu state is withheld.
	 */
	public function testProvidesOnlyPublicStateForAnonymousRendering(): void {
		$this->listener->handle($this->renderEvent(false));

		$this->assertFalse($this->providedState['isLoggedIn']);
		$this->assertSame('/index.php', $this->providedState['homeUrl']);
		foreach (['displayName', 'logoutUrl', 'settingsUrl', 'webmailUrl', 'hasEmailProduct', 'securityUrl', 'helpUrl'] as $key) {
			$this->assertArrayNotHasKey($key, $this->providedState);
		}
	}

	public function testProvidesHeaderState(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getDisplayName')->willReturn('Alice Example');
		$this->userSession->method('getUser')->willReturn($user);

		$this->listener->handle($this->renderEvent());

		$this->assertTrue($this->providedState['isLoggedIn']);
		$this->assertSame('/index.php', $this->providedState['homeUrl']);
		$this->assertSame('Alice Example', $this->providedState['displayName']);
		$this->assertSame('/route/simplesettings.page.index', $this->providedState['settingsUrl']);
		$this->assertSame('https://email.example.com/', $this->providedState['webmailUrl']);
		$this->assertSame('https://example.com/security', $this->providedState['securityUrl']);
		$this->assertSame('https://example.com/help', $this->providedState['helpUrl']);
		$this->assertFalse($this->providedState['hasEmailProduct']);
		$this->assertStringStartsWith('/route/core.login.logout', $this->providedState['logoutUrl']);
	}

	public function testDisplayNameIsEmptyWithoutUser(): void {
		$this->userSession->method('getUser')->willReturn(null);

		$this->listener->handle($this->renderEvent());

		$this->assertSame('', $this->providedState['displayName']);
	}

	public function testRegistersScriptAndStyle(): void {
		$this->listener->handle($this->renderEvent());

		$this->assertContains(
			Application::APP_ID . '/js/' . Application::APP_ID . '-main',
			Util::getScripts(),
		);
		// OCP\Util has no getter for styles, hence the legacy class.
		$this->assertContains(
			Application::APP_ID . '/css/' . Application::APP_ID . '-main',
			\OC_Util::$styles,
		);
	}

	/**
	 * The frontend reads webmailUrl with a null default, so an unconfigured
	 * link must leave the key unset rather than provide an empty string.
	 *
	 * @dataProvider provideUnusableWebmailConfig
	 */
	public function testWebmailUrlIsOmittedWhenUnusable(mixed $peerProducts): void {
		$this->systemConfig['ionos_peer_products'] = $peerProducts;

		$this->listener->handle($this->renderEvent());

		$this->assertArrayNotHasKey('webmailUrl', $this->providedState);
		// The rest of the state is still provided.
		$this->assertArrayHasKey('helpUrl', $this->providedState);
	}

	/** @return array<string, array{mixed}> */
	public static function provideUnusableWebmailConfig(): array {
		return [
			'not configured' => [[]],
			'link missing' => [['ionos_other_target_link' => 'https://example.com/']],
			'link is null' => [['ionos_webmail_target_link' => null]],
			'link is not a string' => [['ionos_webmail_target_link' => ['https://example.com/']]],
			'peer products is not an array' => ['https://example.com/'],
		];
	}

	public function testDetectsEmailProductFromClaim(): void {
		$this->systemConfig['available_products_claim'] = 'products';
		$this->registerUserOidcBackend($this->userOidcBackend(['raw' => ['products' => ['storage', 'mail']]]));

		$this->listener->handle($this->renderEvent());

		$this->assertTrue($this->providedState['hasEmailProduct']);
	}

	public function testDetectsEmailProductFromJsonEncodedClaim(): void {
		$this->systemConfig['available_products_claim'] = 'products';
		$this->registerUserOidcBackend($this->userOidcBackend(['raw' => ['products' => '["storage","mail"]']]));

		$this->listener->handle($this->renderEvent());

		$this->assertTrue($this->providedState['hasEmailProduct']);
	}

	/** @dataProvider provideUserDataWithoutEmailProduct */
	public function testDetectsMissingEmailProduct(array $userData): void {
		$this->systemConfig['available_products_claim'] = 'products';
		$this->registerUserOidcBackend($this->userOidcBackend($userData));

		$this->listener->handle($this->renderEvent());

		$this->assertFalse($this->providedState['hasEmailProduct']);
	}

	/** @return array<string, array{array<string, mixed>}> */
	public static function provideUserDataWithoutEmailProduct(): array {
		return [
			'other products only' => [['raw' => ['products' => ['storage']]]],
			'other products as json' => [['raw' => ['products' => '["storage"]']]],
			'claim missing' => [['raw' => []]],
			'claim is not decodable' => [['raw' => ['products' => 'mail']]],
			'no raw user data' => [['formatted' => ['uid' => 'alice']]],
		];
	}

	public function testDoesNotConsultUserOidcWithoutConfiguredClaim(): void {
		$backend = $this->userOidcBackend(['raw' => ['products' => ['mail']]]);
		$this->registerUserOidcBackend($backend);

		$this->listener->handle($this->renderEvent());

		$this->assertFalse($this->providedState['hasEmailProduct']);
		$this->assertFalse($backend->wasCalled, 'the OIDC backend must not be resolved without a claim');
	}

	public function testHasNoEmailProductWhenUserOidcIsUnavailable(): void {
		$this->systemConfig['available_products_claim'] = 'products';

		$this->listener->handle($this->renderEvent());

		$this->assertFalse($this->providedState['hasEmailProduct']);
	}

	public function testHasNoEmailProductWhenUserOidcThrows(): void {
		$this->systemConfig['available_products_claim'] = 'products';
		$this->registerUserOidcBackend(new class {
			public bool $wasCalled = false;

			public function getUserData(): array {
				$this->wasCalled = true;

				throw new \InvalidArgumentException('No valid uid given');
			}
		});

		$this->listener->handle($this->renderEvent());

		$this->assertFalse($this->providedState['hasEmailProduct']);
	}

	private function renderEvent(bool $loggedIn = true): BeforeTemplateRenderedEvent {
		return new BeforeTemplateRenderedEvent(
			$loggedIn,
			new TemplateResponse(Application::APP_ID, 'index'),
		);
	}

	/**
	 * Stands in for the user_oidc backend, which cannot be mocked with PHPUnit
	 * because the class does not exist unless that app is installed.
	 *
	 * @param array<string, mixed> $userData
	 */
	private function userOidcBackend(array $userData): object {
		return new class($userData) {
			public bool $wasCalled = false;

			/** @param array<string, mixed> $userData */
			public function __construct(
				private array $userData,
			) {
			}

			/** @return array<string, mixed> */
			public function getUserData(): array {
				$this->wasCalled = true;

				return $this->userData;
			}
		};
	}

	/**
	 * Registers the stand-in under the exact name the listener looks up. Passing
	 * null makes the lookup throw, which is what the container does when
	 * user_oidc is not installed.
	 */
	private function registerUserOidcBackend(?object $backend): void {
		\OC::$server->registerService(self::USER_OIDC_BACKEND, static function () use ($backend): object {
			if ($backend === null) {
				throw new \RuntimeException('user_oidc is not installed');
			}

			return $backend;
		});
	}
}
