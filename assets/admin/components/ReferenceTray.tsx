/**
 * Preview tray for reference images with removal controls.
 *
 * @package WPBanana
 */

import { __ } from '@wordpress/i18n';
import type { ChangeEvent, RefObject } from 'react';

import type { ReferenceItem } from '../types/generate';

type ReferenceTrayProps = {
	referenceImages: ReferenceItem[];
	onRemove: ( id: string ) => void;
	fileInputRef: RefObject<HTMLInputElement>;
	onReferenceSelection: ( event: ChangeEvent<HTMLInputElement> ) => void;
};

const ReferenceTray = ( { referenceImages, onRemove, fileInputRef, onReferenceSelection }: ReferenceTrayProps ) => {
	return (
		<>
			<input
				ref={ fileInputRef }
				type="file"
				accept="image/png,image/jpeg,image/webp"
				multiple
				onChange={ onReferenceSelection }
				style={ { display: 'none' } }
			/>
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
					{ referenceImages.map( ( item ) => (
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
								onClick={ () => onRemove( item.id ) }
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
		</>
	);
};

export default ReferenceTray;
