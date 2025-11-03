<?php
/**
 * REST controller: /generate endpoint.
 *
 * @package WPBanana\REST
 */

namespace WPBanana\REST;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

use WPBanana\Services\Options;
use WPBanana\Domain\Image_Params;
use WPBanana\Domain\Reference_Image;
use WPBanana\Domain\Edit_Params;
use WPBanana\Domain\Aspect_Ratios;
use WPBanana\Domain\Binary_Image;
use WPBanana\Provider\Gemini_Provider;
use WPBanana\Provider\OpenAI_Provider;
use WPBanana\Provider\Replicate_Provider;
use WPBanana\Services\Convert_Service;
use WPBanana\Services\Attachment_Service;
use WPBanana\Services\Logging_Service;
use WPBanana\Util\Mime;

use function get_current_user_id;
use function time;
use function wp_check_filetype_and_ext;
use function is_wp_error;
use function sanitize_file_name;
use function getimagesize;
use function wp_handle_sideload;
use function is_uploaded_file;
use function file_exists;
use function wp_delete_file;

/**
 * Handles text-to-image generation requests.
 */
final class Generate_Controller {

	private const TARGET_LONG_EDGE         = 1024;
	private const MAX_REFERENCE_IMAGES     = 4;
	private const SUPPORTED_REFERENCE_MIME = [ 'image/png', 'image/jpeg', 'image/jpg', 'image/webp' ];

	/**
	 * Options service instance.
	 *
	 * @var Options
	 */
	private $options;
	/**
	 * Logging service instance.
	 *
	 * @var Logging_Service
	 */
	private $logger;

	/**
	 * Constructor.
	 *
	 * @param Options         $options Options service.
	 * @param Logging_Service $logger  Logging service.
	 */
	public function __construct( Options $options, Logging_Service $logger ) {
		$this->options = $options;
		$this->logger  = $logger;
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
			'/generate',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'handle' ],
				'permission_callback' => [ $this, 'can_access' ],
				'args'                => [
					'prompt'       => [
						'type'     => 'string',
						'required' => true,
					],
					'provider'     => [
						'type'     => 'string',
						'required' => false,
					],
					'model'        => [
						'type'     => 'string',
						'required' => false,
					],
					'aspect_ratio' => [
						'type'     => 'string',
						'required' => false,
					],
					'width'        => [
						'type'     => 'integer',
						'required' => false,
					],
					'height'       => [
						'type'     => 'integer',
						'required' => false,
					],
					'format'       => [
						'type'     => 'string',
						'required' => false,
					], // png|webp|jpeg.
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
	 * Handle generation request.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle( WP_REST_Request $req ) {
		if ( ! $this->options->is_connected() ) {
			return new WP_Error( 'wp_banana_not_connected', __( 'No provider configured. Add a key in settings.', 'wp-banana' ) );
		}

		$prompt = trim( (string) $req->get_param( 'prompt' ) );
		if ( $prompt === '' || strlen( $prompt ) > 4000 ) {
			return new WP_Error( 'wp_banana_invalid_prompt', __( 'Invalid prompt.', 'wp-banana' ) );
		}
		if ( preg_match( '/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $prompt ) ) {
			return new WP_Error( 'wp_banana_invalid_prompt', __( 'Prompt contains control characters.', 'wp-banana' ) );
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
			if ( $reference_count > 0 ) {
				if ( 'gemini' === $provider ) {
					$fallback = (string) $this->options->get( 'default_editor_model', 'gemini-2.5-flash-image-preview' );
				} elseif ( 'replicate' === $provider ) {
					$fallback = 'black-forest-labs/flux';
				} else {
					$fallback = 'gpt-image-1';
				}
			} else {
				$fallback = 'gemini-2.5-flash-image-preview';
				if ( 'replicate' === $provider ) {
					$fallback = 'black-forest-labs/flux';
				} elseif ( 'openai' === $provider ) {
					$fallback = 'gpt-image-1';
				} else {
					$fallback = (string) $this->options->get( 'default_generator_model', 'gemini-2.5-flash-image-preview' );
				}
			}
			$model     = '' !== $model_input ? sanitize_text_field( $model_input ) : (string) ( $provider_conf['default_model'] ?? $fallback );
			$model_eff = $model;

			$aspect_param = $req->get_param( 'aspect_ratio' );
			$aspect_ratio = is_string( $aspect_param ) ? $this->sanitize_aspect_ratio( $aspect_param ) : '';

			$width_param  = $req->get_param( 'width' );
			$height_param = $req->get_param( 'height' );

			if ( null !== $width_param && null !== $height_param ) {
				$width  = max( 256, min( 4096, (int) $width_param ) );
				$height = max( 256, min( 4096, (int) $height_param ) );
				if ( '' === $aspect_ratio ) {
					$aspect_ratio = $this->aspect_ratio_from_dimensions( $width, $height );
				}
			} else {
				if ( '' === $aspect_ratio ) {
					$default_aspect = (string) $this->options->get( 'generation_defaults.aspect_ratio', Aspect_Ratios::default() );
					$aspect_ratio   = $this->sanitize_aspect_ratio( $default_aspect );
					if ( '' === $aspect_ratio ) {
						$aspect_ratio = Aspect_Ratios::default();
					}
				}
				list( $width, $height ) = $this->dimensions_for_aspect_ratio( $aspect_ratio );
			}

			if ( $reference_count > 0 && isset( $reference_images[0] ) && $reference_images[0] instanceof Reference_Image ) {
				$primary      = $reference_images[0];
				$width        = max( 1, (int) $primary->width );
				$height       = max( 1, (int) $primary->height );
				$aspect_ratio = $this->aspect_ratio_from_dimensions( $width, $height ) ?: '';
			}

			$operation = 'generate';
			if ( $reference_count > 1 ) {
				$operation = 'generate-reference-multi';
			} elseif ( 1 === $reference_count ) {
				$operation = 'generate-reference';
			}

			$current_user = get_current_user_id();
			$log_context  = [
				'operation'       => $operation,
				'user_id'         => $current_user,
				'prompt_excerpt'  => $prompt,
				'reference_count' => $reference_count,
				'save_mode'       => 'new',
			];

			$reference_names = [];
			if ( $reference_count > 0 ) {
				foreach ( $reference_images as $img ) {
					if ( $img instanceof Reference_Image && ! empty( $img->filename ) ) {
						$reference_names[] = $img->filename;
					}
				}
			}

			$request_payload = [
				'reference_count' => $reference_count,
				'reference_names' => $reference_names,
			];

			$format_param = $req->get_param( 'format' );
			$format       = (string) ( $format_param ? $format_param : $this->options->get( 'generation_defaults.format', 'png' ) );
			$format       = in_array( $format, [ 'png', 'webp', 'jpeg' ], true ) ? $format : 'png';

			$dto_width        = $width;
			$dto_height       = $height;
			$dto_aspect_ratio = '' === $aspect_ratio ? null : $aspect_ratio;
			$normalize_width  = $width;
			$normalize_height = $height;

			if ( $reference_count > 1 && ! $this->model_supports_multi_reference( $provider, $model_eff ) ) {
				return new WP_Error( 'wp_banana_reference_not_supported', __( 'Selected model does not support multiple reference images.', 'wp-banana' ) );
			}

			if ( 'gemini' === $provider ) {
				$api_key = isset( $provider_conf['api_key'] ) ? (string) $provider_conf['api_key'] : '';
				if ( '' === $api_key ) {
					return new WP_Error( 'wp_banana_not_connected', __( 'Gemini API key missing.', 'wp-banana' ) );
				}
				$provider_inst = new Gemini_Provider( $api_key, $model_eff );
				if ( 0 === $reference_count ) {
					$dto_aspect_ratio = null;
					$normalize_width  = null;
					$normalize_height = null;
				}
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
				if ( 0 === $reference_count ) {
					$mapped           = $this->map_openai_dimensions( $dto_aspect_ratio );
					$dto_width        = $mapped['width'];
					$dto_height       = $mapped['height'];
					$dto_aspect_ratio = $mapped['aspect_ratio'];
					$normalize_width  = $dto_width;
					$normalize_height = $dto_height;
				}
			}

			$log_context['provider'] = $provider;
			$log_context['model']    = $model_eff;

			$request_payload['width']            = $dto_width;
			$request_payload['height']           = $dto_height;
			$request_payload['aspect_ratio']     = $dto_aspect_ratio ?: null;
			$request_payload['normalize_width']  = $normalize_width;
			$request_payload['normalize_height'] = $normalize_height;
			$request_payload['format']           = $format;

			$log_context['request_payload'] = $request_payload;

			$operation_start = microtime( true );

			if ( 1 === $reference_count && isset( $primary ) ) {
				$normalize_width  = (int) $primary->width;
				$normalize_height = (int) $primary->height;
				$edit_dto         = new Edit_Params( 0, $prompt, $provider, $model_eff, $format, $primary->path, $normalize_width, $normalize_height, 'new' );
				try {
					$binary = $provider_inst->edit( $edit_dto );
				} catch ( \Throwable $e ) {
					$this->record_log(
						array_merge(
							$log_context,
							[
								'status'           => 'error',
								'response_time_ms' => (int) round( ( microtime( true ) - $operation_start ) * 1000 ),
								'error_message'    => $e->getMessage(),
								'error_code'       => $e->getCode() ? (string) $e->getCode() : '',
							]
						)
					);
					return new WP_Error( 'wp_banana_provider_error', $e->getMessage() );
				}
			} else {
				$dto = new Image_Params( $prompt, $provider, $model_eff, $dto_width, $dto_height, $format, $dto_aspect_ratio, $reference_images );
				try {
					$binary = $provider_inst->generate( $dto );
				} catch ( \Throwable $e ) {
					$this->record_log(
						array_merge(
							$log_context,
							[
								'status'           => 'error',
								'response_time_ms' => (int) round( ( microtime( true ) - $operation_start ) * 1000 ),
								'error_message'    => $e->getMessage(),
								'error_code'       => $e->getCode() ? (string) $e->getCode() : '',
							]
						)
					);
					return new WP_Error( 'wp_banana_provider_error', $e->getMessage() );
				}
			}

			/**
			 * Filters the Binary_Image returned from the provider before normalization.
			 *
			 * @since 0.2.0
			 *
			 * @param Binary_Image $binary   Provider response.
			 * @param array        $context  Request context.
			 */
			$binary = apply_filters(
				'wp_banana_generate_binary',
				$binary,
				[
					'provider'        => $provider,
					'model'           => $model_eff,
					'prompt'          => $prompt,
					'operation'       => 1 === $reference_count ? 'edit' : 'generate',
					'attachment_mode' => 1 === $reference_count ? 'single-reference' : 'prompt-only',
				]
			);
			if ( ! ( $binary instanceof Binary_Image ) ) {
				$message = __( 'Filtered provider output is invalid.', 'wp-banana' );
				$this->record_log(
					array_merge(
						$log_context,
						[
							'status'           => 'error',
							'response_time_ms' => (int) round( ( microtime( true ) - $operation_start ) * 1000 ),
							'error_message'    => $message,
							'error_code'       => 'invalid_binary',
						]
					)
				);
				return new WP_Error( 'wp_banana_invalid_binary', __( 'Filtered provider output is invalid.', 'wp-banana' ) );
			}

			// Normalize to requested format/size when applicable.
			try {
				$conv = new Convert_Service();
				$norm = $conv->normalize( $binary->bytes, $format, $normalize_width, $normalize_height );
			} catch ( \Throwable $e ) {
				$this->record_log(
					array_merge(
						$log_context,
						[
							'status'           => 'error',
							'response_time_ms' => (int) round( ( microtime( true ) - $operation_start ) * 1000 ),
							'error_message'    => $e->getMessage(),
							'error_code'       => $e->getCode() ? (string) $e->getCode() : '',
						]
					)
				);
				return new WP_Error( 'wp_banana_convert_failed', $e->getMessage() );
			}

			$timestamp = time();

			$filename_base = Attachment_Service::filename_from_prompt( $prompt, 'ai-image' );
			$title         = Attachment_Service::title_from_prompt( $prompt, __( 'AI Image', 'wp-banana' ) );

			try {
				$att   = new Attachment_Service();
				$saved = $att->save_new(
					$norm,
					$filename_base,
					[],
					null,
					[
						'action'    => 'generate',
						'provider'  => $provider,
						'model'     => $model_eff,
						'mode'      => 'new',
						'user_id'   => $current_user,
						'timestamp' => $timestamp,
						'prompt'    => $prompt,
					],
					$title
				);
			} catch ( \Throwable $e ) {
				$this->record_log(
					array_merge(
						$log_context,
						[
							'status'           => 'error',
							'response_time_ms' => (int) round( ( microtime( true ) - $operation_start ) * 1000 ),
							'error_message'    => $e->getMessage(),
							'error_code'       => $e->getCode() ? (string) $e->getCode() : '',
						]
					)
				);
				return new WP_Error( 'wp_banana_save_failed', $e->getMessage() );
			}

			$response_data = [
				'attachment_id' => $saved['attachment_id'],
				'url'           => $saved['url'],
			];
			/**
			 * Filters the REST response data returned by the generate endpoint.
			 *
			 * @since 0.2.0
			 *
			 * @param array $response_data Default response data.
			 * @param array $saved         Attachment save payload.
			 * @param array $context       Request context metadata.
			 */
			$response_data = apply_filters(
				'wp_banana_generate_response',
				$response_data,
				$saved,
				[
					'provider'  => $provider,
					'model'     => $model_eff,
					'prompt'    => $prompt,
					'user_id'   => $current_user,
					'timestamp' => $timestamp,
				]
			);
			$response_data = is_array( $response_data ) ? $response_data : [
				'attachment_id' => $saved['attachment_id'],
				'url'           => $saved['url'],
			];

			$this->record_log(
				array_merge(
					$log_context,
					[
						'status'           => 'success',
						'response_time_ms' => (int) round( ( microtime( true ) - $operation_start ) * 1000 ),
						'attachment_id'    => (int) $saved['attachment_id'],
						'response_payload' => [
							'attachment_id' => $saved['attachment_id'],
							'url'           => $saved['url'],
							'width'         => $norm->width,
							'height'        => $norm->height,
							'mime'          => $norm->mime,
						],
					]
				)
			);

			return new WP_REST_Response( $response_data, 200 );
		} finally {
			$this->cleanup_reference_images( $reference_images );
		}
	}

	/**
	 * Record a log entry while swallowing logging failures.
	 *
	 * @param array $payload Log payload.
	 * @return void
	 */
	private function record_log( array $payload ): void {
		if ( ! ( $this->logger instanceof Logging_Service ) ) {
			return;
		}

		try {
			$this->logger->record( $payload );
		} catch ( \Throwable $ignored ) {
			// Logging should never block the API; swallow unexpected failures.
		}
	}

	/**
	 * Sanitize aspect ratio string against supported values.
	 *
	 * @param string $ratio Raw aspect ratio.
	 * @return string
	 */
	private function sanitize_aspect_ratio( string $ratio ): string {
		return Aspect_Ratios::sanitize( $ratio );
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

		$max_allowed   = (int) apply_filters( 'wp_banana_generate_max_reference_images', self::MAX_REFERENCE_IMAGES, $req );
		$max_allowed   = $max_allowed >= 0 ? $max_allowed : self::MAX_REFERENCE_IMAGES;
		$allowed_mimes = apply_filters( 'wp_banana_generate_supported_reference_mime_types', self::SUPPORTED_REFERENCE_MIME, $req );
		if ( ! is_array( $allowed_mimes ) ) {
			$allowed_mimes = self::SUPPORTED_REFERENCE_MIME;
		}
		$allowed_mimes = array_values( array_filter( array_map( 'strval', $allowed_mimes ) ) );
		if ( empty( $allowed_mimes ) ) {
			$allowed_mimes = self::SUPPORTED_REFERENCE_MIME;
		}
		$allowed_lookup = array_map( 'strtolower', $allowed_mimes );
		$mime_overrides = Mime::build_sideload_overrides( $allowed_mimes );

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
			if ( $max_allowed >= 0 && count( $collected ) >= $max_allowed ) {
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
			$mime_lower = strtolower( $mime );
			if ( '' === $mime_lower || ! in_array( $mime_lower, $allowed_lookup, true ) ) {
				$this->cleanup_reference_images( $collected );
				return new WP_Error( 'wp_banana_reference_type', __( 'Reference image type is not allowed.', 'wp-banana' ) );
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
				'mimes'     => $mime_overrides,
			];

			$handled = wp_handle_sideload( $sideload_file, $upload_overrides );
			if ( is_wp_error( $handled ) || empty( $handled['file'] ) ) {
				$this->cleanup_reference_images( $collected );
				return new WP_Error( 'wp_banana_reference_copy_failed', __( 'Failed to persist uploaded reference image.', 'wp-banana' ) );
			}

			$target = (string) $handled['file'];
			$mime   = ! empty( $handled['type'] ) ? (string) $handled['type'] : $mime;

			$dimensions = @getimagesize( $target ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- getimagesize() can emit warnings for invalid files.
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
	 * Remove temporary files for collected reference images.
	 *
	 * @param array<Reference_Image> $images Reference images.
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
	 * Determine whether selected provider/model allows multi-image references.
	 *
	 * @param string $provider Provider slug.
	 * @param string $model    Model name.
	 * @return bool
	 */
	private function model_supports_multi_reference( string $provider, string $model ): bool {
		$provider = strtolower( trim( $provider ) );
		$model    = strtolower( trim( $model ) );

		if ( 'gemini' === $provider ) {
			return in_array( $model, [ 'gemini-2.5-flash-image', 'gemini-2.5-flash-image-preview' ], true );
		}
		if ( 'openai' === $provider ) {
			return in_array( $model, [ 'gpt-image-1', 'gpt-image-1-mini' ], true );
		}
		if ( 'replicate' === $provider ) {
			return in_array( $model, [ 'google/nano-banana', 'bytedance/seedream-4', 'reve/remix' ], true );
		}

		return false;
	}

	/**
	 * Map requested ratio to OpenAI-supported dimensions.
	 *
	 * @param string|null $ratio Requested aspect ratio.
	 * @return array{width:int,height:int,aspect_ratio:string}
	 */
	private function map_openai_dimensions( ?string $ratio ): array {
		$default = [
			'width'        => 1024,
			'height'       => 1024,
			'aspect_ratio' => '1:1',
		];

		if ( ! is_string( $ratio ) || '' === $ratio ) {
			return $default;
		}

		$ratio = strtoupper( trim( $ratio ) );
		if ( '1:1' === $ratio ) {
			return $default;
		}
		if ( '3:2' === $ratio ) {
			return [
				'width'        => 1536,
				'height'       => 1024,
				'aspect_ratio' => '3:2',
			];
		}
		if ( '2:3' === $ratio ) {
			return [
				'width'        => 1024,
				'height'       => 1536,
				'aspect_ratio' => '2:3',
			];
		}

		$parts = explode( ':', $ratio );
		if ( 2 === count( $parts ) ) {
			$left  = max( 1.0, (float) $parts[0] );
			$right = max( 1.0, (float) $parts[1] );
			if ( abs( $left - $right ) < 0.01 ) {
				return $default;
			}
			if ( $left > $right ) {
				return [
					'width'        => 1536,
					'height'       => 1024,
					'aspect_ratio' => '3:2',
				];
			}
			return [
				'width'        => 1024,
				'height'       => 1536,
				'aspect_ratio' => '2:3',
			];
		}

		return $default;
	}

	/**
	 * Compute canonical width/height pair for an aspect ratio.
	 *
	 * @param string $ratio Aspect ratio string (e.g. 16:9).
	 * @return array{0:int,1:int}
	 */
	private function dimensions_for_aspect_ratio( string $ratio ): array {
		$parts = explode( ':', $ratio );
		if ( 2 !== count( $parts ) ) {
			$parts = [ '1', '1' ];
		}
		$w = max( 1.0, (float) $parts[0] );
		$h = max( 1.0, (float) $parts[1] );

		if ( $w >= $h ) {
			$width  = self::TARGET_LONG_EDGE;
			$height = (int) round( self::TARGET_LONG_EDGE * $h / $w );
		} else {
			$height = self::TARGET_LONG_EDGE;
			$width  = (int) round( self::TARGET_LONG_EDGE * $w / $h );
		}

		$width  = max( 256, min( 4096, $width ) );
		$height = max( 256, min( 4096, $height ) );
		return [ $width, $height ];
	}

	/**
	 * Derive a supported aspect ratio from dimensions when possible.
	 *
	 * @param int $width  Width in pixels.
	 * @param int $height Height in pixels.
	 * @return string
	 */
	private function aspect_ratio_from_dimensions( int $width, int $height ): string {
		if ( $width <= 0 || $height <= 0 ) {
			return '';
		}
		$gcd = $this->gcd( $width, $height );
		if ( $gcd <= 0 ) {
			return '';
		}
		$numerator   = (int) round( $width / $gcd );
		$denominator = (int) round( $height / $gcd );
		if ( $numerator <= 0 || $denominator <= 0 ) {
			return '';
		}
		$ratio = $numerator . ':' . $denominator;
		return in_array( $ratio, Aspect_Ratios::all(), true ) ? $ratio : '';
	}

	/**
	 * Greatest common divisor calculation.
	 *
	 * @param int $a First integer.
	 * @param int $b Second integer.
	 * @return int
	 */
	private function gcd( int $a, int $b ): int {
		$a = abs( $a );
		$b = abs( $b );
		if ( 0 === $a ) {
			return $b;
		}
		if ( 0 === $b ) {
			return $a;
		}
		while ( 0 !== $b ) {
			$remainder = $a % $b;
			$a         = $b;
			$b         = $remainder;
		}
		return $a > 0 ? $a : 1;
	}
}
