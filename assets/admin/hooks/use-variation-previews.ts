/**
 * Hook that manages variation previews lifecycle.
 *
 * @package WPBanana
 */

import { useCallback, useEffect, useMemo, useRef, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';

import type {
	PreviewAction,
	PreviewContext,
	PreviewResponsePayload,
	SavePreviewResponse,
	VariationPreview,
	VariationPreviewStatus,
} from '../types/generate';
import { formatFromMime, MIN_PROMPT_LENGTH, VARIATION_MAX, VARIATION_MIN } from '../utils/ai-generate';

type GenerateVariationsOptions = {
	count: number;
	prompt: string;
	multiReferenceMode: boolean;
	modelOptions: string[];
	sendGenerateRequest: ( prompt: string, previewOnly: boolean ) => Promise< unknown >;
	onSuccessReset: () => void;
	setReferenceError: ( value: string | null ) => void;
	setSubmitError: ( value: string | null ) => void;
	setIsSubmitting: ( value: boolean ) => void;
};

type UseVariationPreviewsArgs = {
	restNamespace: string;
	dispatchMediaRefresh: () => void;
};

export const useVariationPreviews = ( { restNamespace, dispatchMediaRefresh }: UseVariationPreviewsArgs ) => {
	const [ previewItems, setPreviewItems ] = useState< VariationPreview[] >( [] );
	const [ activePreviewId, setActivePreviewId ] = useState< string | null >( null );
	const [ previewAction, setPreviewAction ] = useState< PreviewAction | null >( null );
	const previewItemsRef = useRef< VariationPreview[] >( [] );
	const variationTimeoutsRef = useRef< number[] >( [] );
	const variationAbortRef = useRef( false );
	const variationStatsRef = useRef<{ successes: number; errors: string[] }>( { successes: 0, errors: [] } );

	useEffect( () => {
		previewItemsRef.current = previewItems;
	}, [ previewItems ] );

	const clearVariationTimeouts = useCallback( () => {
		if ( variationTimeoutsRef.current.length === 0 ) {
			return;
		}
		variationTimeoutsRef.current.forEach( ( token ) => window.clearTimeout( token ) );
		variationTimeoutsRef.current = [];
	}, [] );

	useEffect(
		() => () => {
			variationAbortRef.current = true;
			clearVariationTimeouts();
		},
		[ clearVariationTimeouts ]
	);

	const resetPreviewArea = useCallback( () => {
		variationAbortRef.current = true;
		clearVariationTimeouts();
		setPreviewItems( [] );
		previewItemsRef.current = [];
		setActivePreviewId( null );
		setPreviewAction( null );
		variationStatsRef.current = { successes: 0, errors: [] };
	}, [ clearVariationTimeouts ] );

	const finalizeModalIfDone = useCallback(
		( nextItems: VariationPreview[] ) => {
			if ( nextItems.length === 0 ) {
				resetPreviewArea();
			}
		},
		[ resetPreviewArea ]
	);

	const startVariations = useCallback(
		( {
			count,
			prompt,
			multiReferenceMode,
			modelOptions,
			sendGenerateRequest,
			onSuccessReset,
			setReferenceError,
			setSubmitError,
			setIsSubmitting,
		}: GenerateVariationsOptions ) => {
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
			const seeds: VariationPreview[] = Array.from( { length: bounded } ).map(
				( _value, index ) =>
					( {
						id: `${ createdAt }-${ index }-${ Math.random().toString( 36 ).slice( 2, 10 ) }`,
						index,
						status: 'queued' as VariationPreviewStatus,
					} )
			);

			setPreviewItems( seeds );
			setActivePreviewId( seeds[ 0 ]?.id ?? null );
			setIsSubmitting( true );

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
								prev.map( ( item ) =>
									item.id === seed.id
										? {
												...item,
												status: 'loading' as VariationPreviewStatus,
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
									provider: contextPayload.provider ?? '',
									model: contextPayload.model,
									format: contextPayload.format ?? formatFromMime( previewPayload.mime ),
									prompt: contextPayload.prompt ?? promptForRun,
									filenameBase: contextPayload.filename_base ?? contextPayload.filenameBase ?? '',
									title: contextPayload.title ?? '',
								};

								setPreviewItems( ( prev: VariationPreview[] ) =>
									prev.map( ( item ) =>
										item.id === seed.id
											? {
													...item,
													status: 'complete' as VariationPreviewStatus,
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
								const apiError = runError as { message?: string };
								const message = apiError?.message ?? __( 'Failed to generate image.', 'wp-banana' );
								variationStatsRef.current.errors.push( message );
								setPreviewItems( ( prev: VariationPreview[] ) =>
									prev.map( ( item ) =>
										item.id === seed.id
											? {
													...item,
													status: 'error' as VariationPreviewStatus,
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
					onSuccessReset();
				} else {
					resetPreviewArea();
					if ( variationStatsRef.current.errors.length > 0 ) {
						setSubmitError( variationStatsRef.current.errors[ 0 ] );
					}
				}
				setIsSubmitting( false );
			} );
		},
		[ clearVariationTimeouts, resetPreviewArea ]
	);

	const savePreview = useCallback(
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
				prev.map( ( item ) =>
					item.id === itemId
						? {
								...item,
								status: 'saving' as VariationPreviewStatus,
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
					const next = prev.map( ( item ) =>
						item.id === itemId
							? {
									...item,
									status: 'saved' as VariationPreviewStatus,
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
				dispatchMediaRefresh();
			} catch ( saveError ) {
				const apiError = saveError as { message?: string };
				const message = apiError?.message ?? __( 'Failed to save image.', 'wp-banana' );
				setPreviewItems( ( prev: VariationPreview[] ) =>
					prev.map( ( item ) =>
						item.id === itemId
							? {
									...item,
									status: 'error' as VariationPreviewStatus,
									error: message,
								}
							: item
					)
				);
				setPreviewAction( { message, type: 'error' } );
			}
		},
		[ restNamespace, finalizeModalIfDone, dispatchMediaRefresh ]
	);

	const discardPreview = useCallback(
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
					status: 'complete' as VariationPreviewStatus,
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

	const undoPreview = useCallback( () => {
		const undoItem = previewAction?.undo;
		if ( ! undoItem ) {
			return;
		}
		setPreviewItems( ( prev: VariationPreview[] ) => {
			const existing = prev.some( ( item ) => item.id === undoItem.id );
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
		!! selectedPreview && ( selectedPreview.status === 'complete' || selectedPreview.status === 'error' );
	const canDiscardSelected =
		!! selectedPreview && selectedPreview.status !== 'saving' && selectedPreview.status !== 'saved';

	return {
		previewItems,
		selectedPreview,
		selectedPreviewSrc,
		activePreviewId,
		setActivePreviewId,
		previewAction,
		setPreviewAction,
		canSaveSelected,
		canDiscardSelected,
		resetPreviewArea,
		startVariations,
		savePreview,
		discardPreview,
		undoPreview,
	};
};
