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
		if (!$event->isLoggedIn()) {
			return;
		}

		$this->initialState->provideInitialState('homeUrl',
			$this->urlGenerator->linkTo('', 'index.php'));
		$this->initialState->provideInitialState('displayName',
			$this->userSession->getUser()?->getDisplayName() ?? '');
		$this->initialState->provideInitialState('logoutUrl',
			\OC_User::getLogoutUrl($this->urlGenerator));
		$this->initialState->provideInitialState('settingsUrl',
			$this->urlGenerator->linkToRoute('simplesettings.page.index'));
		$this->initialState->provideInitialState('webmailUrl',
			$this->config->getSystemValue('ionos_peer_products', [])['ionos_webmail_target_link'] ?? null);
		$this->initialState->provideInitialState('hasEmailProduct',
			$this->checkEmailProduct());
		$this->initialState->provideInitialState('securityUrl',
			$this->config->getSystemValue('ionos_security_target_link', ''));
		$this->initialState->provideInitialState('helpUrl',
			$this->config->getSystemValue('ionos_help_target_link', ''));

		Util::addScript(Application::APP_ID, Application::APP_ID . '-main');
		Util::addStyle(Application::APP_ID, Application::APP_ID . '-main');
	}

	private function checkEmailProduct(): bool {
		try {
			$availableProductsClaim = \OC::$server->get(\OC\SystemConfig::class)
				->getValue('available_products_claim');
			if ($availableProductsClaim === '') {
				return false;
			}

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
