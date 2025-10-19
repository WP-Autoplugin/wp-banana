<?php
/**
 * Replicate provider implementation.
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
use WPBanana\Services\Options;
use WPBanana\Util\Http;

/**
 * Replicate API integration for text-to-image and image editing.
 */
final class Replicate_Provider implements Provider_Interface {

	/**
	 * Base URL for Replicate model predictions.
	 *
	 * @var string
	 */
	private $api_url = 'https://api.replicate.com/v1/models';

	/**
	 * Replicate API token.
	 *
	 * @var string
	 */
	private $api_token;

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
	 * @param string $api_token     API token.
	 * @param string $default_model Default model.
	 */
	public function __construct( string $api_token, string $default_model ) {
		$this->api_token     = $api_token;
		$this->default_model = $default_model;
		$this->timeout       = 60; // Replicate synchronous wait limit.
	}

	/**
	 * Generate an image via Replicate.
	 *
	 * @param Image_Params $p Params.
	 * @return Binary_Image
	 * @throws RuntimeException If the request fails or no image can be downloaded.
	 */
	public function generate( Image_Params $p ): Binary_Image {
		$prompt = $this->normalize_prompt( $p->prompt );
		$model  = '' !== $p->model ? $p->model : $this->default_model;
		if ( '' === $model ) {
			throw new RuntimeException( 'Replicate model not configured.' );
		}

		$input = [
			'prompt' => $prompt,
		];
		if ( ! empty( $p->aspect_ratio ) ) {
			$input['aspect_ratio'] = $p->aspect_ratio;
		}
		$output_format = $this->format_for_request( $p->format );
		if ( '' !== $output_format ) {
			$input['output_format'] = $output_format;
		}
		if ( ! empty( $p->reference_images ) ) {
			$input = $this->apply_reference_images( $model, $input, $p->reference_images );
		}
		$data                 = $this->request_prediction(
			$model,
			$input,
			[
				'operation' => 'generate',
				'model'     => $model,
				'prompt'    => $prompt,
			]
		);
		$url                  = $this->extract_output_url( $data['output'] ?? null );
		list( $bytes, $mime ) = $this->download_image( $url );

		$size = getimagesizefromstring( $bytes );
		if ( false === $size || ! is_array( $size ) ) {
			throw new RuntimeException( 'Failed to determine image size from Replicate output.' );
		}

		return new Binary_Image( $bytes, $mime, (int) $size[0], (int) $size[1] );
	}

	/**
	 * Edit an image via Replicate editor models.
	 *
	 * @param Edit_Params $p Params.
	 * @return Binary_Image
	 * @throws RuntimeException If the file cannot be read or the API fails.
	 */
	public function edit( Edit_Params $p ): Binary_Image {
		$model = '' !== $p->model ? $p->model : $this->default_model;
		if ( '' === $model ) {
			throw new RuntimeException( 'Replicate model not configured.' );
		}
		if ( ! file_exists( $p->source_file ) ) {
			throw new RuntimeException( 'Source image not found.' );
		}

		$prompt   = $this->normalize_prompt( $p->prompt );
		$data_uri = $this->data_uri_for_file( $p->source_file );
		// Use plugin default generation format.
		$default_format = ( new Options() )->get( 'generation_defaults.format', 'png' );
		$defaults       = $this->defaults_for_edit_model( $model, $prompt, $data_uri, is_string( $default_format ) ? $default_format : 'png' );
		if ( ! empty( $p->reference_images ) ) {
			$bundle   = $p->reference_images;
			$bundle[] = new Reference_Image(
				$p->source_file,
				'image/png',
				$p->target_width,
				$p->target_height,
				basename( $p->source_file )
			);
			$defaults = $this->apply_reference_images( $model, $defaults, $bundle );
		}
		$data                 = $this->request_prediction(
			$model,
			$defaults,
			[
				'operation'  => 'edit',
				'model'      => $model,
				'prompt'     => $prompt,
				'sourceFile' => $p->source_file,
			]
		);
		$url                  = $this->extract_output_url( $data['output'] ?? null );
		list( $bytes, $mime ) = $this->download_image( $url );

		$size = getimagesizefromstring( $bytes );
		if ( false === $size || ! is_array( $size ) ) {
			throw new RuntimeException( 'Failed to determine image size from Replicate output.' );
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
	 * Perform Replicate prediction request and return decoded payload.
	 *
	 * @param string $model   Model name.
	 * @param array  $input   Input payload.
	 * @param array  $context Request context metadata.
	 * @return array
	 * @throws RuntimeException If the HTTP call fails or the response is invalid.
	 */
	private function request_prediction( string $model, array $input, array $context ): array {
		if ( '' === $this->api_token ) {
			throw new RuntimeException( 'Replicate API token missing.' );
		}
		$url     = trailingslashit( $this->api_url ) . $model . '/predictions';
		$args    = [
			'method'  => 'POST',
			'timeout' => $this->timeout,
			'headers' => [
				'Authorization' => 'Bearer ' . $this->api_token,
				'Content-Type'  => 'application/json',
				'Prefer'        => 'wait',
			],
		];
		$payload = [ 'input' => $input ];
		list( $url, $args, $_payload, $request_context ) = $this->prepare_request( $url, $args, $payload, $context, 'json' );
		$response                                        = Http::request( $url, $args );

		if ( is_wp_error( $response ) ) {
			throw new RuntimeException( esc_html( $response->get_error_message() ) );
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );
		/**
		 * Filters the decoded Replicate response payload.
		 *
		 * @since 0.2.0
		 *
		 * @param mixed $data            Decoded response payload.
		 * @param array $request_context Request context metadata.
		 * @param array $response        Raw HTTP response.
		 */
		$data = apply_filters( 'wp_banana_provider_decoded_response', $data, $request_context, $response );
		$data = apply_filters( 'wp_banana_replicate_decoded_response', $data, $request_context, $response );
		if ( ! is_array( $data ) ) {
			throw new RuntimeException( 'Invalid response from Replicate.' );
		}
		if ( isset( $data['error'] ) && ! empty( $data['error'] ) ) {
			$message = 'Replicate API error: ' . $this->stringify_error( $data['error'] );
			throw new RuntimeException( esc_html( $message ) );
		}
		if ( empty( $data['output'] ) ) {
			throw new RuntimeException( 'Replicate API returned no output.' );
		}

		return $data;
	}

	/**
	 * Apply request filters and finish assembling HTTP arguments.
	 *
	 * @since 0.2.0
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
		/**
		 * Filters the outbound Replicate request before dispatch.
		 *
		 * @since 0.2.0
		 *
		 * @param array $request Request data (URL, args, payload).
		 * @param array $context Request metadata.
		 */
		$request = apply_filters( 'wp_banana_provider_http_request', $request, $context );
		$request = apply_filters( 'wp_banana_replicate_http_request', $request, $context );
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
	 * @since 0.2.0
	 *
	 * @param array  $context Context supplied by caller.
	 * @param string $format  Transport format.
	 * @return array
	 */
	private function normalize_request_context( array $context, string $format ): array {
		$context['provider']       = 'replicate';
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
	 * @since 0.2.0
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
	 * @param mixed $output Response output.
	 * @return string
	 * @throws RuntimeException When no valid URL is found.
	 */
	private function extract_output_url( $output ): string {
		if ( is_string( $output ) && '' !== $output ) {
			return $output;
		}
		if ( is_array( $output ) ) {
			$first = reset( $output );
			if ( is_string( $first ) && '' !== $first ) {
				return $first;
			}
			if ( is_array( $first ) ) {
				foreach ( [ 'image', 'output', 'url' ] as $key ) {
					if ( isset( $first[ $key ] ) && is_string( $first[ $key ] ) && '' !== $first[ $key ] ) {
						return $first[ $key ];
					}
				}
			}
		}
		throw new RuntimeException( 'Replicate response missing output URL.' );
	}

	/**
	 * Download image bytes from a URL.
	 *
	 * @param string $url Image URL.
	 * @return array{0:string,1:string} [ bytes, mime ]
	 * @throws RuntimeException If the download fails.
	 */
	private function download_image( string $url ): array {
		$args                                     = [
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
		$res                                      = wp_remote_get( $url, $args );
		if ( is_wp_error( $res ) ) {
			throw new RuntimeException( esc_html( $res->get_error_message() ) );
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		if ( 200 !== $code ) {
			$message = sprintf( 'Failed to download Replicate image (HTTP %d).', (int) $code );
			throw new RuntimeException( esc_html( $message ) );
		}

		$body = wp_remote_retrieve_body( $res );
		if ( '' === $body ) {
			throw new RuntimeException( 'Replicate returned empty image data.' );
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
	 * Map plugin format slug to Replicate expected values.
	 *
	 * @param string $format Requested format.
	 * @return string
	 */
	private function format_for_request( string $format ): string {
		if ( 'webp' === $format ) {
			return 'webp';
		}
		if ( 'jpeg' === $format || 'jpg' === $format ) {
			return 'jpg';
		}
		if ( 'png' === $format ) {
			return 'png';
		}
		return '';
	}

	/**
	 * Inject reference images into Replicate generation payload.
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
			throw new RuntimeException( 'No valid reference images provided for Replicate.' );
		}

		$needle = strtolower( $model );
		if ( false !== strpos( $needle, 'nano-banana' ) || false !== strpos( $needle, 'seedream' ) ) {
			$input['image_input'] = $data_uris;
			$input['image_input'] = array_reverse( $input['image_input'] );
		} else {
			$input['image'] = $data_uris[0];
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
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Required for API payload.
		$bytes = file_get_contents( $file_path );
		if ( false === $bytes ) {
			throw new RuntimeException( 'Failed to read source image for Replicate edit.' );
		}
		$type    = wp_check_filetype( basename( $file_path ) );
		$mime    = is_array( $type ) && ! empty( $type['type'] ) ? $type['type'] : ( function_exists( 'mime_content_type' ) ? (string) mime_content_type( $file_path ) : 'image/png' );
		$encoded = base64_encode( $bytes ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Required for inline payload encoding.
		if ( false === $encoded ) {
			throw new RuntimeException( 'Failed to encode image for Replicate edit.' );
		}
		return 'data:' . $mime . ';base64,' . $encoded;
	}

	/**
	 * Build default input payload for edit models.
	 *
	 * @param string $model   Model name.
	 * @param string $prompt  Prompt text.
	 * @param string $data_uri Data URI for source image.
	 * @param string $format  Requested format.
	 * @return array
	 */
	private function defaults_for_edit_model( string $model, string $prompt, string $data_uri, string $format ): array {
		$defaults = [
			'prompt'         => $prompt,
			'output_format'  => $this->format_for_request( $format ) ?: 'png',
			'output_quality' => 80,
		];

		$needle = strtolower( $model );
		if ( false !== strpos( $needle, 'nano-banana' ) || false !== strpos( $needle, 'seedream' ) ) {
			$defaults['image_input'] = [ $data_uri ];
		} elseif ( false !== strpos( $needle, 'flux-kontext' ) ) {
			$defaults['input_image']   = $data_uri;
			$defaults['aspect_ratio']  = 'match_input_image';
			$defaults['output_format'] = 'jpg';
			if ( false !== strpos( $needle, 'flux-kontext-max' ) ) {
				$defaults['safety_tolerance'] = 2;
			} elseif ( false !== strpos( $needle, 'flux-kontext-dev' ) ) {
				$defaults['go_fast']             = true;
				$defaults['guidance']            = 2.5;
				$defaults['num_inference_steps'] = 30;
			}
		} elseif ( false !== strpos( $needle, 'seededit' ) ) {
			$defaults['image'] = $data_uri;
		} else {
			$defaults['image'] = $data_uri;
			if ( false !== strpos( $needle, 'qwen-image-edit' ) ) {
				$defaults['go_fast'] = true;
			}
		}

		return $defaults;
	}

	/**
	 * Convert Replicate error structure to readable string.
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
		return 'Unknown error';
	}
}
