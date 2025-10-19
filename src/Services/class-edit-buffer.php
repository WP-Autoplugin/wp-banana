<?php
/**
 * Stores AI edit buffers for deferred application via core image editor.
 *
 * @package WPBanana\Services
 * @since   0.1.0
 */

namespace WPBanana\Services;

use RuntimeException;
use WPBanana\Domain\Binary_Image;
use function esc_html;
use function get_current_user_id;
use function sanitize_key;
use function sanitize_textarea_field;
use function sanitize_text_field;
use function time;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Ephemeral storage for AI edit outputs referenced by core image editor history.
 */
final class Edit_Buffer {

	/**
	 * Transient prefix for stored buffers.
	 *
	 * @var string
	 */
	private const TRANSIENT_PREFIX = 'wp_banana_aiedit_';

	/**
	 * Directory under uploads for persisted buffer files.
	 *
	 * @var string
	 */
	private $dir;

	/**
	 * Constructor.
	 *
	 * @throws \RuntimeException If upload directory is unavailable or buffer directory cannot be created.
	 */
	public function __construct() {
		$upload = wp_upload_dir();
		if ( ! empty( $upload['error'] ) ) {
			$message = 'Upload directory unavailable: ' . (string) $upload['error'];
			throw new RuntimeException( esc_html( $message ) );
		}

		$this->dir = trailingslashit( $upload['basedir'] ) . 'wp-banana-cache';
		if ( ! is_dir( $this->dir ) && ! wp_mkdir_p( $this->dir ) ) {
			throw new RuntimeException( 'Failed to create buffer directory.' );
		}
	}

	/**
	 * Persist binary image to buffer and return key + metadata.
	 *
	 * @param int          $attachment_id Parent attachment ID.
	 * @param Binary_Image $image         Image payload.
	 * @param array        $context       Additional context (provider, model, prompt, mode, user_id, timestamp).
	 *
	 * @return array{key:string,width:int,height:int,mime:string,path:string,context:array}
	 * @throws \RuntimeException If file cannot be written.
	 */
	public function store( int $attachment_id, Binary_Image $image, array $context = [] ): array {
		$key     = wp_generate_password( 32, false, false );
		$ext     = $this->extension_from_mime( $image->mime );
		$base    = 'ai-edit-' . $attachment_id . '-' . $key . '.' . $ext;
		$path    = trailingslashit( $this->dir ) . $base;
		$written = file_put_contents( $path, $image->bytes ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_put_contents -- controlled path.
		if ( false === $written ) {
			throw new RuntimeException( 'Failed to write buffer file.' );
		}

		$sanitized_context = $this->sanitize_context( $context );

		$payload = [
			'attachment_id' => $attachment_id,
			'user_id'       => get_current_user_id(),
			'path'          => $path,
			'width'         => $image->width,
			'height'        => $image->height,
			'mime'          => $image->mime,
			'created'       => time(),
			'context'       => $sanitized_context,
		];
		/**
		 * Filter the TTL for stored edit buffers.
		 *
		 * @since 0.2.0
		 *
		 * @param int   $ttl            Buffer lifetime in seconds.
		 * @param int   $attachment_id  Parent attachment ID.
		 * @param array $context        Sanitized context metadata.
		 */
		$ttl = (int) apply_filters( 'wp_banana_edit_buffer_ttl', HOUR_IN_SECONDS, $attachment_id, $sanitized_context );
		if ( $ttl <= 0 ) {
			$ttl = HOUR_IN_SECONDS;
		}
		set_transient( self::TRANSIENT_PREFIX . $key, $payload, $ttl );

		return [
			'key'     => $key,
			'width'   => $image->width,
			'height'  => $image->height,
			'mime'    => $image->mime,
			'path'    => $path,
			'context' => $sanitized_context,
		];
	}

	/**
	 * Retrieve buffer record by key.
	 *
	 * @param string $key Buffer key.
	 * @return array|null
	 */
	public function get( string $key ): ?array {
		$data = get_transient( self::TRANSIENT_PREFIX . $key );
		if ( ! is_array( $data ) ) {
			return null;
		}
		if ( (int) $data['user_id'] !== get_current_user_id() ) {
			return null;
		}
		if ( empty( $data['path'] ) || ! file_exists( $data['path'] ) ) {
			$this->delete( $key );
			return null;
		}

		// Refresh TTL to keep buffer alive while editing continues.
		set_transient( self::TRANSIENT_PREFIX . $key, $data, HOUR_IN_SECONDS );
		return $data;
	}

	/**
	 * Remove buffer entry and delete file.
	 *
	 * @param string $key Buffer key.
	 * @return void
	 */
	public function delete( string $key ): void {
		$data = get_transient( self::TRANSIENT_PREFIX . $key );
		delete_transient( self::TRANSIENT_PREFIX . $key );
		if ( is_array( $data ) && ! empty( $data['path'] ) && file_exists( $data['path'] ) ) {
			wp_delete_file( $data['path'] );
		}
	}

	/**
	 * Map MIME type to file extension.
	 *
	 * @param string $mime MIME type.
	 * @return string
	 */
	private function extension_from_mime( string $mime ): string {
		if ( 'image/png' === $mime ) {
			return 'png';
		}
		if ( 'image/webp' === $mime ) {
			return 'webp';
		}
		if ( 'image/jpeg' === $mime ) {
			return 'jpg';
		}
		return 'img';
	}

	/**
	 * Sanitize stored context payload used for history/meta updates.
	 *
	 * @param array $context Raw context.
	 * @return array
	 */
	private function sanitize_context( array $context ): array {
		return [
			'provider'  => isset( $context['provider'] ) ? sanitize_key( (string) $context['provider'] ) : '',
			'model'     => isset( $context['model'] ) ? sanitize_text_field( (string) $context['model'] ) : '',
			'prompt'    => isset( $context['prompt'] ) ? sanitize_textarea_field( (string) $context['prompt'] ) : '',
			'action'    => isset( $context['action'] ) ? sanitize_key( (string) $context['action'] ) : 'edit',
			'mode'      => isset( $context['mode'] ) ? sanitize_key( (string) $context['mode'] ) : '',
			'user_id'   => isset( $context['user_id'] ) ? (int) $context['user_id'] : get_current_user_id(),
			'timestamp' => isset( $context['timestamp'] ) ? (int) $context['timestamp'] : time(),
		];
	}
}
