<?php
/**
 * Attachment creation and metadata handling.
 *
 * @package WPBanana\Services
 * @since   0.1.0
 */

namespace WPBanana\Services;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WPBanana\Domain\Binary_Image;
use WPBanana\Services\Attachment_Metadata;
use function get_current_user_id;
use function sanitize_text_field;
use function sanitize_title;
use function time;
use function wp_generate_password;
use function wp_rand;
use function wp_strip_all_tags;

/**
 * Saves binary images as WP attachments.
 */
final class Attachment_Service {

	/**
	 * Save a new attachment from binary.
	 *
	 * @param Binary_Image $bin           Binary image.
	 * @param string       $filename_base Preferred base filename (slugged and uniqued).
	 * @param array        $history       Optional history array to store.
	 * @param int|null     $derived_from  Parent attachment ID (for edits).
	 * @param array        $context       Context for AI metadata (action, provider, model, mode, timestamp).
	 * @param string|null  $title         Optional attachment title override.
	 * @return array{attachment_id:int,url:string}
	 * @throws \RuntimeException If upload directory is not writable, file write fails, or attachment insert fails.
	 */
	public function save_new( Binary_Image $bin, string $filename_base, array $history = [], $derived_from = null, array $context = [], ?string $title = null ): array {
		$upload = wp_upload_dir();
		if ( ! empty( $upload['error'] ) ) {
			throw new \RuntimeException( 'Upload dir error: ' . esc_html( (string) $upload['error'] ) );
		}

		$ext      = $this->ext_from_mime( $bin->mime );
		$proposed = sanitize_file_name( $filename_base . '.' . $ext );
		/**
		 * Filter the suggested filename before ensuring uniqueness.
		 *
		 * @since 0.2.0
		 *
		 * @param string       $proposed      Sanitized filename suggestion.
		 * @param string       $filename_base Base filename (without extension).
		 * @param string       $extension     File extension.
		 * @param Binary_Image $bin           Image payload.
		 * @param array        $context       Metadata context for the attachment.
		 */
		$proposed = apply_filters( 'wp_banana_attachment_pre_unique_filename', $proposed, $filename_base, $ext, $bin, $context );
		$proposed = sanitize_file_name( (string) $proposed );
		if ( '' === $proposed ) {
			$proposed = sanitize_file_name( $filename_base . '.' . $ext );
		}
		$filename = wp_unique_filename( $upload['path'], $proposed );
		/**
		 * Filter the unique filename chosen for the attachment.
		 *
		 * @since 0.2.0
		 *
		 * @param string       $filename      Unique filename (with extension).
		 * @param string       $filename_base Base filename prior to uniqueness.
		 * @param string       $extension     File extension.
		 * @param Binary_Image $bin           Image payload.
		 * @param array        $context       Metadata context for the attachment.
		 */
		$filename = (string) apply_filters( 'wp_banana_attachment_unique_filename', $filename, $filename_base, $ext, $bin, $context );
		$filename = '' !== $filename ? sanitize_file_name( $filename ) : wp_unique_filename( $upload['path'], $proposed );
		$file     = trailingslashit( $upload['path'] ) . $filename;

		// Use WP_Filesystem for file operations as per WP standards.
		require_once ABSPATH . 'wp-admin/includes/file.php';
		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		WP_Filesystem();
		global $wp_filesystem;
		$ok = $wp_filesystem && $wp_filesystem->put_contents( $file, $bin->bytes, FS_CHMOD_FILE );
		if ( ! $ok ) {
			throw new \RuntimeException( 'Failed to write file' );
		}

		$type        = $bin->mime;
		$name        = pathinfo( $filename, PATHINFO_FILENAME );
		$title_value = null !== $title ? sanitize_text_field( $title ) : '';
		if ( '' === $title_value ) {
			$title_value = $name;
		}

		$attachment = [
			'post_mime_type' => $type,
			'post_title'     => $title_value,
			'post_content'   => '',
			'post_status'    => 'inherit',
		];
		$attach_id  = wp_insert_attachment( $attachment, $file, 0 );
		if ( is_wp_error( $attach_id ) ) {
			// Clean up the file on failure to insert the attachment.
			wp_delete_file( $file );
			throw new \RuntimeException( 'Failed to insert attachment: ' . esc_html( $attach_id->get_error_message() ) );
		}

		require_once ABSPATH . 'wp-admin/includes/image.php';
		$meta = wp_generate_attachment_metadata( $attach_id, $file );
		if ( is_array( $meta ) ) {
			$meta = apply_filters( 'wp_banana_attachment_meta', $meta, $attach_id, [ 'action' => 'create' ] );
			wp_update_attachment_metadata( $attach_id, $meta );
		}

		if ( ! empty( $history ) ) {
			update_post_meta( $attach_id, '_ai_history', wp_json_encode( $history ) );
		}
		$context['derived_from'] = $derived_from ? (int) $derived_from : 0;
		/**
		 * Filter the metadata context stored alongside generated attachments.
		 *
		 * @since 0.2.0
		 *
		 * @param array        $context      Context array (action, provider, model, etc).
		 * @param int          $attachment_id Newly created attachment ID.
		 * @param Binary_Image $bin          Image payload.
		 * @param int|null     $derived_from Source attachment ID when applicable.
		 */
		$context                 = apply_filters( 'wp_banana_attachment_context', $context, $attach_id, $bin, $derived_from );
		$context                 = is_array( $context ) ? $context : [];
		$context['derived_from'] = $derived_from ? (int) $derived_from : 0;
		Attachment_Metadata::update_generated_meta( $attach_id, $context );
		Attachment_Metadata::append_event(
			$attach_id,
			[
				'type'         => isset( $context['action'] ) ? $context['action'] : 'generate',
				'provider'     => isset( $context['provider'] ) ? $context['provider'] : '',
				'model'        => isset( $context['model'] ) ? $context['model'] : '',
				'mode'         => isset( $context['mode'] ) ? $context['mode'] : '',
				'timestamp'    => isset( $context['timestamp'] ) ? (int) $context['timestamp'] : time(),
				'user_id'      => isset( $context['user_id'] ) ? (int) $context['user_id'] : get_current_user_id(),
				'prompt'       => isset( $context['prompt'] ) ? $context['prompt'] : '',
				'derived_from' => $derived_from ? (int) $derived_from : 0,
			],
		);

		$payload = [
			'attachment_id' => $attach_id,
			'url'           => wp_get_attachment_url( $attach_id ),
		];

		/**
		 * Fires after an AI-generated attachment has been saved.
		 *
		 * @since 0.2.0
		 *
		 * @param int          $attachment_id Attachment ID.
		 * @param string       $file_path     Absolute path to the file on disk.
		 * @param array        $context       Context metadata stored with the attachment.
		 * @param Binary_Image $bin           Image payload saved to disk.
		 * @param array        $response      REST response payload data.
		 */
		do_action( 'wp_banana_attachment_saved', $attach_id, $file, $context, $bin, $payload );

		return $payload;
	}

	/**
	 * Build a filename base derived from the prompt text.
	 *
	 * @param string $prompt   Prompt text.
	 * @param string $fallback Fallback base when prompt is empty.
	 * @return string
	 */
	public static function filename_from_prompt( string $prompt, string $fallback ): string {
		$snippet = self::first_words( $prompt, 8 );
		if ( '' === $snippet ) {
			$snippet = $fallback;
		}
		$slug = sanitize_title( $snippet );
		if ( '' === $slug ) {
			$slug = sanitize_title( $fallback );
		}
		if ( strlen( $slug ) > 60 ) {
			$slug = substr( $slug, 0, 60 );
		}
		$slug = trim( $slug, '-' );
		if ( '' === $slug ) {
			$slug = sanitize_title( $fallback );
		}
		if ( '' === $slug ) {
			$slug = 'ai-image';
		}
		$base = $slug . '-' . self::random_suffix();
		/**
		 * Filter the generated filename base before persistence.
		 *
		 * @since 0.2.0
		 *
		 * @param string $base     Proposed filename base (without extension).
		 * @param string $prompt   Original prompt text.
		 * @param string $fallback Fallback slug used when prompt is empty.
		 * @param string $slug     Sanitized slug derived from the prompt.
		 */
		$filtered = apply_filters( 'wp_banana_generated_filename_base', $base, $prompt, $fallback, $slug );
		if ( is_string( $filtered ) && '' !== trim( $filtered ) ) {
			$base = $filtered;
		}
		return $base;
	}

	/**
	 * Build a human-friendly title derived from the prompt.
	 *
	 * @param string $prompt   Prompt text.
	 * @param string $fallback Fallback title when prompt is empty.
	 * @return string
	 */
	public static function title_from_prompt( string $prompt, string $fallback ): string {
		$title = self::first_words( $prompt, 10 );
		if ( '' === $title ) {
			$title = $fallback;
		}
		/**
		 * Filter the generated attachment title.
		 *
		 * @since 0.2.0
		 *
		 * @param string $title    Proposed attachment title.
		 * @param string $prompt   Original prompt text.
		 * @param string $fallback Fallback label used when prompt is empty.
		 */
		$filtered = apply_filters( 'wp_banana_generated_attachment_title', $title, $prompt, $fallback );
		$title    = is_string( $filtered ) && '' !== $filtered ? $filtered : $title;
		$title    = sanitize_text_field( $title );
		if ( '' === $title ) {
			$title = sanitize_text_field( $fallback );
		}
		return $title;
	}

	/**
	 * Extract the first N words from text.
	 *
	 * @param string $text  Source text.
	 * @param int    $limit Word limit.
	 * @return string
	 */
	private static function first_words( string $text, int $limit ): string {
		$clean = trim( wp_strip_all_tags( $text ) );
		if ( '' === $clean ) {
			return '';
		}
		$clean = preg_replace( '/\s+/u', ' ', $clean );
		if ( ! is_string( $clean ) ) {
			return '';
		}
		$words = preg_split( '/\s+/u', $clean );
		if ( ! is_array( $words ) ) {
			return '';
		}
		$words = array_slice( $words, 0, $limit );
		return trim( implode( ' ', $words ) );
	}

	/**
	 * Generate a random suffix for filenames.
	 *
	 * @return string
	 */
	private static function random_suffix(): string {
		$length = wp_rand( 4, 8 );
		$suffix = wp_generate_password( $length, false, false );
		if ( ! is_string( $suffix ) || '' === $suffix ) {
			$suffix = (string) wp_rand( 1000, 9999 );
		}
		return strtolower( $suffix );
	}

	/**
	 * Map MIME to file extension.
	 *
	 * @param string $mime MIME type.
	 * @return string
	 */
	private function ext_from_mime( string $mime ): string {
		if ( 'image/png' === $mime ) {
			return 'png';
		}
		if ( 'image/webp' === $mime ) {
			return 'webp';
		}
		if ( 'image/jpeg' === $mime ) {
			return 'jpg';
		}
		return 'bin';
	}
}
