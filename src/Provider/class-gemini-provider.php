<?php
/**
 * Gemini provider implementation.
 *
 * @package WPBanana\Provider
 * @since   0.1.0
 */

namespace WPBanana\Provider;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use RuntimeException;
use function esc_html;
use WPBanana\Domain\Binary_Image;
use WPBanana\Domain\Aspect_Ratios;
use WPBanana\Domain\Edit_Params;
use WPBanana\Domain\Image_Params;
use WPBanana\Domain\Reference_Image;
use WPBanana\Util\Http;

/**
 * Calls Gemini Native Image endpoints for generate and edit.
 */
final class Gemini_Provider implements Provider_Interface {

	/**
	 * Base API endpoint.
	 *
	 * @var string
	 */
	private $api_url = 'https://generativelanguage.googleapis.com/v1beta/models';

	/**
	 * Gemini API key.
	 *
	 * @var string
	 */
	private $api_key;

	/**
	 * Default model name.
	 *
	 * @var string
	 */
	private $default_model;

	/**
	 * Timeout in seconds.
	 *
	 * @var int
	 */
	private $timeout;

	/**
	 * Constructor.
	 *
	 * @param string $api_key       API key.
	 * @param string $default_model Default model.
	 */
	public function __construct( string $api_key, string $default_model ) {
		$this->api_key       = $api_key;
		$this->default_model = $default_model;
		$this->timeout       = 60; // Fixed 60s timeout for Gemini.
	}

	/**
	 * Generate an image from prompt.
	 *
	 * @param Image_Params $p Params.
	 * @return Binary_Image
	 * @throws RuntimeException If the API call fails or no image is returned.
	 */
	public function generate( Image_Params $p ): Binary_Image {
		$model = '' !== $p->model ? $p->model : $this->default_model;
		if ( '' === $this->api_key ) {
			throw new RuntimeException( 'Gemini API key missing.' );
		}
		if ( '' === $model ) {
			throw new RuntimeException( 'Gemini model not configured.' );
		}

		$model_config    = $this->resolve_model_config( $model, $p->resolution );
		$transport_model = $model_config['api_model'];

		$is_imagen = $this->is_imagen_model( $transport_model );
		if ( $is_imagen && ! empty( $p->reference_images ) ) {
			throw new RuntimeException( 'Imagen 4 models do not support reference images.' );
		}

		if ( $is_imagen ) {
			$url     = trailingslashit( $this->api_url ) . rawurlencode( $transport_model ) . ':predict';
			$payload = $this->build_imagen_generate_payload( $p, $transport_model );
		} else {
			$url     = trailingslashit( $this->api_url ) . rawurlencode( $transport_model ) . ':generateContent';
			$payload = $this->build_generate_payload( $p, $model_config );
		}

		$data = $this->perform_request(
			$url,
			$payload,
			[
				'operation' => 'generate',
				'model'     => $model,
				'api_model' => $transport_model,
				'prompt'    => $p->prompt,
				'endpoint'  => $is_imagen ? 'predict' : 'generateContent',
			]
		);
		$img  = $is_imagen ? $this->extract_imagen_image( $data ) : $this->extract_inline_image( $data );
		if ( ! $img ) {
			throw new RuntimeException( 'Gemini response did not include image bytes.' );
		}
		list( $bytes, $mime ) = $img;
		$size                 = getimagesizefromstring( $bytes );
		if ( false === $size || ! is_array( $size ) ) {
			throw new RuntimeException( 'Failed to determine image size.' );
		}
		return new Binary_Image( $bytes, $mime, (int) $size[0], (int) $size[1] );
	}

	/**
	 * Edit an image using prompt + input image.
	 *
	 * @param Edit_Params $p Params.
	 * @return Binary_Image
	 * @throws RuntimeException If the source image is not found, cannot be read, or no image is returned.
	 */
	public function edit( Edit_Params $p ): Binary_Image {
		$model = '' !== $p->model ? $p->model : $this->default_model;
		if ( '' === $this->api_key ) {
			throw new RuntimeException( 'Gemini API key missing.' );
		}
		if ( '' === $model ) {
			throw new RuntimeException( 'Gemini model not configured.' );
		}
		if ( $this->is_imagen_model( $model ) ) {
			throw new RuntimeException( 'Imagen 4 models do not support editing.' );
		}

		$model_config    = $this->resolve_model_config( $model, null );
		$transport_model = $model_config['api_model'];

		$url = trailingslashit( $this->api_url ) . rawurlencode( $transport_model ) . ':generateContent';

		if ( ! file_exists( $p->source_file ) ) {
			throw new RuntimeException( 'Source image not found' );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file read is required for API payload.
		$bytes = file_get_contents( $p->source_file );
		if ( false === $bytes ) {
			throw new RuntimeException( 'Failed to read source image' );
		}
		$ft      = wp_check_filetype( $p->source_file );
		$mime    = is_array( $ft ) && ! empty( $ft['type'] ) ? $ft['type'] : 'image/png';
		$payload = $this->build_edit_payload( $p, $mime, $bytes, $model_config );
		$data    = $this->perform_request(
			$url,
			$payload,
			[
				'operation'  => 'edit',
				'model'      => $model,
				'api_model'  => $transport_model,
				'prompt'     => $p->prompt,
				'sourceFile' => $p->source_file,
			]
		);
		$img     = $this->extract_inline_image( $data );
		if ( ! $img ) {
			throw new RuntimeException( 'Gemini response did not include edited image bytes.' );
		}
		list( $out, $out_mime ) = $img;
		$size                   = getimagesizefromstring( $out );
		if ( false === $size || ! is_array( $size ) ) {
			throw new RuntimeException( 'Failed to determine image size.' );
		}
		return new Binary_Image( $out, $out_mime, (int) $size[0], (int) $size[1] );
	}

	/**
	 * Provider capability hints.
	 *
	 * @param string $capability Capability string.
	 * @return bool
	 */
	public function supports( string $capability ): bool {
		if ( 'generate' === $capability || 'edit' === $capability ) {
			return true;
		}
		if ( 'formats:[png,webp,jpeg]' === $capability ) {
			return true;
		}
		if ( 0 === strpos( $capability, 'size:' ) ) {
			return true;
		}
		return false;
	}

	/**
	 * Extract first inline image bytes + mime from Gemini response.
	 *
	 * @param array|null $json Decoded JSON.
	 * @return array{0:string,1:string}|null [ bytes, mime ]
	 */
	private function extract_inline_image( $json ) {
		if ( ! is_array( $json ) ) {
			return null;
		}
		if ( empty( $json['candidates'][0]['content']['parts'] ) || ! is_array( $json['candidates'][0]['content']['parts'] ) ) {
			return null;
		}
		$parts = $json['candidates'][0]['content']['parts'];
		foreach ( $parts as $part ) {
			if ( isset( $part['inlineData']['data'] ) ) {
				$b64  = $part['inlineData']['data'];
				$mime = isset( $part['inlineData']['mimeType'] ) ? $part['inlineData']['mimeType'] : 'image/png';
				$bin  = base64_decode( $b64 ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Required for decoding API response.
				if ( false !== $bin ) {
					return [ $bin, $mime ];
				}
			}
			if ( isset( $part['inline_data']['data'] ) ) {
				$b64  = $part['inline_data']['data'];
				$mime = isset( $part['inline_data']['mime_type'] ) ? $part['inline_data']['mime_type'] : 'image/png';
				$bin  = base64_decode( $b64 ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Required for decoding API response.
				if ( false !== $bin ) {
					return [ $bin, $mime ];
				}
			}
		}
		return null;
	}

	/**
	 * Extract Imagen image bytes + mime from predict response.
	 *
	 * @param array|null $json Decoded JSON.
	 * @return array{0:string,1:string}|null
	 */
	private function extract_imagen_image( $json ) {
		if ( ! is_array( $json ) ) {
			return null;
		}
		if ( empty( $json['predictions'] ) || ! is_array( $json['predictions'] ) ) {
			return null;
		}
		$first = reset( $json['predictions'] );
		if ( ! is_array( $first ) ) {
			return null;
		}

		$b64_keys = [
			'bytesBase64Encoded',
			'bytes_base64_encoded',
		];
		$b64      = '';
		foreach ( $b64_keys as $key ) {
			if ( isset( $first[ $key ] ) && is_string( $first[ $key ] ) && '' !== $first[ $key ] ) {
				$b64 = $first[ $key ];
				break;
			}
		}
		if ( '' === $b64 ) {
			return null;
		}

		$mime_keys = [
			'mimeType',
			'mime_type',
		];
		$mime      = 'image/png';
		foreach ( $mime_keys as $key ) {
			if ( isset( $first[ $key ] ) && is_string( $first[ $key ] ) && '' !== $first[ $key ] ) {
				$mime = $first[ $key ];
				break;
			}
		}

		$bin = base64_decode( $b64 ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Required for decoding API response.
		if ( false === $bin ) {
			return null;
		}

		return [ $bin, $mime ];
	}

	/**
	 * Resolve the transport model id and related options.
	 *
	 * @param string      $model      Selected model id.
	 * @param string|null $resolution Optional resolution parameter (e.g. 1K, 2K, 4K).
	 * @return array{api_model:string,image_size:?string,supports_image_config:bool}
	 */
	private function resolve_model_config( string $model, ?string $resolution = null ): array {
		$normalized = strtolower( trim( $model ) );
		$config     = [
			'api_model'             => $model,
			'image_size'            => null,
			'supports_image_config' => false,
		];

		if ( 0 === strpos( $normalized, 'gemini-3-pro-image-preview' ) ) {
			$config['api_model']             = 'gemini-3-pro-image-preview';
			$config['supports_image_config'] = true;

			// Use explicit resolution parameter if provided.
			if ( null !== $resolution && '' !== $resolution ) {
				$config['image_size'] = $resolution;
			} else {
				// Fall back to extracting resolution from model name for backward compatibility.
				$suffix   = substr( $normalized, strlen( 'gemini-3-pro-image-preview' ) );
				$suffix   = ( '-' === substr( $suffix, 0, 1 ) ) ? substr( $suffix, 1 ) : $suffix;
				$size_map = [
					'1k' => '1K',
					'2k' => '2K',
					'4k' => '4K',
				];
				if ( isset( $size_map[ $suffix ] ) ) {
					$config['image_size'] = $size_map[ $suffix ];
				}
			}
		}

		return $config;
	}

	/**
	 * Build payload for image generation requests.
	 *
	 * @param Image_Params $params Generation parameters.
	 * @param array        $model_config Model configuration details.
	 * @return array
	 * @throws RuntimeException If reference image reading/encoding fails.
	 */
	private function build_generate_payload( Image_Params $params, array $model_config = [] ): array {
		$prompt = $this->normalize_prompt( $params->prompt );
		$parts  = [
			[ 'text' => $prompt ],
		];

		foreach ( $params->reference_images as $reference ) {
			if ( ! ( $reference instanceof Reference_Image ) ) {
				continue;
			}
			if ( ! file_exists( $reference->path ) ) {
				continue;
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file read is required for API payload.
			$bytes = file_get_contents( $reference->path );
			if ( false === $bytes ) {
				throw new RuntimeException( 'Failed to read reference image for Gemini.' );
			}
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Required for API payload encoding.
			$encoded = base64_encode( $bytes );
			if ( false === $encoded ) {
				throw new RuntimeException( 'Failed to encode reference image for Gemini.' );
			}
			$parts[] = [
				'inline_data' => [
					'mime_type' => $reference->mime ?: 'image/png',
					'data'      => $encoded,
				],
			];
		}

		$payload = [
			'contents'         => [
				[
					'role'  => 'user',
					'parts' => $parts,
				],
			],
			'generationConfig' => [
				'responseModalities' => [ 'IMAGE' ],
			],
		];

		$image_config = $this->build_image_config_from_generate_params( $params, $model_config );
		if ( ! empty( $image_config ) ) {
			$payload['generationConfig']['imageConfig'] = $image_config;
		}

		return $payload;
	}

	/**
	 * Derive imageConfig payload for generation requests.
	 *
	 * @param Image_Params $params       Generation parameters.
	 * @param array        $model_config Model configuration.
	 * @return array<string,string>
	 */
	private function build_image_config_from_generate_params( Image_Params $params, array $model_config ): array {
		if ( empty( $model_config['supports_image_config'] ) ) {
			return [];
		}

		$config = [];
		$aspect = $this->sanitize_aspect_ratio_value( $params->aspect_ratio );
		if ( '' === $aspect ) {
			$aspect = $this->aspect_ratio_from_dimensions_full( (int) $params->width, (int) $params->height );
		}
		if ( '' !== $aspect ) {
			$config['aspectRatio'] = $aspect;
		}
		if ( isset( $model_config['image_size'] ) && is_string( $model_config['image_size'] ) && '' !== $model_config['image_size'] ) {
			$config['imageSize'] = $model_config['image_size'];
		}
		return $config;
	}

	/**
	 * Derive imageConfig payload for edit requests.
	 *
	 * @param Edit_Params $params       Edit parameters.
	 * @param array       $model_config Model configuration.
	 * @return array<string,string>
	 */
	private function build_image_config_from_edit_params( Edit_Params $params, array $model_config ): array {
		if ( empty( $model_config['supports_image_config'] ) ) {
			return [];
		}

		$config = [];
		$aspect = $this->aspect_ratio_from_dimensions_full( (int) $params->target_width, (int) $params->target_height );
		if ( '' !== $aspect ) {
			$config['aspectRatio'] = $aspect;
		}
		if ( isset( $model_config['image_size'] ) && is_string( $model_config['image_size'] ) && '' !== $model_config['image_size'] ) {
			$config['imageSize'] = $model_config['image_size'];
		}
		return $config;
	}

	/**
	 * Build Imagen predict payload.
	 *
	 * @param Image_Params $params Image parameters.
	 * @param string       $model  Model name.
	 * @return array
	 */
	private function build_imagen_generate_payload( Image_Params $params, string $model ): array {
		$prompt     = $this->normalize_prompt( $params->prompt );
		$image_size = $this->determine_imagen_image_size( $params, $model );
		$aspect     = $this->determine_imagen_aspect_ratio( $params );
		$parameters = [
			'sampleCount'      => 1,
			'imageSize'        => $image_size,
			'aspectRatio'      => $aspect,
			'personGeneration' => 'dont_allow',
		];
		$context    = [
			'model'  => $model,
			'prompt' => $params->prompt,
			'width'  => $params->width,
			'height' => $params->height,
		];
		/**
		 * Filters Imagen 4 generation parameters before the request.
		 *
		 * @since 0.2.2
		 *
		 * @param array $parameters Imagen request parameters.
		 * @param array $context    Request context (model, dimensions, prompt).
		 */
		$parameters = apply_filters( 'wp_banana_gemini_imagen_parameters', $parameters, $context );
		if ( ! is_array( $parameters ) ) {
			$parameters = [];
		}
		$parameters = array_filter(
			$parameters,
			static function ( $value ) {
				return null !== $value && '' !== $value;
			}
		);

		$payload = [
			'instances'  => [
				[
					'prompt' => $prompt,
				],
			],
			'parameters' => $parameters,
		];

		/**
		 * Filters the Imagen 4 request payload.
		 *
		 * @since 0.2.2
		 *
		 * @param array $payload Request payload prior to encoding.
		 * @param array $context Request context (model, dimensions, prompt).
		 */
		$payload = apply_filters( 'wp_banana_gemini_imagen_request', $payload, $context );

		return is_array( $payload ) ? $payload : [
			'instances'  => [
				[
					'prompt' => $prompt,
				],
			],
			'parameters' => $parameters,
		];
	}

	/**
	 * Determine Imagen image size hint from parameters.
	 *
	 * @param Image_Params $params Image parameters.
	 * @param string       $model  Model name.
	 * @return string
	 */
	private function determine_imagen_image_size( Image_Params $params, string $model ): string {
		$width    = max( 0, (int) $params->width );
		$height   = max( 0, (int) $params->height );
		$longedge = max( $width, $height );

		if ( false !== strpos( strtolower( $model ), 'fast' ) ) {
			return '1K';
		}
		if ( $longedge >= 1536 || false !== strpos( strtolower( $model ), 'ultra' ) ) {
			return '2K';
		}
		return '1K';
	}

	/**
	 * Determine Imagen aspect ratio from parameters.
	 *
	 * @param Image_Params $params Image parameters.
	 * @return string
	 */
	private function determine_imagen_aspect_ratio( Image_Params $params ): string {
		$allowed = [
			'1:1',
			'3:4',
			'4:3',
			'9:16',
			'16:9',
		];

		$ratio = '';
		if ( is_string( $params->aspect_ratio ) && '' !== $params->aspect_ratio ) {
			$ratio = strtoupper( str_replace( ' ', '', $params->aspect_ratio ) );
		}
		if ( '' === $ratio ) {
			$ratio = $this->aspect_ratio_from_dimensions( (int) $params->width, (int) $params->height );
		}
		if ( in_array( $ratio, $allowed, true ) ) {
			return $ratio;
		}
		return '1:1';
	}

	/**
	 * Compute Imagen aspect ratio from width/height.
	 *
	 * @param int $width  Width in pixels.
	 * @param int $height Height in pixels.
	 * @return string
	 */
	private function aspect_ratio_from_dimensions( int $width, int $height ): string {
		if ( $width <= 0 || $height <= 0 ) {
			return '1:1';
		}
		$ratio        = $width / max( 1, $height );
		$map          = [
			'1:1'  => 1.0,
			'3:4'  => 0.75,
			'4:3'  => 4.0 / 3.0,
			'9:16' => 0.5625,
			'16:9' => 16.0 / 9.0,
		];
		$closest_key  = '1:1';
		$closest_diff = null;
		foreach ( $map as $key => $value ) {
			$diff = abs( $ratio - $value );
			if ( null === $closest_diff || $diff < $closest_diff ) {
				$closest_diff = $diff;
				$closest_key  = $key;
			}
		}
		return $closest_key;
	}

	/**
	 * Map requested or derived ratio to supported Gemini ratios.
	 *
	 * @param int $width  Width.
	 * @param int $height Height.
	 * @return string
	 */
	private function aspect_ratio_from_dimensions_full( int $width, int $height ): string {
		if ( $width <= 0 || $height <= 0 ) {
			return '';
		}
		$ratio         = $width / max( 1, $height );
		$closest_ratio = '';
		$closest_diff  = null;

		foreach ( Aspect_Ratios::all() as $candidate ) {
			$parts = explode( ':', $candidate );
			if ( 2 !== count( $parts ) ) {
				continue;
			}
			$left  = max( 0.001, (float) $parts[0] );
			$right = max( 0.001, (float) $parts[1] );
			$value = $left / $right;
			$diff  = abs( $ratio - $value );
			if ( null === $closest_diff || $diff < $closest_diff ) {
				$closest_diff  = $diff;
				$closest_ratio = strtoupper( $candidate );
			}
		}

		return $closest_ratio;
	}

	/**
	 * Sanitize an aspect ratio value.
	 *
	 * @param string|null $ratio Raw ratio.
	 * @return string
	 */
	private function sanitize_aspect_ratio_value( $ratio ): string {
		if ( ! is_string( $ratio ) ) {
			return '';
		}
		$canonical = Aspect_Ratios::sanitize( $ratio );
		if ( '' !== $canonical ) {
			return $canonical;
		}
		$trimmed = strtoupper( trim( $ratio ) );
		if ( preg_match( '/^[0-9]+:[0-9]+$/', $trimmed ) ) {
			return $trimmed;
		}
		return '';
	}

	/**
	 * Whether the provided model id is an Imagen 4 variant.
	 *
	 * @param string $model Model name.
	 * @return bool
	 */
	private function is_imagen_model( string $model ): bool {
		$model = strtolower( $model );
		return 0 === strpos( $model, 'imagen-4.0-' );
	}

	/**
	 * Build payload for edit requests with inline image data.
	 *
	 * @param Edit_Params $params Edit parameters.
	 * @param string      $mime   Source MIME type.
	 * @param string      $bytes  Source image bytes.
	 * @param array       $model_config Model configuration details.
	 * @return array
	 * @throws RuntimeException If reference image reading/encoding fails.
	 */
	private function build_edit_payload( Edit_Params $params, string $mime, string $bytes, array $model_config ): array {
		$prompt = $this->normalize_prompt( $params->prompt );
		$parts  = [
			[ 'text' => $prompt ],
		];

		foreach ( $params->reference_images as $reference ) {
			if ( ! ( $reference instanceof Reference_Image ) ) {
				continue;
			}
			if ( ! file_exists( $reference->path ) ) {
				continue;
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file read required for payload encoding.
			$ref_bytes = file_get_contents( $reference->path );
			if ( false === $ref_bytes ) {
				throw new RuntimeException( 'Failed to read reference image for Gemini edit.' );
			}
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Required for API payload encoding.
			$ref_encoded = base64_encode( $ref_bytes );
			if ( false === $ref_encoded ) {
				throw new RuntimeException( 'Failed to encode reference image for Gemini edit.' );
			}
			$parts[] = [
				'inline_data' => [
					'mime_type' => $reference->mime ?: 'image/png',
					'data'      => $ref_encoded,
				],
			];
		}

		// Append original image last.
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Required for API payload encoding.
		$encoded = base64_encode( $bytes );
		if ( false === $encoded ) {
			throw new RuntimeException( 'Failed to encode source image for Gemini edit.' );
		}
		$parts[] = [
			'inline_data' => [
				'mime_type' => $mime,
				'data'      => $encoded,
			],
		];

		$payload = [
			'contents'         => [
				[
					'role'  => 'user',
					'parts' => $parts,
				],
			],
			'generationConfig' => [
				'responseModalities' => [ 'IMAGE' ],
			],
		];

		$image_config = $this->build_image_config_from_edit_params( $params, $model_config );
		if ( ! empty( $image_config ) ) {
			$payload['generationConfig']['imageConfig'] = $image_config;
		}

		return $payload;
	}

	/**
	 * Execute request and return decoded JSON or throw RuntimeException.
	 *
	 * @param string $url     Endpoint.
	 * @param array  $payload Request payload.
	 * @param array  $context Additional context (operation, model, prompt... ).
	 * @return array
	 * @throws RuntimeException If the request fails or response is invalid.
	 */
	private function perform_request( string $url, array $payload, array $context ): array {
		list( $url, $args, $_payload, $request_context ) = $this->prepare_request( $url, $payload, $context );
		$res = Http::request( $url, $args );
		if ( is_wp_error( $res ) ) {
			throw new RuntimeException( esc_html( $res->get_error_message() ) );
		}

		$body = wp_remote_retrieve_body( $res );
		$data = json_decode( $body, true );

		/**
		 * Filters the decoded Gemini response.
		 *
		 * @since 0.2.0
		 *
		 * @param mixed $data            Decoded response payload.
		 * @param array $request_context Request context used for the call.
		 * @param array $res             Raw HTTP response.
		 */
		$data = apply_filters( 'wp_banana_provider_decoded_response', $data, $request_context, $res );
		$data = apply_filters( 'wp_banana_gemini_decoded_response', $data, $request_context, $res );
		if ( ! is_array( $data ) ) {
			throw new RuntimeException( 'Invalid response from Gemini.' );
		}
		if ( isset( $data['error'] ) ) {
			throw new RuntimeException( esc_html( $this->stringify_error( $data['error'] ) ) );
		}

		return $data;
	}

	/**
	 * Apply provider request filters and build transport arguments.
	 *
	 * @param string $url      Endpoint URL.
	 * @param mixed  $payload  Request payload prior to encoding.
	 * @param array  $context  Additional context (operation, model, prompt... ).
	 * @return array{0:string,1:array,2:mixed,3:array}
	 */
	private function prepare_request( string $url, $payload, array $context ): array {
		$args        = [
			'method'  => 'POST',
			'timeout' => $this->timeout,
			'headers' => [
				'x-goog-api-key' => $this->api_key,
				'Content-Type'   => 'application/json',
			],
		];
		$context     = $this->normalize_request_context( $context, 'json' );
			$request = [
				'url'     => $url,
				'args'    => $args,
				'payload' => $payload,
			];
			/**
			 * Filters the outbound Gemini request prior to dispatch.
			 *
			 * @since 0.2.0
			 *
			 * @param array $request Request data (URL, args, payload).
			 * @param array $context Contextual metadata for the request.
			 */
			$request = apply_filters( 'wp_banana_provider_http_request', $request, $context );
			$request = apply_filters( 'wp_banana_gemini_http_request', $request, $context );
			$url     = isset( $request['url'] ) ? (string) $request['url'] : $url;
			$args    = isset( $request['args'] ) && is_array( $request['args'] ) ? $request['args'] : $args;
			$payload = array_key_exists( 'payload', $request ) ? $request['payload'] : $payload;
			if ( ! isset( $args['body'] ) ) {
				$args['body'] = $this->encode_payload( $payload );
			}
			return [ $url, $args, $payload, $context ];
	}

	/**
	 * Normalize request context for hook consumers.
	 *
	 * @param array  $context Caller-provided context.
	 * @param string $format  Transport format identifier.
	 * @return array
	 */
	private function normalize_request_context( array $context, string $format ): array {
		$context['provider']       = 'gemini';
		$context['request_format'] = $format;
		if ( ! isset( $context['operation'] ) ) {
			$context['operation'] = '';
		}
		if ( ! isset( $context['model'] ) ) {
			$context['model'] = '';
		}
		return $context;
	}

	/**
	 * Encode payload for Gemini JSON endpoints.
	 *
	 * @param mixed $payload Request payload.
	 * @return string|mixed
	 */
	private function encode_payload( $payload ) {
		if ( is_string( $payload ) || null === $payload ) {
			return $payload;
		}
		if ( is_array( $payload ) || is_object( $payload ) ) {
			return wp_json_encode( $payload );
		}
		return wp_json_encode( $payload );
	}

	/**
	 * Normalize prompt for transport.
	 *
	 * @param string $prompt Prompt text.
	 * @return string
	 */
	private function normalize_prompt( string $prompt ): string {
		return trim( preg_replace( '/[\r\n]+/', ' ', $prompt ) );
	}

	/**
	 * Turn Gemini error payload into readable message.
	 *
	 * @param mixed $error Error payload.
	 * @return string
	 */
	private function stringify_error( $error ): string {
		if ( is_string( $error ) ) {
			return $error;
		}
		if ( is_array( $error ) ) {
			if ( isset( $error['message'] ) && is_string( $error['message'] ) ) {
				return $error['message'];
			}
			return wp_json_encode( $error );
		}
		return 'Gemini API error';
	}
}
