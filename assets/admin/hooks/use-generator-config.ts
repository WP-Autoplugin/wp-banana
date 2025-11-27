/**
 * Hook that encapsulates generator provider/model/aspect logic.
 *
 * @package WPBanana
 */

import { useEffect, useMemo, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { __, _n, sprintf } from '@wordpress/i18n';

import type { ProviderInfo } from '../types/generate';
import { MULTI_IMAGE_MODEL_ALLOWLIST } from '../utils/ai-generate';

type ModelsResponse = {
	models?: string[];
};

export type GeneratorSummary = {
	aspect: string;
	model: string;
	provider: string;
	references: string;
	fallback: string;
};

type UseGeneratorConfigArgs = {
	providers: ProviderInfo[];
	restNamespace: string;
	defaultGeneratorModel?: string;
	defaultGeneratorProvider?: string;
	aspectRatioOptions?: string[];
	defaultAspectRatio?: string;
	resolutionOptions?: string[];
	defaultResolution?: string;
	referenceCount: number;
	multiReferenceMode: boolean;
	purposeOverride?: 'generate' | 'edit';
};

export const useGeneratorConfig = ( {
	providers,
	restNamespace,
	defaultGeneratorModel,
	defaultGeneratorProvider,
	aspectRatioOptions,
	defaultAspectRatio,
	resolutionOptions,
	defaultResolution,
	referenceCount,
	multiReferenceMode,
	purposeOverride,
}: UseGeneratorConfigArgs ) => {
	const connectedProviders = useMemo(
		() => providers.filter( ( provider ) => provider.connected !== false ),
		[ providers ]
	);

	const aspectOptions = useMemo( () => {
		if ( ! Array.isArray( aspectRatioOptions ) ) {
			return [] as string[];
		}
		return aspectRatioOptions
			.map( ( option ) => ( typeof option === 'string' ? option.trim().toUpperCase() : '' ) )
			.filter( ( option ): option is string => option.length > 0 );
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

	const resolutionOpts = useMemo( () => {
		if ( ! Array.isArray( resolutionOptions ) ) {
			return [] as string[];
		}
		return resolutionOptions
			.map( ( option ) => ( typeof option === 'string' ? option.trim().toUpperCase() : '' ) )
			.filter( ( option ): option is string => option.length > 0 );
	}, [ resolutionOptions ] );

	const preferredResolution = useMemo( () => {
		if ( typeof defaultResolution === 'string' ) {
			const canonical = defaultResolution.trim().toUpperCase();
			if ( canonical && resolutionOpts.includes( canonical ) ) {
				return canonical;
			}
		}
		return resolutionOpts[ 0 ] ?? '';
	}, [ resolutionOpts, defaultResolution ] );

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

		return connectedProviders[ 0 ]?.slug ?? providers[ 0 ]?.slug ?? '';
	}, [ connectedProviders, providers, defaultGeneratorProvider, defaultGeneratorModel ] );

	const [ provider, setProvider ] = useState( preferredProvider );
	const [ models, setModels ] = useState< string[] >( [] );
	const [ model, setModel ] = useState( '' );
	const [ modelsLoading, setModelsLoading ] = useState( false );
	const [ loadError, setLoadError ] = useState< string | null >( null );
	const [ aspectRatio, setAspectRatio ] = useState( preferredAspectRatio );
	const [ resolution, setResolution ] = useState( preferredResolution );

	const modelSupportsResolution = useMemo( () => {
		if ( ! model || ! provider ) {
			return false;
		}
		const modelLower = model.toLowerCase();
		if ( provider === 'gemini' && modelLower === 'gemini-3-pro-image-preview' ) {
			return true;
		}
		if ( provider === 'replicate' && modelLower === 'google/nano-banana-pro' ) {
			return true;
		}
		return false;
	}, [ model, provider ] );

	useEffect( () => {
		if ( provider && connectedProviders.some( ( item ) => item.slug === provider ) ) {
			return;
		}
		if ( preferredProvider ) {
			setProvider( preferredProvider );
			return;
		}
		setProvider( connectedProviders[ 0 ]?.slug ?? '' );
	}, [ preferredProvider, provider, connectedProviders ] );

	useEffect( () => {
		if ( referenceCount > 0 || aspectOptions.length === 0 ) {
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

	useEffect( () => {
		if ( referenceCount > 0 || resolutionOpts.length === 0 || ! modelSupportsResolution ) {
			if ( resolution !== '' ) {
				setResolution( '' );
			}
			return;
		}
		if ( resolutionOpts.includes( resolution ) ) {
			return;
		}
		if ( preferredResolution !== resolution ) {
			setResolution( preferredResolution );
		}
	}, [ resolutionOpts, resolution, preferredResolution, referenceCount, modelSupportsResolution ] );

	useEffect( () => {
		if ( ! provider ) {
			setModels( [] );
			return;
		}
		const purpose = purposeOverride ?? ( referenceCount > 0 ? 'edit' : 'generate' );
		let isMounted = true;
		setModelsLoading( true );
		setLoadError( null );
		apiFetch( { path: `${ restNamespace }/models?provider=${ provider }&purpose=${ purpose }` } )
			.then( ( response ) => {
				if ( ! isMounted ) {
					return;
				}
				const payload = response as ModelsResponse;
				const responseModels = Array.isArray( payload?.models ) ? payload.models : [];
				setModels( responseModels );

				if ( responseModels.length === 0 ) {
					setModel( '' );
					return;
				}

				if ( defaultGeneratorModel && responseModels.includes( defaultGeneratorModel ) ) {
					setModel( defaultGeneratorModel );
					return;
				}

				if ( ! responseModels.includes( model ) ) {
					setModel( responseModels[ 0 ] );
				}
			} )
			.catch( ( error ) => {
				if ( ! isMounted ) {
					return;
				}
				const message = ( error as { message?: string } )?.message ?? __( 'Failed to load models.', 'wp-banana' );
				setModels( [] );
				setLoadError( message );
				setModel( '' );
			} )
			.finally( () => {
				if ( isMounted ) {
					setModelsLoading( false );
				}
			} );
		return () => {
			isMounted = false;
		};
	}, [ provider, restNamespace, defaultGeneratorModel, referenceCount, purposeOverride ] );

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

	const aspectRatioEnabled = referenceCount === 0 && aspectOptions.length > 0;
	const resolutionEnabled = referenceCount === 0 && resolutionOpts.length > 0 && modelSupportsResolution;

	const summary: GeneratorSummary = useMemo( () => {
		const aspect = aspectRatioEnabled ? ( aspectRatio || __( 'Default aspect ratio', 'wp-banana' ) ) : '';
		const defaultModelName = selectedProviderConfig?.default_model ? String( selectedProviderConfig.default_model ) : '';
		let modelName =
			model ||
			( modelsLoading
				? __( 'Loading model…', 'wp-banana' )
				: defaultModelName !== ''
					? defaultModelName
					: __( 'Provider default model', 'wp-banana' ) );
		if ( multiReferenceMode && modelOptions.length === 0 ) {
			modelName = __( 'No compatible model', 'wp-banana' );
		}
		const referenceSummary =
			referenceCount > 0 ? sprintf( _n( '%d reference', '%d references', referenceCount, 'wp-banana' ), referenceCount ) : '';
		const hasContent = aspect || modelName || providerLabel || referenceSummary;
		return {
			aspect,
			model: modelName,
			provider: providerLabel,
			references: referenceSummary,
			fallback: hasContent ? '' : __( 'Using provider defaults', 'wp-banana' ),
		};
	}, [
		aspectRatioEnabled,
		aspectRatio,
		model,
		modelsLoading,
		selectedProviderConfig,
		multiReferenceMode,
		modelOptions,
		referenceCount,
		providerLabel,
	] );

	const modelRequirementSatisfied = useMemo( () => {
		if ( modelOptions.length > 0 ) {
			return model !== '' && modelOptions.includes( model );
		}
		if ( multiReferenceMode ) {
			return false;
		}
		return model !== '' || models.length === 0;
	}, [ modelOptions, model, multiReferenceMode, models ] );

	return {
		provider,
		setProvider,
		model,
		setModel,
		models,
		modelOptions,
		modelsLoading,
		loadError,
		aspectRatio,
		setAspectRatio,
		aspectRatioEnabled,
		aspectOptions,
		resolution,
		setResolution,
		resolutionEnabled,
		resolutionOptions: resolutionOpts,
		connectedProviders,
		selectedProviderConfig,
		summary,
		modelRequirementSatisfied,
		preferredAspectRatio,
		preferredResolution,
	};
};
