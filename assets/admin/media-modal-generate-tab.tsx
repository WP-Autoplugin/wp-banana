/**
 * Adds a "Generate Image" tab to WordPress media modals.
 *
 * @package WPBanana
 */

import { render } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import GeneratePanel from './generate-ai-panel';
import type { ProviderInfo } from './types/generate';

type MediaData = {
	restNamespace: string;
	providers: ProviderInfo[];
	defaultGeneratorModel?: string;
	defaultGeneratorProvider?: string;
	defaultAspectRatio?: string;
	aspectRatioOptions?: string[];
	defaultResolution?: string;
	resolutionOptions?: string[];
};

type WindowWithBanana = Window & {
	wpBananaMedia?: MediaData;
	wp?: {
		element?: {
			createRoot?: ( container: HTMLElement ) => { render: ( element: JSX.Element ) => void; unmount?: () => void };
		};
		media?: any;
	};
};

const roots = new WeakMap< HTMLElement, { render: ( element: JSX.Element ) => void; unmount?: () => void } >();

const getData = (): MediaData | null => {
	const win = window as unknown as WindowWithBanana;
	const data = win.wpBananaMedia;
	if ( ! data || ! Array.isArray( data.providers ) ) {
		return null;
	}
	const connected = data.providers.filter( ( provider ) => provider.connected !== false );
	if ( connected.length === 0 ) {
		return null;
	}
	return {
		restNamespace: data.restNamespace,
		providers: connected,
		defaultGeneratorModel: data.defaultGeneratorModel,
		defaultGeneratorProvider: data.defaultGeneratorProvider,
		defaultAspectRatio: data.defaultAspectRatio,
		aspectRatioOptions: data.aspectRatioOptions,
		defaultResolution: data.defaultResolution,
		resolutionOptions: data.resolutionOptions,
	};
};

const mountGeneratePanel = (
	container: HTMLElement,
	data: MediaData,
	onComplete: () => void
) => {
	const win = window as unknown as WindowWithBanana;
	const wpElement = win.wp?.element;

	if ( typeof wpElement?.createRoot === 'function' ) {
		let root = roots.get( container );
		if ( ! root ) {
			root = wpElement.createRoot( container );
			roots.set( container, root );
		}
		root.render(
			<GeneratePanel
				providers={ data.providers }
				restNamespace={ data.restNamespace }
				onComplete={ onComplete }
				defaultGeneratorModel={ data.defaultGeneratorModel }
				defaultGeneratorProvider={ data.defaultGeneratorProvider }
				defaultAspectRatio={ data.defaultAspectRatio }
				aspectRatioOptions={ data.aspectRatioOptions }
				defaultResolution={ data.defaultResolution }
				resolutionOptions={ data.resolutionOptions }
			/>
		);
		return;
	}

	render(
		<GeneratePanel
			providers={ data.providers }
			restNamespace={ data.restNamespace }
			onComplete={ onComplete }
			defaultGeneratorModel={ data.defaultGeneratorModel }
			defaultGeneratorProvider={ data.defaultGeneratorProvider }
			defaultAspectRatio={ data.defaultAspectRatio }
			aspectRatioOptions={ data.aspectRatioOptions }
			defaultResolution={ data.defaultResolution }
			resolutionOptions={ data.resolutionOptions }
		/>,
		container
	);
};

const refreshMediaLibrary = () => {
	const win = window as unknown as WindowWithBanana;
	const media = win.wp?.media;
	const frame = media?.frame;
	if ( ! frame ) {
		return;
	}

	const collections: any[] = [];
	const state = typeof frame.state === 'function' ? frame.state() : null;
	const stateLibrary = state && typeof state.get === 'function' ? state.get( 'library' ) : null;
	if ( stateLibrary ) {
		collections.push( stateLibrary );
	}
	if ( frame.library ) {
		collections.push( frame.library );
	}
	const contentView = typeof frame.content?.get === 'function' ? frame.content.get() : null;
	if ( contentView?.collection ) {
		collections.push( contentView.collection );
	}

	collections.forEach( ( collection ) => {
		if ( ! collection ) {
			return;
		}

		if ( typeof collection.props?.set === 'function' ) {
			collection.props.set( { ignore: Date.now() } );
		}

		const urlValue = (() => {
			if ( typeof collection.url === 'function' ) {
				try {
					return collection.url();
				} catch ( error ) {
					return '';
				}
			}
			return collection.url;
		})();

		const hasUrl = typeof urlValue === 'string' && urlValue.length > 0;
		if ( ! hasUrl ) {
			return;
		}

		if ( typeof collection.more === 'function' ) {
			collection.more();
			return;
		}
		if ( typeof collection.fetch === 'function' ) {
			collection.fetch();
		}
	} );

	if ( typeof frame.trigger === 'function' ) {
		frame.trigger( 'library:refresh' );
	}
};

if ( typeof document !== 'undefined' ) {
	document.addEventListener( 'wp-banana:media-refresh', () => {
		refreshMediaLibrary();
	} );
}

const enhanceMediaModal = ( modal: HTMLElement, data: MediaData ) => {
	// If we've already injected our generate tab into this modal, skip.
	const existingTab = modal.querySelector< HTMLButtonElement >( '.media-menu-item.wp-banana-media-tab' );
	if ( existingTab ) {
		return;
	}

	let panel: HTMLElement | null = null;

	const router = modal.querySelector< HTMLElement >( '.media-router' );
	const content = modal.querySelector< HTMLElement >( '.media-frame-content' );
	if ( ! router || ! content ) {
		return;
	}

    // Generate unique IDs for tab + panel to satisfy ARIA relationships and avoid collisions if multiple modals exist.
	const instanceId = `${ Date.now().toString( 36 ) }-${ Math.random().toString( 36 ).slice( 2, 8 ) }`;
	const tabId = `wp-banana-generate-tab-${ instanceId }`;
	const panelId = `wp-banana-generate-panel-${ instanceId }`;

	const tab = document.createElement( 'button' );
	tab.type = 'button';
	tab.id = tabId;
	tab.className = 'media-menu-item wp-banana-media-tab';
	tab.textContent = __( 'Generate Image', 'wp-banana' );
	tab.setAttribute( 'role', 'tab' );
	tab.setAttribute( 'aria-selected', 'false' );
	tab.setAttribute( 'aria-controls', panelId );
	tab.dataset.wpBanana = '1';

	router.appendChild( tab );

	const createPanel = () => {
		const container = document.createElement( 'div' );
		container.className = 'wp-banana-media-panel';
		container.id = panelId;
		container.setAttribute( 'role', 'tabpanel' );
		container.setAttribute( 'aria-labelledby', tabId );
		container.setAttribute( 'hidden', 'hidden' );
		container.setAttribute( 'aria-hidden', 'true' );
		container.style.display = 'none';
		content.appendChild( container );
		return container;
	};

	panel = createPanel();
	let mounted = false;

	const hidePanel = () => {
		modal.classList.remove( 'wp-banana-media-tab-active' );
		tab.classList.remove( 'active' );
		tab.setAttribute( 'aria-selected', 'false' );
		if ( panel && panel.isConnected ) {
			panel.setAttribute( 'hidden', 'hidden' );
			panel.setAttribute( 'aria-hidden', 'true' );
			panel.style.display = 'none';
		}
	};

	const switchToLibrary = () => {
		const libraryTab = router.querySelector< HTMLElement >( '#menu-item-browse' )
			|| router.querySelector< HTMLElement >( '.media-menu-item[data-router="browse"]' )
			|| router.querySelector< HTMLElement >( '.media-menu-item[data-router="library"]' )
			|| router.querySelector< HTMLElement >( '.media-menu-item:not(.wp-banana-media-tab)' );
		if ( ! libraryTab || libraryTab === tab ) {
			hidePanel();
			return;
		}

		hidePanel();
		if ( typeof libraryTab.click === 'function' ) {
			libraryTab.click();
		}
	};

	const setActive = () => {
		router.querySelectorAll< HTMLElement >( '.media-menu-item' ).forEach( ( item ) => {
			const isActive = item === tab;
			item.classList.toggle( 'active', isActive );
			item.setAttribute( 'aria-selected', isActive ? 'true' : 'false' );
		} );
	};

	const showPanel = () => {
		if ( ! panel || ! panel.isConnected ) {
			if ( panel ) {
				roots.delete( panel );
			}
			panel = createPanel();
			mounted = false;
		}

		if ( ! mounted ) {
			mountGeneratePanel( panel, data, () => {
				refreshMediaLibrary();
				switchToLibrary();
			} );
			mounted = true;
		}
		panel.removeAttribute( 'hidden' );
		panel.removeAttribute( 'aria-hidden' );
		panel.style.display = 'block';
		setActive();
		modal.classList.add( 'wp-banana-media-tab-active' );
		const textarea = panel.querySelector< HTMLTextAreaElement >( 'textarea' );
		if ( textarea ) {
			setTimeout( () => textarea.focus(), 50 );
		}
	};

	router.addEventListener( 'click', ( event ) => {
			const target = event.target as HTMLElement | null;
			const menuItem = target?.closest< HTMLElement >( '.media-menu-item' );
			if ( ! menuItem ) {
				return;
			}

			if ( menuItem === tab ) {
				event.preventDefault();
				event.stopPropagation();
				event.stopImmediatePropagation();
				showPanel();
				return;
			}

			hidePanel();
	} );

	// Button already handles Enter/Space activation natively; no custom keydown needed.

	const closeButton = modal.querySelector< HTMLElement >( '.media-modal-close' );
	if ( closeButton ) {
		closeButton.addEventListener( 'click', hidePanel );
	}

	const backdrop = modal.nextElementSibling as HTMLElement | null;
	if ( backdrop && backdrop.classList.contains( 'media-modal-backdrop' ) ) {
		backdrop.addEventListener( 'click', hidePanel );
	}

	const modalObserver = new MutationObserver( () => {
		const isHidden = modal.style.display === 'none' || modal.getAttribute( 'aria-hidden' ) === 'true';
		if ( isHidden ) {
			hidePanel();
		}
	} );

	modalObserver.observe( modal, { attributes: true, attributeFilter: [ 'style', 'class', 'aria-hidden' ] } );

	hidePanel();
};

const init = () => {
	const data = getData();
	if ( ! data ) {
		return;
	}

	const apply = ( modal: HTMLElement ) => enhanceMediaModal( modal, data );

	document.querySelectorAll< HTMLElement >( '.media-modal' ).forEach( apply );

	const observer = new MutationObserver( ( records ) => {
		records.forEach( ( record ) => {
			record.addedNodes.forEach( ( node ) => {
				if ( ! ( node instanceof HTMLElement ) ) {
					return;
				}
				if ( node.matches( '.media-modal' ) ) {
					apply( node );
					return;
				}
				node.querySelectorAll( '.media-modal' ).forEach( ( modal ) => {
					apply( modal as HTMLElement );
				} );
			} );
		} );
	} );

	observer.observe( document.body, { childList: true, subtree: true } );
};

if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', init );
} else {
	init();
}
