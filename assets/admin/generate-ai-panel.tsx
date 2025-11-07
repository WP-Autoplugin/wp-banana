/**
 * Generate image panel rendered within the Media Library.
 *
 * @package WPBanana
 */

import { useState, useCallback, useMemo, useRef, useEffect } from '@wordpress/element';
import type { KeyboardEvent } from 'react';
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';
import { Card, CardBody, Notice } from '@wordpress/components';

import type { ProviderInfo } from './types/generate';
import PromptComposer from './components/PromptComposer';
import OptionsDrawer from './components/OptionsDrawer';
import ReferenceTray from './components/ReferenceTray';
import VariationMenu from './components/VariationMenu';
import VariationPanel from './components/VariationPanel';
import { useGeneratorConfig } from './hooks/use-generator-config';
import { useReferenceImages } from './hooks/use-reference-images';
import { useVariationPreviews } from './hooks/use-variation-previews';
import { MIN_PROMPT_LENGTH, VARIATION_OPTIONS } from './utils/ai-generate';

export interface GeneratePanelProps {
	providers: ProviderInfo[];
	restNamespace: string;
	onComplete: () => void;
	defaultGeneratorModel?: string;
	defaultGeneratorProvider?: string;
	aspectRatioOptions?: string[];
	defaultAspectRatio?: string;
	enableReferenceDragDrop?: boolean;
}

type ApiError = {
	message?: string;
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
	const [ prompt, setPrompt ] = useState( '' );
	const [ submitError, setSubmitError ] = useState< string | null >( null );
	const [ isSubmitting, setIsSubmitting ] = useState( false );
	const [ showOptions, setShowOptions ] = useState( false );
	const [ isVariationMenuOpen, setIsVariationMenuOpen ] = useState( false );

	const handleReferencesChange = useCallback( () => {
		setSubmitError( null );
	}, [] );

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
		dropOverlayVisible,
	} = useReferenceImages( {
		enableReferenceDragDrop,
		onReferencesChange: handleReferencesChange,
	} );

	const multiReferenceMode = referenceCount > 1;

	const {
		provider,
		setProvider,
		model,
		setModel,
		modelOptions,
		modelsLoading,
		loadError,
		aspectRatio,
		setAspectRatio,
		aspectRatioEnabled,
		aspectOptions,
		connectedProviders,
		summary,
		modelRequirementSatisfied,
		preferredAspectRatio,
	} = useGeneratorConfig( {
		providers,
		restNamespace,
		defaultGeneratorModel,
		defaultGeneratorProvider,
		aspectRatioOptions,
		defaultAspectRatio,
		referenceCount,
		multiReferenceMode,
	} );

	const dispatchMediaRefresh = useCallback( () => {
		document.dispatchEvent( new CustomEvent( 'wp-banana:media-refresh' ) );
	}, [] );

	const {
		previewItems,
		selectedPreview,
		selectedPreviewSrc,
		activePreviewId,
		setActivePreviewId,
		previewAction,
		canSaveSelected,
		canDiscardSelected,
		resetPreviewArea,
		startVariations,
		savePreview,
		discardPreview,
		undoPreview,
	} = useVariationPreviews( { restNamespace, dispatchMediaRefresh } );

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
		[ referenceCount, prepareReferenceFiles, setReferenceError, provider, model, restNamespace, aspectRatioEnabled, aspectRatio ]
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
		setIsSubmitting( true );
		try {
			await sendGenerateRequest( trimmedPrompt, false );
			setPrompt( '' );
			if ( aspectRatioEnabled ) {
				setAspectRatio( preferredAspectRatio );
			}
			setShowOptions( false );
			resetReferenceImages();
			dispatchMediaRefresh();
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
	}, [
		prompt,
		multiReferenceMode,
		modelOptions,
		setReferenceError,
		sendGenerateRequest,
		aspectRatioEnabled,
		preferredAspectRatio,
		resetReferenceImages,
		dispatchMediaRefresh,
		onComplete,
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

	const variationMenuRef = useRef<HTMLDivElement | null >( null );

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

	const handleVariationToggle = useCallback( () => {
		if ( isSubmitting || ! canSubmit ) {
			return;
		}
		setIsVariationMenuOpen( ( open ) => ! open );
	}, [ isSubmitting, canSubmit ] );

	const onVariationsSuccessReset = useCallback( () => {
		setPrompt( '' );
		if ( aspectRatioEnabled ) {
			setAspectRatio( preferredAspectRatio );
		}
		resetReferenceImages();
		setShowOptions( false );
	}, [ aspectRatioEnabled, preferredAspectRatio, resetReferenceImages ] );

	const handleVariationSelect = useCallback(
		( count: number ) => {
			setIsVariationMenuOpen( false );
			if ( isSubmitting ) {
				return;
			}
			startVariations( {
				count,
				prompt,
				multiReferenceMode,
				modelOptions,
				sendGenerateRequest,
				onSuccessReset: onVariationsSuccessReset,
				setReferenceError,
				setSubmitError,
				setIsSubmitting,
			} );
		},
		[
			isSubmitting,
			startVariations,
			prompt,
			multiReferenceMode,
			modelOptions,
			sendGenerateRequest,
			onVariationsSuccessReset,
			setReferenceError,
			setSubmitError,
		]
	);

	const variationMenu = (
		<VariationMenu isOpen={ isVariationMenuOpen } options={ VARIATION_OPTIONS } onSelect={ handleVariationSelect } />
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

				<PromptComposer
					prompt={ prompt }
					onPromptChange={ setPrompt }
					onPromptKeyDown={ handlePromptKeyDown }
					isSubmitting={ isSubmitting }
					canSubmit={ canSubmit }
					onSubmit={ handleSubmit }
					onReferenceClick={ triggerReferenceDialog }
					showOptions={ showOptions }
					onToggleOptions={ () => setShowOptions( ( value ) => ! value ) }
					summary={ summary }
					variationMenuRef={ variationMenuRef }
					onVariationToggle={ handleVariationToggle }
					isVariationMenuOpen={ isVariationMenuOpen }
					variationMenu={ variationMenu }
					enableReferenceDragDrop={ enableReferenceDragDrop }
					dropOverlayVisible={ dropOverlayVisible }
					referenceTray={
						<ReferenceTray
							referenceImages={ referenceImages }
							onRemove={ removeReference }
							fileInputRef={ fileInputRef }
							onReferenceSelection={ handleReferenceSelection }
						/>
					}
				/>

				<OptionsDrawer
					show={ showOptions }
					connectedProviders={ connectedProviders }
					provider={ provider }
					onProviderChange={ setProvider }
					model={ model }
					onModelChange={ setModel }
					modelOptions={ modelOptions }
					modelsLoading={ modelsLoading }
					aspectRatioEnabled={ aspectRatioEnabled }
					aspectRatio={ aspectRatio }
					onAspectRatioChange={ setAspectRatio }
					aspectOptions={ aspectOptions }
					isSubmitting={ isSubmitting }
				/>

				<VariationPanel
					previewItems={ previewItems }
					selectedPreview={ selectedPreview }
					selectedPreviewSrc={ selectedPreviewSrc }
					onSelect={ setActivePreviewId }
					previewAction={ previewAction }
					onUndo={ undoPreview }
					onClear={ resetPreviewArea }
					canSaveSelected={ canSaveSelected }
					canDiscardSelected={ canDiscardSelected }
					onSave={ savePreview }
					onDiscard={ discardPreview }
				/>
			</CardBody>
		</Card>
	);
};

export default GeneratePanel;
