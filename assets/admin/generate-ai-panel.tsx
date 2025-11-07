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
	Button,
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
	gemini: [ 'gemini-2.5-flash-image-preview', 'gemini-2.5-flash-image' ],
	openai: [ 'gpt-image-1', 'gpt-image-1-mini' ],
	replicate: [ 'google/nano-banana', 'bytedance/seedream-4', 'reve/remix' ],
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

type PreviewContext = {
	provider: string;
	model?: string;
	format: string;
	prompt: string;
	filenameBase: string;
	title: string;
};

type VariationPreviewStatus = 'queued' | 'loading' | 'complete' | 'error' | 'saving' | 'saved';

type VariationPreview = {
	id: string;
	index: number;
	status: VariationPreviewStatus;
	data?: string;
	mime?: string;
	width?: number;
	height?: number;
	bytes?: number;
	error?: string;
	context?: PreviewContext;
	attachmentId?: number;
	url?: string;
};

type PreviewAction = {
	message: string;
	type: 'success' | 'warning' | 'error';
	undo?: VariationPreview;
};

type PreviewResponsePayload = {
	preview?: {
		data?: string;
		mime?: string;
		width?: number;
		height?: number;
		bytes?: number;
	};
	context?: {
		provider?: string;
		model?: string;
		prompt?: string;
		format?: string;
		filename_base?: string;
		filenameBase?: string;
		title?: string;
		timestamp?: number;
	};
};

type SavePreviewResponse = {
	attachment_id?: number;
	url?: string;
};

const MIME_EXTENSION_MAP: Record<string, string> = {
	'image/jpeg': 'jpg',
	'image/jpg': 'jpg',
	'image/png': 'png',
	'image/webp': 'webp',
};

const VARIATION_MIN = 1;
const VARIATION_MAX = 4;
const VARIATION_OPTIONS: number[] = [ 1, 2, 3, 4 ];

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

const formatFromMime = ( mime?: string ): string => {
	if ( ! mime ) {
		return 'png';
	}
	const mapped = MIME_EXTENSION_MAP[ mime.toLowerCase() ];
	return mapped ?? 'png';
};

const previewStatusLabel = ( status: VariationPreviewStatus ): string => {
	switch ( status ) {
		case 'queued':
		case 'loading':
			return __( 'Generating…', 'wp-banana' );
		case 'complete':
			return __( 'Ready', 'wp-banana' );
		case 'saving':
			return __( 'Saving…', 'wp-banana' );
		case 'saved':
			return __( 'Saved', 'wp-banana' );
		case 'error':
			return __( 'Failed', 'wp-banana' );
		default:
			return '';
	}
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
	const [ isVariationMenuOpen, setIsVariationMenuOpen ] = useState( false );
	const [ previewItems, setPreviewItems ] = useState< VariationPreview[] >( [] );
	const [ activePreviewId, setActivePreviewId ] = useState< string | null >( null );
	const [ previewAction, setPreviewAction ] = useState< PreviewAction | null >( null );
	const variationMenuRef = useRef< HTMLDivElement | null >( null );
	const previewItemsRef = useRef< VariationPreview[] >( [] );
	const variationTimeoutsRef = useRef< number[] >( [] );
	const variationAbortRef = useRef( false );
	const variationStatsRef = useRef<{ successes: number; errors: string[] }>( { successes: 0, errors: [] } );
	const clearVariationTimeouts = useCallback( () => {
		if ( variationTimeoutsRef.current.length === 0 ) {
			return;
		}
		variationTimeoutsRef.current.forEach( ( token ) => window.clearTimeout( token ) );
		variationTimeoutsRef.current = [];
	}, [] );
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
	const selectedPreview = useMemo( () => {
		if ( previewItems.length === 0 ) {
			return null;
		}
		const found = previewItems.find( ( item ) => item.id === activePreviewId );
		return found ?? previewItems[ 0 ];
	}, [ previewItems, activePreviewId ] );
	const selectedPreviewSrc =
		selectedPreview && selectedPreview.data && selectedPreview.mime
			? `data:${ selectedPreview.mime };base64,${ selectedPreview.data }`
			: '';
	const canSaveSelected =
		!! selectedPreview &&
		( selectedPreview.status === 'complete' || selectedPreview.status === 'error' );
	const canDiscardSelected =
		!! selectedPreview && selectedPreview.status !== 'saving' && selectedPreview.status !== 'saved';

	useEffect( () => {
		referenceImagesRef.current = referenceImages;
	}, [ referenceImages ] );

	useEffect( () => {
		previewItemsRef.current = previewItems;
	}, [ previewItems ] );

	useEffect( () => {
		if ( previewItems.length === 0 ) {
			if ( activePreviewId !== null ) {
				setActivePreviewId( null );
			}
			return;
		}
		const activeExists = previewItems.some( ( item ) => item.id === activePreviewId );
		if ( ! activeExists ) {
			setActivePreviewId( previewItems[ 0 ].id );
		}
	}, [ previewItems, activePreviewId ] );

	useEffect( () => {
		if ( ! isVariationMenuOpen ) {
			return;
		}
		const handleClickOutside = ( event: MouseEvent ) => {
			if ( ! variationMenuRef.current ) {
				return;
			}
			if ( event.target instanceof Node && ! variationMenuRef.current.contains( event.target ) ) {
				setIsVariationMenuOpen( false );
			}
		};
		document.addEventListener( 'mousedown', handleClickOutside );
		return () => {
			document.removeEventListener( 'mousedown', handleClickOutside );
		};
	}, [ isVariationMenuOpen ] );

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

	const prepareReferenceFiles = useCallback( async (): Promise< File[] > => {
		const updated = Array.isArray( referenceImagesRef.current ) ? [ ...referenceImagesRef.current ] : [];
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
	}, [ setReferenceImages ] );

	const sendGenerateRequest = useCallback(
		async ( targetPrompt: string, previewOnly: boolean ) => {
			if ( referenceCount > 0 ) {
				let referenceFiles: File[] = [];
				try {
					referenceFiles = await prepareReferenceFiles();
				} catch ( prepareError ) {
					setReferenceError( __( 'Could not load the selected images.', 'wp-banana' ) );
					throw prepareError;
				}

				const formData = new window.FormData();
				formData.append( 'prompt', targetPrompt );
				formData.append( 'provider', provider );
				if ( model ) {
					formData.append( 'model', model );
				}
				if ( previewOnly ) {
					formData.append( 'preview_only', '1' );
				}
				referenceFiles.forEach( ( file ) => {
					formData.append( 'reference_images[]', file, file.name );
				} );
				return apiFetch( {
					path: `${ restNamespace }/generate`,
					method: 'POST',
					body: formData,
				} );
			}

			const payload: Record<string, string> = {
				prompt: targetPrompt,
				provider,
			};
			if ( model ) {
				payload.model = model;
			}
			if ( aspectRatioEnabled && aspectRatio ) {
				payload.aspect_ratio = aspectRatio;
			}
			if ( previewOnly ) {
				payload.preview_only = '1';
			}
			return apiFetch( {
				path: `${ restNamespace }/generate`,
				method: 'POST',
				data: payload,
			} );
		},
		[
			referenceCount,
			prepareReferenceFiles,
			setReferenceError,
			provider,
			model,
			restNamespace,
			aspectRatioEnabled,
			aspectRatio,
		]
	);

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

	useEffect( () => () => {
		variationAbortRef.current = true;
		clearVariationTimeouts();
	}, [ clearVariationTimeouts ] );


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
			await sendGenerateRequest( trimmedPrompt, false );
			setPrompt( '' );
			if ( aspectRatioEnabled ) {
				setAspectRatio( preferredAspectRatio );
			}
			setShowOptions( false );
			resetReferenceImages();
			onComplete();
		} catch ( error ) {
			const apiError = error as ApiError;
			if ( apiError?.message ) {
				setSubmitError( apiError.message );
			} else if ( error instanceof Error && error.message ) {
				setSubmitError( error.message );
			} else {
				setSubmitError( __( 'Failed to generate image.', 'wp-banana' ) );
			}
		} finally {
			setIsSubmitting( false );
		}
	};

	const resetPreviewArea = useCallback( () => {
		variationAbortRef.current = true;
		clearVariationTimeouts();
		setPreviewItems( [] );
		previewItemsRef.current = [];
		setActivePreviewId( null );
		setPreviewAction( null );
		variationStatsRef.current = { successes: 0, errors: [] };
		if ( isSubmitting ) {
			setIsSubmitting( false );
		}
	}, [ clearVariationTimeouts, isSubmitting ] );

	const finalizeModalIfDone = useCallback(
		( nextItems: VariationPreview[] ) => {
			if ( nextItems.length === 0 ) {
				resetPreviewArea();
			}
		},
		[ resetPreviewArea ]
	);

	const handleGenerateVariations = useCallback(
		( count: number ) => {
			const bounded = Math.max( VARIATION_MIN, Math.min( VARIATION_MAX, count ) );
			const trimmedPrompt = prompt.trim();
			if ( trimmedPrompt.length < MIN_PROMPT_LENGTH ) {
				setSubmitError( __( 'Please enter a longer prompt.', 'wp-banana' ) );
				return;
			}
			if ( multiReferenceMode && modelOptions.length === 0 ) {
				setSubmitError( __( 'Choose a model that supports multiple reference images.', 'wp-banana' ) );
				return;
			}

			variationAbortRef.current = false;
			clearVariationTimeouts();
			setSubmitError( null );
			setReferenceError( null );
			setPreviewAction( null );
			variationStatsRef.current = { successes: 0, errors: [] };

			const createdAt = Date.now();
			const seeds: VariationPreview[] = Array.from( { length: bounded } ).map( ( _value, index ) => ( {
				id: `${ createdAt }-${ index }-${ Math.random().toString( 36 ).slice( 2, 10 ) }`,
				index,
				status: 'queued' as VariationPreviewStatus,
			} ) );

			setPreviewItems( seeds );
			setActivePreviewId( seeds[ 0 ]?.id ?? null );
			setIsSubmitting( true );
			setIsVariationMenuOpen( false );

			const promptForRun = trimmedPrompt;

			const tasks = seeds.map(
				( seed, index ) =>
					new Promise<void>( ( resolve ) => {
						const execute = async () => {
							if ( variationAbortRef.current ) {
								resolve();
								return;
							}
							setPreviewItems( ( prev: VariationPreview[] ) =>
								prev.map( ( item: VariationPreview ): VariationPreview =>
									item.id === seed.id
										? {
												...item,
												status: 'loading',
											}
										: item
								)
							);
							try {
								const response = ( await sendGenerateRequest( promptForRun, true ) ) as PreviewResponsePayload;
								if ( variationAbortRef.current ) {
									resolve();
									return;
								}
								const previewPayload = response?.preview;
								const contextPayload = response?.context ?? {};
								if ( ! previewPayload?.data ) {
									throw new Error( __( 'Failed to generate image.', 'wp-banana' ) );
								}
								const context: PreviewContext = {
									provider: contextPayload.provider ?? provider,
									model: contextPayload.model,
									format: contextPayload.format ?? formatFromMime( previewPayload.mime ),
									prompt: contextPayload.prompt ?? promptForRun,
									filenameBase:
										contextPayload.filename_base ??
										contextPayload.filenameBase ??
										'',
									title: contextPayload.title ?? '',
								};

								setPreviewItems( ( prev: VariationPreview[] ) =>
									prev.map( ( item: VariationPreview ): VariationPreview =>
										item.id === seed.id
											? {
													...item,
													status: 'complete',
													data: previewPayload.data ?? '',
													mime: previewPayload.mime ?? 'image/png',
													width: previewPayload.width,
													height: previewPayload.height,
													bytes: previewPayload.bytes,
													context,
													error: undefined,
												}
											: item
									)
								);
								variationStatsRef.current.successes += 1;
								setActivePreviewId( ( current ) => current ?? seed.id );
							} catch ( runError ) {
								if ( variationAbortRef.current ) {
									resolve();
									return;
								}
								const apiError = runError as ApiError;
								const message = apiError?.message ?? __( 'Failed to generate image.', 'wp-banana' );
								variationStatsRef.current.errors.push( message );
								setPreviewItems( ( prev: VariationPreview[] ) =>
									prev.map( ( item: VariationPreview ): VariationPreview =>
										item.id === seed.id
											? {
													...item,
													status: 'error',
													error: message,
												}
											: item
									)
								);
								setPreviewAction( { message, type: 'error' } );
							} finally {
								resolve();
							}
						};

						const timeout = window.setTimeout( execute, index * 1000 );
						variationTimeoutsRef.current.push( timeout );
					} )
			);

			Promise.all( tasks ).then( () => {
				clearVariationTimeouts();
				if ( variationAbortRef.current ) {
					return;
				}
				if ( variationStatsRef.current.successes > 0 ) {
					setPrompt( '' );
					if ( aspectRatioEnabled ) {
						setAspectRatio( preferredAspectRatio );
					}
					resetReferenceImages();
					setShowOptions( false );
				} else {
					resetPreviewArea();
					if ( variationStatsRef.current.errors.length > 0 ) {
						setSubmitError( variationStatsRef.current.errors[ 0 ] );
					}
				}
				setIsSubmitting( false );
			} );
		},
		[
			prompt,
			setSubmitError,
			multiReferenceMode,
			modelOptions,
			clearVariationTimeouts,
			sendGenerateRequest,
			aspectRatioEnabled,
			preferredAspectRatio,
			resetReferenceImages,
			setShowOptions,
			setReferenceError,
			provider,
			resetPreviewArea,
		]
	);

	const handleVariationSelect = useCallback(
		( count: number ) => {
			setIsVariationMenuOpen( false );
			if ( isSubmitting ) {
				return;
			}
			handleGenerateVariations( count );
		},
		[ handleGenerateVariations, isSubmitting ]
	);

	const handlePreviewSave = useCallback(
		async ( itemId: string ) => {
			const target = previewItemsRef.current.find( ( item ) => item.id === itemId );
			if ( ! target ) {
				return;
			}
			if ( ( target.status !== 'complete' && target.status !== 'error' ) || ! target.data || ! target.context ) {
				return;
			}

			setPreviewAction( null );
			setPreviewItems( ( prev: VariationPreview[] ) =>
				prev.map( ( item ): VariationPreview =>
					item.id === itemId
						? {
								...item,
								status: 'saving',
								error: undefined,
							}
						: item
				)
			);

			try {
				const response = ( await apiFetch( {
					path: `${ restNamespace }/generate/save-preview`,
					method: 'POST',
					data: {
						data: target.data,
						provider: target.context.provider,
						model: target.context.model ?? '',
						format: target.context.format,
						mime: target.mime,
						prompt: target.context.prompt,
						filename_base: target.context.filenameBase,
						title: target.context.title,
					},
				} ) ) as SavePreviewResponse;

				setPreviewItems( ( prev: VariationPreview[] ) => {
					const next = prev.map( ( item ): VariationPreview =>
						item.id === itemId
							? {
									...item,
									status: 'saved',
									attachmentId: response?.attachment_id,
									url: response?.url,
									error: undefined,
								}
							: item
					);
					finalizeModalIfDone( next );
					return next;
				} );

				setPreviewAction( {
					message: __( 'Image saved to Media Library.', 'wp-banana' ),
					type: 'success',
				} );
			} catch ( saveError ) {
				const apiError = saveError as ApiError;
				const message = apiError?.message ?? __( 'Failed to save image.', 'wp-banana' );
				setPreviewItems( ( prev: VariationPreview[] ) =>
					prev.map( ( item ): VariationPreview =>
						item.id === itemId
							? {
									...item,
									status: 'error',
									error: message,
								}
							: item
					)
				);
				setPreviewAction( { message, type: 'error' } );
			}
		},
		[ restNamespace, finalizeModalIfDone ]
	);

	const handlePreviewDiscard = useCallback(
		( itemId: string ) => {
			const currentItems: VariationPreview[] = Array.isArray( previewItemsRef.current ) ? previewItemsRef.current : [];
			const removedItem = currentItems.find( ( item ) => item.id === itemId ) || null;
			const nextItems = currentItems.filter( ( item ) => item.id !== itemId );
			setPreviewItems( nextItems );
			previewItemsRef.current = nextItems;

			if ( nextItems.length === 0 ) {
				setActivePreviewId( null );
			} else if ( activePreviewId === itemId ) {
				setActivePreviewId( nextItems[ 0 ].id );
			}

			finalizeModalIfDone( nextItems );
			if ( removedItem ) {
				const restored: VariationPreview = {
					...removedItem,
					status: 'complete',
				};
				setPreviewAction( {
					message: __( 'Deleted.', 'wp-banana' ),
					type: 'warning',
					undo: restored,
				} );
			} else {
				setPreviewAction( {
					message: __( 'Deleted.', 'wp-banana' ),
					type: 'warning',
				} );
			}
		},
		[ activePreviewId, finalizeModalIfDone ]
	);

	const handlePreviewUndo = useCallback( () => {
		const undoItem = previewAction?.undo;
		if ( ! undoItem ) {
			return;
		}
		setPreviewItems( ( prev: VariationPreview[] ) => {
			const existing = prev.some( ( item: VariationPreview ) => item.id === undoItem.id );
			if ( existing ) {
				return prev;
			}
			const next: VariationPreview[] = [ undoItem, ...prev ];
			next.sort( ( a, b ) => a.index - b.index );
			return next;
		} );
		setActivePreviewId( undoItem.id );
		setPreviewAction( null );
	}, [ previewAction ] );

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
					<div
						ref={ variationMenuRef }
						style={ { display: 'flex', alignItems: 'center', gap: '12px', position: 'relative', zIndex: 1000 } }
					>
						<div className="button-group">
							<button
								type="button"
								className="button button-primary wp-banana-generate-panel__submit"
								onClick={ handleSubmit }
								disabled={ ! canSubmit || isSubmitting }
								title={ __( 'Generate one image and save to Media Library', 'wp-banana' ) }
							>
								{ __( 'Generate Image', 'wp-banana' ) }
							</button>
							<button
								type="button"
								className="button button-primary"
								onClick={ () => {
									if ( isSubmitting ) {
										return;
									}
									setIsVariationMenuOpen( ( open ) => ! open );
								} }
								disabled={ isSubmitting || ! canSubmit }
								aria-haspopup="menu"
								aria-expanded={ isVariationMenuOpen }
								aria-label={ __( 'Generate multiple variations', 'wp-banana' ) }
								style={ { padding: '0 4px' } }
								title={ __( 'Generate multiple variations and preview before saving', 'wp-banana' ) }
							>
								<span
									className="dashicons dashicons-arrow-down-alt2"
									aria-hidden="true"
									style={ { lineHeight: '30px' } }
								/>
							</button>
						</div>
						{ isSubmitting && (
							<span className="spinner is-active" aria-hidden="true" />
						) }
						{ isVariationMenuOpen && (
							<div
								className="wp-banana-generate-panel__variation-menu"
								role="menu"
								style={ {
									position: 'absolute',
									top: '100%',
									right: 0,
									marginTop: '4px',
									background: '#fff',
									border: '1px solid #dcdcde',
									borderRadius: '4px',
									boxShadow: '0 4px 12px rgba(0,0,0,0.15)',
									zIndex: 10,
									minWidth: '180px',
									padding: '4px 0',
								} }
							>
								{ VARIATION_OPTIONS.map( ( option ) => (
									<button
										key={ option }
										type="button"
										role="menuitem"
										className="button button-link"
										style={ {
											display: 'flex',
											width: '100%',
											justifyContent: 'space-between',
											alignItems: 'center',
											padding: '6px 12px',
											boxSizing: 'border-box',
											textDecoration: 'none',
										} }
										onClick={ () => handleVariationSelect( option ) }
									>
										<span>
											{ sprintf(
												_n( '%d image', '%d images', option, 'wp-banana' ),
												option
											) }
										</span>
										{ option === 1 && <span className="dashicons dashicons-format-image" aria-hidden="true" /> }
										{ option > 1 && <span className="dashicons dashicons-images-alt2" aria-hidden="true" /> }
									</button>
								) ) }
								<span
									style={ {
										display: 'block',
										padding: '4px 12px 2px',
										fontSize: '11px',
										fontWeight: '400',
										color: '#555d66',
										borderTop: '1px solid #e1e1e1',
									} }
								>
									{ __( 'Generate and Preview Images', 'wp-banana' ) }
								</span>
							</div>
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
				{ previewItems.length > 0 && (
					<div
						className="wp-banana-generate-panel__variations"
						style={ {
							marginTop: '24px',
							borderTop: '1px solid #dcdcde',
							paddingTop: '16px',
						} }
					>
						<div
							style={ {
								display: 'flex',
								alignItems: 'center',
								justifyContent: 'space-between',
								marginBottom: '12px',
								gap: '12px',
							} }
						>
							<h3 style={ { margin: 0 } }>{ __( 'Generated Variations', 'wp-banana' ) }</h3>
							<Button variant="secondary" onClick={ resetPreviewArea }>
								{ __( 'Clear previews', 'wp-banana' ) }
							</Button>
						</div>
						{ previewAction && previewAction.type !== 'success' && (
							<Notice
								status={ previewAction.type === 'warning' ? 'warning' : 'error' }
								isDismissible={ false }
								style={ { marginBottom: '16px' } }
							>
								<div
									className="wp-banana-generate-variations-notice"
									style={ { display: 'flex', alignItems: 'center', gap: '8px', flexWrap: 'wrap' } }
								>
									<span>{ previewAction.message }</span>
									{ previewAction.undo ? (
										<Button variant="link" onClick={ handlePreviewUndo }>
											{ __( 'Undo', 'wp-banana' ) }
										</Button>
									) : null }
								</div>
							</Notice>
						) }
						<div
							className="wp-banana-generate-variations-layout"
							style={ {
								display: 'flex',
								gap: '16px',
								flexWrap: 'wrap',
							} }
						>
							<div
								className="wp-banana-generate-variations-list"
								style={ {
									width: '200px',
									maxHeight: '360px',
									overflowY: 'auto',
									display: 'flex',
									flexDirection: 'column',
									gap: '8px',
								} }
							>
								{ previewItems.map( ( item ) => {
									const isActive = item.id === selectedPreview?.id;
									const thumbSrc = item.data && item.mime ? `data:${ item.mime };base64,${ item.data }` : '';
									return (
										<button
											key={ item.id }
											type="button"
											onClick={ () => setActivePreviewId( item.id ) }
											className="button button-link"
											style={ {
												display: 'flex',
												flexDirection: 'column',
												alignItems: 'stretch',
												gap: '6px',
												padding: '6px',
												border: isActive ? '2px solid #007cba' : '1px solid #dcdcde',
												borderRadius: '4px',
												background: isActive ? '#f0f6fc' : '#fff',
												textAlign: 'left',
											} }
										>
											<div
												style={ {
													position: 'relative',
													height: '64px',
													background: '#f6f7f7',
													display: 'flex',
													alignItems: 'center',
													justifyContent: 'center',
													overflow: 'hidden',
													borderRadius: '3px',
												} }
											>
												{ item.status === 'complete' || item.status === 'saved' || item.status === 'error' ? (
													thumbSrc ? (
														<img
															src={ thumbSrc }
															alt=""
															style={ { width: '100%', height: '100%', objectFit: 'cover' } }
														/>
													) : (
														<span>{ __( 'Preview unavailable', 'wp-banana' ) }</span>
													)
												) : (
													<Spinner />
												) }
												{ item.status === 'saved' && (
													<span
														style={ {
															position: 'absolute',
															top: '4px',
															right: '4px',
															background: '#007cba',
															color: '#fff',
															padding: '2px 6px',
															borderRadius: '3px',
															fontSize: '11px',
															fontWeight: 600,
														} }
													>
														{ __( 'Saved', 'wp-banana' ) }
													</span>
												) }
											</div>
											<div style={ { display: 'flex', flexDirection: 'column', gap: '2px' } }>
												<strong>{ sprintf( __( 'Variation %d', 'wp-banana' ), item.index + 1 ) }</strong>
												<span style={ { fontSize: '12px', color: '#555' } }>
													{ previewStatusLabel( item.status ) }
												</span>
											</div>
										</button>
									);
								} ) }
							</div>
							<div
								className="wp-banana-generate-variations-preview"
								style={ {
									flex: 1,
									minWidth: '260px',
									display: 'flex',
									flexDirection: 'column',
									gap: '16px',
								} }
							>
								<div
									style={ {
										position: 'relative',
										background: '#f6f7f7',
										border: '1px solid #dcdcde',
										borderRadius: '4px',
										minHeight: '300px',
										display: 'flex',
										alignItems: 'center',
										justifyContent: 'center',
										padding: '12px',
									} }
								>
									{ ! selectedPreview ? (
										<span>{ __( 'No preview selected.', 'wp-banana' ) }</span>
									) : selectedPreview.status === 'loading' || selectedPreview.status === 'queued' ? (
										<Spinner />
									) : selectedPreview.status === 'error' ? (
										<Notice status="error" isDismissible={ false }>
											{ selectedPreview.error ?? __( 'Failed to generate image.', 'wp-banana' ) }
										</Notice>
									) : (
										<>
											{ selectedPreviewSrc ? (
												<img
													src={ selectedPreviewSrc }
													alt=""
													style={ {
														maxWidth: '100%',
														maxHeight: '360px',
														borderRadius: '4px',
														objectFit: 'contain',
													} }
												/>
											) : (
												<span>{ __( 'Preview unavailable', 'wp-banana' ) }</span>
											) }
											{ selectedPreview.status === 'saving' && (
												<div
													style={ {
														position: 'absolute',
														inset: 0,
														background: 'rgba(255,255,255,0.7)',
														display: 'flex',
														alignItems: 'center',
														justifyContent: 'center',
														gap: '8px',
													} }
												>
													<Spinner /> { __( 'Saving…', 'wp-banana' ) }
												</div>
											) }
										</>
									) }
								</div>
								<div style={ { display: 'flex', gap: '8px' } }>
									<Button
										isPrimary
										onClick={ selectedPreview ? () => handlePreviewSave( selectedPreview.id ) : undefined }
										disabled={ ! canSaveSelected || selectedPreview?.status === 'saving' }
									>
										{ __( 'Save', 'wp-banana' ) }
									</Button>
									<Button
										variant="secondary"
										onClick={ selectedPreview ? () => handlePreviewDiscard( selectedPreview.id ) : undefined }
										disabled={ ! canDiscardSelected || selectedPreview?.status === 'saving' }
									>
										{ __( 'Discard', 'wp-banana' ) }
									</Button>
								</div>
							</div>
						</div>
					</div>
				) }
			</CardBody>
		</Card>
	</>
	);
};

export default GeneratePanel;
