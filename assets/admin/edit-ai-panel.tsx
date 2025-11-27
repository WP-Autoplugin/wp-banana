/**
 * AI Edit panel integration inside the core image editor.
 *
 * @package WPBanana
 */

import { render, useEffect, useMemo, useState, useCallback } from '@wordpress/element';
import type { ChangeEvent, KeyboardEvent } from 'react';
import { __, sprintf } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import {
	Card,
	CardBody,
	Notice,
} from '@wordpress/components';
import type { ProviderInfo } from './types/generate';
import PromptComposer from './components/PromptComposer';
import ReferenceTray from './components/ReferenceTray';
import OptionsDrawer from './components/OptionsDrawer';
import { useGeneratorConfig } from './hooks/use-generator-config';
import { useReferenceImages } from './hooks/use-reference-images';
import { MIN_PROMPT_LENGTH, supportsMultiImageModel } from './utils/ai-generate';

declare global {
	interface Window {
		imageEdit?: any;
		jQuery?: any;
	}
}

type EditPanelProps = {
	attachmentId: number;
	providers: ProviderInfo[];
	restNamespace: string;
	defaultEditorModel?: string;
	defaultEditorProvider?: string;
};

type ModelsResponse = {
	models?: string[];
};

type ApiError = {
	message?: string;
};

type EditSuccessPayload = {
	attachment_id?: number;
	url?: string;
	parent_id?: number;
};

type BufferResponse = {
	buffer_key: string;
	width: number;
	height: number;
	mime: string;
	attachment_id: number;
	provider?: string;
	model?: string;
	prompt?: string;
};

type SaveAsResponse = {
	attachment_id?: number;
	url?: string;
	parent_id?: number;
	filename?: string;
};

type NoticeState = {
	type: 'success' | 'info';
	message: string;
	url?: string;
	linkLabel?: string;
	openInNewTab?: boolean;
};

const HISTORY_KEY = 'banana';
const NOTICE_EVENT = 'wp-banana-ai-notice';
const HISTORY_CHANGE_EVENT = 'wp-banana-history-change';

const parseHistoryValue = ( value: string ): unknown[] => {
	if ( ! value ) {
		return [];
	}
	try {
		const parsed = JSON.parse( value );
		return Array.isArray( parsed ) ? parsed : [];
	} catch ( error ) {
		return [];
	}
};

const getActiveHistorySteps = ( attachmentId: number ): unknown[] => {
	const historyField = document.getElementById( `imgedit-history-${ attachmentId }` ) as HTMLInputElement | HTMLTextAreaElement | null;
	if ( ! historyField ) {
		return [];
	}
	const steps = parseHistoryValue( historyField.value || '' );
	if ( steps.length === 0 ) {
		return steps;
	}
	const undoneField = document.getElementById( `imgedit-undone-${ attachmentId }` ) as HTMLInputElement | null;
	if ( ! undoneField || ! undoneField.value ) {
		return steps;
	}
	const undoneCount = parseInt( undoneField.value, 10 );
	if ( Number.isNaN( undoneCount ) || undoneCount <= 0 ) {
		return steps;
	}
	return steps.slice( 0, Math.max( 0, steps.length - undoneCount ) );
};

const resolveBaseBufferKey = ( attachmentId: number ): string | null => {
	const steps = getActiveHistorySteps( attachmentId );
	for ( let index = steps.length - 1; index >= 0; index -= 1 ) {
		const entry = steps[ index ];
		if ( ! entry || typeof entry !== 'object' ) {
			continue;
		}
		const banana = ( entry as Record<string, unknown> )[ HISTORY_KEY ];
		if ( banana && typeof banana === 'object' ) {
			const key = ( banana as Record<string, unknown> ).key;
			if ( typeof key === 'string' && key.length > 0 ) {
				return key;
			}
		}
	}
	return null;
};

const ensureEditToggleStyles = () => {
	const styleId = 'wp-banana-edit-toggle-styles';
	if ( document.getElementById( styleId ) ) {
		return;
	}
	const style = document.createElement( 'style' );
	style.id = styleId;
	style.textContent = `
.imgedit-menu .wp-banana-edit-toggle.button:after {
	font: normal 16px/1 dashicons;
	content: '\\f140';
	margin-left: 2px;
	margin-right: 0;
	speak: never;
	-webkit-font-smoothing: antialiased;
	display: inline-block;
	top: 0;
}
.imgedit-menu .wp-banana-edit-toggle.button[aria-expanded="true"]:after {
	content: '\\f142';
}
`;
	const target = document.head ?? document.body ?? document.documentElement;
	if ( target ) {
		target.appendChild( style );
	}
};

const escapeHtml = ( value: string ): string => {
	return value
		.replace( /&/g, '&amp;' )
		.replace( /</g, '&lt;' )
		.replace( />/g, '&gt;' )
		.replace( /"/g, '&quot;' )
		.replace( /'/g, '&#039;' );
};

const updateEditorNotice = (
	attachmentId: number,
	status: 'success' | 'error',
	message: string,
	url?: string,
	options?: { openInNewTab?: boolean; label?: string }
) => {
	const container = document.getElementById( `imgedit-response-${ attachmentId }` );
	if ( ! container ) {
		return;
	}
	const classes = status === 'success' ? 'notice notice-success' : 'notice notice-error';
	const openInNewTab = options?.openInNewTab !== false;
	const linkLabel = options?.label ?? __( 'Open image in new tab', 'wp-banana' );
	let html = `<div class="${ classes }" tabindex="-1" role="alert"><p>${ escapeHtml( message ) }`;
	if ( url ) {
		const targetAttr = openInNewTab ? ' target="_blank" rel="noopener noreferrer"' : '';
		html += ` <a href="${ escapeHtml( url ) }"${ targetAttr }><em>${ escapeHtml( linkLabel ) }</em></a>`;
	}
	html += '</p></div>';
	container.innerHTML = html;
	const speak = ( window as unknown as { wp?: { a11y?: { speak?: ( text: string ) => void } } } ).wp?.a11y?.speak;
	if ( typeof speak === 'function' ) {
		speak( message );
	}
};

const patchImageEdit = () => {
	const imageEdit = ( window as unknown as { imageEdit?: any } ).imageEdit;
	const $ = ( window as unknown as { jQuery?: any } ).jQuery;
	if ( ! imageEdit || ! $ || imageEdit.__wpBananaPatched ) {
		return;
	}

	const emitHistoryChange = ( postid?: number ) => {
		if ( typeof postid === 'number' && ! Number.isNaN( postid ) ) {
			document.dispatchEvent( new CustomEvent<{ attachmentId: number }>( HISTORY_CHANGE_EVENT, { detail: { attachmentId: postid } } ) );
		}
	};

	imageEdit.filterHistory = function filterHistoryPatched( postid: number, setSize: number ) {
		const historyField = $( `#imgedit-history-${ postid }` );
		const undoneField = $( `#imgedit-undone-${ postid }` );
		const historyValue = historyField.val();
		if ( '' === historyValue || null === historyValue || undefined === historyValue ) {
			if ( setSize ) {
				this.hold.w = this.hold.ow;
				this.hold.h = this.hold.oh;
			}
			return '';
		}

		let history;
		try {
			history = JSON.parse( historyValue );
		} catch ( error ) {
			return '';
		}

		let pop = parseInt( undoneField.val() || '0', 10 );
		if ( pop > 0 ) {
			history = history.slice( 0, history.length - pop );
		}

		if ( setSize ) {
			if ( history.length === 0 ) {
				this.hold.w = this.hold.ow;
				this.hold.h = this.hold.oh;
				return '';
			}
			const lastEntry = history[ history.length - 1 ];
			if ( lastEntry ) {
				const banana = lastEntry[ HISTORY_KEY ];
				if ( banana && typeof banana.fw !== 'undefined' ) {
					this.hold.w = banana.fw;
					this.hold.h = banana.fh;
				} else {
					const crop = lastEntry.c;
					const rotate = lastEntry.r;
					const flip = lastEntry.f;
					const dims = crop || rotate || flip;
					if ( dims && typeof dims.fw !== 'undefined' && typeof dims.fh !== 'undefined' ) {
						this.hold.w = dims.fw;
						this.hold.h = dims.fh;
					}
				}
			}
		}

		const ops: Record<string, unknown>[] = [];
		for ( let index = 0; index < history.length; index++ ) {
			const entry = history[ index ];
			if ( entry && entry.c ) {
				const crop = entry.c;
				ops[ index ] = {
					c: {
						x: crop.x,
						y: crop.y,
						w: crop.w,
						h: crop.h,
						r: crop.r,
					},
				};
			} else if ( entry && entry.r ) {
				const rotate = entry.r;
				ops[ index ] = {
					r: rotate.r,
				};
			} else if ( entry && entry.f ) {
				const flip = entry.f;
				ops[ index ] = {
					f: flip.f,
				};
			} else if ( entry && entry[ HISTORY_KEY ] ) {
				const banana = entry[ HISTORY_KEY ];
				ops[ index ] = {
					type: 'banana',
					[ HISTORY_KEY ]: {
						key: banana.key,
						fw: banana.fw,
						fh: banana.fh,
						mime: banana.mime || '',
					},
				};
			}
		}

		return JSON.stringify( ops );
	};

	imageEdit.__wpBananaPatched = true;

	if ( ! imageEdit.__wpBananaHistoryPatched ) {
		const originalAddStep = typeof imageEdit.addStep === 'function' ? imageEdit.addStep : null;
		if ( originalAddStep ) {
			imageEdit.addStep = function patchedAddStep( op: unknown, postid: number, nonce: string ) {
				const result = originalAddStep.call( this, op, postid, nonce );
				emitHistoryChange( postid );
				return result;
			};
		}

		const originalUndo = typeof imageEdit.undo === 'function' ? imageEdit.undo : null;
		if ( originalUndo ) {
			imageEdit.undo = function patchedUndo( postid: number, nonce: string ) {
				const result = originalUndo.call( this, postid, nonce );
				emitHistoryChange( postid );
				return result;
			};
		}

		const originalRedo = typeof imageEdit.redo === 'function' ? imageEdit.redo : null;
		if ( originalRedo ) {
			imageEdit.redo = function patchedRedo( postid: number, nonce: string ) {
				const result = originalRedo.call( this, postid, nonce );
				emitHistoryChange( postid );
				return result;
			};
		}

		imageEdit.__wpBananaHistoryPatched = true;
	}
};

const pushBufferedStep = ( payload: BufferResponse ) => {
	patchImageEdit();
	const imageEdit = ( window as unknown as { imageEdit?: any } ).imageEdit;
	if ( ! imageEdit ) {
		throw new Error( __( 'Image editor not ready.', 'wp-banana' ) );
	}
	const nonceField = document.getElementById( `imgedit-nonce-${ payload.attachment_id }` ) as HTMLInputElement | null;
	if ( ! nonceField || '' === nonceField.value ) {
		throw new Error( __( 'Editor security token missing.', 'wp-banana' ) );
	}
	const bananaPayload: Record<string, unknown> = {
		key: payload.buffer_key,
		fw: payload.width,
		fh: payload.height,
		mime: payload.mime,
	};
	if ( payload.provider ) {
		bananaPayload.provider = payload.provider;
	}
	if ( payload.model ) {
		bananaPayload.model = payload.model;
	}
	if ( payload.prompt ) {
		bananaPayload.prompt = payload.prompt;
	}
	imageEdit.addStep(
		{
			type: 'banana',
			[ HISTORY_KEY ]: bananaPayload,
		},
		payload.attachment_id,
		nonceField.value
	);
};

const buildLibraryUrl = ( attachmentId: number ): string => {
	const ajaxUrl = ( window as unknown as { ajaxurl?: string } ).ajaxurl;
	if ( ajaxUrl ) {
		try {
			const url = new URL( ajaxUrl, window.location.origin );
			url.hash = '';
			const segments = url.pathname.split( '/' );
			segments[ segments.length - 1 ] = 'upload.php';
			url.pathname = segments.join( '/' );
			url.search = `item=${ attachmentId }`;
			return url.toString();
		} catch ( error ) {
			// Fallback when URL parsing fails.
		}
	}
	const origin = window.location.origin.replace( /\/$/, '' );
	return `${ origin }/wp-admin/upload.php?item=${ attachmentId }`;
};

const registerSaveAsButton = (
	tools: HTMLElement,
	attachmentId: number,
	restNamespace: string,
	openPanel: () => void
) => {
	const submitRow = tools.querySelector<HTMLElement>( '.imgedit-submit' );
	if ( ! submitRow || submitRow.querySelector( '.wp-banana-save-as' ) ) {
		return;
	}

	const button = document.createElement( 'button' );
	button.type = 'button';
	button.className = 'button wp-banana-save-as';
	button.textContent = __( 'Save As Copy', 'wp-banana' );
	button.style.marginLeft = '8px';
	submitRow.appendChild( button );

	const dispatchNotice = ( state: NoticeState ) => {
		document.dispatchEvent( new CustomEvent< NoticeState >( NOTICE_EVENT, { detail: state } ) );
	};

	const computeHasChanges = () => {
		const editorApi = ( window as unknown as { imageEdit?: any } ).imageEdit;
		if ( ! editorApi || typeof editorApi.filterHistory !== 'function' ) {
			return false;
		}
		const historyJson = editorApi.filterHistory( attachmentId, 0 );
		return !! historyJson && historyJson !== '[]';
	};

	const updateDisabledState = () => {
		if ( button.classList.contains( 'is-busy' ) ) {
			return;
		}
		button.disabled = ! computeHasChanges();
	};

	updateDisabledState();

	const historyListener = ( event: Event ) => {
		const custom = event as CustomEvent<{ attachmentId?: number }>;
		if ( custom.detail && custom.detail.attachmentId === attachmentId ) {
			updateDisabledState();
		}
	};

	document.addEventListener( HISTORY_CHANGE_EVENT, historyListener as EventListener );

	button.addEventListener( 'click', async () => {
		const imageEdit = ( window as unknown as { imageEdit?: any } ).imageEdit;
		if ( ! imageEdit ) {
			const msg = __( 'Image editor not ready.', 'wp-banana' );
			openPanel();
			updateEditorNotice( attachmentId, 'error', msg );
			dispatchNotice( { type: 'info', message: msg } );
			return;
		}
		const historyJson = imageEdit.filterHistory( attachmentId, 0 );
		if ( ! historyJson || historyJson === '[]' ) {
			const msg = __( 'No edits to save.', 'wp-banana' );
			openPanel();
			updateEditorNotice( attachmentId, 'error', msg );
			dispatchNotice( { type: 'info', message: msg } );
			return;
		}

		button.disabled = true;
		button.classList.add( 'is-busy' );
		try {
			const response = ( await apiFetch( {
				path: `${ restNamespace }/edit/save-as`,
				method: 'POST',
				data: {
					attachment_id: attachmentId,
					history: historyJson,
				},
			} ) ) as SaveAsResponse;
			const filename = response?.filename ?? '';
			const message = filename ? sprintf( __( 'Copy saved: %s', 'wp-banana' ), filename ) : __( 'Copy saved.', 'wp-banana' );
			const linkId = response?.attachment_id ?? attachmentId;
			const libraryUrl = buildLibraryUrl( linkId );
			const linkLabel = __( 'Open image', 'wp-banana' );
			openPanel();
			updateEditorNotice( attachmentId, 'success', message, libraryUrl, { openInNewTab: false, label: linkLabel } );
			dispatchNotice( {
				type: 'success',
				message,
				url: libraryUrl,
				linkLabel,
				openInNewTab: false,
			} );
		} catch ( error ) {
			const apiError = error as ApiError;
			const message = apiError?.message ?? __( 'Failed to save copy.', 'wp-banana' );
			openPanel();
			updateEditorNotice( attachmentId, 'error', message );
			dispatchNotice( { type: 'info', message } );
		} finally {
			button.disabled = false;
			button.classList.remove( 'is-busy' );
			updateDisabledState();
		}
		} );
};

const EditPanel = ( {
	attachmentId,
	providers,
	restNamespace,
	defaultEditorModel,
	defaultEditorProvider,
}: EditPanelProps ) => {
	const [ prompt, setPrompt ] = useState( '' );
	const [ submitError, setSubmitError ] = useState< string | null >( null );
	const [ isSubmitting, setIsSubmitting ] = useState( false );
	const [ notice, setNotice ] = useState< NoticeState | null >( null );
	const [ showOptions, setShowOptions ] = useState( false );

	const {
		fileInputRef,
		referenceImages,
		referenceError,
		setReferenceError,
		referenceCount,
		removeReference,
		handleReferenceSelection,
		triggerReferenceDialog,
		resetReferenceImages,
		prepareReferenceFiles,
	} = useReferenceImages( {
		onReferencesChange: () => setSubmitError( null ),
	} );

	const multiReferenceMode = referenceCount > 0;

	const {
		provider,
		setProvider,
		model,
		setModel,
		modelOptions,
		models,
		modelsLoading,
		loadError,
		connectedProviders,
		selectedProviderConfig,
		summary,
		modelRequirementSatisfied,
	} = useGeneratorConfig( {
		providers,
		restNamespace,
		defaultGeneratorModel: defaultEditorModel,
		defaultGeneratorProvider: defaultEditorProvider,
		referenceCount,
		multiReferenceMode,
		purposeOverride: 'edit',
	} );

	useEffect( () => {
		const handler = ( event: Event ) => {
			const custom = event as CustomEvent< NoticeState >;
			if ( custom.detail ) {
				setNotice( custom.detail );
			}
		};
		document.addEventListener( NOTICE_EVENT, handler as EventListener );
		return () => {
			document.removeEventListener( NOTICE_EVENT, handler as EventListener );
		};
	}, [] );

	const multiReferenceSupported = useMemo(
		() =>
			supportsMultiImageModel( provider, {
				model,
				availableModels: models,
				fallbackModels: [ selectedProviderConfig?.default_model, defaultEditorModel ],
			} ),
		[ provider, model, models, selectedProviderConfig, defaultEditorModel ]
	);

	const canSubmit = useMemo( () => {
		if ( isSubmitting || modelsLoading ) {
			return false;
		}
		if ( prompt.trim().length < MIN_PROMPT_LENGTH ) {
			return false;
		}
		if ( provider === '' ) {
			return false;
		}
		if ( ! modelRequirementSatisfied ) {
			return false;
		}
		return true;
	}, [ isSubmitting, modelsLoading, prompt, provider, modelRequirementSatisfied ] );

	const handleReferenceButtonClick = useCallback( () => {
		if ( ! multiReferenceSupported ) {
			setReferenceError( __( 'Switch to a supported model to send multiple reference images.', 'wp-banana' ) );
			return;
		}
		triggerReferenceDialog();
	}, [ multiReferenceSupported, triggerReferenceDialog, setReferenceError ] );

	const handleEditReferenceSelection = useCallback(
		( event: ChangeEvent<HTMLInputElement> ) => {
			if ( ! multiReferenceSupported ) {
				event.target.value = '';
				return;
			}
			handleReferenceSelection( event );
		},
		[ multiReferenceSupported, handleReferenceSelection ]
	);

	const handleSubmit = useCallback( async () => {
		const trimmedPrompt = prompt.trim();
		if ( trimmedPrompt.length < MIN_PROMPT_LENGTH ) {
			setSubmitError( __( 'Please enter a longer prompt.', 'wp-banana' ) );
			return;
		}
		if ( multiReferenceMode && modelOptions.length === 0 ) {
			setSubmitError( __( 'Choose a model that supports multiple reference images.', 'wp-banana' ) );
			return;
		}
		setSubmitError( null );
		setReferenceError( null );
		setNotice( null );
		setIsSubmitting( true );
		setNotice( null );
		try {
			let response: unknown;
			const baseBufferKey = resolveBaseBufferKey( attachmentId );
			if ( referenceCount > 0 ) {
				let referenceFiles: File[] = [];
				try {
					referenceFiles = await prepareReferenceFiles();
				} catch ( prepareError ) {
					setReferenceError( __( 'Could not load the selected images.', 'wp-banana' ) );
					throw prepareError;
				}
				const formData = new window.FormData();
				formData.append( 'attachment_id', String( attachmentId ) );
				formData.append( 'prompt', trimmedPrompt );
				formData.append( 'provider', provider );
				if ( model ) {
					formData.append( 'model', model );
				}
				formData.append( 'save_mode', 'buffer' );
				if ( baseBufferKey ) {
					formData.append( 'base_buffer_key', baseBufferKey );
				}
				referenceFiles.forEach( ( file ) => {
					formData.append( 'reference_images[]', file, file.name );
				} );
				response = await apiFetch( {
					path: `${ restNamespace }/edit`,
					method: 'POST',
					body: formData,
				} );
			} else {
				const payload: Record<string, unknown> = {
					attachment_id: attachmentId,
					prompt: trimmedPrompt,
					provider,
					model,
					save_mode: 'buffer',
				};
				if ( baseBufferKey ) {
					payload.base_buffer_key = baseBufferKey;
				}
				response = await apiFetch( {
					path: `${ restNamespace }/edit`,
					method: 'POST',
					data: payload,
				} );
			}
			const maybeBuffer = response as Partial<BufferResponse>;
			if ( maybeBuffer && typeof maybeBuffer.buffer_key === 'string' ) {
				const effectiveBuffer: BufferResponse = {
					buffer_key: maybeBuffer.buffer_key,
					width: maybeBuffer.width ?? 0,
					height: maybeBuffer.height ?? 0,
					mime: maybeBuffer.mime ?? 'image/png',
					attachment_id: maybeBuffer.attachment_id ?? attachmentId,
					provider: maybeBuffer.provider ?? provider,
					model: maybeBuffer.model ?? model,
					prompt: maybeBuffer.prompt ?? trimmedPrompt,
				};
				try {
					pushBufferedStep( effectiveBuffer );
					setNotice( {
						type: 'success',
						message: __( 'AI edit applied. Use Save Edits or Save As to keep these changes.', 'wp-banana' ),
					} );
					setPrompt( '' );
					resetReferenceImages();
				} catch ( clientError ) {
					const msg = clientError instanceof Error ? clientError.message : __( 'Failed to apply edit in editor.', 'wp-banana' );
					setSubmitError( msg );
				}
			} else {
				const payload = response as EditSuccessPayload;
				if ( payload && payload.url ) {
					setNotice( {
						type: 'success',
						message: __( 'Edited image saved.', 'wp-banana' ),
						url: payload.url,
					} );
				} else {
					setNotice( {
						type: 'success',
						message: __( 'Edit completed.', 'wp-banana' ),
					} );
				}
				setPrompt( '' );
				resetReferenceImages();
			}
			setShowOptions( false );
		} catch ( error ) {
			const apiError = error as ApiError;
			setSubmitError( apiError?.message ?? __( 'Failed to edit image.', 'wp-banana' ) );
		} finally {
			setIsSubmitting( false );
		}
	}, [
		prompt,
		multiReferenceMode,
		modelOptions,
		setReferenceError,
		referenceCount,
		prepareReferenceFiles,
		provider,
		model,
		restNamespace,
		attachmentId,
		resetReferenceImages,
	] );

	const handlePromptKeyDown = useCallback(
		( event: KeyboardEvent<HTMLTextAreaElement> ) => {
			if ( event.key !== 'Enter' ) {
				return;
			}
			if ( ! event.metaKey && ! event.ctrlKey ) {
				return;
			}
			event.preventDefault();
			if ( ! canSubmit || isSubmitting ) {
				return;
			}
			handleSubmit();
		},
		[ canSubmit, isSubmitting, handleSubmit ]
	);

	return (
		<Card>
			<CardBody>
				{ loadError && (
					<Notice status="error" isDismissible={ false }>
						{ loadError }
					</Notice>
				) }
				{ submitError && (
					<Notice status="error" isDismissible onRemove={ () => setSubmitError( null ) }>
						{ submitError }
					</Notice>
				) }
				{ referenceError && (
					<Notice status="warning" isDismissible onRemove={ () => setReferenceError( null ) }>
						{ referenceError }
					</Notice>
				) }
				{ multiReferenceMode && ! modelsLoading && modelOptions.length === 0 && (
					<Notice status="warning" isDismissible={ false }>
						{ __( 'Switch to a supported model to send multiple reference images.', 'wp-banana' ) }
					</Notice>
				) }
				{ notice && (
					<Notice
						status={ notice.type === 'success' ? 'success' : 'info' }
						isDismissible
						onRemove={ () => setNotice( null ) }
					>
						{ notice.message }
						{ notice.url && (
							<>
								{' '}
								<a
									href={ notice.url }
									target={ notice.openInNewTab === false ? undefined : '_blank' }
									rel={ notice.openInNewTab === false ? undefined : 'noopener noreferrer' }
								>
									<em>{ notice.linkLabel ?? __( 'Open image in new tab', 'wp-banana' ) }</em>
								</a>
							</>
						) }
					</Notice>
				) }

				<PromptComposer
					prompt={ prompt }
					onPromptChange={ setPrompt }
					onPromptKeyDown={ handlePromptKeyDown }
					isSubmitting={ isSubmitting }
					canSubmit={ canSubmit }
					onSubmit={ handleSubmit }
					onReferenceClick={ handleReferenceButtonClick }
					showOptions={ showOptions }
					onToggleOptions={ () => setShowOptions( ( value ) => ! value ) }
					summary={ summary }
					enableReferenceDragDrop={ false }
					dropOverlayVisible={ false }
					promptLabel={ __( 'Describe the desired changes', 'wp-banana' ) }
					promptPlaceholder={ __( 'Add a glowing neon outline…', 'wp-banana' ) }
					submitLabel={ __( 'Apply AI Edit', 'wp-banana' ) }
					submitTooltip={ __( 'Apply AI edits and stage them in the editor buffer', 'wp-banana' ) }
					referenceTray={
						<ReferenceTray
							referenceImages={ referenceImages }
							onRemove={ removeReference }
							fileInputRef={ fileInputRef }
							onReferenceSelection={ handleEditReferenceSelection }
						/>
					}
				/>

				<OptionsDrawer
					show={ showOptions }
					connectedProviders={ connectedProviders }
					provider={ provider }
					onProviderChange={ ( value: string ) => setProvider( value ) }
					model={ model }
					onModelChange={ ( value: string ) => setModel( value ) }
					modelOptions={ modelOptions }
					modelsLoading={ modelsLoading }
					aspectRatioEnabled={ false }
					aspectRatio=""
					onAspectRatioChange={ ( _value: string ) => {} }
					aspectOptions={ [] }
					resolutionEnabled={ false }
					resolution=""
					onResolutionChange={ ( _value: string ) => {} }
					resolutionOptions={ [] }
					isSubmitting={ isSubmitting }
				/>
			</CardBody>
		</Card>
	);
};

type MountProps = {
	container: HTMLElement;
	attachmentId: number;
	providers: ProviderInfo[];
	restNamespace: string;
	defaultEditorModel?: string;
	defaultEditorProvider?: string;
};

type RootEntry = {
	render: ( element: JSX.Element ) => void;
};

const roots = new WeakMap< HTMLElement, RootEntry >();

const renderPanel = ( {
	container,
	attachmentId,
	providers,
	restNamespace,
	defaultEditorModel,
	defaultEditorProvider,
}: MountProps ) => {
	let root = roots.get( container );
	if ( ! root ) {
		const wpElement = ( ( window as unknown ) as { wp?: { element?: Record< string, unknown > } } ).wp?.element as any;
		const supportsCreateRoot = typeof wpElement?.createRoot === 'function';
		if ( supportsCreateRoot ) {
			const created = wpElement.createRoot( container );
			root = {
				render: ( element: JSX.Element ) => created.render( element ),
			};
		} else {
			root = {
				render: ( element: JSX.Element ) => render( element, container ),
			};
		}
		roots.set( container, root );
	}

	root.render(
		<EditPanel
			attachmentId={ attachmentId }
			providers={ providers }
			restNamespace={ restNamespace }
			defaultEditorModel={ defaultEditorModel }
			defaultEditorProvider={ defaultEditorProvider }
		/>
	);
};

type MediaData = {
	restNamespace: string;
	providers: ProviderInfo[];
	defaultEditorModel?: string;
	defaultEditorProvider?: string;
	defaultGeneratorModel?: string;
	defaultGeneratorProvider?: string;
	defaultAspectRatio?: string;
	aspectRatioOptions?: string[];
	iconUrl?: string;
};

type SetupContext = {
	data: MediaData;
};

const findAttachmentId = ( element: HTMLElement ): number | null => {
	const panel = element.closest< HTMLElement >( '[id^="imgedit-panel-"]' );
	if ( ! panel ) {
		return null;
	}
	const match = panel.id.match( /imgedit-panel-(\d+)/ );
	if ( ! match ) {
		return null;
	}
	return parseInt( match[1], 10 );
};

const enhanceEditorToolbar = ( tools: HTMLElement, context: SetupContext ) => {
	if ( tools.dataset.wpBananaEnhanced === '1' ) {
		return;
	}

	const toolbar = tools.querySelector< HTMLElement >( '.imgedit-menu' );
	if ( ! toolbar ) {
		return;
	}

	const attachmentId = findAttachmentId( tools );
	if ( ! attachmentId ) {
		return;
	}

	patchImageEdit();

	ensureEditToggleStyles();

	const toggle = document.createElement( 'button' );
	toggle.type = 'button';
	toggle.className = 'button wp-banana-edit-toggle';
	toggle.setAttribute( 'aria-expanded', 'false' );
	toggle.style.marginLeft = '8px';
	toggle.style.whiteSpace = 'nowrap';
	toggle.style.display = 'inline-flex';
	toggle.style.alignItems = 'center';
	toggle.style.gap = '6px';

	if ( context.data.iconUrl ) {
		const icon = document.createElement( 'span' );
		icon.setAttribute( 'aria-hidden', 'true' );
		icon.style.display = 'inline-block';
		icon.style.width = '16px';
		icon.style.height = '16px';
		icon.style.backgroundColor = 'currentColor';
		icon.style.maskImage = `url(${ context.data.iconUrl })`;
		icon.style.webkitMaskImage = `url(${ context.data.iconUrl })`;
		icon.style.maskRepeat = 'no-repeat';
		icon.style.webkitMaskRepeat = 'no-repeat';
		icon.style.maskPosition = 'center';
		icon.style.webkitMaskPosition = 'center';
		icon.style.maskSize = 'contain';
		icon.style.webkitMaskSize = 'contain';
		toggle.appendChild( icon );
	}

	toggle.appendChild( document.createTextNode( __( 'AI Edit', 'wp-banana' ) ) );

	toolbar.appendChild( toggle );

	const panel = document.createElement( 'div' );
	panel.className = 'wp-banana-edit-panel';
	panel.style.display = 'none';
	panel.style.marginTop = '12px';
	panel.style.maxWidth = '100%';
	panel.style.width = '100%';
	panel.style.flexBasis = '100%';
	panel.style.flexGrow = '0';
	panel.style.flexShrink = '0';
	panel.style.flex = '0 0 100%';
	panel.style.alignSelf = 'stretch';
	panel.style.clear = 'both';
	panel.style.boxSizing = 'border-box';

	const submitRow = tools.querySelector< HTMLElement >( '.imgedit-submit' );
	if ( submitRow ) {
		submitRow.insertAdjacentElement( 'afterend', panel );
	} else {
		tools.appendChild( panel );
	}

	let mounted = false;
	const ensureMounted = () => {
		if ( mounted ) {
			return;
		}
		mounted = true;
		renderPanel( {
			container: panel,
			attachmentId,
			providers: context.data.providers,
			restNamespace: context.data.restNamespace,
			defaultEditorModel: context.data.defaultEditorModel,
			defaultEditorProvider: context.data.defaultEditorProvider,
		} );
	};

	const showPanel = () => {
		ensureMounted();
		panel.style.display = 'block';
		toggle.setAttribute( 'aria-expanded', 'true' );
		toggle.classList.add( 'wp-banana-edit-toggle--open' );
		const textarea = panel.querySelector< HTMLTextAreaElement >( 'textarea' );
		if ( textarea ) {
			setTimeout( () => textarea.focus(), 50 );
		}
	};

	registerSaveAsButton( tools, attachmentId, context.data.restNamespace, showPanel );

	toggle.addEventListener( 'click', ( event ) => {
		event.preventDefault();
		const isOpen = panel.style.display === 'block';
		if ( isOpen ) {
			panel.style.display = 'none';
			toggle.setAttribute( 'aria-expanded', 'false' );
			toggle.classList.remove( 'wp-banana-edit-toggle--open' );
			return;
		}
		showPanel();
	} );

	tools.dataset.wpBananaEnhanced = '1';
};

const observeImageEditor = ( context: SetupContext ) => {
	const scan = () => {
	const nodes = document.querySelectorAll( '.imgedit-panel-content.imgedit-panel-tools' );
	nodes.forEach( ( el ) => {
		if ( el instanceof HTMLElement ) {
			enhanceEditorToolbar( el, context );
		}
	} );
	};

	scan();

	const observer = new MutationObserver( ( records ) => {
		records.forEach( ( record ) => {
			record.addedNodes.forEach( ( node ) => {
				if ( !( node instanceof HTMLElement ) ) {
					return;
				}
				if ( node.matches( '.imgedit-panel-content.imgedit-panel-tools' ) ) {
					enhanceEditorToolbar( node, context );
					return;
				}
				const nested = node.querySelectorAll( '.imgedit-panel-content.imgedit-panel-tools' );
				nested.forEach( ( el ) => {
					if ( el instanceof HTMLElement ) {
						enhanceEditorToolbar( el, context );
					}
				} );
			} );
		} );
	} );

	observer.observe( document.body, { childList: true, subtree: true } );
};

const init = () => {
	const data = ( window as unknown as { wpBananaMedia?: MediaData } ).wpBananaMedia;
	if ( ! data || ! Array.isArray( data.providers ) || data.providers.length === 0 ) {
		return;
	}
	const connected = data.providers.filter( ( provider ) => provider.connected !== false );
	if ( connected.length === 0 ) {
		return;
	}

	patchImageEdit();
	observeImageEditor( { data } );
};

if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', init );
} else {
	init();
}

export type { EditSuccessPayload };
export default EditPanel;
