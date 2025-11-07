/**
 * Prompt composer with controls for references and variation menu.
 *
 * @package WPBanana
 */

import type { KeyboardEvent, ReactNode, RefObject } from 'react';
import { __ } from '@wordpress/i18n';
import { TextareaControl } from '@wordpress/components';

import type { GeneratorSummary } from '../hooks/use-generator-config';

type PromptComposerProps = {
	prompt: string;
	onPromptChange: ( value: string ) => void;
	onPromptKeyDown: ( event: KeyboardEvent<HTMLTextAreaElement> ) => void;
	isSubmitting: boolean;
	canSubmit: boolean;
	onSubmit: () => void;
	onReferenceClick: () => void;
	showOptions: boolean;
	onToggleOptions: () => void;
	summary: GeneratorSummary;
	variationMenuRef: RefObject<HTMLDivElement>;
	onVariationToggle: () => void;
	isVariationMenuOpen: boolean;
	variationMenu?: ReactNode;
	enableReferenceDragDrop?: boolean;
	dropOverlayVisible?: boolean;
};

const PromptComposer = ( {
	prompt,
	onPromptChange,
	onPromptKeyDown,
	isSubmitting,
	canSubmit,
	onSubmit,
	onReferenceClick,
	showOptions,
	onToggleOptions,
	summary,
	variationMenuRef,
	onVariationToggle,
	isVariationMenuOpen,
	variationMenu,
	enableReferenceDragDrop = false,
	dropOverlayVisible = false,
}: PromptComposerProps ) => {
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
			<div
				className="wp-banana-generate-panel__prompt"
				style={ { position: 'relative', marginBottom: '8px' } }
			>
				<TextareaControl
					label={ __( 'Describe the image', 'wp-banana' ) }
					value={ prompt }
					onChange={ onPromptChange }
					onKeyDown={ onPromptKeyDown }
					rows={ 5 }
					placeholder={ __( 'A cat surfing a wave at sunset…', 'wp-banana' ) }
					disabled={ isSubmitting }
				/>
				<button
					type="button"
					className="button button-secondary button-small wp-banana-generate-panel__reference-picker"
					onClick={ onReferenceClick }
					disabled={ isSubmitting }
					aria-label={ __( 'Add reference images', 'wp-banana' ) }
					style={ {
						position: 'absolute',
						top: '24px',
						right: '0',
						lineHeight: '20px',
						padding: '0 4px',
						border: 'none',
						borderRadius: 0,
						background: 'transparent',
					} }
				>
					<span className="dashicons dashicons-paperclip" aria-hidden="true" />
				</button>
			</div>

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
							onClick={ onSubmit }
							disabled={ ! canSubmit || isSubmitting }
							title={ __( 'Generate one image and save to Media Library', 'wp-banana' ) }
						>
							{ __( 'Generate Image', 'wp-banana' ) }
						</button>
						<button
							type="button"
							className="button button-primary"
							onClick={ onVariationToggle }
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
					{ isSubmitting && <span className="spinner is-active" aria-hidden="true" /> }
					{ variationMenu }
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
					<button type="button" className="button-link" onClick={ onToggleOptions }>
						{ showOptions ? __( 'Hide options', 'wp-banana' ) : __( 'Change', 'wp-banana' ) }
					</button>
				</div>
			</div>
		</>
	);
};

export default PromptComposer;
