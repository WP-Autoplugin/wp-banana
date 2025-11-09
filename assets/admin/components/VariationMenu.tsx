/**
 * Popover menu listing available variation batch counts.
 *
 * @package WPBanana
 */

import { __, _n, sprintf } from '@wordpress/i18n';

type VariationMenuProps = {
	isOpen: boolean;
	options: number[];
	onSelect: ( value: number ) => void;
};

const VariationMenu = ( { isOpen, options, onSelect }: VariationMenuProps ) => {
	if ( ! isOpen ) {
		return null;
	}

	return (
		<div
			className="wp-banana-generate-panel__variation-menu"
			role="menu"
			style={ {
				position: 'absolute',
				top: '100%',
				right: 0,
				marginTop: '4px',
				background: '#fff',
				border: '1px solid #dcdcde',
				borderRadius: '4px',
				boxShadow: '0 4px 12px rgba(0,0,0,0.15)',
				zIndex: 10,
				minWidth: '180px',
				padding: '4px 0',
			} }
		>
			{ options.map( ( option ) => (
				<button
					key={ option }
					type="button"
					role="menuitem"
					className="button button-link"
					style={ {
						display: 'flex',
						width: '100%',
						justifyContent: 'space-between',
						alignItems: 'center',
						padding: '6px 12px',
						boxSizing: 'border-box',
						textDecoration: 'none',
					} }
					onClick={ () => onSelect( option ) }
				>
					<span>{ sprintf( _n( '%d image', '%d images', option, 'wp-banana' ), option ) }</span>
					{ option === 1 && <span className="dashicons dashicons-format-image" aria-hidden="true" /> }
					{ option > 1 && <span className="dashicons dashicons-images-alt2" aria-hidden="true" /> }
				</button>
			) ) }
			<span
				style={ {
					display: 'block',
					padding: '4px 12px 2px',
					fontSize: '11px',
					fontWeight: '400',
					color: '#555d66',
					borderTop: '1px solid #e1e1e1',
				} }
			>
				{ __( 'Generate and Preview Images', 'wp-banana' ) }
			</span>
		</div>
	);
};

export default VariationMenu;
