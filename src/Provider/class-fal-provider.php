<?php
/**
 * Fal.ai provider implementation.
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
use WPBanana\Domain\Edit_Params;
use WPBanana\Domain\Image_Params;
use WPBanana\Domain\Reference_Image;
use WPBanana\Util\Http;

/**
 * Fal.ai API integration for text-to-image and image editing.
 */
final class Fal_Provider implements Provider_Interface {

	/**
	 * Base URL for Fal.ai model endpoints.
	 *
	 * @var string
	 */
	private $api_url = 'https://fal.run';

	/**
	 * Fal.ai API key.
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
		$this->timeout       = 120;
	}

	/**
	 * Generate an image via Fal.ai.
	 *
	 * @param Image_Params $p Params.
	 * @return Binary_Image
	 * @throws RuntimeException If the request fails or no image can be downloaded.
	 */
	public function generate( Image_Params $p ): Binary_Image {
		$prompt = $this->normalize_prompt( $p->prompt );
		$model  = '' !== $p->model ? $p->model : $this->default_model;
		$config = $this->resolve_model_config( $model, $p->width, $p->height, $p->aspect_ratio );

		if ( '' === $model ) {
			throw new RuntimeException( 'Fal.ai model not configured.' );
		}

		$input = [
			'prompt'     => $prompt,
			'num_images' => 1,
		];

		if ( ! empty( $config['image_size'] ) ) {
			$input['image_size'] = $config['image_size'];
		}

		$output_format = $this->format_for_request( $p->format );
		if ( '' !== $output_format ) {
			$input['output_format'] = $output_format;
		}

		if ( ! empty( $p->reference_images ) ) {
			$input = $this->apply_reference_images( $model, $input, $p->reference_images );
		}

		$data                 = $this->request_generation(
			$model,
			$input,
			[
				'operation' => 'generate',
				'model'     => $model,
				'prompt'    => $prompt,
			]
		);
		$url                  = $this->extract_output_url( $data );
		list( $bytes, $mime ) = $this->download_image( $url );

		$size = getimagesizefromstring( $bytes );
		if ( false === $size || ! is_array( $size ) ) {
			throw new RuntimeException( 'Failed to determine image size from Fal.ai output.' );
		}

		return new Binary_Image( $bytes, $mime, (int) $size[0], (int) $size[1] );
	}

	/**
	 * Edit an image via Fal.ai editor models.
	 *
	 * @param Edit_Params $p Params.
	 * @return Binary_Image
	 * @throws RuntimeException If the file cannot be read or the API fails.
	 */
	public function edit( Edit_Params $p ): Binary_Image {
		$model = '' !== $p->model ? $p->model : $this->default_model;

		if ( '' === $model ) {
			throw new RuntimeException( 'Fal.ai model not configured.' );
		}

		if ( ! file_exists( $p->source_file ) ) {
			throw new RuntimeException( 'Source image not found.' );
		}

		$prompt   = $this->normalize_prompt( $p->prompt );
		$data_uri = $this->data_uri_for_file( $p->source_file );
		$input    = $this->build_edit_payload( $model, $prompt, $data_uri, $p );

		$data = $this->request_generation(
			$model,
			$input,
			[
				'operation'  => 'edit',
				'model'      => $model,
				'prompt'     => $prompt,
				'sourceFile' => $p->source_file,
			]
		);

		$url                  = $this->extract_output_url( $data );
		list( $bytes, $mime ) = $this->download_image( $url );

		$size = getimagesizefromstring( $bytes );
		if ( false === $size || ! is_array( $size ) ) {
			throw new RuntimeException( 'Failed to determine image size from Fal.ai output.' );
		}

		return new Binary_Image( $bytes, $mime, (int) $size[0], (int) $size[1] );
	}

	/**
	 * Capability hints.
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
	 * Resolve model to api_model and image_size hints.
	 *
	 * @param string      $model        Selected model identifier.
	 * @param int|null    $width        Target width.
	 * @param int|null    $height       Target height.
	 * @param string|null $aspect_ratio Aspect ratio hint.
	 * @return array{api_model:string,image_size:?string}
	 */
	private function resolve_model_config( string $model, ?int $width = null, ?int $height = null, ?string $aspect_ratio = null ): array {
		$config = [
			'api_model'  => $model,
			'image_size' => null,
		];

		$normalized = strtolower( trim( $model ) );

		if ( $this->model_is_seedream( $normalized ) ) {
			$config['image_size'] = $this->seedream_image_size_for_dimensions( $width, $height, $aspect_ratio );
			return $config;
		}

		if ( null !== $width && null !== $height ) {
			$size_string = $this->dimensions_to_fal_size( $width, $height );
			if ( '' !== $size_string ) {
				$config['image_size'] = $size_string;
			}
		} elseif ( ! empty( $aspect_ratio ) ) {
			$config['image_size'] = $this->aspect_ratio_to_fal_size( $aspect_ratio );
		}

		return $config;
	}

	/**
	 * Convert width/height to Fal.ai image_size string.
	 *
	 * @param int $width  Width in pixels.
	 * @param int $height Height in pixels.
	 * @return string
	 */
	private function dimensions_to_fal_size( int $width, int $height ): string {
		$width  = max( 256, min( 2048, $width ) );
		$height = max( 256, min( 2048, $height ) );
		return $width . 'x' . $height;
	}

	/**
	 * Map aspect ratio to Fal.ai image_size string.
	 *
	 * @param string $aspect_ratio Aspect ratio (e.g. "1:1", "16:9").
	 * @return string
	 */
	private function aspect_ratio_to_fal_size( string $aspect_ratio ): string {
		$ratio = strtoupper( trim( $aspect_ratio ) );

		$map = [
			'1:1'  => '1024x1024',
			'16:9' => '1920x1080',
			'9:16' => '1080x1920',
			'4:3'  => '1024x768',
			'3:4'  => '768x1024',
			'3:2'  => '1024x683',
			'2:3'  => '683x1024',
		];

		return $map[ $ratio ] ?? '1024x1024';
	}

	/**
	 * Normalize prompt string for API usage.
	 *
	 * @param string $prompt Prompt.
	 * @return string
	 */
	private function normalize_prompt( string $prompt ): string {
		$prompt = trim( preg_replace( '/[\r\n]+/', ' ', $prompt ) );
		return $prompt;
	}

	/**
	 * Perform Fal.ai generation request and return decoded payload.
	 *
	 * @param string $model   Model name.
	 * @param array  $input   Input payload.
	 * @param array  $context Request context metadata.
	 * @return array
	 * @throws RuntimeException If the HTTP call fails or the response is invalid.
	 */
	private function request_generation( string $model, array $input, array $context ): array {
		if ( '' === $this->api_key ) {
			throw new RuntimeException( 'Fal.ai API key missing.' );
		}

		$url     = trailingslashit( $this->api_url ) . ltrim( $model, '/' );
		$args    = [
			'method'  => 'POST',
			'timeout' => $this->timeout,
			'headers' => [
				'Authorization' => 'Key ' . $this->api_key,
				'Content-Type'  => 'application/json',
			],
		];
		$payload = $input;

		list( $url, $args, $_payload, $request_context ) = $this->prepare_request( $url, $args, $payload, $context, 'json' );
		$response                                        = Http::request( $url, $args );

		if ( is_wp_error( $response ) ) {
			throw new RuntimeException( esc_html( $response->get_error_message() ) );
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		$data = apply_filters( 'wp_banana_provider_decoded_response', $data, $request_context, $response );
		$data = apply_filters( 'wp_banana_fal_decoded_response', $data, $request_context, $response );

		if ( ! is_array( $data ) ) {
			throw new RuntimeException( 'Invalid response from Fal.ai.' );
		}

		if ( isset( $data['error'] ) && ! empty( $data['error'] ) ) {
			$message = 'Fal.ai API error: ' . $this->stringify_error( $data['error'] );
			throw new RuntimeException( esc_html( $message ) );
		}

		return $data;
	}

	/**
	 * Apply request filters and finish assembling HTTP arguments.
	 *
	 * @param string $url       Endpoint URL.
	 * @param array  $args      Baseline HTTP arguments.
	 * @param mixed  $payload   Raw payload before transport encoding.
	 * @param array  $context   Request context metadata.
	 * @param string $format    Transport format identifier.
	 * @param bool   $auto_body Whether to set args['body'] automatically.
	 * @return array{0:string,1:array,2:mixed,3:array}
	 */
	private function prepare_request( string $url, array $args, $payload, array $context, string $format, bool $auto_body = true ): array {
		$context = $this->normalize_request_context( $context, $format );
		$request = [
			'url'     => $url,
			'args'    => $args,
			'payload' => $payload,
		];

		$request = apply_filters( 'wp_banana_provider_http_request', $request, $context );
		$request = apply_filters( 'wp_banana_fal_http_request', $request, $context );

		$url     = isset( $request['url'] ) ? (string) $request['url'] : $url;
		$args    = isset( $request['args'] ) && is_array( $request['args'] ) ? $request['args'] : $args;
		$payload = array_key_exists( 'payload', $request ) ? $request['payload'] : $payload;

		if ( $auto_body && ! isset( $args['body'] ) ) {
			$args['body'] = $this->encode_body( $payload, $format );
		}

		return [ $url, $args, $payload, $context ];
	}

	/**
	 * Normalise request context for hooks.
	 *
	 * @param array  $context Context supplied by caller.
	 * @param string $format  Transport format.
	 * @return array
	 */
	private function normalize_request_context( array $context, string $format ): array {
		$context['provider']       = 'fal';
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
	 * Encode payload for transport.
	 *
	 * @param mixed  $payload Payload prior to encoding.
	 * @param string $format  Transport format identifier.
	 * @return mixed
	 */
	private function encode_body( $payload, string $format ) {
		if ( 'json' === $format ) {
			if ( is_string( $payload ) || null === $payload ) {
				return $payload;
			}
			if ( is_array( $payload ) || is_object( $payload ) ) {
				return wp_json_encode( $payload );
			}
			return wp_json_encode( $payload );
		}
		return $payload;
	}

	/**
	 * Extract the first output URL from the response payload.
	 *
	 * @param mixed $data Response data.
	 * @return string
	 * @throws RuntimeException When no valid URL is found.
	 */
	private function extract_output_url( $data ): string {
		if ( ! is_array( $data ) ) {
			throw new RuntimeException( 'Fal.ai response is invalid.' );
		}

		if ( isset( $data['images'] ) && is_array( $data['images'] ) && ! empty( $data['images'] ) ) {
			$first = reset( $data['images'] );
			if ( is_array( $first ) && isset( $first['url'] ) && is_string( $first['url'] ) && '' !== $first['url'] ) {
				return $first['url'];
			}
		}

		if ( isset( $data['image'] ) && is_array( $data['image'] ) && isset( $data['image']['url'] ) ) {
			return (string) $data['image']['url'];
		}

		if ( isset( $data['url'] ) && is_string( $data['url'] ) && '' !== $data['url'] ) {
			return $data['url'];
		}

		throw new RuntimeException( 'Fal.ai response missing output image URL.' );
	}

	/**
	 * Download image bytes from a URL.
	 *
	 * @param string $url Image URL.
	 * @return array{0:string,1:string} [ bytes, mime ]
	 * @throws RuntimeException If the download fails.
	 */
	private function download_image( string $url ): array {
		$args = [
			'timeout'   => $this->timeout,
			'sslverify' => true,
		];

		list( $url, $args, $_payload, $_context ) = $this->prepare_request(
			$url,
			$args,
			null,
			[
				'operation' => 'download',
				'model'     => '',
			],
			'get',
			false
		);

		$res = wp_remote_get( $url, $args );

		if ( is_wp_error( $res ) ) {
			throw new RuntimeException( esc_html( $res->get_error_message() ) );
		}

		$code = (int) wp_remote_retrieve_response_code( $res );
		if ( 200 !== $code ) {
			$message = sprintf( 'Failed to download Fal.ai image (HTTP %d).', (int) $code );
			throw new RuntimeException( esc_html( $message ) );
		}

		$body = wp_remote_retrieve_body( $res );
		if ( '' === $body ) {
			throw new RuntimeException( 'Fal.ai returned empty image data.' );
		}

		$mime = $this->mime_from_response( $res, $body );
		return [ $body, $mime ];
	}

	/**
	 * Determine MIME type from HTTP response or bytes.
	 *
	 * @param array  $response HTTP response array.
	 * @param string $body     Image bytes.
	 * @return string
	 */
	private function mime_from_response( array $response, string $body ): string {
		$header = wp_remote_retrieve_header( $response, 'content-type' );
		if ( is_string( $header ) && '' !== $header ) {
			$parts = explode( ';', strtolower( $header ) );
			return trim( $parts[0] );
		}
		$info = getimagesizefromstring( $body );
		if ( is_array( $info ) && isset( $info['mime'] ) ) {
			return $info['mime'];
		}
		return 'image/png';
	}

	/**
	 * Map plugin format slug to Fal.ai expected values.
	 *
	 * @param string $format Requested format.
	 * @return string
	 */
	private function format_for_request( string $format ): string {
		if ( 'png' === $format ) {
			return 'png';
		}
		if ( 'jpeg' === $format || 'jpg' === $format ) {
			return 'jpeg';
		}
		if ( 'webp' === $format ) {
			return 'webp';
		}
		return '';
	}

	/**
	 * Inject reference images into Fal.ai generation payload.
	 *
	 * @param string                 $model      Model name.
	 * @param array<string,mixed>    $input      Existing input payload.
	 * @param array<Reference_Image> $references Reference images.
	 * @return array<string,mixed>
	 * @throws RuntimeException If no valid reference images are provided.
	 */
	private function apply_reference_images( string $model, array $input, array $references ): array {
		$data_uris = [];
		foreach ( $references as $reference ) {
			if ( ! ( $reference instanceof Reference_Image ) ) {
				continue;
			}
			if ( ! file_exists( $reference->path ) ) {
				continue;
			}
			$data_uris[] = $this->data_uri_for_file( $reference->path );
		}

		if ( empty( $data_uris ) ) {
			throw new RuntimeException( 'No valid reference images provided for Fal.ai.' );
		}

		$normalized = strtolower( $model );

		if ( false !== strpos( $normalized, 'flux' ) ) {
			if ( 1 === count( $data_uris ) ) {
				$input['image_url'] = $data_uris[0];
			} else {
				$input['image_urls'] = $data_uris;
			}
		} else {
			$input['image_url'] = $data_uris[0];
		}

		return $input;
	}

	/**
	 * Create data URI string for a local file.
	 *
	 * @param string $file_path Absolute file path.
	 * @return string
	 * @throws RuntimeException If the file cannot be read.
	 */
	private function data_uri_for_file( string $file_path ): string {
		$bytes = file_get_contents( $file_path );
		if ( false === $bytes ) {
			throw new RuntimeException( 'Failed to read source image for Fal.ai.' );
		}
		$type    = wp_check_filetype( basename( $file_path ) );
		$mime    = is_array( $type ) && ! empty( $type['type'] ) ? $type['type'] : ( function_exists( 'mime_content_type' ) ? (string) mime_content_type( $file_path ) : 'image/png' );
		$encoded = base64_encode( $bytes );
		if ( false === $encoded ) {
			throw new RuntimeException( 'Failed to encode image for Fal.ai.' );
		}
		return 'data:' . $mime . ';base64,' . $encoded;
	}

	/**
	 * Build input payload for edit requests.
	 *
	 * @param string      $model    Model name.
	 * @param string      $prompt   Prompt text.
	 * @param string      $data_uri Data URI for source image.
	 * @param Edit_Params $params   Edit parameters.
	 * @return array
	 */
	private function build_edit_payload( string $model, string $prompt, string $data_uri, Edit_Params $params ): array {
		$input = [
			'prompt'     => $prompt,
			'num_images' => 1,
		];

		$image_uris = [ $data_uri ];

		if ( ! empty( $params->reference_images ) ) {
			foreach ( $params->reference_images as $reference ) {
				if ( ! ( $reference instanceof Reference_Image ) ) {
					continue;
				}
				if ( ! file_exists( $reference->path ) ) {
					continue;
				}
				$image_uris[] = $this->data_uri_for_file( $reference->path );
			}
		}

		if ( $this->model_uses_image_urls( $model ) ) {
			$input['image_urls'] = array_values( $image_uris );
		} else {
			$input['image_url'] = $image_uris[0];
		}

		$format = $this->format_for_request( $params->format );
		if ( '' !== $format ) {
			$input['output_format'] = $format;
		}

		if ( $params->target_width > 0 && $params->target_height > 0 ) {
			if ( $this->model_is_seedream( $model ) ) {
				$input['image_size'] = $this->seedream_image_size_for_dimensions( $params->target_width, $params->target_height, null );
			} elseif ( ! $this->model_uses_named_image_size( $model ) ) {
				$input['image_size'] = $this->dimensions_to_fal_size( $params->target_width, $params->target_height );
			}
		}

		return $input;
	}

	/**
	 * Check whether the model expects `image_urls` instead of `image_url`.
	 *
	 * @param string $model Model identifier.
	 * @return bool
	 */
	private function model_uses_image_urls( string $model ): bool {
		$normalized = strtolower( trim( $model ) );

		// Reve's single-image edit endpoint uses `image_url`.
		if ( false !== strpos( $normalized, 'reve/edit' ) && false === strpos( $normalized, 'remix' ) ) {
			return false;
		}

		// Most fal edit/remix endpoints (Flux 2, Nano Banana, Gemini image edit, Reve remix) use `image_urls`.
		return false !== strpos( $normalized, '/edit' ) || false !== strpos( $normalized, 'remix' );
	}

	/**
	 * Check if the model expects named/object image size values instead of `WxH`.
	 *
	 * @param string $model Model identifier.
	 * @return bool
	 */
	private function model_uses_named_image_size( string $model ): bool {
		$normalized = strtolower( trim( $model ) );

		$needle = [
			'flux-2/edit',
			'flux-2-pro/edit',
			'flux-2-max/edit',
			'flux-2/turbo/edit',
			'flux-2/flash/edit',
			'flux-2-flex/edit',
		];

		foreach ( $needle as $item ) {
			if ( false !== strpos( $normalized, $item ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Check if model is ByteDance Seedream on Fal.
	 *
	 * @param string $model Model identifier.
	 * @return bool
	 */
	private function model_is_seedream( string $model ): bool {
		$normalized = strtolower( trim( $model ) );
		return false !== strpos( $normalized, 'seedream' );
	}

	/**
	 * Map dimensions/aspect to Seedream image_size tokens.
	 *
	 * @param int|null    $width        Target width.
	 * @param int|null    $height       Target height.
	 * @param string|null $aspect_ratio Optional ratio hint (e.g. 16:9).
	 * @return string
	 */
	private function seedream_image_size_for_dimensions( ?int $width, ?int $height, ?string $aspect_ratio ): string {
		$ratio = '';

		if ( is_string( $aspect_ratio ) && '' !== trim( $aspect_ratio ) ) {
			$ratio = trim( $aspect_ratio );
		} elseif ( is_int( $width ) && is_int( $height ) && $width > 0 && $height > 0 ) {
			$gcd   = $this->greatest_common_divisor( $width, $height );
			$left  = (int) round( $width / $gcd );
			$right = (int) round( $height / $gcd );
			$ratio = $left . ':' . $right;
		}

		$normalized = strtolower( $ratio );
		$map        = [
			'1:1'  => 'square',
			'3:4'  => 'portrait_4_3',
			'4:3'  => 'landscape_4_3',
			'9:16' => 'portrait_16_9',
			'16:9' => 'landscape_16_9',
		];

		if ( isset( $map[ $normalized ] ) ) {
			return $map[ $normalized ];
		}

		$long_edge = 0;
		if ( is_int( $width ) && is_int( $height ) ) {
			$long_edge = max( $width, $height );
		}

		if ( $long_edge >= 2160 ) {
			return 'auto_4K';
		}

		if ( $long_edge >= 1400 ) {
			return 'auto_2K';
		}

		return 'square';
	}

	/**
	 * Compute the GCD for two integers.
	 *
	 * @param int $a First number.
	 * @param int $b Second number.
	 * @return int
	 */
	private function greatest_common_divisor( int $a, int $b ): int {
		$a = abs( $a );
		$b = abs( $b );
		if ( 0 === $a ) {
			return max( 1, $b );
		}
		if ( 0 === $b ) {
			return max( 1, $a );
		}
		while ( 0 !== $b ) {
			$tmp = $b;
			$b   = $a % $b;
			$a   = $tmp;
		}
		return max( 1, $a );
	}

	/**
	 * Convert Fal.ai error structure to readable string.
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
			if ( isset( $error['detail'] ) && is_string( $error['detail'] ) ) {
				return $error['detail'];
			}
			return wp_json_encode( $error );
		}
		return 'Unknown error';
	}
}
