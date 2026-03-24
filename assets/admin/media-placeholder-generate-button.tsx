/**
 * Adds a Generate button to empty core/image placeholders.
 *
 * @package WPBanana
 */

import type { ComponentType, ReactElement } from 'react';
import { Button } from '@wordpress/components';
import { Fragment } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { openGenerateMediaModal } from './media-modal-generate-tab';

type MediaLibraryButtonProps = {
	open: () => void;
};

type MediaPlaceholderValue = {
	id?: number;
	src?: string;
};

type MediaPlaceholderProps = {
	allowedTypes?: string[];
	disableMediaButtons?: boolean;
	mediaLibraryButton?: ( props: MediaLibraryButtonProps ) => ReactElement;
	value?: MediaPlaceholderValue;
};

type WindowWithWordPress = Window & {
	wp?: {
		blockEditor?: {
			store?: unknown;
		};
		data?: {
			useSelect?: ( mapSelect: ( select: ( store?: unknown ) => any ) => unknown, deps?: unknown[] ) => unknown;
		};
		hooks?: {
			addFilter?: (
				hookName: string,
				namespace: string,
				callback: (
					component: ComponentType< MediaPlaceholderProps >
				) => ComponentType< MediaPlaceholderProps >
			) => void;
		};
	};
};

const NAMESPACE = 'wp-banana/media-placeholder-generate-button';
const wpWindow = window as WindowWithWordPress;

const isImagePlaceholder = ( allowedTypes?: string[] ): boolean => {
	if ( ! Array.isArray( allowedTypes ) || allowedTypes.length === 0 ) {
		return false;
	}

	return allowedTypes.every(
		( allowedType ) =>
			allowedType === 'image' || allowedType.startsWith( 'image/' )
	);
};

const shouldShowGenerateButton = (
	props: MediaPlaceholderProps,
	selectedBlockName: string | null
): boolean => {
	if ( selectedBlockName !== 'core/image' ) {
		return false;
	}

	if ( props.disableMediaButtons ) {
		return false;
	}

	if ( props.value?.id || props.value?.src ) {
		return false;
	}

	return isImagePlaceholder( props.allowedTypes );
};

wpWindow.wp?.hooks?.addFilter?.(
	'editor.MediaPlaceholder',
	NAMESPACE,
	(
		OriginalComponent: ComponentType< MediaPlaceholderProps >
	): ComponentType< MediaPlaceholderProps > => {
		return function MediaPlaceholderWithGenerateButton(
			props: MediaPlaceholderProps
		): ReactElement {
			const selectedBlockName = wpWindow.wp?.data?.useSelect?.( ( select ) => {
				const store = wpWindow.wp?.blockEditor?.store;
				const selectedClientId = select( store ).getSelectedBlockClientId?.();

				if ( ! selectedClientId ) {
					return null;
				}

				return select( store ).getBlockName?.( selectedClientId ) ?? null;
			}, [] ) as string | null;

			if ( ! shouldShowGenerateButton( props, selectedBlockName ) ) {
				return <OriginalComponent { ...props } />;
			}

			const originalMediaLibraryButton = props.mediaLibraryButton;
			const mediaLibraryButton = (
				buttonProps: MediaLibraryButtonProps
			): ReactElement => {
				const mediaLibraryButtonElement = originalMediaLibraryButton ? (
					originalMediaLibraryButton( buttonProps )
				) : (
					<Button
						__next40pxDefaultSize
						variant="secondary"
						onClick={ buttonProps.open }
					>
						{ __( 'Media Library', 'wp-banana' ) }
					</Button>
				);

				return (
					<Fragment>
						{ mediaLibraryButtonElement }
						<Button
							__next40pxDefaultSize
							variant="secondary"
							onClick={ () => openGenerateMediaModal( buttonProps.open ) }
						>
							{ __( 'Generate', 'wp-banana' ) }
						</Button>
					</Fragment>
				);
			};

			return (
				<OriginalComponent
					{ ...props }
					mediaLibraryButton={ mediaLibraryButton }
				/>
			);
		};
	}
);
