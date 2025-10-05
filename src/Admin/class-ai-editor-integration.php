<?php
/**
 * Hooks WP core image editor to apply buffered AI edits.
 *
 * @package WPBanana\Admin
 * @since   0.1.0
 */

namespace WPBanana\Admin;

use WPBanana\Services\Attachment_Metadata;
use WPBanana\Services\Edit_Buffer;
use function get_current_user_id;
use function time;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Integrates buffered AI outputs into core image editing workflow.
 */
final class AI_Editor_Integration {

	/**
	 * Edit buffer service.
	 *
	 * @var Edit_Buffer
	 */
	private $buffer;

	/**
	 * Pending context captured from buffered edits awaiting save.
	 *
	 * @var array<int,array<int,array<string,mixed>>>
	 */
	private $pending = [];

	/**
	 * Constructor.
	 *
	 * @param Edit_Buffer $buffer Buffer service.
	 */
	public function __construct( Edit_Buffer $buffer ) {
		$this->buffer = $buffer;
	}

	/**
	 * Register integration hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'wp_image_editor_before_change', [ $this, 'maybe_apply_buffers' ], 10, 2 );
		add_filter( 'wp_save_image_editor_file', [ $this, 'record_buffer_events' ], 10, 5 );
	}

	/**
	 * Apply buffered edits before core operations.
	 *
	 * @param \WP_Image_Editor $image   WP image editor.
	 * @param array            $changes Pending change objects.
	 * @return \WP_Image_Editor
	 */
	public function maybe_apply_buffers( $image, array $changes ) {
		foreach ( $changes as $change ) {
			if ( ! isset( $change->banana ) || ! is_object( $change->banana ) ) {
				continue;
			}

			$key = isset( $change->banana->key ) ? (string) $change->banana->key : '';
			if ( '' === $key ) {
				continue;
			}

			$record = $this->buffer->get( $key );
			if ( ! $record ) {
				continue;
			}
			if ( isset( $record['context'] ) && is_array( $record['context'] ) ) {
				$attachment_id = isset( $record['attachment_id'] ) ? (int) $record['attachment_id'] : 0;
				if ( $attachment_id > 0 ) {
					if ( ! isset( $this->pending[ $attachment_id ] ) ) {
						$this->pending[ $attachment_id ] = [];
					}
					$this->pending[ $attachment_id ][] = $record['context'];
				}
			}

			$new_editor = wp_get_image_editor( $record['path'] );
			if ( is_wp_error( $new_editor ) ) {
				continue;
			}

			$image = $new_editor;

			// Mark as processed so core loop skips it.
			unset( $change->banana );
			$change->type = 'noop';
		}

		return $image;
	}

	/**
	 * Persist captured AI contexts when the editor saves back to disk.
	 *
	 * @param mixed            $override  Whether to short-circuit the save.
	 * @param string           $filename  Filename being saved.
	 * @param \WP_Image_Editor $image   Image editor instance.
	 * @param string           $mime_type Mime type.
	 * @param int              $post_id   Attachment ID.
	 * @return mixed Unmodified override value.
	 */
	public function record_buffer_events( $override, $filename, $image, $mime_type, $post_id ) {
		if ( ! $post_id || empty( $this->pending[ $post_id ] ) ) {
			return $override;
		}

		$events = $this->pending[ $post_id ];
		unset( $this->pending[ $post_id ] );

		$last_payload = null;
		foreach ( $events as $context ) {
			if ( ! is_array( $context ) ) {
				continue;
			}
			$payload = [
				'action'       => 'edit',
				'provider'     => isset( $context['provider'] ) ? (string) $context['provider'] : '',
				'model'        => isset( $context['model'] ) ? (string) $context['model'] : '',
				'mode'         => 'replace',
				'user_id'      => isset( $context['user_id'] ) ? (int) $context['user_id'] : get_current_user_id(),
				'timestamp'    => isset( $context['timestamp'] ) ? (int) $context['timestamp'] : time(),
				'prompt'       => isset( $context['prompt'] ) ? (string) $context['prompt'] : '',
				'derived_from' => $post_id,
			];
			Attachment_Metadata::append_event(
				$post_id,
				array_merge(
					$payload,
					[
						'type'          => 'edit',
						'attachment_id' => $post_id,
					]
				)
			);
			$last_payload = $payload;
		}

		if ( $last_payload ) {
			Attachment_Metadata::update_generated_meta( $post_id, $last_payload );
		}

		return $override;
	}
}
