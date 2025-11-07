/**
 * Hook for managing reference images, drag/drop, and queued injections.
 *
 * @package WPBanana
 */

import { useCallback, useEffect, useMemo, useRef, useState } from '@wordpress/element';
import type { ChangeEvent } from 'react';
import { __ } from '@wordpress/i18n';

import type { ReferenceInjection, ReferenceItem } from '../types/generate';
import { normaliseFilename, REFERENCE_LIMIT } from '../utils/ai-generate';

type UseReferenceImagesArgs = {
	limit?: number;
	enableReferenceDragDrop?: boolean;
	onReferencesChange?: () => void;
};

type ReferenceInjectionEvent = CustomEvent< {
	references?: ReferenceInjection[];
} >;

export const useReferenceImages = ( {
	limit = REFERENCE_LIMIT,
	enableReferenceDragDrop = false,
	onReferencesChange,
}: UseReferenceImagesArgs ) => {
	const [ referenceImages, setReferenceImages ] = useState< ReferenceItem[] >( [] );
	const [ referenceError, setReferenceError ] = useState< string | null >( null );
	const [ isDraggingFiles, setIsDraggingFiles ] = useState( false );
	const referenceImagesRef = useRef< ReferenceItem[] >( [] );
	const fileInputRef = useRef< HTMLInputElement | null >( null );
	const dragDepthRef = useRef( 0 );

	const commitReferences = useCallback(
		( next: ReferenceItem[] ) => {
			referenceImagesRef.current = next;
			setReferenceImages( next );
			if ( onReferencesChange ) {
				onReferencesChange();
			}
		},
		[ onReferencesChange ]
	);

	useEffect( () => {
		referenceImagesRef.current = referenceImages;
	}, [ referenceImages ] );

	useEffect(
		() => () => {
			referenceImagesRef.current.forEach( ( item ) => {
				if ( item.revokeOnCleanup && item.url ) {
					window.URL.revokeObjectURL( item.url );
				}
			} );
		},
		[]
	);

	const resetReferenceImages = useCallback( () => {
		referenceImagesRef.current.forEach( ( item ) => {
			if ( item.revokeOnCleanup && item.url ) {
				window.URL.revokeObjectURL( item.url );
			}
		} );
		commitReferences( [] );
		setReferenceError( null );
	}, [ commitReferences ] );

	const triggerReferenceDialog = useCallback( () => {
		if ( fileInputRef.current ) {
			fileInputRef.current.click();
		}
	}, [] );

	const addReferenceFiles = useCallback(
		( incoming: File[] ) => {
			if ( ! Array.isArray( incoming ) || incoming.length === 0 ) {
				return;
			}
			const current = Array.isArray( referenceImagesRef.current ) ? referenceImagesRef.current : [];
			let remainingSlots = Math.max( 0, limit - current.length );
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
				commitReferences( next );
				setReferenceError( validFiles > accepted ? __( 'You can upload up to 4 reference images.', 'wp-banana' ) : null );
			} else if ( validFiles > 0 ) {
				setReferenceError( __( 'You can upload up to 4 reference images.', 'wp-banana' ) );
			}
		},
		[ commitReferences, limit ]
	);

	const handleReferenceSelection = useCallback(
		( event: ChangeEvent<HTMLInputElement> ) => {
			const files = event.target.files;
			if ( files && files.length > 0 ) {
				addReferenceFiles( Array.from( files ) );
			}
			event.target.value = '';
		},
		[ addReferenceFiles ]
	);

	const removeReference = useCallback(
		( id: string ) => {
			const remaining = referenceImages.filter( ( item ) => {
				if ( item.id === id ) {
					if ( item.revokeOnCleanup && item.url ) {
						window.URL.revokeObjectURL( item.url );
					}
					return false;
				}
				return true;
			} );
			commitReferences( remaining );
			setReferenceError( null );
		},
		[ referenceImages, commitReferences ]
	);

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
			commitReferences( updated );
		}
		referenceImagesRef.current = updated;
		return prepared;
	}, [ commitReferences ] );

	const applyReferenceInjection = useCallback(
		( entries: ReferenceInjection[] ) => {
			resetReferenceImages();
			if ( ! Array.isArray( entries ) || entries.length === 0 ) {
				return;
			}
			const limited: ReferenceItem[] = [];
			entries.slice( 0, limit ).forEach( ( item ) => {
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
			commitReferences( limited );
			if ( entries.length > limit ) {
				setReferenceError( __( 'Only the first 4 selected images were attached as references.', 'wp-banana' ) );
			}
		},
		[ commitReferences, limit, resetReferenceImages ]
	);

	useEffect( () => {
		const dequeueReferences = ( entries: ReferenceInjection[] | undefined ) => {
			if ( ! entries ) {
				return;
			}
			const globalWindow = window as unknown as { wpBananaReferenceQueue?: ReferenceInjection[][] };
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
			const globalWindow = window as unknown as { wpBananaReferenceQueue?: ReferenceInjection[][] };
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
			resetDragState();
		};
	}, [ addReferenceFiles, enableReferenceDragDrop ] );

	const dropOverlayVisible = useMemo( () => enableReferenceDragDrop && isDraggingFiles, [ enableReferenceDragDrop, isDraggingFiles ] );

	return {
		fileInputRef,
		referenceImages,
		referenceError,
		setReferenceError,
		referenceCount: referenceImages.length,
		addReferenceFiles,
		removeReference,
		handleReferenceSelection,
		triggerReferenceDialog,
		resetReferenceImages,
		prepareReferenceFiles,
		dropOverlayVisible,
		isDraggingFiles,
	};
};
