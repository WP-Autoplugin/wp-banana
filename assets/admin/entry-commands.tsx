/**
 * Entry bundle for WP Command Palette integration.
 *
 * @package WPBanana
 */

import { __ } from '@wordpress/i18n';
import { dispatch } from '@wordpress/data';
import { store as commandsStore } from '@wordpress/commands';
import { image, settings, plus } from '@wordpress/icons';

/**
 * Register WP Banana commands in the Command Palette.
 */
function registerBananaCommands(): void {
	const { registerCommand } = dispatch(commandsStore);

	// Command: Navigate to Generate Image page
	registerCommand({
		name: 'wp-banana/generate-image-page',
		label: __('Generate Image with AI', 'wp-banana'),
		icon: image,
		category: 'view',
		keywords: ['banana', 'ai', 'image', 'generate', 'create', 'media'],
		callback: ({ close }: { close: () => void }) => {
			close();
			window.location.href = 'upload.php?page=wp-banana-generate';
		},
	});

	// Command: Navigate to WP Banana Settings
	registerCommand({
		name: 'wp-banana/settings',
		label: __('WP Banana Settings', 'wp-banana'),
		icon: settings,
		category: 'view',
		keywords: ['banana', 'ai', 'image', 'settings', 'configuration', 'api'],
		callback: ({ close }: { close: () => void }) => {
			close();
			window.location.href = 'options-general.php?page=wp-banana';
		},
	});

	// Command: Open AI Image Generator (quick action)
	// This triggers an event that can be caught by the existing UI to open the generator modal
	registerCommand({
		name: 'wp-banana/open-generator',
		label: __('Open AI Image Generator', 'wp-banana'),
		icon: plus,
		category: 'command',
		keywords: ['banana', 'ai', 'image', 'generate', 'create', 'modal'],
		callback: ({ close }: { close: () => void }) => {
			close();
			// Dispatch a custom event that the media modal integration can listen for
			const event = new CustomEvent('wpBanana:openGenerator', {
				bubbles: true,
				cancelable: true,
			});
			document.dispatchEvent(event);

			// If no handler catches it, open the media modal with the generate tab
			if (window.wp?.media) {
				const frame = window.wp.media({
					title: __('Generate Image', 'wp-banana'),
					button: { text: __('Insert', 'wp-banana') },
					multiple: false,
				});

				// Open the frame
				frame.open();

				// Try to switch to the generate tab after the frame opens
				frame.on('open', () => {
					// The generate tab is added by media-modal-generate-tab.tsx
					// It automatically becomes active when the frame opens
					const $ = window.jQuery;
					if ($) {
						// Trigger a click on the generate tab if it exists
						setTimeout(() => {
							const generateTab = $('.media-frame-router a[data-tab="generate"]');
							if (generateTab.length) {
								generateTab.trigger('click');
							}
						}, 100);
					}
				});
			}
		},
	});
}

// Register commands when the DOM is ready
document.addEventListener('DOMContentLoaded', registerBananaCommands);
