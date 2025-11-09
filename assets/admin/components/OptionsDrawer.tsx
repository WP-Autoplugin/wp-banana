/**
 * Collapsible drawer for provider/model/aspect selections.
 *
 * @package WPBanana
 */

import { __ } from '@wordpress/i18n';
import { SelectControl, Spinner } from '@wordpress/components';
import type { ReactNode } from 'react';

import type { ProviderInfo } from '../types/generate';

type OptionsDrawerProps = {
	show: boolean;
	connectedProviders: ProviderInfo[];
	provider: string;
	onProviderChange: ( value: string ) => void;
	model: string;
	onModelChange: ( value: string ) => void;
	modelOptions: string[];
	modelsLoading: boolean;
	aspectRatioEnabled: boolean;
	aspectRatio: string;
	onAspectRatioChange: ( value: string ) => void;
	aspectOptions: string[];
	isSubmitting: boolean;
	children?: ReactNode;
};

const OptionsDrawer = ( {
	show,
	connectedProviders,
	provider,
	onProviderChange,
	model,
	onModelChange,
	modelOptions,
	modelsLoading,
	aspectRatioEnabled,
	aspectRatio,
	onAspectRatioChange,
	aspectOptions,
	isSubmitting,
	children,
}: OptionsDrawerProps ) => {
	if ( ! show ) {
		return (
			<>
				{ children }
				{ modelsLoading && (
					<div
						className="wp-banana-generate-panel__spinner"
						style={ { display: 'flex', alignItems: 'center', gap: '8px' } }
					>
						<Spinner /> { __( 'Loading models…', 'wp-banana' ) }
					</div>
				) }
			</>
		);
	}

	return (
		<div className="wp-banana-generate-panel__options" style={ { marginTop: '16px' } }>
			{ children }
			{ connectedProviders.length > 1 && (
				<SelectControl
					label={ __( 'Provider', 'wp-banana' ) }
					value={ provider }
					onChange={ onProviderChange }
					options={ connectedProviders.map( ( item ) => ( {
						label: item.label,
						value: item.slug,
					} ) ) }
					disabled={ isSubmitting }
				/>
			) }

			<SelectControl
				label={ __( 'Model', 'wp-banana' ) }
				value={ model }
				onChange={ onModelChange }
				disabled={ modelsLoading || modelOptions.length === 0 || isSubmitting }
				options={
					modelOptions.length > 0
						? modelOptions.map( ( value ) => ( { label: value, value } ) )
						: [ { label: __( 'No models available', 'wp-banana' ), value: '' } ]
				}
			/>

			{ aspectRatioEnabled && (
				<SelectControl
					label={ __( 'Aspect ratio', 'wp-banana' ) }
					value={ aspectRatio }
					onChange={ onAspectRatioChange }
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
		</div>
	);
};

export default OptionsDrawer;
