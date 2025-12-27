<?php
/**
 * Capability helpers.
 *
 * @package WPBanana\Util
 * @since   0.1.0
 */

namespace WPBanana\Util;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Capability checks and filters.
 */
final class Caps {

	public const GENERATE = 'wp_banana_generate';
	public const EDIT     = 'wp_banana_edit';
	public const SAVE     = 'wp_banana_save';
	public const MODELS   = 'wp_banana_models';

	/**
	 * List all custom capabilities provided by this plugin.
	 *
	 * @return string[]
	 */
	public static function all(): array {
		return [
			self::GENERATE,
			self::EDIT,
			self::SAVE,
			self::MODELS,
		];
	}

	/**
	 * Whether the current user can replace the original file for an attachment.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return bool
	 */
	public static function can_replace_original( int $attachment_id ): bool {
		$allowed = current_user_can( 'edit_post', $attachment_id );
		/**
		 * Filtered capability result.
		 *
		 * @var bool $filtered
		 */
		$filtered = apply_filters( 'wp_banana_can_replace_original', $allowed, $attachment_id, wp_get_current_user() );
		return (bool) $filtered;
	}
}
