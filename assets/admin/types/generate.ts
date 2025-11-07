/**
 * Shared types for AI generation UI.
 *
 * @package WPBanana
 */

export interface ProviderInfo {
	slug: string;
	label: string;
	default_model: string;
	connected?: boolean;
}

export type ReferenceItem = {
	id: string;
	file?: File;
	url: string;
	revokeOnCleanup?: boolean;
	sourceUrl?: string;
	filename?: string;
	mimeType?: string;
};

export type ReferenceInjection = {
	attachmentId?: number;
	sourceUrl: string;
	previewUrl?: string;
	filename?: string;
	mime?: string;
};

export type PreviewContext = {
	provider: string;
	model?: string;
	format: string;
	prompt: string;
	filenameBase: string;
	title: string;
};

export type VariationPreviewStatus = 'queued' | 'loading' | 'complete' | 'error' | 'saving' | 'saved';

export type VariationPreview = {
	id: string;
	index: number;
	status: VariationPreviewStatus;
	data?: string;
	mime?: string;
	width?: number;
	height?: number;
	bytes?: number;
	error?: string;
	context?: PreviewContext;
	attachmentId?: number;
	url?: string;
};

export type PreviewAction = {
	message: string;
	type: 'success' | 'warning' | 'error';
	undo?: VariationPreview;
};

export type PreviewResponsePayload = {
	preview?: {
		data?: string;
		mime?: string;
		width?: number;
		height?: number;
		bytes?: number;
	};
	context?: {
		provider?: string;
		model?: string;
		prompt?: string;
		format?: string;
		filename_base?: string;
		filenameBase?: string;
		title?: string;
		timestamp?: number;
	};
};

export type SavePreviewResponse = {
	attachment_id?: number;
	url?: string;
};
