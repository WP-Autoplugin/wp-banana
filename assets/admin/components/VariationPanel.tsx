/**
 * Layout for variation previews, notices, and actions.
 *
 * @package WPBanana
 */

import { Notice, Spinner } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';

import type { PreviewAction, VariationPreview } from '../types/generate';
import { previewStatusLabel } from '../utils/ai-generate';

type VariationPanelProps = {
	previewItems: VariationPreview[];
	selectedPreview: VariationPreview | null;
	selectedPreviewSrc: string;
	onSelect: ( id: string ) => void;
	previewAction: PreviewAction | null;
	onUndo: () => void;
	onClear: () => void;
	canSaveSelected: boolean;
	canDiscardSelected: boolean;
	onSave: ( id: string ) => void;
	onDiscard: ( id: string ) => void;
};

const VariationPanel = ( {
	previewItems,
	selectedPreview,
	selectedPreviewSrc,
	onSelect,
	previewAction,
	onUndo,
	onClear,
	canSaveSelected,
	canDiscardSelected,
	onSave,
	onDiscard,
}: VariationPanelProps ) => {
	if ( previewItems.length === 0 ) {
		return null;
	}

	return (
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
				<button type="button" className="button" onClick={ onClear }>
					{ __( 'Clear previews', 'wp-banana' ) }
				</button>
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
							<button type="button" className="button-link" onClick={ onUndo }>
								{ __( 'Undo', 'wp-banana' ) }
							</button>
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
						maxHeight: '440px',
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
								onClick={ () => onSelect( item.id ) }
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
									textDecoration: 'none',
									boxShadow: 'none',
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
											maxHeight: '500px',
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
						<button
							type="button"
							className="button button-primary"
							onClick={ selectedPreview ? () => onSave( selectedPreview.id ) : undefined }
							disabled={ ! canSaveSelected || selectedPreview?.status === 'saving' }
						>
							{ __( 'Save', 'wp-banana' ) }
						</button>
						<button
							type="button"
							className="button"
							onClick={ selectedPreview ? () => onDiscard( selectedPreview.id ) : undefined }
							disabled={ ! canDiscardSelected || selectedPreview?.status === 'saving' }
						>
							{ __( 'Discard', 'wp-banana' ) }
						</button>
					</div>
				</div>
			</div>
		</div>
	);
};

export default VariationPanel;
