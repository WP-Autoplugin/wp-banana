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
