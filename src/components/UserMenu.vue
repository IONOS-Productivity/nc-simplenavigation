<script setup lang="ts">
import { ref } from 'vue'
import { t } from '@nextcloud/l10n'
import { onClickOutside } from '@vueuse/core'
import {
	mdiEmail,
	mdiCog,
	mdiAccountKey,
	mdiHelpCircle,
	mdiLogout,
	mdiAccount,
} from '@mdi/js'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'

const props = defineProps<{
	displayName: string
	settingsUrl: string
	securityUrl: string
	helpUrl: string
	logoutUrl: string
	webmailUrl: string | null
	hasEmailProduct: boolean
}>()

const showMenu = ref(false)
const root = ref<HTMLElement | null>(null)

onClickOutside(root, () => { showMenu.value = false })
</script>

<template>
	<!--
		Webmail and user menu are siblings, not nested. Both the mount point
		(#simplenavigation-usermenu) and this root are flex rows, so the webmail
		link and the user menu trigger are laid out by the gap: 16px declared on
		.ion-usermenu-root — matching the spacing .header-end gives its own
		children, exactly like the Svelte header-end.
	-->
	<div ref="root" class="ion-usermenu-root">
		<a
			v-if="props.webmailUrl && props.hasEmailProduct"
			:href="props.webmailUrl"
			target="_blank"
			rel="noopener noreferrer"
			class="ion-header-action"
			:title="t('simplenavigation', 'IONOS Webmail')">
			<NcIconSvgWrapper :path="mdiEmail" :size="26" />
		</a>

		<div class="ion-user-menu">
			<button
				class="ion-user-menu__trigger"
				:aria-label="t('simplenavigation', 'User menu')"
				:aria-expanded="showMenu"
				@click="showMenu = !showMenu">
				<NcIconSvgWrapper :path="mdiAccount" :size="26" />
			</button>

			<div v-if="showMenu" class="ion-user-menu__panel">
				<div class="ion-user-menu__title">
					<span class="ion-user-menu__username">{{ props.displayName }}</span>
				</div>
				<div class="ion-user-menu__divider" />
				<nav class="ion-user-menu__nav">
					<a :href="props.settingsUrl" class="ion-user-menu__item" @click="showMenu = false">
						<NcIconSvgWrapper :path="mdiCog" :size="20" />
						<span>{{ t('simplenavigation', 'Settings') }}</span>
					</a>
					<a
						:href="props.securityUrl"
						target="_blank"
						rel="noopener noreferrer"
						class="ion-user-menu__item"
						@click="showMenu = false">
						<NcIconSvgWrapper :path="mdiAccountKey" :size="20" />
						<span>{{ t('simplenavigation', 'Login & Security') }}</span>
					</a>
					<a
						:href="props.helpUrl"
						target="_blank"
						rel="noopener noreferrer"
						class="ion-user-menu__item"
						@click="showMenu = false">
						<NcIconSvgWrapper :path="mdiHelpCircle" :size="20" />
						<span>{{ t('simplenavigation', 'Help & Support') }}</span>
					</a>
					<div class="ion-user-menu__divider" />
					<a :href="props.logoutUrl" class="ion-user-menu__item" @click="showMenu = false">
						<NcIconSvgWrapper :path="mdiLogout" :size="20" />
						<span>{{ t('simplenavigation', 'Logout') }}</span>
					</a>
				</nav>
			</div>
		</div>
	</div>
</template>

<style lang="scss">
// NC's rule (#header .header-end > div { height: 100%; position: relative })
// automatically gives this element the full header height. We add flex so its
// children (.ion-usermenu-root) are centred on the cross axis.
#simplenavigation-usermenu {
	display: flex !important;
	align-items: center;
}

// Groups the webmail link and user-menu trigger as a centred flex row.
// gap: 16px spaces the two icons; flex-shrink: 0 prevents them collapsing.
.ion-usermenu-root {
	display: flex;
	align-items: center;
	gap: 16px;
	flex-shrink: 0;
}

// Webmail link and any other standalone header action icons
.ion-header-action {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	color: var(--ion-text);
	text-decoration: none;
	flex-shrink: 0;

	// NcIconSvgWrapper renders span.icon-vue with display:inline-block and
	// various NC rules can reduce its opacity to 0.5. Force both.
	.icon-vue {
		display: flex !important;
		opacity: 1 !important;
	}

	&:hover {
		opacity: 0.8;
	}
}

.ion-user-menu {
	position: relative;
	display: flex;
	align-items: center;
	flex-shrink: 0;

	// Full reset — defeat all global <button> and NC stylesheet rules
	&__trigger {
		appearance: none !important;
		-webkit-appearance: none !important;
		display: inline-flex !important;
		align-items: center !important;
		justify-content: center !important;
		background: none !important;
		background-color: transparent !important;
		border: none !important;
		box-shadow: none !important;
		outline: none !important;
		padding: 0 !important;
		margin: 0 !important;
		border-radius: 0 !important;
		width: auto !important;
		height: auto !important;
		min-width: 0 !important;
		min-height: 0 !important;
		cursor: pointer !important;
		color: var(--ion-text) !important;
		opacity: 1 !important;
		filter: none !important;

		// NcIconSvgWrapper renders span.icon-vue with display:inline-block and
		// various NC rules can reduce its opacity to 0.5. Force both.
		.icon-vue {
			display: flex !important;
			opacity: 1 !important;
		}

		&:hover,
		&:focus,
		&:focus-visible,
		&:active {
			background: none !important;
			background-color: transparent !important;
			border: none !important;
			box-shadow: none !important;
			outline: none !important;
			opacity: 0.8 !important;
		}
	}

	&__panel {
		position: fixed;
		top: 64px;
		right: 16px;
		width: 280px;
		background-color: var(--ion-color-main-background);
		border: 2px solid var(--ion-context-menu-border);
		border-radius: 8px;
		box-shadow: var(--ion-shadow);
		overflow: hidden;
		font-size: 16px;
		color: var(--ion-text);
		z-index: 10000;
	}

	&__title {
		display: flex;
		align-items: center;
		padding: 16px;
		background-color: var(--ion-context-menu-title-background);
		cursor: default;
	}

	&__username {
		font-weight: 600;
		word-break: break-all;
	}

	&__divider {
		width: 100%;
		height: 1px;
		border-top: 1px solid var(--ion-context-menu-border);
		flex-shrink: 0;
	}

	&__nav {
		display: flex;
		flex-direction: column;
	}

	&__item {
		display: flex;
		align-items: center;
		gap: 8px;
		padding: 8px;
		text-decoration: none;
		color: var(--ion-context-menu-item-text);
		font-weight: 500;
		line-height: 24px;

		&:hover {
			background-color: var(--ion-context-menu-item-background-hover);
		}

		&:active {
			background-color: var(--ion-context-menu-item-background-active);
		}
	}
}
</style>
