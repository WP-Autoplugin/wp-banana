/**
 * Inject AI details into the media attachment modal.
 *
 * @package WPBanana
 */

import { __, _n, sprintf } from '@wordpress/i18n';
import type { ProviderInfo } from './types/generate';

type BananaUser = {
	id?: number;
	name?: string;
};

type BananaLink = {
	id: number;
	title?: string;
	editLink?: string | null;
	viewLink?: string | null;
};

type BananaHistoryEntry = {
	type?: string;
	provider?: string;
	model?: string;
	mode?: string;
	timestamp?: number;
	user?: BananaUser | null;
	derivedFrom?: BananaLink | null;
	prompt?: string;
};

type BananaMeta = {
	isGenerated?: boolean;
	lastAction?: string;
	lastProvider?: string;
	lastModel?: string;
	lastTimestamp?: number;
	lastUser?: BananaUser | null;
	lastPrompt?: string;
	lastMode?: string;
	derivedFrom?: BananaLink | null;
	history?: BananaHistoryEntry[];
	historyCount?: number;
	historyEnabled?: boolean;
};

type AttachmentView = {
	model?: {
		toJSON?: () => Record<string, unknown>;
	};
	el?: HTMLElement;
};

type BananaWindow = Window & {
	wp?: {
		media?: {
			view?: {
				Attachment?: {
					Details?: any;
				};
			};
		};
	};
	wpBananaMedia?: {
		providers?: ProviderInfo[];
	};
};

const actionLabels: Record<string, string> = {
	generate: __( 'Generated', 'wp-banana' ),
	edit: __( 'Edited', 'wp-banana' ),
};

const modeLabels: Record<string, string> = {
	new: __( 'New copy', 'wp-banana' ),
	replace: __( 'Replaced original', 'wp-banana' ),
	save_as: __( 'Saved as copy', 'wp-banana' ),
	buffer: __( 'Buffered edit', 'wp-banana' ),
};

const getProviders = (): ProviderInfo[] => {
	const win = window as BananaWindow;
	if ( win.wpBananaMedia && Array.isArray( win.wpBananaMedia.providers ) ) {
		return win.wpBananaMedia.providers;
	}
	return [];
};

const getProviderLabel = ( slug?: string ): string => {
	if ( ! slug ) {
		return '';
	}
	const providers = getProviders();
	const found = providers.find( ( provider ) => provider.slug === slug );
	return found?.label ?? slug;
};

const formatTimestamp = ( timestamp?: number ): string => {
	if ( ! timestamp || Number.isNaN( timestamp ) ) {
		return '';
	}
	try {
		const date = new Date( timestamp * 1000 );
		const formatter = new Intl.DateTimeFormat( undefined, {
			year: 'numeric',
			month: 'short',
			day: '2-digit',
			hour: 'numeric',
			minute: '2-digit',
		} );
		return formatter.format( date );
	} catch ( error ) {
		return '';
	}
};

const createDetailRow = ( className: string, label: string, content: Array<string | Node> ): HTMLDivElement => {
	const row = document.createElement( 'div' );
	row.className = className;
	const strong = document.createElement( 'strong' );
	strong.textContent = label;
	row.appendChild( strong );
	row.appendChild( document.createTextNode( ' ' ) );
	content.forEach( ( item ) => {
		if ( typeof item === 'string' ) {
			row.appendChild( document.createTextNode( item ) );
			return;
		}
		row.appendChild( item );
	} );
	return row;
};

const normalizeId = ( value: unknown ): number | null => {
	if ( typeof value === 'number' && Number.isFinite( value ) ) {
		return value;
	}
	if ( typeof value === 'string' ) {
		const parsed = parseInt( value, 10 );
		if ( ! Number.isNaN( parsed ) ) {
			return parsed;
		}
	}
	return null;
};

const buildHistoryList = ( history: BananaHistoryEntry[] ): HTMLDivElement => {
	const wrapper = document.createElement( 'div' );
	wrapper.className = 'wp-banana-history-list';
	const list = document.createElement( 'ul' );
	list.className = 'wp-banana-history-list__items';

	const entries = [ ...history ].reverse();
	entries.forEach( ( entry ) => {
		const item = document.createElement( 'li' );
		item.className = 'wp-banana-history-list__item';

		const summaryParts: string[] = [];
		const action = entry.type ? actionLabels[ entry.type ] ?? entry.type : '';
		if ( action ) {
			summaryParts.push( action );
		}
		const provider = entry.provider ? getProviderLabel( entry.provider ) : '';
		if ( provider ) {
			summaryParts.push( sprintf( __( 'via %s', 'wp-banana' ), provider ) );
		}
		if ( entry.model ) {
			summaryParts.push( entry.model );
		}
		if ( entry.mode ) {
			const modeLabel = modeLabels[ entry.mode ] ?? entry.mode;
			summaryParts.push( modeLabel );
		}

		if ( summaryParts.length > 0 ) {
			const summary = document.createElement( 'span' );
			summary.className = 'wp-banana-history-list__summary';
			summary.textContent = summaryParts.join( ', ' );
			summary.style.display = 'block';
			item.appendChild( summary );
		}

		const metaLineParts: string[] = [];
		const timestamp = formatTimestamp( entry.timestamp );
		if ( timestamp ) {
			metaLineParts.push( timestamp );
		}
		if ( entry.user?.name ) {
			metaLineParts.push( sprintf( __( 'by %s', 'wp-banana' ), entry.user.name ) );
		}
		if ( metaLineParts.length > 0 ) {
			const metaLine = document.createElement( 'span' );
			metaLine.className = 'wp-banana-history-list__meta';
			metaLine.textContent = metaLineParts.join( ' · ' );
			item.appendChild( metaLine );
		}

		if ( entry.derivedFrom && entry.derivedFrom.id ) {
			const derived = document.createElement( 'div' );
			derived.className = 'wp-banana-history-list__derived';
			const link = document.createElement( 'a' );
			link.href = entry.derivedFrom.editLink || entry.derivedFrom.viewLink || '#';
			link.textContent = entry.derivedFrom.title ?? `#${ entry.derivedFrom.id }`;
			link.target = '_blank';
			link.rel = 'noopener noreferrer';
			derived.appendChild( document.createTextNode( __( 'Source:', 'wp-banana' ) + ' ' ) );
			derived.appendChild( link );
			item.appendChild( derived );
		}

		if ( entry.prompt && entry.prompt.trim() !== '' ) {
			const prompt = document.createElement( 'div' );
			prompt.className = 'wp-banana-history-list__prompt';
			prompt.textContent = entry.prompt.trim();
			item.appendChild( prompt );
		}

		list.appendChild( item );
	} );

	if ( entries.length === 0 ) {
		const empty = document.createElement( 'li' );
		empty.className = 'wp-banana-history-list__item is-empty';
		empty.textContent = __( 'No AI history events recorded yet.', 'wp-banana' );
		list.appendChild( empty );
	}

	wrapper.appendChild( list );
	return wrapper;
};

const buildGeneratedSummary = ( meta: BananaMeta ): string => {
	const base = meta.isGenerated ? __( 'Yes', 'wp-banana' ) : __( 'No', 'wp-banana' );
	const parts: string[] = [];
	const action = meta.lastAction ? actionLabels[ meta.lastAction ] ?? meta.lastAction : '';
	if ( action ) {
		parts.push( action );
	}
	const provider = meta.lastProvider ? getProviderLabel( meta.lastProvider ) : '';
	if ( provider ) {
		parts.push( sprintf( __( 'via %s', 'wp-banana' ), provider ) );
	}
	if ( meta.lastModel ) {
		parts.push( meta.lastModel );
	}
	const mode = meta.lastMode ? modeLabels[ meta.lastMode ] ?? meta.lastMode : '';
	if ( mode ) {
		parts.push( mode );
	}
	const timestamp = formatTimestamp( meta.lastTimestamp );
	if ( timestamp ) {
		parts.push( timestamp );
	}
	if ( meta.lastUser?.name ) {
		parts.push( sprintf( __( 'by %s', 'wp-banana' ), meta.lastUser.name ) );
	}

	return parts.length > 0 ? `${ base } — ${ parts.join( ', ' ) }` : base;
};

const enhanceDetailsView = ( view: AttachmentView ) => {
	const element = view?.el;
	if ( ! element || !( element instanceof HTMLElement ) ) {
		return;
	}
	const details = element.querySelector<HTMLElement>( '.details' );
	if ( ! details ) {
		return;
	}

	const existing = details.querySelector<HTMLElement>( '.wp-banana-ai-details' );
	if ( existing ) {
		existing.remove();
	}

	const data = view?.model?.toJSON ? view.model.toJSON() : null;
	if ( ! data || typeof data !== 'object' ) {
		return;
	}
	const meta = ( data as { wpBanana?: BananaMeta } ).wpBanana;
	if ( ! meta ) {
		return;
	}

	const hasInfo = Boolean(
		meta.isGenerated ||
		meta.derivedFrom ||
		( meta.historyCount && meta.historyCount > 0 ) ||
		( meta.lastPrompt && meta.lastPrompt.trim() !== '' )
	);
	if ( ! hasInfo ) {
		return;
	}

	const wrapper = document.createElement( 'div' );
	wrapper.className = 'wp-banana-ai-details';

	const currentId = normalizeId( ( data as { id?: unknown } ).id );
	const derivedId = normalizeId( meta.derivedFrom?.id );
	const sameAsCurrent = currentId !== null && derivedId !== null && currentId === derivedId;

	if ( meta.derivedFrom && meta.derivedFrom.id && ! sameAsCurrent ) {
		const link = document.createElement( 'a' );
		link.href = meta.derivedFrom.editLink || meta.derivedFrom.viewLink || '#';
		link.textContent = meta.derivedFrom.title ?? `#${ meta.derivedFrom.id }`;
		link.target = '_blank';
		link.rel = 'noopener noreferrer';
		const derivedRow = createDetailRow(
			'wp-banana-ai-details__item',
			__( 'Derived from:', 'wp-banana' ),
			[ link ]
		);
		wrapper.appendChild( derivedRow );
	}

	if ( meta.historyCount && meta.historyCount > 0 && Array.isArray( meta.history ) ) {
		const toggle = document.createElement( 'button' );
		toggle.type = 'button';
		toggle.className = 'button-link wp-banana-history-toggle';
		const viewLabel = sprintf(
			_n( 'View history (%d entry)', 'View history (%d entries)', meta.historyCount, 'wp-banana' ),
			meta.historyCount
		);
		const hideLabel = __( 'Hide history', 'wp-banana' );
		toggle.textContent = viewLabel;
		toggle.setAttribute( 'aria-expanded', 'false' );

		const historyList = buildHistoryList( meta.history );
		historyList.style.display = 'none';

		toggle.addEventListener( 'click', ( event ) => {
			event.preventDefault();
			const expanded = toggle.getAttribute( 'aria-expanded' ) === 'true';
			const nextState = ! expanded;
			toggle.setAttribute( 'aria-expanded', nextState ? 'true' : 'false' );
			toggle.textContent = nextState ? hideLabel : viewLabel;
			historyList.style.display = nextState ? 'block' : 'none';
		} );

		const historyRow = createDetailRow(
			'wp-banana-ai-details__item wp-banana-ai-details__history',
			__( 'AI history:', 'wp-banana' ),
			[ toggle ]
		);
		historyRow.appendChild( historyList );
		wrapper.appendChild( historyRow );
	} else if ( meta.historyEnabled === false ) {
		const disabledRow = createDetailRow(
			'wp-banana-ai-details__item',
			__( 'AI history:', 'wp-banana' ),
			[ __( 'History tracking is disabled for this site.', 'wp-banana' ) ]
		);
		wrapper.appendChild( disabledRow );
	}

	details.insertBefore( wrapper, details.firstChild );
};

const patchAttachmentDetails = () => {
	const win = window as BananaWindow;
	const details = win.wp?.media?.view?.Attachment?.Details as any;
	if ( ! details ) {
		return;
	}
	if ( details.__wpBananaDetailsPatched ) {
		return;
	}

	const originalRender = details.prototype?.render;
	if ( typeof originalRender !== 'function' ) {
		return;
	}
	details.prototype.render = function renderPatched( ...args: unknown[] ) {
		const result = originalRender.apply( this, args );
		enhanceDetailsView( this as AttachmentView );
		return result;
	};

	if ( details.TwoColumn ) {
		const originalTwoColumnRender = details.TwoColumn.prototype?.render;
		if ( typeof originalTwoColumnRender === 'function' ) {
			details.TwoColumn.prototype.render = function renderTwoColumnPatched( ...args: unknown[] ) {
			const result = originalTwoColumnRender.apply( this, args );
			enhanceDetailsView( this as AttachmentView );
			return result;
			};
		}
	}

	details.__wpBananaDetailsPatched = true;
};

const initEnhancements = () => {
	patchAttachmentDetails();
};

if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', initEnhancements );
} else {
	initEnhancements();
}
