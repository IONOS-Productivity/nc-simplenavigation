# Plan: Replace Legacy Theme Header with `simplenavigation` App

## Goal

Replace the custom Svelte header injected via `nc-ionos-theme/core/templates/layout.user.php`
with a fully Nextcloud-native Vue 3 implementation inside this app.

## Current State

| What | Where |
|---|---|
| Header HTML | `nc-ionos-theme/core/templates/layout.user.php` — `<header id="ionos-global-nav">` block |
| Header UI | Svelte web components: `ionos-global-nav`, `ionos-user-menu`, `ionos-user-menu-item` |
| State passing | PHP string interpolation directly in the template |
| This app | Stub — Vue 3 scaffold, mounts on its own page, not on every page |

## Approach: `BeforeTemplateRenderedEvent`

Register an `IEventListener` that fires on every authenticated user page render.
The listener injects the script/style bundle and pushes all needed data via `IInitialState`.
The Vue bundle mounts itself into the existing `<header id="header">` DOM element,
replacing its content. No theme template override required.

---

## Target File Structure

```
simplenavigation/
├── appinfo/
│   └── info.xml                               # Remove <navigations> entry
├── lib/
│   ├── AppInfo/
│   │   └── Application.php                    # Register BeforeTemplateRenderedListener
│   └── Listener/
│       └── BeforeTemplateRenderedListener.php # Injects script + initial state on every page
├── src/
│   ├── main.ts                                # Mount Vue into existing <header>
│   ├── App.vue                                # Root: full header shell
│   └── components/
│       ├── HeaderLogo.vue                     # Left side: home link + brand/product logos
│       └── UserMenu.vue                       # Right side: NcAvatar trigger + NcActions dropdown
└── (no templates/, no routes.php)
```

---

## Implementation Steps

### 1. `BeforeTemplateRenderedListener.php`

Only fires for authenticated, non-guest, full-page renders (`$event->isLoggedIn()`).
Provides all header data as initial state, then adds the script and style.

```php
namespace OCA\Simplenavigation\Listener;

use OCP\AppFramework\Http\Events\BeforeTemplateRenderedEvent;
use OCP\AppFramework\Services\IInitialState;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IConfig;
use OCP\IURLGenerator;
use OCP\IUserSession;
use OCP\Util;
use OCA\Simplenavigation\AppInfo\Application;

class BeforeTemplateRenderedListener implements IEventListener {
    public function __construct(
        private readonly IInitialState $initialState,
        private readonly IURLGenerator $urlGenerator,
        private readonly IUserSession $userSession,
        private readonly IConfig $config,
    ) {}

    public function handle(Event $event): void {
        if (!($event instanceof BeforeTemplateRenderedEvent)) return;
        if (!$event->isLoggedIn()) return;

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
        // Port logic from layout.user.php: read available_products_claim from
        // SystemConfig, look up user OIDC data, check for "mail" in the claim.
        try {
            $availableProductsClaim = \OC::$server->get(\OC\SystemConfig::class)
                ->getValue('available_products_claim');
            if ($availableProductsClaim === '') return false;

            $userOIDCBackend = \OC::$server->get(\OCA\UserOIDC\User\Backend::class);
            $userData = $userOIDCBackend->getUserData();
            $availableProductsData = $userData['raw'][$availableProductsClaim] ?? [];
            $availableProducts = is_array($availableProductsData)
                ? $availableProductsData
                : (array) json_decode($availableProductsData);

            return in_array('mail', $availableProducts);
        } catch (\Throwable) {
            return false;
        }
    }
}
```

### 2. `Application.php` — Register the Listener

```php
public function register(IRegistrationContext $context): void {
    $context->registerEventListener(
        \OCP\AppFramework\Http\Events\BeforeTemplateRenderedEvent::class,
        \OCA\Simplenavigation\Listener\BeforeTemplateRenderedListener::class
    );
}
```

### 3. `appinfo/info.xml` — Remove `<navigations>`

The app is a background service, not a launcher entry. Delete the entire
`<navigations>` block so it does not appear in the app menu.

### 4. `vite.config.ts` — Fix Entry Point

The config currently references `main.js`; fix it to `main.ts`:

```ts
main: resolve(join('src', 'main.ts')),
```

### 5. `main.ts` — Take Over `<header>`

NC renders its own content into `<header id="header">`. Clear it and mount the
Vue app into it. If the element is missing (e.g. on a page the listener skipped),
nothing happens.

```ts
import { createApp } from 'vue'
import { loadState } from '@nextcloud/initial-state'
import App from './App.vue'

const header = document.getElementById('header')
if (header) {
    header.innerHTML = '<div id="simplenavigation-header"></div>'
    createApp(App, {
        homeUrl:         loadState('simplenavigation', 'homeUrl', '/'),
        displayName:     loadState('simplenavigation', 'displayName', ''),
        logoutUrl:       loadState('simplenavigation', 'logoutUrl', ''),
        settingsUrl:     loadState('simplenavigation', 'settingsUrl', ''),
        webmailUrl:      loadState<string | null>('simplenavigation', 'webmailUrl', null),
        hasEmailProduct: loadState('simplenavigation', 'hasEmailProduct', false),
        securityUrl:     loadState('simplenavigation', 'securityUrl', ''),
        helpUrl:         loadState('simplenavigation', 'helpUrl', ''),
    }).mount('#simplenavigation-header')
}
```

### 6. Vue Components — Native NC Icons

Icons come from `vue-material-design-icons` (same as `simplesettings`).
NC components from `@nextcloud/vue` v9 named imports. No `ionos-icons` anywhere.

**`App.vue`** — header shell mirroring the IONOS layout:

```vue
<script setup lang="ts">
import HeaderLogo from './components/HeaderLogo.vue'
import UserMenu from './components/UserMenu.vue'
import IconEmail from 'vue-material-design-icons/Email.vue'

const props = defineProps<{
    homeUrl: string
    displayName: string
    logoutUrl: string
    settingsUrl: string
    webmailUrl: string | null
    hasEmailProduct: boolean
    securityUrl: string
    helpUrl: string
}>()
</script>

<template>
    <div class="ion-global-nav">
        <HeaderLogo :home-url="props.homeUrl" />
        <div class="header-right">
            <div id="unified-search" />
            <a v-if="props.hasEmailProduct && props.webmailUrl"
               :href="props.webmailUrl"
               target="_blank"
               class="header-action"
               :title="t('simplenavigation', 'IONOS Webmail')">
                <IconEmail :size="20" />
            </a>
            <UserMenu
                :display-name="props.displayName"
                :settings-url="props.settingsUrl"
                :security-url="props.securityUrl"
                :help-url="props.helpUrl"
                :logout-url="props.logoutUrl"
            />
        </div>
    </div>
</template>
```

**`UserMenu.vue`** — `NcActions` with `NcActionLink` rows, `NcAvatar` as trigger:

```vue
<script setup lang="ts">
import NcAvatar from '@nextcloud/vue/components/NcAvatar'
import NcActions from '@nextcloud/vue/components/NcActions'
import NcActionLink from '@nextcloud/vue/components/NcActionLink'
import IconCog from 'vue-material-design-icons/Cog.vue'
import IconKey from 'vue-material-design-icons/Key.vue'
import IconHelpCircle from 'vue-material-design-icons/HelpCircle.vue'
import IconLogout from 'vue-material-design-icons/Logout.vue'

defineProps<{
    displayName: string
    settingsUrl: string
    securityUrl: string
    helpUrl: string
    logoutUrl: string
}>()
</script>

<template>
    <NcActions :aria-label="displayName" force-menu>
        <template #icon>
            <NcAvatar :display-name="displayName" :size="34" :show-user-status="false" />
        </template>
        <NcActionLink :href="settingsUrl">
            <template #icon><IconCog /></template>
            {{ t('simplenavigation', 'Settings') }}
        </NcActionLink>
        <NcActionLink :href="securityUrl" target="_blank">
            <template #icon><IconKey /></template>
            {{ t('simplenavigation', 'Login & Security') }}
        </NcActionLink>
        <NcActionLink :href="helpUrl" target="_blank">
            <template #icon><IconHelpCircle /></template>
            {{ t('simplenavigation', 'Help & Support') }}
        </NcActionLink>
        <NcActionLink :href="logoutUrl">
            <template #icon><IconLogout /></template>
            {{ t('simplenavigation', 'Logout') }}
        </NcActionLink>
    </NcActions>
</template>
```

**CSS** — reset the NC `#header` baseline and apply the IONOS 64 px design on top:

```scss
// Override NC header chrome so our Vue layout takes full control
#header {
    padding: 0 !important;
    background: var(--ion-color-main-background) !important;
    box-shadow: var(--ion-shadow-header) !important;
    height: 64px !important;
}

#simplenavigation-header,
.ion-global-nav {
    height: 64px;
}

.ion-global-nav {
    display: flex;
    align-items: center;
    padding: 0 24px;
    gap: 16px;
}

.header-right {
    display: flex;
    align-items: center;
    margin-left: auto;
    gap: 16px;
}
```

### 7. `package.json` — Add Missing Dependency

`vue-material-design-icons` must be a direct dependency (it is transitive via
`@nextcloud/vue` but should be explicit):

```json
"dependencies": {
    "@nextcloud/initial-state": "^2.2.0",
    "@nextcloud/l10n": "^3.1.0",
    "@nextcloud/vue": "^9.6.0",
    "vue": "^3.5.32",
    "vue-material-design-icons": "^5.3.1"
}
```

---

## Changes Outside This App

### `nc-ionos-theme/core/templates/layout.user.php`

Remove the entire `<header id="ionos-global-nav">` block. The stock NC
`<header id="header">` is what the Vue app takes over via `main.ts`. This is
the test gate: if the listener is wired correctly the Vue header renders; if not,
the plain NC header shows — never a blank page.

```diff
-    <header id="ionos-global-nav">
-        <ionos-global-nav home_src="...">
-            ...
-        </ionos-global-nav>
-    </header>
-
     <main id="content" class="app-...">
```

### `IONOS/Makefile`

Add a build target alongside the other `build_dep_*` entries and wire it into
`.build_deps`:

```makefile
build_dep_simplenavigation_app: ## Install and build simplenavigation app
	cd apps-custom/simplenavigation && \
	npm ci && \
	npm run build
```

```diff
-.build_deps: ... build_dep_simplesettings_app build_dep_nc_ionos_processes_app ...
+.build_deps: ... build_dep_simplesettings_app build_dep_simplenavigation_app build_dep_nc_ionos_processes_app ...
```

### `IONOS/configure.sh`

Enable the app inside `config_apps()`:

```sh
config_apps() {
    ...
    echo "Enable simplenavigation app"
    ooc app:enable simplenavigation
    ...
}
```

---

## Data Flow

```
Nextcloud Core
  └─ BeforeTemplateRenderedEvent (every authenticated page)
        └─ BeforeTemplateRenderedListener
              ├─ IInitialState.provideInitialState(homeUrl, displayName, ...)
              └─ Util::addScript / addStyle
                    └─ <script> injected in <head>
                          └─ main.ts runs in browser
                                ├─ loadState() reads hidden <input> tags
                                └─ createApp(App, props).mount('#simplenavigation-header')
                                      └─ replaces #header DOM content
```

---

## Implementation Checklist

- [ ] `lib/Listener/BeforeTemplateRenderedListener.php` — implement, port `checkEmailProduct()`
- [ ] `lib/AppInfo/Application.php` — register listener in `register()`
- [ ] `appinfo/info.xml` — remove `<navigations>` block
- [ ] `vite.config.ts` — fix entry point to `main.ts`
- [ ] `package.json` — add `@nextcloud/initial-state`, `@nextcloud/l10n`, `vue-material-design-icons`
- [ ] `src/main.ts` — take over `#header`, mount Vue app
- [ ] `src/App.vue` — header shell with props, webmail link
- [ ] `src/components/HeaderLogo.vue` — home link + brand/product SVGs
- [ ] `src/components/UserMenu.vue` — `NcActions` + `NcActionLink` + `NcAvatar`
- [ ] CSS — reset `#header` NC defaults, apply 64 px IONOS layout
- [ ] `nc-ionos-theme/core/templates/layout.user.php` — delete `<header id="ionos-global-nav">` block
- [ ] `IONOS/Makefile` — add `build_dep_simplenavigation_app`, wire into `.build_deps`
- [ ] `IONOS/configure.sh` — add `ooc app:enable simplenavigation` in `config_apps()`
