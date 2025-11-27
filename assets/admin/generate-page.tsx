/**
 * Standalone Generate Image admin page bootstrap.
 *
 * @package WPBanana
 */

import { render } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import GeneratePanel from './generate-ai-panel';
import type { ProviderInfo } from './types/generate';

declare global {
	interface Window {
		wpBananaGeneratePage?: {
			restNamespace: string;
			providers: ProviderInfo[];
			redirectUrl?: string;
			defaultGeneratorModel?: string;
			defaultGeneratorProvider?: string;
			defaultAspectRatio?: string;
			aspectRatioOptions?: string[];
			defaultResolution?: string;
			resolutionOptions?: string[];
		};
	}
}

let root: any | null = null;

const mountPanel = (
	container: HTMLElement,
	payload: {
		restNamespace: string;
		providers: ProviderInfo[];
		redirectUrl?: string;
		defaultGeneratorModel?: string;
		defaultGeneratorProvider?: string;
		defaultAspectRatio?: string;
		aspectRatioOptions?: string[];
		defaultResolution?: string;
		resolutionOptions?: string[];
	}
) => {
	const wpElement = ( ( window as unknown ) as { wp?: { element?: Record<string, unknown> } } ).wp?.element as any;
	const supportsCreateRoot = typeof wpElement?.createRoot === 'function';
	const onComplete = () => {
		if ( payload.redirectUrl ) {
			window.location.href = payload.redirectUrl;
			return;
		}
		window.location.reload();
	};

	if ( supportsCreateRoot ) {
		if ( ! root ) {
			root = wpElement.createRoot( container );
		}
		root.render(
			<GeneratePanel
				providers={ payload.providers }
				restNamespace={ payload.restNamespace }
				onComplete={ onComplete }
				defaultGeneratorModel={ payload.defaultGeneratorModel }
				defaultGeneratorProvider={ payload.defaultGeneratorProvider }
				defaultAspectRatio={ payload.defaultAspectRatio }
				aspectRatioOptions={ payload.aspectRatioOptions }
				defaultResolution={ payload.defaultResolution }
				resolutionOptions={ payload.resolutionOptions }
				enableReferenceDragDrop
			/>
		);
		return;
	}

	render(
		<GeneratePanel
			providers={ payload.providers }
			restNamespace={ payload.restNamespace }
			onComplete={ onComplete }
			defaultGeneratorModel={ payload.defaultGeneratorModel }
			defaultGeneratorProvider={ payload.defaultGeneratorProvider }
			defaultAspectRatio={ payload.defaultAspectRatio }
			aspectRatioOptions={ payload.aspectRatioOptions }
			defaultResolution={ payload.defaultResolution }
			resolutionOptions={ payload.resolutionOptions }
			enableReferenceDragDrop
		/>,
		container
	);

};

const renderNoProvidersNotice = ( container: HTMLElement ) => {
	container.innerHTML = '';
	const notice = document.createElement( 'div' );
	notice.className = 'notice notice-warning';
	const paragraph = document.createElement( 'p' );
	paragraph.textContent = __( 'No connected providers available. Add a key in Settings → AI Images.', 'wp-banana' );
	notice.appendChild( paragraph );
	container.appendChild( notice );
};

const init = () => {
	const container = document.getElementById( 'wp-banana-generate-page' );
	if ( ! container ) {
		return;
	}

	const payload = window.wpBananaGeneratePage;
	if ( ! payload || ! Array.isArray( payload.providers ) ) {
		renderNoProvidersNotice( container );
		return;
	}

	const connectedProviders = payload.providers.filter( ( provider ) => provider.connected !== false );
	if ( connectedProviders.length === 0 ) {
		renderNoProvidersNotice( container );
		return;
	}

	mountPanel( container, {
		restNamespace: payload.restNamespace,
		providers: connectedProviders,
		redirectUrl: payload.redirectUrl,
		defaultGeneratorModel: payload.defaultGeneratorModel,
		defaultGeneratorProvider: payload.defaultGeneratorProvider,
		defaultAspectRatio: payload.defaultAspectRatio,
		aspectRatioOptions: payload.aspectRatioOptions,
		defaultResolution: payload.defaultResolution,
		resolutionOptions: payload.resolutionOptions,
	} );
};

if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', init );
} else {
	init();
}
