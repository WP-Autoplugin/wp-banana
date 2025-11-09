/**
 * Media Library toolbar enhancements (Generate Image panel).
 *
 * @package WPBanana
 */

import { render } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import GeneratePanel from './generate-ai-panel';
import type { ProviderInfo } from './types/generate';

declare global {
	interface Window {
		wpBananaMedia?: {
			restNamespace: string;
			providers: ProviderInfo[];
			defaultGeneratorModel?: string;
			defaultGeneratorProvider?: string;
			defaultAspectRatio?: string;
			aspectRatioOptions?: string[];
		};
		wpBananaReferenceQueue?: ReferencePayload[][];
	}
}

let root: any | null = null;
const REFERENCE_LIMIT = 4;

type AttachmentSizeMap = Record<string, { url?: string } | undefined>;

type MediaAttachment = {
	id?: number | string;
	mime?: string;
	type?: string;
	subtype?: string;
	url?: string;
	filename?: string;
	sizes?: AttachmentSizeMap | null;
	[ key: string ]: unknown;
};

type MediaSelectionModel = {
	toJSON?: () => MediaAttachment;
	attributes?: MediaAttachment;
};

type ReferencePayload = {
	attachmentId?: number;
	sourceUrl: string;
	previewUrl: string;
	filename?: string;
	mime?: string;
};

const enqueueReferenceDispatch = ( references: ReferencePayload[] ) => {
	const globalWindow = ( window as unknown ) as { wpBananaReferenceQueue?: ReferencePayload[][] };
	if ( ! Array.isArray( globalWindow.wpBananaReferenceQueue ) ) {
		globalWindow.wpBananaReferenceQueue = [];
	}
	globalWindow.wpBananaReferenceQueue.push( references );
	window.dispatchEvent( new CustomEvent( 'wp-banana:set-reference-images', {
		detail: {
			references,
		},
	} ) );
};

const mountGeneratePanel = (
	container: HTMLElement,
	data: {
		restNamespace: string;
		providers: ProviderInfo[];
		defaultGeneratorModel?: string;
		defaultGeneratorProvider?: string;
		defaultAspectRatio?: string;
		aspectRatioOptions?: string[];
	}
) => {
	const wpElement = ( ( window as unknown ) as { wp?: { element?: Record<string, unknown> } } ).wp?.element as any;
	const supportsCreateRoot = typeof wpElement?.createRoot === 'function';

	if ( supportsCreateRoot ) {
		if ( ! root ) {
			root = wpElement.createRoot( container );
		}
		root.render(
			<GeneratePanel
				providers={ data.providers }
				restNamespace={ data.restNamespace }
				onComplete={ () => {} }
				defaultGeneratorModel={ data.defaultGeneratorModel }
				defaultGeneratorProvider={ data.defaultGeneratorProvider }
				defaultAspectRatio={ data.defaultAspectRatio }
				aspectRatioOptions={ data.aspectRatioOptions }
			/>
		);
		return;
	}

	// Fallback for older WordPress versions still on React 17 wrapper
	render(
		<GeneratePanel
			providers={ data.providers }
			restNamespace={ data.restNamespace }
			onComplete={ () => {} }
			defaultGeneratorModel={ data.defaultGeneratorModel }
			defaultGeneratorProvider={ data.defaultGeneratorProvider }
			defaultAspectRatio={ data.defaultAspectRatio }
			aspectRatioOptions={ data.aspectRatioOptions }
		/>,
		container
	);
};

let mediaRefreshListenerBound = false;
const triggerAttachmentFilterChange = () => {
	const selects = document.querySelectorAll<HTMLSelectElement>( 'select.attachment-filters' );
	let triggered = false;
	selects.forEach( ( select ) => {
		const event = new Event( 'change', { bubbles: true } );
		select.dispatchEvent( event );
		triggered = true;
	} );

	if ( ! triggered ) {
		const refreshButton = document.querySelector<HTMLButtonElement>( '.media-toolbar-secondary button' );
		if ( refreshButton ) {
			refreshButton.click();
		}
	}
};

const init = () => {
	const pageNow = ( ( window as unknown ) as { pagenow?: string } ).pagenow;
	if ( pageNow !== 'upload' ) {
		return;
	}

	if ( ! mediaRefreshListenerBound ) {
		document.addEventListener( 'wp-banana:media-refresh', triggerAttachmentFilterChange );
		mediaRefreshListenerBound = true;
	}

	const data = window.wpBananaMedia;
	if ( ! data || ! Array.isArray( data.providers ) || data.providers.length === 0 ) {
		return;
	}

	const connectedProviders = data.providers.filter( ( provider ) => provider.connected !== false );
	if ( connectedProviders.length === 0 ) {
		return;
	}

	const wrap = document.querySelector< HTMLElement >( '#wpbody-content .wrap' );
	if ( ! wrap ) {
		return;
	}

	const existingAction = wrap.querySelector< HTMLElement >( '.page-title-action' );
	const button = document.createElement( 'button' );
	button.type = 'button';
	button.className = 'page-title-action wp-banana-generate-toggle';
	button.textContent = __( 'Generate Image', 'wp-banana' );
	button.setAttribute( 'aria-expanded', 'false' );

	if ( existingAction && existingAction.parentNode ) {
		existingAction.insertAdjacentElement( 'afterend', button );
	} else {
		const heading = wrap.querySelector< HTMLElement >( '.wp-heading-inline' );
		if ( heading ) {
			heading.insertAdjacentElement( 'afterend', button );
		} else {
			wrap.prepend( button );
		}
	}

	const panel = document.createElement( 'div' );
	panel.id = 'wp-banana-generate-panel';
	panel.className = 'wp-banana-generate-panel';
	panel.style.display = 'none';
	panel.style.marginTop = '12px';
	panel.style.width = '100%';
	panel.style.maxWidth = 'none';
	panel.style.boxSizing = 'border-box';

	const headerEnd = wrap.querySelector< HTMLElement >( '.wp-header-end' );
	if ( headerEnd ) {
		headerEnd.insertAdjacentElement( 'afterend', panel );
	} else if ( button.nextElementSibling ) {
		button.insertAdjacentElement( 'afterend', panel );
	} else {
		wrap.appendChild( panel );
	}

	button.setAttribute( 'aria-controls', panel.id );

	let isMounted = false;
	const ensureMounted = () => {
		if ( isMounted ) {
			return;
		}
		mountGeneratePanel( panel, {
			restNamespace: data.restNamespace,
			providers: connectedProviders,
			defaultGeneratorModel: data.defaultGeneratorModel,
			defaultGeneratorProvider: data.defaultGeneratorProvider,
			defaultAspectRatio: data.defaultAspectRatio,
			aspectRatioOptions: data.aspectRatioOptions,
		} );
		isMounted = true;
	};

	const focusPrompt = () => {
		const textarea = panel.querySelector< HTMLTextAreaElement >( 'textarea' );
		if ( textarea ) {
			textarea.focus();
		}
	};

	const openPanel = () => {
		ensureMounted();
		panel.style.display = 'block';
		button.setAttribute( 'aria-expanded', 'true' );
		button.classList.add( 'wp-banana-generate-toggle--open' );
		focusPrompt();
	};

	const closePanel = () => {
		panel.style.display = 'none';
		button.setAttribute( 'aria-expanded', 'false' );
		button.classList.remove( 'wp-banana-generate-toggle--open' );
	};

	button.addEventListener( 'click', ( event ) => {
		// Avoid triggering the core media uploader toggle bound to page-title-action.
		event.stopPropagation();
		event.stopImmediatePropagation();
		const isOpen = panel.style.display === 'block';
		if ( isOpen ) {
			closePanel();
			return;
		}
		openPanel();
	} );

	const setupBulkVariantButton = () => {
		let actionButton: HTMLButtonElement | null = null;
		let deleteButtonObserver: MutationObserver | null = null;
		let waitObserver: MutationObserver | null = null;
		let pollHandle: number | null = null;
		let attempts = 0;

		const mediaGlobal = ( window as unknown ) as {
			wp?: {
				media?: {
					frame?: any;
				};
			};
		};

		const selectionEvents = [ 'add', 'remove', 'reset', 'update' ];

		const attemptSetup = (): boolean => {
			if ( actionButton ) {
				return true;
			}

			const secondaryToolbar = wrap.querySelector< HTMLElement >( '.media-toolbar-secondary' );
			const deleteButton = secondaryToolbar?.querySelector< HTMLButtonElement >( '.delete-selected-button' ) ?? null;
			if ( ! secondaryToolbar || ! deleteButton ) {
				return false;
			}

			const frame = mediaGlobal.wp?.media?.frame;
			if ( ! frame ) {
				return false;
			}

			const state = typeof frame.state === 'function' ? frame.state() : null;
			if ( ! state || typeof state.get !== 'function' ) {
				return false;
			}

			const selection = state.get( 'selection' );
			if ( ! selection ) {
				return false;
			}

			actionButton = document.createElement( 'button' );
			actionButton.type = 'button';
			actionButton.className = 'button media-button button-primary button-large wp-banana-bulk-variant-button hidden';
			actionButton.textContent = __( 'Create AI Variant', 'wp-banana' );
			actionButton.disabled = true;

			deleteButton.insertAdjacentElement( 'beforebegin', actionButton );

			const isBulkModeActive = () => {
				if ( deleteButton.classList.contains( 'hidden' ) ) {
					return false;
				}
				if ( deleteButton.hasAttribute( 'hidden' ) ) {
					return false;
				}
				return deleteButton.offsetParent !== null;
			};

			const getSelectionCount = () => {
				if ( typeof selection.length === 'number' ) {
					return selection.length;
				}
				const models = ( selection as { models?: unknown[] } ).models;
				return Array.isArray( models ) ? models.length : 0;
			};

			const updateButtonState = () => {
				const count = getSelectionCount();
				const bulkActive = isBulkModeActive();
				if ( ! bulkActive ) {
					actionButton?.classList.add( 'hidden' );
					if ( actionButton ) {
						actionButton.disabled = true;
					}
					return;
				}

				actionButton?.classList.remove( 'hidden' );
				if ( actionButton ) {
					actionButton.disabled = count === 0;
					actionButton.textContent = count > 1 ? __( 'Combine with AI', 'wp-banana' ) : __( 'Create AI Variant', 'wp-banana' );
				}
			};

			if ( typeof selection.on === 'function' ) {
				selectionEvents.forEach( ( eventName ) => {
					selection.on( eventName, updateButtonState );
				} );
			}

			deleteButtonObserver = new MutationObserver( () => {
				updateButtonState();
			} );
			deleteButtonObserver.observe( deleteButton, {
				attributes: true,
				attributeFilter: [ 'class', 'style', 'hidden', 'disabled', 'aria-hidden' ],
			} );

			const collectSelectedAttachments = (): MediaAttachment[] => {
				const models: Array< MediaSelectionModel | null > = typeof selection.toArray === 'function' ? selection.toArray() : ( selection.models ?? [] );
				return models
					.map( ( model: MediaSelectionModel | null ) => {
						if ( ! model ) {
							return null;
						}
						if ( typeof model.toJSON === 'function' ) {
							return model.toJSON();
						}
						return model.attributes ?? null;
					} )
					.filter( ( item ): item is MediaAttachment => !! item );
			};

			actionButton.addEventListener( 'click', () => {
				const attachments = collectSelectedAttachments();
				if ( attachments.length === 0 ) {
					return;
				}

				const images = attachments.filter( ( item: MediaAttachment ) => {
					const mime = typeof item.mime === 'string' ? item.mime : typeof item.type === 'string' && typeof item.subtype === 'string' ? `${ item.type }/${ item.subtype }` : '';
					return mime.startsWith( 'image/' );
				} );

				if ( images.length === 0 ) {
					window.alert( __( 'Selected items must be images to use them as AI references.', 'wp-banana' ) );
					return;
				}

				if ( images.length > REFERENCE_LIMIT ) {
					window.alert( __( 'Select up to four images for AI references.', 'wp-banana' ) );
				}

				const limited = images.slice( 0, REFERENCE_LIMIT );
				const references: ReferencePayload[] = [];
				limited.forEach( ( item: MediaAttachment ) => {
					const mime = typeof item.mime === 'string' ? item.mime : typeof item.type === 'string' && typeof item.subtype === 'string' ? `${ item.type }/${ item.subtype }` : undefined;
					const filename = typeof item.filename === 'string' ? item.filename : undefined;
					const sourceUrl = typeof item.url === 'string' ? item.url : undefined;
					if ( ! sourceUrl ) {
						return;
					}
					const sizes = item.sizes && typeof item.sizes === 'object' ? ( item.sizes as AttachmentSizeMap ) : undefined;
					const previewCandidate = [ 'medium', 'large', 'thumbnail', 'full' ]
						.map( ( key ) => sizes?.[ key ]?.url )
						.find( ( value ): value is string => typeof value === 'string' && value.length > 0 );
					const previewUrl = previewCandidate ?? sourceUrl;
					references.push( {
						attachmentId: typeof item.id === 'number' ? item.id : parseInt( String( item.id ), 10 ) || undefined,
						sourceUrl,
						previewUrl,
						filename,
						mime,
					} );
				} );

			if ( references.length === 0 ) {
				window.alert( __( 'Unable to load the selected images.', 'wp-banana' ) );
				return;
			}

			enqueueReferenceDispatch( references );
			openPanel();
			panel.scrollIntoView( { behavior: 'smooth', block: 'start' } );
		} );

			updateButtonState();
			return true;
		};

		const resolveSetup = () => {
			if ( attemptSetup() ) {
				if ( waitObserver ) {
					waitObserver.disconnect();
					waitObserver = null;
				}
				if ( pollHandle !== null ) {
					window.clearInterval( pollHandle );
					pollHandle = null;
				}
				return true;
			}
			return false;
		};

		if ( resolveSetup() ) {
			return;
		}

		waitObserver = new MutationObserver( () => {
			resolveSetup();
		} );
		waitObserver.observe( wrap, { childList: true, subtree: true } );

		const bulkToggle = wrap.querySelector< HTMLElement >( '.select-mode-toggle' );
		if ( bulkToggle ) {
			bulkToggle.addEventListener( 'click', () => {
				window.setTimeout( () => {
					resolveSetup();
				}, 0 );
			} );
		}

		pollHandle = window.setInterval( () => {
			attempts += 1;
			if ( resolveSetup() || attempts > 40 ) {
				if ( pollHandle !== null ) {
					window.clearInterval( pollHandle );
					pollHandle = null;
				}
			}
		}, 250 );
	};

	setupBulkVariantButton();
};

if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', init );
} else {
	init();
}
