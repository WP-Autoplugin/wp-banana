/**
 * WP Banana Command Palette command registration.
 *
 * @package WPBanana
 */

import { useCommands } from '@wordpress/commands';
import { createRoot, render } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { plus, settings } from '@wordpress/icons';

declare global {
	interface Window {
		wpBananaCommandPalette?: {
			urls?: {
				generatePage?: string;
				settings?: string;
				settingsApi?: string;
				settingsDefaults?: string;
				settingsAdvanced?: string;
				logs?: string;
			};
			capabilities?: {
				canGenerate?: boolean;
				canManage?: boolean;
			};
			flags?: {
				isConnected?: boolean;
				canOpenUploadPanel?: boolean;
			};
			screen?: {
				base?: string;
				id?: string;
				postType?: string;
				isUpload?: boolean;
				isSettings?: boolean;
				isGenerate?: boolean;
				isLogs?: boolean;
			};
		};
		wpBananaOpenGeneratePanel?: () => boolean;
		wpBananaOpenGenerateMediaModal?: () => boolean;
		__wpBananaCommandPaletteMounted?: boolean;
	}
}

type CommandCallbackContext = {
	close: () => void;
};

type CommandDefinition = {
	name: string;
	label: string;
	icon: unknown;
	category: 'view';
	keywords: string[];
	callback: ( context: CommandCallbackContext ) => void;
};

type CommandPalettePayload = NonNullable< Window['wpBananaCommandPalette'] >;

const navigateTo = ( url: string ) => ( { close }: CommandCallbackContext ) => {
	close();
	window.location.href = url;
};

const openGenerateUi = ( url: string ) => ( { close }: CommandCallbackContext ) => {
	close();

	if ( typeof window.wpBananaOpenGeneratePanel === 'function' ) {
		const opened = window.wpBananaOpenGeneratePanel();
		if ( opened ) {
			return;
		}
	}

	if ( typeof window.wpBananaOpenGenerateMediaModal === 'function' ) {
		const opened = window.wpBananaOpenGenerateMediaModal();
		if ( opened ) {
			return;
		}
	}

	window.location.href = url;
};

const buildCommands = ( payload: CommandPalettePayload ): CommandDefinition[] => {
	const commands: CommandDefinition[] = [];
	const urls = payload.urls ?? {};
	const capabilities = payload.capabilities ?? {};

	if ( capabilities.canGenerate && urls.generatePage ) {
		commands.push( {
			name: 'wp-banana/open-generate-page',
			label: __( 'Generate Image', 'wp-banana' ),
			icon: plus,
			category: 'view',
			keywords: [ 'banana', 'ai', 'image', 'generate', 'media' ],
			callback: openGenerateUi( urls.generatePage ),
		} );
	}

	if ( capabilities.canManage && urls.settings ) {
		commands.push( {
			name: 'wp-banana/open-settings',
			label: __( 'WP Banana Settings', 'wp-banana' ),
			icon: settings,
			category: 'view',
			keywords: [ 'banana', 'settings', 'providers', 'openai', 'gemini', 'replicate', 'api', 'defaults', 'advanced' ],
			callback: navigateTo( urls.settings ),
		} );
	}

	if ( capabilities.canManage && urls.logs ) {
		commands.push( {
			name: 'wp-banana/open-logs',
			label: __( 'WP Banana API Logs', 'wp-banana' ),
			icon: settings,
			category: 'view',
			keywords: [ 'banana', 'logs', 'api', 'debug', 'requests', 'responses' ],
			callback: navigateTo( urls.logs ),
		} );
	}

	return commands;
};

const CommandPaletteRegistrar = ( { payload }: { payload: CommandPalettePayload } ) => {
	useCommands( buildCommands( payload ) );
	return null;
};

const init = () => {
	if ( window.__wpBananaCommandPaletteMounted ) {
		return;
	}

	const payload = window.wpBananaCommandPalette;
	if ( ! payload || ! document.body ) {
		return;
	}

	const hasEligibleCommands =
		( payload.capabilities?.canGenerate && payload.urls?.generatePage ) ||
		( payload.capabilities?.canManage &&
			( payload.urls?.settings || payload.urls?.logs ) );

	if ( ! hasEligibleCommands ) {
		return;
	}

	window.__wpBananaCommandPaletteMounted = true;

	const container = document.createElement( 'div' );
	container.id = 'wp-banana-command-palette-root';
	container.setAttribute( 'hidden', 'hidden' );
	document.body.appendChild( container );

	if ( typeof createRoot === 'function' ) {
		createRoot( container ).render( <CommandPaletteRegistrar payload={ payload } /> );
		return;
	}

	render( <CommandPaletteRegistrar payload={ payload } />, container );
};

if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', init );
} else {
	init();
}
