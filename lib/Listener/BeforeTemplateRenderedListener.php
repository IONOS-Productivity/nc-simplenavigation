<?php

declare(strict_types=1);

namespace OCA\Simplenavigation\Listener;

use OCA\Simplenavigation\AppInfo\Application;
use OCP\AppFramework\Http\Events\BeforeTemplateRenderedEvent;
use OCP\AppFramework\Services\IInitialState;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IConfig;
use OCP\IURLGenerator;
use OCP\IUserSession;
use OCP\Util;

/** @template-implements IEventListener<BeforeTemplateRenderedEvent> */
class BeforeTemplateRenderedListener implements IEventListener {
	/** @psalm-suppress PossiblyUnusedMethod instantiated by the DI container */
	public function __construct(
		private readonly IInitialState $initialState,
		private readonly IURLGenerator $urlGenerator,
		private readonly IUserSession $userSession,
		private readonly IConfig $config,
	) {
	}

	public function handle(Event $event): void {
		if (!($event instanceof BeforeTemplateRenderedEvent)) {
			return;
		}

		$isLoggedIn = $event->isLoggedIn();
		$this->initialState->provideInitialState('isLoggedIn', $isLoggedIn);
		$this->initialState->provideInitialState('homeUrl',
			$this->urlGenerator->linkTo('', 'index.php'));
		$this->initialState->provideInitialState('productName',
			$this->config->getSystemValue('ionos_product_name', ''));

		if ($isLoggedIn) {
			$this->initialState->provideInitialState('displayName',
				$this->userSession->getUser()?->getDisplayName() ?? '');
			$this->initialState->provideInitialState('logoutUrl',
				(string)\OC_User::getLogoutUrl($this->urlGenerator));
			$this->initialState->provideInitialState('settingsUrl',
				$this->urlGenerator->linkToRoute('simplesettings.page.index'));

			// Left unset when not configured: the frontend defaults this key to null.
			$webmailUrl = $this->getPeerProductLink('ionos_webmail_target_link');
			if ($webmailUrl !== null) {
				$this->initialState->provideInitialState('webmailUrl', $webmailUrl);
			}

			$this->initialState->provideInitialState('hasEmailProduct',
				$this->checkEmailProduct());
			$this->initialState->provideInitialState('securityUrl',
				$this->config->getSystemValueString('ionos_security_target_link'));
			$this->initialState->provideInitialState('helpUrl',
				$this->config->getSystemValueString('ionos_help_target_link'));
		}

		Util::addScript(Application::APP_ID, Application::APP_ID . '-main');
		Util::addStyle(Application::APP_ID, Application::APP_ID . '-main');
	}

	private function getPeerProductLink(string $key): ?string {
		$peerProducts = $this->config->getSystemValue('ionos_peer_products', []);
		if (!is_array($peerProducts)
			|| !isset($peerProducts[$key])
			|| !is_string($peerProducts[$key])) {
			return null;
		}

		return $peerProducts[$key];
	}

	/**
	 * psalm cannot resolve the optional user_oidc Backend, so everything
	 * derived from getUserData() stays mixed and the bool return type of this
	 * method is not verifiable.
	 *
	 * @psalm-suppress MixedInferredReturnType
	 */
	private function checkEmailProduct(): bool {
		$availableProductsClaim = $this->config->getSystemValueString('available_products_claim');
		if ($availableProductsClaim === '') {
			return false;
		}

		try {
			$userOIDCBackend = \OC::$server->get(\OCA\UserOIDC\User\Backend::class);
			$userData = $userOIDCBackend->getUserData();
			$availableProductsData = $userData['raw'][$availableProductsClaim] ?? [];
			$availableProducts = is_array($availableProductsData)
				? $availableProductsData
				: (array)json_decode($availableProductsData);

			return in_array('mail', $availableProducts);
		} catch (\Throwable) {
			return false;
		}
	}
}
