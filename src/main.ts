import { createApp } from 'vue'
import { loadState } from '@nextcloud/initial-state'
import { translate as t } from '@nextcloud/l10n'
import HeaderLogo from './components/HeaderLogo.vue'
import UserMenu from './components/UserMenu.vue'
import './styles.scss'

function makeApp(component: object, props: Record<string, unknown>) {
	const app = createApp(component, props)
	app.config.globalProperties.t = t
	return app
}

// Our script loads after NC core (afterAppId = 'core' default).
// By the time our DOMContentLoaded fires, NC core has already run setUpUserMenu(),
// setUpContactsMenu() etc. and mounted Vue 2 apps into the placeholder divs.
// We must NOT wipe #header.innerHTML.
//
// Layout after our script runs:
//   #header
//   ├── #simplenavigation-logo   ← our HeaderLogo Vue 3 app (inserted)
//   └── .header-end              ← original NC div, kept intact
//       ├── #unified-search      ← NC unified search already mounted here
//       ├── #simplenavigation-usermenu ← our UserMenu Vue 3 app (inserted)
//       ├── #notifications       ← hidden
//       ├── #contactsmenu        ← hidden
//       └── #user-menu           ← hidden (NC AccountMenu still alive but invisible)

window.addEventListener('DOMContentLoaded', () => {
	const header = document.getElementById('header')
	if (!header) return

	const headerEnd = header.querySelector<HTMLElement>('.header-end')
	if (!headerEnd) return

	const isLoggedIn = loadState('simplenavigation', 'isLoggedIn', false)

	// Hide NC elements we don't want visible
	for (const id of ['notifications', 'contactsmenu', 'user-menu']) {
		const el = document.getElementById(id)
		if (el) el.style.display = 'none'
	}
	const headerStart = header.querySelector<HTMLElement>('.header-start')
	if (headerStart) headerStart.style.display = 'none'

	// Mount our logo before .header-end
	const logoMount = document.createElement('div')
	logoMount.id = 'simplenavigation-logo'
	header.insertBefore(logoMount, headerEnd)

	makeApp(HeaderLogo, {
		homeUrl: loadState('simplenavigation', 'homeUrl', '/'),
	}).mount('#simplenavigation-logo')

	// Mount webmail link + our UserMenu only for authenticated users
	if (isLoggedIn) {
		const userMenuMount = document.createElement('div')
		userMenuMount.id = 'simplenavigation-usermenu'
		headerEnd.appendChild(userMenuMount)

		makeApp(UserMenu, {
			displayName: loadState('simplenavigation', 'displayName', ''),
			logoutUrl: loadState('simplenavigation', 'logoutUrl', ''),
			settingsUrl: loadState('simplenavigation', 'settingsUrl', ''),
			webmailUrl: loadState<string | null>('simplenavigation', 'webmailUrl', null),
			hasEmailProduct: loadState<boolean>('simplenavigation', 'hasEmailProduct', false),
			securityUrl: loadState('simplenavigation', 'securityUrl', ''),
			helpUrl: loadState('simplenavigation', 'helpUrl', ''),
		}).mount('#simplenavigation-usermenu')
	}
})
