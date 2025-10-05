/**
 * Generate image panel rendered within the Media Library.
 *
 * @package WPBanana
 */

import { useState, useEffect, useMemo } from '@wordpress/element';
import { useRef, useCallback, type ChangeEvent, type KeyboardEvent } from 'react';
import { __, _n, sprintf } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import {
	Card,
	CardBody,
	Notice,
	SelectControl,
	Spinner,
	TextareaControl,
} from '@wordpress/components';

export interface ProviderInfo {
	slug: string;
	label: string;
	default_model: string;
	connected?: boolean;
}

interface GeneratePanelProps {
	providers: ProviderInfo[];
	restNamespace: string;
	onComplete: () => void;
	defaultGeneratorModel?: string;
	defaultGeneratorProvider?: string;
	aspectRatioOptions?: string[];
	defaultAspectRatio?: string;
	enableReferenceDragDrop?: boolean;
}

type ModelsResponse = {
	models?: string[];
};

type ApiError = {
	message?: string;
};

const MIN_PROMPT_LENGTH = 3;
const REFERENCE_LIMIT = 4;
const MULTI_IMAGE_MODEL_ALLOWLIST: Record<string, string[]> = {
	gemini: [ 'gemini-2.5-flash-image-preview' ],
	openai: [ 'gpt-image-1' ],
	replicate: [ 'google/nano-banana', 'bytedance/seedream-4' ],
};

type ReferenceItem = {
	id: string;
	file?: File;
	url: string;
	revokeOnCleanup?: boolean;
	sourceUrl?: string;
	filename?: string;
	mimeType?: string;
};

type ReferenceInjection = {
	attachmentId?: number;
	sourceUrl: string;
	previewUrl?: string;
	filename?: string;
	mime?: string;
};

type ReferenceInjectionEvent = CustomEvent< {
	references?: ReferenceInjection[];
} >;

declare global {
	interface Window {
		wpBananaReferenceQueue?: ReferenceInjection[][];
	}
}

const MIME_EXTENSION_MAP: Record<string, string> = {
	'image/jpeg': 'jpg',
	'image/jpg': 'jpg',
	'image/png': 'png',
	'image/webp': 'webp',
};

const normaliseFilename = ( candidate: string | undefined, fallbackIndex: number, mime: string ): string => {
	const fallback = `reference-${ fallbackIndex }`;
	const safeName = candidate && candidate.trim().length > 0 ? candidate.trim() : fallback;
	const extensionKey = MIME_EXTENSION_MAP[ mime.toLowerCase() ] ?? '';
	if ( extensionKey === '' ) {
		return safeName;
	}
	const lower = safeName.toLowerCase();
	if ( lower.endsWith( `.${ extensionKey }` ) ) {
		return safeName;
	}
	const lastDot = safeName.lastIndexOf( '.' );
	if ( lastDot > 0 ) {
		return `${ safeName.slice( 0, lastDot ) }.${ extensionKey }`;
	}
	return `${ safeName }.${ extensionKey }`;
};

const GeneratePanel = ( {
	providers,
	restNamespace,
	onComplete,
	defaultGeneratorModel,
	defaultGeneratorProvider,
	aspectRatioOptions,
	defaultAspectRatio,
	enableReferenceDragDrop = false,
}: GeneratePanelProps ) => {
	const connectedProviders = useMemo(
		() => providers.filter( ( provider ) => provider.connected !== false ),
		[ providers ]
	);

	const aspectOptions = useMemo( () => {
		if ( ! Array.isArray( aspectRatioOptions ) ) {
			return [] as string[];
		}
		return aspectRatioOptions
			.map( ( item ) => ( typeof item === 'string' ? item.trim().toUpperCase() : '' ) )
			.filter( ( item ): item is string => item.length > 0 );
	}, [ aspectRatioOptions ] );

	const preferredAspectRatio = useMemo( () => {
		if ( typeof defaultAspectRatio === 'string' ) {
			const canonical = defaultAspectRatio.trim().toUpperCase();
			if ( canonical && aspectOptions.includes( canonical ) ) {
				return canonical;
			}
		}
		return aspectOptions[ 0 ] ?? '';
	}, [ aspectOptions, defaultAspectRatio ] );

	const preferredProvider = useMemo( () => {
		if ( defaultGeneratorProvider ) {
			const match = connectedProviders.find( ( item ) => item.slug === defaultGeneratorProvider );
			if ( match ) {
				return match.slug;
			}
		}

		if ( defaultGeneratorModel ) {
			const byModel = connectedProviders.find( ( item ) => item.default_model === defaultGeneratorModel );
			if ( byModel ) {
				return byModel.slug;
			}
			const anyProvider = providers.find( ( item ) => item.default_model === defaultGeneratorModel );
			if ( anyProvider ) {
				const stillConnected = connectedProviders.find( ( item ) => item.slug === anyProvider.slug );
				if ( stillConnected ) {
					return stillConnected.slug;
				}
			}
		}

		return connectedProviders.length > 0 ? connectedProviders[ 0 ].slug : providers[ 0 ]?.slug ?? '';
	}, [ connectedProviders, providers, defaultGeneratorProvider, defaultGeneratorModel ] );

	const [ provider, setProvider ] = useState( preferredProvider );
	const [ prompt, setPrompt ] = useState( '' );
	const [ models, setModels ] = useState< string[] >( [] );
	const [ model, setModel ] = useState( '' );
	const [ modelsLoading, setModelsLoading ] = useState( false );
	const [ loadError, setLoadError ] = useState< string | null >( null );
	const [ submitError, setSubmitError ] = useState< string | null >( null );
	const [ isSubmitting, setIsSubmitting ] = useState( false );
	const [ aspectRatio, setAspectRatio ] = useState( preferredAspectRatio );
	const [ showOptions, setShowOptions ] = useState( false );
	const [ referenceImages, setReferenceImages ] = useState< ReferenceItem[] >( [] );
	const [ referenceError, setReferenceError ] = useState< string | null >( null );
	const [ isDraggingFiles, setIsDraggingFiles ] = useState( false );
	const fileInputRef = useRef< HTMLInputElement | null >( null );
	const referenceImagesRef = useRef< ReferenceItem[] >( referenceImages );
	const dragDepthRef = useRef( 0 );
	const referenceCount = referenceImages.length;
	const multiReferenceMode = referenceCount > 1;
	const modelOptions = useMemo( () => {
		if ( ! multiReferenceMode ) {
			return models;
		}
		const allowList = MULTI_IMAGE_MODEL_ALLOWLIST[ provider ] ?? [];
		if ( allowList.length === 0 ) {
			return [] as string[];
		}
		const allowSet = new Set( allowList );
		return models.filter( ( value ) => allowSet.has( value ) );
	}, [ models, multiReferenceMode, provider ] );
	const aspectRatioEnabled = referenceCount === 0 && aspectOptions.length > 0;

	useEffect( () => {
		referenceImagesRef.current = referenceImages;
	}, [ referenceImages ] );

	useEffect( () => () => {
		referenceImagesRef.current.forEach( ( item ) => {
			if ( item.revokeOnCleanup && item.url ) {
				window.URL.revokeObjectURL( item.url );
			}
		} );
	}, [] );

	const resetReferenceImages = useCallback( () => {
		referenceImagesRef.current.forEach( ( item ) => {
			if ( item.revokeOnCleanup && item.url ) {
				window.URL.revokeObjectURL( item.url );
			}
		} );
		setReferenceImages( [] );
		setReferenceError( null );
	}, [] );

	const applyReferenceInjection = useCallback( ( entries: ReferenceInjection[] ) => {
		setPrompt( '' );
		setSubmitError( null );
		resetReferenceImages();
		if ( ! Array.isArray( entries ) || entries.length === 0 ) {
			return;
		}
		const limited: ReferenceItem[] = [];
		entries.slice( 0, REFERENCE_LIMIT ).forEach( ( item ) => {
			if ( ! item.sourceUrl || item.sourceUrl.length === 0 ) {
				return;
			}
			const id = `${ Date.now() }-${ Math.random().toString( 36 ).slice( 2 ) }`;
			const sourceUrl = item.sourceUrl;
			const previewUrl = item.previewUrl && item.previewUrl.length > 0 ? item.previewUrl : sourceUrl;
			limited.push( {
				id,
				url: previewUrl,
				sourceUrl,
				filename: item.filename,
				mimeType: item.mime,
			} );
		} );
		setReferenceImages( limited );
		referenceImagesRef.current = limited;
		if ( entries.length > REFERENCE_LIMIT ) {
			setReferenceError( __( 'Only the first 4 selected images were attached as references.', 'wp-banana' ) );
		}
	}, [ resetReferenceImages ] );

	useEffect( () => {
		const dequeueReferences = ( entries: ReferenceInjection[] | undefined ) => {
			if ( ! entries ) {
				return;
			}
			const globalWindow = ( window as unknown ) as { wpBananaReferenceQueue?: ReferenceInjection[][] };
			const queue = globalWindow.wpBananaReferenceQueue;
			if ( ! Array.isArray( queue ) || queue.length === 0 ) {
				return;
			}
			const index = queue.indexOf( entries );
			if ( index >= 0 ) {
				queue.splice( index, 1 );
			}
			if ( queue.length === 0 ) {
				delete globalWindow.wpBananaReferenceQueue;
			}
		};

		const flushQueuedReferences = () => {
			const globalWindow = ( window as unknown ) as { wpBananaReferenceQueue?: ReferenceInjection[][] };
			const queue = globalWindow.wpBananaReferenceQueue;
			if ( ! Array.isArray( queue ) || queue.length === 0 ) {
				return;
			}
			while ( queue.length > 0 ) {
				const next = queue.shift();
				if ( Array.isArray( next ) && next.length > 0 ) {
					applyReferenceInjection( next );
				}
			}
			delete globalWindow.wpBananaReferenceQueue;
		};

		const handleReferenceInjection = ( event: Event ) => {
			const customEvent = event as ReferenceInjectionEvent;
			const entries = customEvent.detail?.references;
			if ( ! Array.isArray( entries ) || entries.length === 0 ) {
				dequeueReferences( entries );
				return;
			}
			dequeueReferences( entries );
			applyReferenceInjection( entries );
		};

		flushQueuedReferences();
		window.addEventListener( 'wp-banana:set-reference-images', handleReferenceInjection as EventListener );
		return () => {
			window.removeEventListener( 'wp-banana:set-reference-images', handleReferenceInjection as EventListener );
		};
	}, [ applyReferenceInjection ] );

	useEffect( () => {
		if ( referenceCount > 0 ) {
			if ( aspectRatio !== '' ) {
				setAspectRatio( '' );
			}
			return;
		}
		if ( aspectOptions.length === 0 ) {
			if ( aspectRatio !== '' ) {
				setAspectRatio( '' );
			}
			return;
		}
		if ( aspectOptions.includes( aspectRatio ) ) {
			return;
		}
		if ( preferredAspectRatio !== aspectRatio ) {
			setAspectRatio( preferredAspectRatio );
		}
	}, [ aspectOptions, aspectRatio, preferredAspectRatio, referenceCount ] );

	const selectedProviderConfig = useMemo(
		() => providers.find( ( item ) => item.slug === provider ),
		[ provider, providers ]
	);

	const providerLabel = useMemo( () => {
		if ( selectedProviderConfig?.label ) {
			return selectedProviderConfig.label;
		}
		const fallback = providers.find( ( item ) => item.slug === provider );
		return fallback?.label ?? ( provider ? provider.toUpperCase() : '' );
	}, [ provider, providers, selectedProviderConfig ] );

	const summary = useMemo( () => {
		const aspect = aspectRatioEnabled ? ( aspectRatio || __( 'Default aspect ratio', 'wp-banana' ) ) : '';
		const defaultModelName = selectedProviderConfig?.default_model ? String( selectedProviderConfig.default_model ) : '';
		let modelName = model || ( modelsLoading ? __( 'Loading model…', 'wp-banana' ) : ( defaultModelName !== '' ? defaultModelName : __( 'Provider default model', 'wp-banana' ) ) );
		if ( multiReferenceMode && modelOptions.length === 0 ) {
			modelName = __( 'No compatible model', 'wp-banana' );
		}
		const providerName = providerLabel;
		const referenceSummary = referenceCount > 0 ? sprintf( _n( '%d reference', '%d references', referenceCount, 'wp-banana' ), referenceCount ) : '';
		const hasContent = aspect || modelName || providerName || referenceSummary;
		return {
			aspect,
			model: modelName,
			provider: providerName,
			references: referenceSummary,
			fallback: hasContent ? '' : __( 'Using provider defaults', 'wp-banana' ),
		};
	}, [ aspectRatioEnabled, aspectRatio, model, modelsLoading, providerLabel, selectedProviderConfig, multiReferenceMode, modelOptions, referenceCount ] );

	useEffect( () => {
		if ( provider && connectedProviders.some( ( item ) => item.slug === provider ) ) {
			return;
		}
		if ( preferredProvider ) {
			setProvider( preferredProvider );
		}
	}, [ preferredProvider, provider, connectedProviders ] );

	useEffect( () => {
		if ( ! provider ) {
			return;
		}
		const purpose = referenceCount > 0 ? 'edit' : 'generate';
		let isMounted = true;
		setModelsLoading( true );
		setLoadError( null );
		apiFetch( { path: `${ restNamespace }/models?provider=${ provider }&purpose=${ purpose }` } )
			.then( ( response ) => {
				if ( ! isMounted ) {
					return;
				}
				const payload = response as ModelsResponse;
				const available = Array.isArray( payload.models ) ? payload.models : [];
				setModels( available );
				if ( available.length === 0 ) {
					setModel( '' );
					return;
				}
				const candidates = referenceCount > 0
					? [ selectedProviderConfig?.default_model ]
					: [ defaultGeneratorModel, selectedProviderConfig?.default_model ];
				const chosen = candidates.find( ( value ): value is string => !! value && available.includes( value ) );
				if ( chosen ) {
					setModel( chosen );
					return;
				}
				setModel( available[ 0 ] );
			} )
			.catch( ( error: ApiError ) => {
				if ( ! isMounted ) {
					return;
				}
				setModels( [] );
				setModel( '' );
				setLoadError( error?.message ?? __( 'Failed to load models.', 'wp-banana' ) );
			} )
			.finally( () => {
				if ( isMounted ) {
					setModelsLoading( false );
				}
			} );

		return () => {
			isMounted = false;
		};
	}, [ provider, restNamespace, selectedProviderConfig, defaultGeneratorModel, referenceCount ] );

	useEffect( () => {
		if ( modelsLoading ) {
			return;
		}
		if ( modelOptions.length === 0 ) {
			if ( multiReferenceMode && model !== '' ) {
				setModel( '' );
			}
			return;
		}
		if ( ! modelOptions.includes( model ) ) {
			setModel( modelOptions[ 0 ] );
		}
	}, [ modelOptions, model, modelsLoading, multiReferenceMode ] );

	const modelRequirementSatisfied = useMemo( () => {
		if ( modelOptions.length > 0 ) {
			return model !== '' && modelOptions.includes( model );
		}
		if ( multiReferenceMode ) {
			return false;
		}
		return model !== '' || models.length === 0;
	}, [ modelOptions, model, multiReferenceMode, models ] );

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
		if ( aspectRatioEnabled && aspectRatio === '' ) {
			return false;
		}
		return true;
	}, [ isSubmitting, modelsLoading, prompt, provider, modelRequirementSatisfied, aspectRatioEnabled, aspectRatio ] );

	const triggerReferenceDialog = useCallback( () => {
		if ( fileInputRef.current ) {
			fileInputRef.current.click();
		}
	}, [] );

	const addReferenceFiles = useCallback( ( incoming: File[] ) => {
		if ( ! Array.isArray( incoming ) || incoming.length === 0 ) {
			return;
		}
		const current = Array.isArray( referenceImagesRef.current ) ? referenceImagesRef.current : [];
		let remainingSlots = Math.max( 0, REFERENCE_LIMIT - current.length );
		let accepted = 0;
		let validFiles = 0;
		const additions: ReferenceItem[] = [];
		incoming.forEach( ( file ) => {
			if ( ! file?.type || ! file.type.startsWith( 'image/' ) ) {
				return;
			}
			validFiles += 1;
			if ( remainingSlots <= 0 ) {
				return;
			}
			const id = `${ Date.now() }-${ Math.random().toString( 36 ).slice( 2 ) }`;
			const objectUrl = window.URL.createObjectURL( file );
			additions.push( {
				id,
				file,
				url: objectUrl,
				revokeOnCleanup: true,
				filename: file.name,
				mimeType: file.type,
			} );
			remainingSlots -= 1;
			accepted += 1;
		} );
		if ( accepted > 0 ) {
			const next = [ ...current, ...additions ];
			referenceImagesRef.current = next;
			setReferenceImages( next );
			setSubmitError( null );
			setReferenceError( validFiles > accepted ? __( 'You can upload up to 4 reference images.', 'wp-banana' ) : null );
		} else if ( validFiles > 0 ) {
			setReferenceError( __( 'You can upload up to 4 reference images.', 'wp-banana' ) );
		}
	}, [ setReferenceImages, setSubmitError, setReferenceError ] );

	const handleReferenceSelection = useCallback( ( event: ChangeEvent<HTMLInputElement> ) => {
		const files = event.target.files;
		if ( files && files.length > 0 ) {
			addReferenceFiles( Array.from( files ) );
		}
		event.target.value = '';
	}, [ addReferenceFiles ] );

	const removeReference = useCallback( ( id: string ) => {
		const remaining = referenceImages.filter( ( item ) => {
			if ( item.id === id ) {
				if ( item.revokeOnCleanup && item.url ) {
					window.URL.revokeObjectURL( item.url );
				}
				return false;
			}
			return true;
		} );
		setReferenceImages( remaining );
		setReferenceError( null );
	}, [ referenceImages ] );

	useEffect( () => {
		if ( ! enableReferenceDragDrop ) {
			return;
		}

		const hasFiles = ( event: DragEvent ): boolean => {
			const types = event.dataTransfer?.types;
			if ( ! types ) {
				return false;
			}
			return Array.from( types as ArrayLike<string> ).includes( 'Files' );
		};

		const handleDragEnter = ( event: DragEvent ) => {
			if ( ! hasFiles( event ) ) {
				return;
			}
			event.preventDefault();
			event.stopPropagation();
			dragDepthRef.current += 1;
			setIsDraggingFiles( true );
		};

		const handleDragOver = ( event: DragEvent ) => {
			if ( ! hasFiles( event ) ) {
				return;
			}
			event.preventDefault();
			event.stopPropagation();
			if ( event.dataTransfer ) {
				event.dataTransfer.dropEffect = 'copy';
			}
			setIsDraggingFiles( true );
		};

		const resetDragState = () => {
			dragDepthRef.current = 0;
			setIsDraggingFiles( false );
		};

		const handleDragLeave = ( event: DragEvent ) => {
			if ( ! hasFiles( event ) ) {
				return;
			}
			event.preventDefault();
			event.stopPropagation();
			dragDepthRef.current = Math.max( 0, dragDepthRef.current - 1 );
			if ( dragDepthRef.current === 0 ) {
				setIsDraggingFiles( false );
			}
		};

		const handleDrop = ( event: DragEvent ) => {
			if ( ! hasFiles( event ) ) {
				return;
			}
			event.preventDefault();
			event.stopPropagation();
			const dataTransfer = event.dataTransfer;
			const droppedFiles = dataTransfer ? Array.from( dataTransfer.files || [] ) : [];
			if ( droppedFiles.length > 0 ) {
				addReferenceFiles( droppedFiles );
			}
			if ( dataTransfer ) {
				try {
					dataTransfer.clearData();
				} catch ( _error ) {
					// Some browsers throw when clearing data from non-user initiated drops.
				}
			}
			resetDragState();
		};

		const handleDragEnd = ( event: DragEvent ) => {
			if ( ! hasFiles( event ) ) {
				return;
			}
			resetDragState();
		};

		window.addEventListener( 'dragenter', handleDragEnter );
		window.addEventListener( 'dragover', handleDragOver );
		window.addEventListener( 'dragleave', handleDragLeave );
		window.addEventListener( 'drop', handleDrop );
		window.addEventListener( 'dragend', handleDragEnd );

		return () => {
			window.removeEventListener( 'dragenter', handleDragEnter );
			window.removeEventListener( 'dragover', handleDragOver );
			window.removeEventListener( 'dragleave', handleDragLeave );
			window.removeEventListener( 'drop', handleDrop );
			window.removeEventListener( 'dragend', handleDragEnd );
			dragDepthRef.current = 0;
			setIsDraggingFiles( false );
		};
	}, [ addReferenceFiles, enableReferenceDragDrop ] );


	const handleSubmit = async () => {
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
		setIsSubmitting( true );
		try {
			if ( referenceCount > 0 ) {
				const prepareReferenceFiles = async (): Promise< File[] > => {
					const updated = [ ...referenceImages ];
					const prepared: File[] = [];
					let mutated = false;
					for ( let index = 0; index < updated.length; index += 1 ) {
						const item = updated[ index ];
						if ( item.file instanceof File ) {
							prepared.push( item.file );
							continue;
						}
						if ( ! item.sourceUrl ) {
							throw new Error( __( 'Could not load the selected images.', 'wp-banana' ) );
						}
						let response: Response;
						try {
							response = await window.fetch( item.sourceUrl, { credentials: 'same-origin' } );
						} catch ( fetchError ) {
							throw new Error( __( 'Could not load the selected images.', 'wp-banana' ) );
						}
						if ( ! response.ok ) {
							throw new Error( __( 'Could not load the selected images.', 'wp-banana' ) );
						}
						const blob = await response.blob();
						const inferredMime = blob.type || item.mimeType || 'image/png';
						const filename = normaliseFilename( item.filename, index + 1, inferredMime );
						const file = new File( [ blob ], filename, { type: inferredMime } );
						updated[ index ] = {
							...item,
							file,
							mimeType: inferredMime,
						};
						prepared.push( file );
						mutated = true;
					}
					if ( mutated ) {
						setReferenceImages( updated );
					}
					referenceImagesRef.current = updated;
					return prepared;
				};

				let referenceFiles: File[] = [];
				try {
					referenceFiles = await prepareReferenceFiles();
				} catch ( prepareError ) {
					setReferenceError( __( 'Could not load the selected images.', 'wp-banana' ) );
					throw prepareError;
				}

				const formData = new window.FormData();
				formData.append( 'prompt', trimmedPrompt );
				formData.append( 'provider', provider );
				if ( model ) {
					formData.append( 'model', model );
				}
				referenceFiles.forEach( ( file ) => {
					formData.append( 'reference_images[]', file, file.name );
				} );
				await apiFetch( {
					path: `${ restNamespace }/generate`,
					method: 'POST',
					body: formData,
				} );
			} else {
				const payload: Record<string, string> = {
					prompt: trimmedPrompt,
					provider,
				};
				if ( model ) {
					payload.model = model;
				}
				if ( aspectRatioEnabled && aspectRatio ) {
					payload.aspect_ratio = aspectRatio;
				}
				await apiFetch( {
					path: `${ restNamespace }/generate`,
					method: 'POST',
					data: payload,
				} );
			}
			setPrompt( '' );
			if ( aspectRatioEnabled ) {
				setAspectRatio( preferredAspectRatio );
			}
			setShowOptions( false );
			resetReferenceImages();
			onComplete();
		} catch ( error ) {
			const apiError = error as ApiError;
			setSubmitError( apiError?.message ?? __( 'Failed to generate image.', 'wp-banana' ) );
		} finally {
			setIsSubmitting( false );
		}
	};

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

	const dropOverlayVisible = enableReferenceDragDrop && isDraggingFiles;

	return (
		<>
			{ enableReferenceDragDrop && (
				<div
					className="uploader-window wp-banana-generate-page__drop-overlay"
					style={ {
						display: dropOverlayVisible ? 'block' : 'none',
						opacity: dropOverlayVisible ? 1 : 0,
					} }
					aria-hidden="true"
				>
					<div className="uploader-window-content">
						<div className="uploader-editor-title">
							{ __( 'Drop files to attach', 'wp-banana' ) }
						</div>
					</div>
				</div>
			) }
			<Card>
			<CardBody>
				{ loadError && (
					<Notice status="error" isDismissible={ false }>
						{ loadError }
					</Notice>
				) }
				{ submitError && (
					<Notice status="error" isDismissible={ false }>
						{ submitError }
					</Notice>
				) }
				{ referenceError && (
					<Notice status="warning" isDismissible={ false }>
						{ referenceError }
					</Notice>
				) }
				{ multiReferenceMode && ! modelsLoading && modelOptions.length === 0 && (
					<Notice status="warning" isDismissible={ false }>
						{ __( 'Switch to a supported model to send multiple reference images.', 'wp-banana' ) }
					</Notice>
				) }

				<div
					className="wp-banana-generate-panel__prompt"
					style={ { position: 'relative', marginBottom: '8px' } }
				>
					<TextareaControl
						label={ __( 'Describe the image', 'wp-banana' ) }
						value={ prompt }
						onChange={ setPrompt }
						onKeyDown={ handlePromptKeyDown }
						rows={ 5 }
						placeholder={ __( 'A cat surfing a wave at sunset…', 'wp-banana' ) }
						disabled={ isSubmitting }
					/>
					<button
						type="button"
						className="button button-secondary button-small wp-banana-generate-panel__reference-picker"
						onClick={ triggerReferenceDialog }
						disabled={ isSubmitting }
						aria-label={ __( 'Add reference images', 'wp-banana' ) }
						style={ { position: 'absolute', top: '24px', right: '0', lineHeight: '20px', padding: '0 4px', border: 'none', borderRadius: 0, background: 'transparent' } }
					>
						<span className="dashicons dashicons-paperclip" aria-hidden="true" />
					</button>
					<input
						ref={ fileInputRef }
						type="file"
						accept="image/png,image/jpeg,image/webp"
						multiple
						onChange={ handleReferenceSelection }
						style={ { display: 'none' } }
					/>
				</div>

				{ referenceImages.length > 0 && (
					<div
						className="wp-banana-generate-panel__reference-list"
						style={ {
							display: 'flex',
							gap: '8px',
							marginBottom: '16px',
							flexWrap: 'wrap',
						} }
					>
						{ referenceImages.map( ( item: ReferenceItem ) => (
							<div
								key={ item.id }
								style={ {
									position: 'relative',
									width: '72px',
									height: '72px',
									overflow: 'hidden',
									borderRadius: '4px',
									border: '1px solid #dcdcde',
								} }
							>
								<img
									src={ item.url }
									alt=""
									style={ { width: '100%', height: '100%', objectFit: 'cover' } }
								/>
								<button
									type="button"
									className="button-link wp-banana-generate-panel__reference-remove"
									onClick={ () => removeReference( item.id ) }
									aria-label={ __( 'Remove reference image', 'wp-banana' ) }
									style={ {
										position: 'absolute',
										top: '2px',
										right: '2px',
										display: 'inline-flex',
										alignItems: 'center',
										justifyContent: 'center',
										width: '22px',
										height: '22px',
										background: 'rgba(0,0,0,0.55)',
										color: '#fff',
										borderRadius: '3px',
										textDecoration: 'none',
									} }
								>
									<span className="dashicons dashicons-no" />
								</button>
							</div>
						) ) }
					</div>
				) }

				{ showOptions && connectedProviders.length > 1 && (
					<SelectControl
						label={ __( 'Provider', 'wp-banana' ) }
						value={ provider }
						onChange={ ( value: string ) => setProvider( value ) }
						options={ connectedProviders.map( ( item ) => ( {
							label: item.label,
							value: item.slug,
						} ) ) }
						disabled={ isSubmitting }
					/>
				) }

				{ showOptions && (
					<SelectControl
						label={ __( 'Model', 'wp-banana' ) }
						value={ model }
						onChange={ ( value: string ) => setModel( value ) }
						disabled={ modelsLoading || modelOptions.length === 0 || isSubmitting }
						options={
							modelOptions.length > 0
								? modelOptions.map( ( value ) => ( { label: value, value } ) )
								: [ { label: __( 'No models available', 'wp-banana' ), value: '' } ]
						}
					/>
				) }

				{ showOptions && aspectRatioEnabled && (
					<SelectControl
						label={ __( 'Aspect ratio', 'wp-banana' ) }
						value={ aspectRatio }
						onChange={ ( value: string ) => setAspectRatio( value ) }
						disabled={ isSubmitting }
						options={ aspectOptions.map( ( value ) => ( { label: value, value } ) ) }
					/>
				) }
				{ modelsLoading && (
					<div
						className="wp-banana-generate-panel__spinner"
						style={ { display: 'flex', alignItems: 'center', gap: '8px' } }
					>
						<Spinner /> { __( 'Loading models…', 'wp-banana' ) }
					</div>
				) }

				<div
					className="wp-banana-generate-panel__actions"
					style={ {
						marginTop: '16px',
						display: 'flex',
						flexWrap: 'wrap',
						alignItems: 'center',
						gap: '12px',
						justifyContent: 'space-between',
					} }
				>
					<div style={ { display: 'flex', alignItems: 'center', gap: '12px' } }>
						<button
							type="button"
							className="button button-primary wp-banana-generate-panel__submit"
							onClick={ handleSubmit }
							disabled={ ! canSubmit || isSubmitting }
						>
							{ __( 'Generate Image', 'wp-banana' ) }
						</button>
						{ isSubmitting && (
							<span className="spinner is-active" aria-hidden="true" />
						) }
					</div>
					<div style={ { display: 'flex', alignItems: 'center', gap: '8px', flexWrap: 'wrap', textAlign: 'right' } }>
						{ summary.fallback ? (
							<span>{ summary.fallback }</span>
						) : (
							<span>
								{ summary.aspect && <span>{ summary.aspect } — </span> }
								<code>{ summary.model }</code>
								{ summary.provider && <span> ({ summary.provider })</span> }
								{ summary.references && <span> - { summary.references }</span> }
							</span>
						) }
						<button
							type="button"
							className="button-link"
							onClick={ () => setShowOptions( ! showOptions ) }
						>
							{ showOptions ? __( 'Hide options', 'wp-banana' ) : __( 'Change', 'wp-banana' ) }
						</button>
					</div>
				</div>
			</CardBody>
		</Card>
		</>
	);
};

export default GeneratePanel;
