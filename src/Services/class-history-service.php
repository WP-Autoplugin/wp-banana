<?php
/**
 * History helper for optional prompt/model logs.
 *
 * @package WPBanana\Services
 * @since   0.1.0
 */

namespace WPBanana\Services;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Appends steps to attachment history meta.
 */
final class History_Service {

	/**
	 * Append a history step to attachment.
	 *
	 * @param int   $attachment_id Attachment ID.
	 * @param array $step         Step data.
	 * @return void
	 */
	public function append_step( int $attachment_id, array $step ): void {
		$raw = get_post_meta( $attachment_id, '_ai_history', true );
		$arr = [];
		if ( is_string( $raw ) && '' !== $raw ) {
			$decoded = json_decode( $raw, true );
			if ( is_array( $decoded ) ) {
				$arr = $decoded;
			}
		}
		$arr[] = $step;
		update_post_meta( $attachment_id, '_ai_history', wp_json_encode( $arr ) );
	}
}
