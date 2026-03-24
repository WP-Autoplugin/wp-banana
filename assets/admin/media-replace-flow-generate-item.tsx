/**
 * Adds a Generate item to core/image replace menus.
 *
 * @package WPBanana
 */

import type { ComponentType, ReactElement, ReactNode } from 'react';
import { MenuItem } from '@wordpress/components';
import { Fragment } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { openGenerateMediaModal } from './media-modal-generate-tab';

type MediaUploadRenderProps = {
	open: () => void;
};

type MediaReplaceFlowChildrenArgs = {
	onClose: () => void;
};

type MediaReplaceFlowProps = {
	addToGallery?: boolean;
	allowedTypes?: string[];
	children?:
		| ReactNode
		| ( ( props: MediaReplaceFlowChildrenArgs ) => ReactNode );
	mediaId?: number;
	mediaIds?: number[];
	multiple?: boolean;
	onSelect?: ( media: unknown ) => void;
};

type WindowWithWordPress = Window & {
	wp?: {
		blockEditor?: {
			MediaUpload?: ComponentType< {
				addToGallery?: boolean;
				allowedTypes?: string[];
				gallery?: boolean;
				multiple?: boolean;
				onSelect?: ( media: unknown ) => void;
				render: ( props: MediaUploadRenderProps ) => ReactElement;
				value?: number | number[];
			} >;
			store?: unknown;
		};
		data?: {
			useSelect?: (
				mapSelect: ( select: ( store?: unknown ) => any ) => unknown,
				deps?: unknown[]
			) => unknown;
		};
		hooks?: {
			addFilter?: (
				hookName: string,
				namespace: string,
				callback: (
					component: ComponentType< MediaReplaceFlowProps >
				) => ComponentType< MediaReplaceFlowProps >
			) => void;
		};
	};
};

const NAMESPACE = 'wp-banana/media-replace-flow-generate-item';
const wpWindow = window as WindowWithWordPress;

const onlyAllowsImages = ( allowedTypes?: string[] ): boolean => {
	if ( ! Array.isArray( allowedTypes ) || allowedTypes.length === 0 ) {
		return false;
	}

	return allowedTypes.every(
		( allowedType ) =>
			allowedType === 'image' || allowedType.startsWith( 'image/' )
	);
};

const renderExistingChildren = (
	children: MediaReplaceFlowProps['children'],
	props: MediaReplaceFlowChildrenArgs
): ReactNode => {
	if ( typeof children === 'function' ) {
		return children( props );
	}

	return children ?? null;
};

wpWindow.wp?.hooks?.addFilter?.(
	'editor.MediaReplaceFlow',
	NAMESPACE,
	(
		OriginalComponent: ComponentType< MediaReplaceFlowProps >
	): ComponentType< MediaReplaceFlowProps > => {
		return function MediaReplaceFlowWithGenerate(
			props: MediaReplaceFlowProps
		): ReactElement {
			const selectedBlockName = wpWindow.wp?.data?.useSelect?.( ( select ) => {
				const store = wpWindow.wp?.blockEditor?.store;
				const selectedClientId = select( store ).getSelectedBlockClientId?.();

				if ( ! selectedClientId ) {
					return null;
				}

				return select( store ).getBlockName?.( selectedClientId ) ?? null;
			}, [] ) as string | null;

			const MediaUpload = wpWindow.wp?.blockEditor?.MediaUpload;
			const showGenerateItem =
				selectedBlockName === 'core/image' &&
				typeof MediaUpload === 'function' &&
				onlyAllowsImages( props.allowedTypes );

			if ( ! showGenerateItem ) {
				return <OriginalComponent { ...props } />;
			}

			const children = ( childProps: MediaReplaceFlowChildrenArgs ) => {
				const gallery = !! props.multiple && onlyAllowsImages( props.allowedTypes );
				const value = props.multiple ? props.mediaIds : props.mediaId;

				return (
					<Fragment>
						{ renderExistingChildren( props.children, childProps ) }
						<MediaUpload
							addToGallery={ props.addToGallery }
							allowedTypes={ props.allowedTypes }
							gallery={ gallery }
							multiple={ props.multiple }
							onSelect={ props.onSelect }
							value={ value }
							render={ ( { open } ) => (
								<MenuItem
									onClick={ () => {
										openGenerateMediaModal( open );
									} }
								>
									{ __( 'Generate', 'wp-banana' ) }
								</MenuItem>
							) }
						/>
					</Fragment>
				);
			};

			return <OriginalComponent { ...props } children={ children } />;
		};
	}
);
