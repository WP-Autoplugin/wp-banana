<?php
/**
 * REST controller: /edit endpoint.
 *
 * @package WPBanana\REST
 * @since   0.1.0
 */

namespace WPBanana\REST;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WP_Error;
use WP_Post;
use WP_REST_Request;
use WP_REST_Response;

use WPBanana\Services\Options;
use WPBanana\Provider\Gemini_Provider;
use WPBanana\Provider\OpenAI_Provider;
use WPBanana\Provider\Replicate_Provider;
use WPBanana\Domain\Edit_Params as Edit_DTO;
use WPBanana\Domain\Reference_Image;
use WPBanana\Domain\Binary_Image;
use WPBanana\Services\Convert_Service;
use WPBanana\Services\Attachment_Service;
use WPBanana\Services\Attachment_Metadata;
use WPBanana\Util\Caps;
use WPBanana\Services\Edit_Buffer;

use function get_current_user_id;
use function time;
use function wp_check_filetype_and_ext;
use function sanitize_file_name;
use function getimagesize;
use function is_wp_error;
use function wp_handle_sideload;
use function file_exists;
use function is_uploaded_file;
use function wp_delete_file;

/**
 * Handles image edit requests.
 */
final class Edit_Controller {

	private const MAX_REFERENCE_IMAGES     = 4;
	private const SUPPORTED_REFERENCE_MIME = [ 'image/png', 'image/jpeg', 'image/jpg', 'image/webp' ];

	/**
	 * Options service instance.
	 *
	 * @var Options
	 */
	private $options;

	/**
	 * AI edit buffer storage.
	 *
	 * @var Edit_Buffer
	 */
	private $buffer;

	/**
	 * Constructor.
	 *
	 * @param Options     $options Options service.
	 * @param Edit_Buffer $buffer  Edit buffer store.
	 */
	public function __construct( Options $options, Edit_Buffer $buffer ) {
		$this->options = $options;
		$this->buffer  = $buffer;
	}

	/**
	 * Register route definition.
	 *
	 * @param string $ns REST namespace.
	 * @return void
	 */
	public function register( $ns ): void {
		register_rest_route(
			$ns,
			'/edit',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'handle' ],
				'permission_callback' => [ $this, 'can_access' ],
				'args'                => [
					'attachment_id'   => [
						'type'     => 'integer',
						'required' => true,
					],
					'prompt'          => [
						'type'     => 'string',
						'required' => true,
					],
					'provider'        => [
						'type'     => 'string',
						'required' => false,
					],
					'model'           => [
						'type'     => 'string',
						'required' => false,
					],
					'format'          => [
						'type'     => 'string',
						'required' => false,
					],
					'save_mode'       => [
						'type'     => 'string',
						'required' => false,
					],
					'base_buffer_key' => [
						'type'     => 'string',
						'required' => false,
					],
				],
			]
		);

		register_rest_route(
			$ns,
			'/edit/save-as',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'save_as' ],
				'permission_callback' => [ $this, 'can_access' ],
				'args'                => [
					'attachment_id' => [
						'type'     => 'integer',
						'required' => true,
					],
					'history'       => [
						'type'     => 'string',
						'required' => true,
					],
				],
			]
		);
	}

	/**
	 * Permission check for route access.
	 *
	 * @return bool
	 */
	public function can_access(): bool {
		return current_user_can( 'upload_files' );
	}

	/**
	 * Handle edit request.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle( WP_REST_Request $req ) {
		if ( ! $this->options->is_connected() ) {
			return new WP_Error( 'wp_banana_not_connected', __( 'No provider configured. Add a key in settings.', 'wp-banana' ) );
		}

		$id   = (int) $req->get_param( 'attachment_id' );
		$post = get_post( $id );
		if ( ! ( $post instanceof WP_Post ) || 'attachment' !== $post->post_type ) {
			return new WP_Error( 'wp_banana_invalid_attachment', __( 'Attachment not found.', 'wp-banana' ) );
		}
		if ( ! current_user_can( 'edit_post', $id ) ) {
			return new WP_Error( 'rest_forbidden', __( 'You cannot edit this attachment.', 'wp-banana' ) );
		}
		$mime = get_post_mime_type( $id );
		if ( ! is_string( $mime ) || 0 !== strpos( $mime, 'image/' ) ) {
			return new WP_Error( 'wp_banana_invalid_attachment', __( 'Attachment is not an image.', 'wp-banana' ) );
		}

		$prompt = trim( (string) $req->get_param( 'prompt' ) );
		if ( '' === $prompt || strlen( $prompt ) > 4000 ) {
			return new WP_Error( 'wp_banana_invalid_prompt', __( 'Invalid prompt.', 'wp-banana' ) );
		}

		$save_mode = (string) ( $req->get_param( 'save_mode' ) ? $req->get_param( 'save_mode' ) : 'new' );
		$save_mode = in_array( $save_mode, [ 'new', 'replace', 'buffer' ], true ) ? $save_mode : 'new';

		// Resolve source info.
		$file = get_attached_file( $id );
		if ( ! $file || ! file_exists( $file ) ) {
			return new WP_Error( 'wp_banana_invalid_attachment', __( 'Source file not found.', 'wp-banana' ) );
		}
		$dims = getimagesize( $file );
		if ( false === $dims ) {
			return new WP_Error( 'wp_banana_invalid_attachment', __( 'Could not read image size.', 'wp-banana' ) );
		}
		$orig_w = (int) $dims[0];
		$orig_h = (int) $dims[1];

		$base_buffer_key = '';
		$raw_buffer_key  = $req->get_param( 'base_buffer_key' );
		if ( $raw_buffer_key ) {
			$base_buffer_key = sanitize_text_field( (string) $raw_buffer_key );
		}
		if ( '' !== $base_buffer_key ) {
			$buffer_record = $this->buffer->get( $base_buffer_key );
			if ( ! is_array( $buffer_record ) || empty( $buffer_record['path'] ) ) {
				return new WP_Error( 'wp_banana_buffer_missing', __( 'Previous AI edit is no longer available. Reapply the edit before continuing.', 'wp-banana' ) );
			}
			$buffer_path = (string) $buffer_record['path'];
			if ( ! file_exists( $buffer_path ) ) {
				return new WP_Error( 'wp_banana_buffer_missing', __( 'Previous AI edit is no longer available. Reapply the edit before continuing.', 'wp-banana' ) );
			}
			$dims = getimagesize( $buffer_path );
			if ( false === $dims ) {
				return new WP_Error( 'wp_banana_buffer_invalid', __( 'Failed to load buffered edit for chaining.', 'wp-banana' ) );
			}
			$file   = $buffer_path;
			$orig_w = (int) $dims[0];
			$orig_h = (int) $dims[1];
		}

		$reference_images = $this->collect_reference_images( $req );
		if ( is_wp_error( $reference_images ) ) {
			return $reference_images;
		}
		$reference_count = count( $reference_images );

		try {
			$provider = $req->get_param( 'provider' );
			$provider = is_string( $provider ) ? sanitize_key( $provider ) : 'gemini';
			if ( ! in_array( $provider, [ 'gemini', 'replicate', 'openai' ], true ) ) {
				return new WP_Error( 'wp_banana_invalid_provider', __( 'Unsupported provider.', 'wp-banana' ) );
			}
			$provider_conf = $this->options->get_provider_config( $provider );
			$model_input   = (string) ( $req->get_param( 'model' ) ? $req->get_param( 'model' ) : '' );
			$fallback      = 'gemini-2.5-flash-image-preview';
			if ( 'replicate' === $provider ) {
				$fallback = 'black-forest-labs/flux';
			} elseif ( 'openai' === $provider ) {
				$fallback = 'gpt-image-1';
			} else {
				$fallback = (string) $this->options->get( 'default_editor_model', 'gemini-2.5-flash-image-preview' );
			}
			$model     = '' !== $model_input ? sanitize_text_field( $model_input ) : (string) ( $provider_conf['default_model'] ?? $fallback );
			$model_eff = $model;

			if ( $reference_count > 0 && ! $this->model_supports_multi_reference( $provider, $model_eff ) ) {
				return new WP_Error( 'wp_banana_reference_not_supported', __( 'Selected model does not support multiple reference images.', 'wp-banana' ) );
			}

			// Determine requested/target format.
			$src_type         = wp_check_filetype( $file );
			$src_mime         = is_array( $src_type ) && ! empty( $src_type['type'] ) ? $src_type['type'] : 'image/png';
			$src_format       = $this->format_from_mime( $src_mime );
			$requested_format = (string) ( $req->get_param( 'format' ) ? $req->get_param( 'format' ) : '' );
			$format           = in_array( $requested_format, [ 'png', 'webp', 'jpeg' ], true ) ? $requested_format : $src_format;

			if ( 'replace' === $save_mode ) {
				// For replace, keep original format to avoid extension/mime mismatch.
				$format = $src_format;
				if ( ! Caps::can_replace_original( $id ) ) {
					return new WP_Error( 'rest_forbidden', __( 'Replacing the original is not allowed.', 'wp-banana' ) );
				}
			}

			// Provider configuration.

			$current_user    = get_current_user_id();
			$event_timestamp = time();

			if ( 'gemini' === $provider ) {
				$api_key = isset( $provider_conf['api_key'] ) ? (string) $provider_conf['api_key'] : '';
				if ( '' === $api_key ) {
					return new WP_Error( 'wp_banana_not_connected', __( 'Gemini API key missing.', 'wp-banana' ) );
				}
				$provider_inst = new Gemini_Provider( $api_key, $model_eff );
			} elseif ( 'replicate' === $provider ) {
				$api_token = isset( $provider_conf['api_token'] ) ? (string) $provider_conf['api_token'] : '';
				if ( '' === $api_token ) {
					return new WP_Error( 'wp_banana_not_connected', __( 'Replicate API token missing.', 'wp-banana' ) );
				}
				$provider_inst = new Replicate_Provider( $api_token, $model_eff );
			} else {
				$api_key = isset( $provider_conf['api_key'] ) ? (string) $provider_conf['api_key'] : '';
				if ( '' === $api_key ) {
					return new WP_Error( 'wp_banana_not_connected', __( 'OpenAI API key missing.', 'wp-banana' ) );
				}
				$provider_inst = new OpenAI_Provider( $api_key, $model_eff );
			}

			$dto = new Edit_DTO( $id, $prompt, $provider, $model_eff, $format, $file, $orig_w, $orig_h, $save_mode, $reference_images );

			try {
				$binary = $provider_inst->edit( $dto );
			} catch ( \Throwable $e ) {
				return new WP_Error( 'wp_banana_provider_error', $e->getMessage() );
			}

			// Normalize to original size and chosen format.
			try {
				$conv = new Convert_Service();
				$norm = $conv->normalize( $binary->bytes, $format, $orig_w, $orig_h );
			} catch ( \Throwable $e ) {
				return new WP_Error( 'wp_banana_convert_failed', $e->getMessage() );
			}

			if ( 'buffer' === $save_mode ) {
				try {
					$record = $this->buffer->store(
						$id,
						$norm,
						[
							'action'    => 'edit',
							'provider'  => $provider,
							'model'     => $model_eff,
							'prompt'    => $prompt,
							'mode'      => 'buffer',
							'user_id'   => $current_user,
							'timestamp' => $event_timestamp,
						]
					);
				} catch ( \Throwable $e ) {
					return new WP_Error( 'wp_banana_save_failed', $e->getMessage() );
				}
				return new WP_REST_Response(
					[
						'buffer_key'    => $record['key'],
						'width'         => $record['width'],
						'height'        => $record['height'],
						'mime'          => $record['mime'],
						'attachment_id' => $id,
						'provider'      => $provider,
						'model'         => $model_eff,
						'prompt'        => $prompt,
					],
					200
				);
			}

			if ( 'replace' === $save_mode ) {
				// Overwrite original file and regenerate metadata.
				global $wp_filesystem;
				if ( ! $wp_filesystem || ! is_object( $wp_filesystem ) ) {
					require_once ABSPATH . '/wp-admin/includes/file.php';
					WP_Filesystem();
				}
				if ( ! $wp_filesystem->put_contents( $file, $norm->bytes, FS_CHMOD_FILE ) ) {
					return new WP_Error( 'wp_banana_save_failed', __( 'Failed to overwrite file.', 'wp-banana' ) );
				}
				require_once ABSPATH . 'wp-admin/includes/image.php';
				$meta = wp_generate_attachment_metadata( $id, $file );
				if ( is_array( $meta ) ) {
					wp_update_attachment_metadata( $id, $meta );
				}

				Attachment_Metadata::update_generated_meta(
					$id,
					[
						'action'       => 'edit',
						'provider'     => $provider,
						'model'        => $model_eff,
						'mode'         => 'replace',
						'user_id'      => $current_user,
						'timestamp'    => $event_timestamp,
						'prompt'       => $prompt,
						'derived_from' => $id,
					]
				);
				Attachment_Metadata::append_event(
					$id,
					[
						'type'          => 'edit',
						'provider'      => $provider,
						'model'         => $model_eff,
						'mode'          => 'replace',
						'user_id'       => $current_user,
						'timestamp'     => $event_timestamp,
						'prompt'        => $prompt,
						'derived_from'  => $id,
						'attachment_id' => $id,
					]
				);
				return new WP_REST_Response(
					[
						'attachment_id' => $id,
						'url'           => wp_get_attachment_url( $id ),
						'parent_id'     => $id,
					],
					200
				);
			}

			$filename_base = Attachment_Service::filename_from_prompt( $prompt, 'ai-edit-' . $id );
			$title         = Attachment_Service::title_from_prompt( $prompt, __( 'AI Edit', 'wp-banana' ) );

			// Save as new attachment.
			try {
				$att   = new Attachment_Service();
				$saved = $att->save_new(
					$norm,
					$filename_base,
					[],
					$id,
					[
						'action'    => 'edit',
						'provider'  => $provider,
						'model'     => $model_eff,
						'mode'      => 'new',
						'user_id'   => $current_user,
						'timestamp' => $event_timestamp,
						'prompt'    => $prompt,
					],
					$title
				);
			} catch ( \Throwable $e ) {
				return new WP_Error( 'wp_banana_save_failed', $e->getMessage() );
			}

			return new WP_REST_Response(
				[
					'attachment_id' => $saved['attachment_id'],
					'url'           => $saved['url'],
					'parent_id'     => $id,
				],
				200
			);
		} finally {
			$this->cleanup_reference_images( $reference_images );
		}
	}

	/**
	 * Save current editor state as a new attachment.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function save_as( WP_REST_Request $req ) {
		$id   = (int) $req->get_param( 'attachment_id' );
		$post = get_post( $id );
		if ( ! ( $post instanceof WP_Post ) || 'attachment' !== $post->post_type ) {
			return new WP_Error( 'wp_banana_invalid_attachment', __( 'Attachment not found.', 'wp-banana' ) );
		}
		if ( ! current_user_can( 'edit_post', $id ) ) {
			return new WP_Error( 'rest_forbidden', __( 'You cannot edit this attachment.', 'wp-banana' ) );
		}

		$history_raw = (string) $req->get_param( 'history' );
		$history_raw = trim( $history_raw );
		if ( '' === $history_raw ) {
			return new WP_Error( 'wp_banana_invalid_history', __( 'Nothing to save.', 'wp-banana' ) );
		}

		$changes = json_decode( $history_raw );
		if ( null === $changes || JSON_ERROR_NONE !== json_last_error() || ! is_array( $changes ) ) {
			return new WP_Error( 'wp_banana_invalid_history', __( 'Invalid history payload.', 'wp-banana' ) );
		}

		require_once ABSPATH . 'wp-admin/includes/image-edit.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$source = \_load_image_to_edit_path( $id, 'full' );
		if ( ! $source ) {
			return new WP_Error( 'wp_banana_invalid_attachment', __( 'Source file not found.', 'wp-banana' ) );
		}
		$editor = \wp_get_image_editor( $source );
		if ( is_wp_error( $editor ) ) {
			return new WP_Error( 'wp_banana_edit_failed', $editor->get_error_message() );
		}

		$processed_changes = [];
		$last_context      = [];
		if ( ! empty( $changes ) ) {
			foreach ( $changes as $entry ) {
				if ( isset( $entry->banana ) && is_object( $entry->banana ) ) {
					$key = isset( $entry->banana->key ) ? sanitize_text_field( (string) $entry->banana->key ) : '';
					if ( '' === $key ) {
						continue;
					}
					$record = $this->buffer->get( $key );
					if ( ! $record || empty( $record['path'] ) || ! file_exists( $record['path'] ) ) {
						return new WP_Error( 'wp_banana_invalid_history', __( 'AI edit buffer expired. Try applying the edit again.', 'wp-banana' ) );
					}
					if ( isset( $record['context'] ) && is_array( $record['context'] ) ) {
						$last_context = $record['context'];
					}
					$new_editor = \wp_get_image_editor( $record['path'] );
					if ( is_wp_error( $new_editor ) ) {
						return new WP_Error( 'wp_banana_edit_failed', $new_editor->get_error_message() );
					}
					$editor = $new_editor;
					continue;
				}
				$processed_changes[] = $entry;
			}
		}

		if ( ! empty( $processed_changes ) ) {
			$editor = \image_edit_apply_changes( $editor, $processed_changes );
		}

		// Make sure wp_tempnam is available.
		if ( ! function_exists( 'wp_tempnam' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		$tmp = wp_tempnam( 'wp-banana-edit-' . $id );
		if ( ! $tmp ) {
			return new WP_Error( 'wp_banana_save_failed', __( 'Could not allocate temporary file.', 'wp-banana' ) );
		}

		$result = $editor->save( $tmp );
		if ( is_wp_error( $result ) ) {
			return new WP_Error( 'wp_banana_save_failed', $result->get_error_message() );
		}

		$mime   = isset( $result['mime-type'] ) ? (string) $result['mime-type'] : 'image/png';
		$width  = isset( $result['width'] ) ? (int) $result['width'] : 0;
		$height = isset( $result['height'] ) ? (int) $result['height'] : 0;

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local temp file read.
		$bytes = file_get_contents( $result['path'] );
		if ( false === $bytes ) {
			wp_delete_file( $result['path'] );
			return new WP_Error( 'wp_banana_save_failed', __( 'Failed to read rendered image.', 'wp-banana' ) );
		}

		$binary          = new Binary_Image( $bytes, $mime, $width, $height );
		$current_user    = get_current_user_id();
		$event_timestamp = time();
		$context_prompt  = isset( $last_context['prompt'] ) ? (string) $last_context['prompt'] : '';
		$filename_base   = Attachment_Service::filename_from_prompt( $context_prompt, 'ai-edit-' . $id );
		$title           = Attachment_Service::title_from_prompt( $context_prompt, __( 'AI Edit', 'wp-banana' ) );
		try {
			$attachment = ( new Attachment_Service() )->save_new(
				$binary,
				$filename_base,
				[],
				$id,
				[
					'action'    => 'edit',
					'provider'  => isset( $last_context['provider'] ) ? (string) $last_context['provider'] : '',
					'model'     => isset( $last_context['model'] ) ? (string) $last_context['model'] : '',
					'mode'      => 'save_as',
					'user_id'   => $current_user,
					'timestamp' => $event_timestamp,
					'prompt'    => $context_prompt,
				],
				$title
			);
		} catch ( \Throwable $e ) {
			wp_delete_file( $result['path'] );
			return new WP_Error( 'wp_banana_save_failed', $e->getMessage() );
		}

		wp_delete_file( $result['path'] );

		return new WP_REST_Response(
			[
				'attachment_id' => $attachment['attachment_id'],
				'url'           => $attachment['url'],
				'parent_id'     => $id,
				'filename'      => basename( $attachment['url'] ),
			],
			200
		);
	}

	/**
	 * Extract uploaded reference images from the request.
	 *
	 * @param WP_REST_Request $req Request instance.
	 * @return Reference_Image[]|WP_Error
	 */
	private function collect_reference_images( WP_REST_Request $req ) {
		$files = $req->get_file_params();
		if ( empty( $files['reference_images'] ) || ! is_array( $files['reference_images'] ) ) {
			return [];
		}

		$bucket  = $files['reference_images'];
		$entries = [];
		$names   = $bucket['name'] ?? [];

		if ( is_array( $names ) ) {
			foreach ( $names as $idx => $filename ) {
				$entries[] = [
					'name'     => (string) $filename,
					'tmp_name' => $bucket['tmp_name'][ $idx ] ?? '',
					'type'     => $bucket['type'][ $idx ] ?? '',
					'error'    => $bucket['error'][ $idx ] ?? UPLOAD_ERR_NO_FILE,
					'size'     => $bucket['size'][ $idx ] ?? 0,
				];
			}
		} else {
			$entries[] = [
				'name'     => (string) $names,
				'tmp_name' => $bucket['tmp_name'] ?? '',
				'type'     => $bucket['type'] ?? '',
				'error'    => $bucket['error'] ?? UPLOAD_ERR_NO_FILE,
				'size'     => $bucket['size'] ?? 0,
			];
		}

		$collected = [];
		foreach ( $entries as $entry ) {
			if ( count( $collected ) >= self::MAX_REFERENCE_IMAGES ) {
				break;
			}
			$error = (int) ( $entry['error'] ?? UPLOAD_ERR_NO_FILE );
			if ( UPLOAD_ERR_NO_FILE === $error ) {
				continue;
			}
			if ( UPLOAD_ERR_OK !== $error ) {
				$this->cleanup_reference_images( $collected );
				return new WP_Error( 'wp_banana_reference_upload_failed', __( 'Failed to upload reference image.', 'wp-banana' ) );
			}

			$tmp_name = (string) ( $entry['tmp_name'] ?? '' );
			if ( '' === $tmp_name || ! is_uploaded_file( $tmp_name ) ) {
				$this->cleanup_reference_images( $collected );
				return new WP_Error( 'wp_banana_reference_missing', __( 'Uploaded reference image is missing.', 'wp-banana' ) );
			}

			$original   = sanitize_file_name( (string) ( $entry['name'] ?? 'reference.png' ) );
			$file_check = wp_check_filetype_and_ext( $tmp_name, $original );
			$mime       = is_array( $file_check ) && ! empty( $file_check['type'] ) ? $file_check['type'] : (string) ( $entry['type'] ?? '' );
			if ( ! in_array( $mime, self::SUPPORTED_REFERENCE_MIME, true ) ) {
				$this->cleanup_reference_images( $collected );
				return new WP_Error( 'wp_banana_reference_type', __( 'Reference images must be PNG, JPEG, or WebP.', 'wp-banana' ) );
			}

			if ( ! function_exists( 'wp_handle_sideload' ) ) {
				require_once ABSPATH . 'wp-admin/includes/file.php';
			}

			$sideload_file = [
				'name'     => $original ?: 'reference.png',
				'type'     => $mime,
				'tmp_name' => $tmp_name,
				'error'    => $error,
				'size'     => (int) ( $entry['size'] ?? 0 ),
			];

			$upload_overrides = [
				'test_form' => false,
				'mimes'     => [
					'png'  => 'image/png',
					'jpg'  => 'image/jpeg',
					'jpeg' => 'image/jpeg',
					'webp' => 'image/webp',
				],
			];

			$handled = wp_handle_sideload( $sideload_file, $upload_overrides );
			if ( is_wp_error( $handled ) || empty( $handled['file'] ) ) {
				$this->cleanup_reference_images( $collected );
				return new WP_Error( 'wp_banana_reference_copy_failed', __( 'Failed to persist uploaded reference image.', 'wp-banana' ) );
			}

			$target = (string) $handled['file'];
			$mime   = ! empty( $handled['type'] ) ? (string) $handled['type'] : $mime;

			$dimensions = @getimagesize( $target ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- suppress warnings for invalid files.
			if ( ! is_array( $dimensions ) || empty( $dimensions[0] ) || empty( $dimensions[1] ) ) {
				$this->cleanup_reference_images( $collected );
				return new WP_Error( 'wp_banana_reference_invalid', __( 'Reference image could not be read.', 'wp-banana' ) );
			}

			$collected[] = new Reference_Image(
				$target,
				$mime,
				(int) $dimensions[0],
				(int) $dimensions[1],
				$original ?: basename( $target )
			);
		}

		return $collected;
	}

	/**
	 * Remove temporary reference images from disk.
	 *
	 * @param array<Reference_Image> $images Images to clean up.
	 * @return void
	 */
	private function cleanup_reference_images( array $images ): void {
		foreach ( $images as $image ) {
			if ( $image instanceof Reference_Image && ! empty( $image->path ) && file_exists( $image->path ) ) {
				wp_delete_file( $image->path );
			}
		}
	}

	/**
	 * Determine if the selected model supports multiple reference images.
	 *
	 * @param string $provider Provider slug.
	 * @param string $model    Model identifier.
	 * @return bool
	 */
	private function model_supports_multi_reference( string $provider, string $model ): bool {
		$provider = strtolower( trim( $provider ) );
		$model    = strtolower( trim( $model ) );

		if ( 'gemini' === $provider ) {
			return 'gemini-2.5-flash-image-preview' === $model;
		}
		if ( 'openai' === $provider ) {
			return 'gpt-image-1' === $model;
		}
		if ( 'replicate' === $provider ) {
			return in_array( $model, [ 'google/nano-banana', 'bytedance/seedream-4' ], true );
		}

		return false;
	}

	/**
	 * Map MIME type to output format slug.
	 *
	 * @param string $mime MIME type.
	 * @return string
	 */
	private function format_from_mime( $mime ) {
		if ( 'image/png' === $mime ) {
			return 'png';
		}
		if ( 'image/webp' === $mime ) {
			return 'webp';
		}
		if ( 'image/jpeg' === $mime ) {
			return 'jpeg';
		}
		return 'png';
	}
}
