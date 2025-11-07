/**
 * Utilities shared across AI generation UI.
 *
 * @package WPBanana
 */

import { __ } from '@wordpress/i18n';

export const MIN_PROMPT_LENGTH = 3;
export const REFERENCE_LIMIT = 4;

export const VARIATION_MIN = 1;
export const VARIATION_MAX = 4;
export const VARIATION_OPTIONS: number[] = [ 1, 2, 3, 4 ];

export const MULTI_IMAGE_MODEL_ALLOWLIST: Record<string, string[]> = {
	gemini: [ 'gemini-2.5-flash-image-preview', 'gemini-2.5-flash-image' ],
	openai: [ 'gpt-image-1', 'gpt-image-1-mini' ],
	replicate: [ 'google/nano-banana', 'bytedance/seedream-4', 'reve/remix' ],
};

export const MIME_EXTENSION_MAP: Record<string, string> = {
	'image/jpeg': 'jpg',
	'image/jpg': 'jpg',
	'image/png': 'png',
	'image/webp': 'webp',
};

export const normaliseFilename = ( candidate: string | undefined, fallbackIndex: number, mime: string ): string => {
	const fallback = `reference-${ fallbackIndex }`;
	const safeName = candidate && candidate.trim().length > 0 ? candidate.trim() : fallback;
	const extensionKey = MIME_EXTENSION_MAP[ mime.toLowerCase() ] ?? '';
	if ( extensionKey === '' ) {
		return safeName;
	}
	const lower = safeName.toLowerCase();
	if ( lower.endsWith( `.${ extensionKey }` ) ) {
		return safeName;
	}
	const lastDot = safeName.lastIndexOf( '.' );
	if ( lastDot > 0 ) {
		return `${ safeName.slice( 0, lastDot ) }.${ extensionKey }`;
	}
	return `${ safeName }.${ extensionKey }`;
};

export const formatFromMime = ( mime?: string ): string => {
	if ( ! mime ) {
		return 'png';
	}
	const mapped = MIME_EXTENSION_MAP[ mime.toLowerCase() ];
	return mapped ?? 'png';
};

export const previewStatusLabel = ( status: string ): string => {
	switch ( status ) {
		case 'queued':
		case 'loading':
			return __( 'Generating…', 'wp-banana' );
		case 'complete':
			return __( 'Ready', 'wp-banana' );
		case 'saving':
			return __( 'Saving…', 'wp-banana' );
		case 'saved':
			return __( 'Saved', 'wp-banana' );
		case 'error':
			return __( 'Failed', 'wp-banana' );
		default:
			return '';
	}
};

export const supportsMultiImageModel = (
	provider: string,
	{
		model,
		availableModels = [],
		fallbackModels = [],
	}: {
		model?: string;
		availableModels?: string[];
		fallbackModels?: Array<string | undefined>;
	} = {}
): boolean => {
	const allowList = MULTI_IMAGE_MODEL_ALLOWLIST[ provider ] ?? [];
	if ( allowList.length === 0 ) {
		return false;
	}
	const candidates: string[] = [];
	if ( model ) {
		candidates.push( model );
	}
	fallbackModels
		.filter( ( value ): value is string => typeof value === 'string' && value.length > 0 )
		.forEach( ( value ) => candidates.push( value ) );
	if ( candidates.length === 0 && availableModels.length > 0 ) {
		return availableModels.some( ( value ) => allowList.includes( value ) );
	}
	return candidates.some( ( value ) => allowList.includes( value ) );
};
