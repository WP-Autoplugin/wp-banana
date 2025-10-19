<?php
/**
 * OpenAI provider implementation.
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
 * OpenAI Images API integration for text-to-image and edits.
 */
final class OpenAI_Provider implements Provider_Interface {

	/**
	 * OpenAI create image endpoint.
	 *
	 * @var string
	 */
	private $generation_url = 'https://api.openai.com/v1/images/generations';

	/**
	 * OpenAI edit endpoint.
	 *
	 * @var string
	 */
	private $edit_url = 'https://api.openai.com/v1/images/edits';

	/**
	 * API key.
	 *
	 * @var string
	 */
	private $api_key;

	/**
	 * Default model.
	 *
	 * @var string
	 */
	private $default_model;

	/**
	 * Timeout seconds.
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
	 * Generate an image from a prompt.
	 *
	 * @param Image_Params $p Parameters.
	 * @return Binary_Image
	 * @throws RuntimeException If generation fails.
	 */
	public function generate( Image_Params $p ): Binary_Image {
		$model = '' !== $p->model ? $p->model : $this->default_model;
		if ( '' === $this->api_key ) {
			throw new RuntimeException( 'OpenAI API key missing.' );
		}
		if ( '' === $model ) {
			throw new RuntimeException( 'OpenAI model not configured.' );
		}

		if ( ! empty( $p->reference_images ) ) {
			return $this->generate_with_references( $p, $model );
		}

		$payload = [
			'model'  => $model,
			'prompt' => $this->normalize_prompt( $p->prompt ),
			'n'      => 1,
		];
		$size    = $this->size_for_request( $p->width, $p->height, $p->aspect_ratio );
		if ( $size ) {
			$payload['size'] = $size;
		}

		$data = $this->perform_json_request(
			$this->generation_url,
			$payload,
			[
				'operation' => 'generate',
				'model'     => $model,
				'prompt'    => $p->prompt,
			]
		);
		return $this->image_from_payload( $data );
	}

	/**
	 * Edit an image using prompt + source file.
	 *
	 * @param Edit_Params $p Parameters.
	 * @return Binary_Image
	 * @throws RuntimeException If edit fails.
	 */
	public function edit( Edit_Params $p ): Binary_Image {
		$model = '' !== $p->model ? $p->model : $this->default_model;
		if ( '' === $this->api_key ) {
			throw new RuntimeException( 'OpenAI API key missing.' );
		}
		if ( '' === $model ) {
			throw new RuntimeException( 'OpenAI model not configured.' );
		}
		if ( ! file_exists( $p->source_file ) ) {
			throw new RuntimeException( 'Source image not found.' );
		}

		$fields = [
			'model'  => $model,
			'prompt' => $this->normalize_prompt( $p->prompt ),
			'n'      => 1,
		];

		$size = $this->size_for_request( $p->target_width, $p->target_height, null );
		if ( $size ) {
			$fields['size'] = $size;
		}

		if ( ! empty( $p->reference_images ) ) {
			$index = 0;
			foreach ( $p->reference_images as $reference ) {
				if ( ! ( $reference instanceof Reference_Image ) ) {
					continue;
				}
				if ( ! file_exists( $reference->path ) ) {
					continue;
				}
				$fields[ 'image[' . $index . ']' ] = $this->curl_file_for_path( $reference->path );
				++$index;
			}
			$fields[ 'image[' . $index . ']' ] = $this->curl_file_for_path( $p->source_file );
		} else {
			$fields['image'] = $this->curl_file_for_path( $p->source_file );
		}

		$data = $this->perform_multipart_request(
			$this->edit_url,
			$fields,
			[
				'operation'  => 'edit',
				'model'      => $model,
				'prompt'     => $p->prompt,
				'sourceFile' => $p->source_file,
			]
		);
		return $this->image_from_payload( $data );
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
	 * Generate using OpenAI's edit endpoint with reference images.
	 *
	 * @param Image_Params $p     Parameters.
	 * @param string       $model Model name.
	 * @return Binary_Image
	 * @throws RuntimeException If generation fails.
	 */
	private function generate_with_references( Image_Params $p, string $model ): Binary_Image {
		$fields = [
			'model'  => $model,
			'prompt' => $this->normalize_prompt( $p->prompt ),
			'n'      => 1,
		];

		$size = $this->size_for_request( $p->width, $p->height, null );
		if ( $size ) {
			$fields['size'] = $size;
		}

		$index = 0;
		foreach ( $p->reference_images as $reference ) {
			if ( ! ( $reference instanceof Reference_Image ) ) {
				continue;
			}
			if ( ! file_exists( $reference->path ) ) {
				continue;
			}
			$fields[ 'image[' . $index . ']' ] = $this->curl_file_for_path( $reference->path );
			++$index;
		}

		if ( 0 === $index ) {
			throw new RuntimeException( 'No valid reference images provided.' );
		}

		$data = $this->perform_multipart_request(
			$this->edit_url,
			$fields,
			[
				'operation' => 'generate',
				'model'     => $model,
				'prompt'    => $p->prompt,
			]
		);
		return $this->image_from_payload( $data );
	}

	/**
	 * Perform JSON request and decode response.
	 *
	 * @param string $url      Endpoint.
	 * @param array  $payload  Payload data.
	 * @param array  $context  Request context metadata.
	 * @return array
	 * @throws RuntimeException If the request fails or response is invalid.
	 */
	private function perform_json_request( string $url, array $payload, array $context ): array {
		$args = [
			'method'  => 'POST',
			'timeout' => $this->timeout,
			'headers' => [
				'Authorization' => 'Bearer ' . $this->api_key,
				'Content-Type'  => 'application/json',
			],
		];
		list( $url, $args, $_payload, $request_context ) = $this->prepare_request( $url, $args, $payload, $context, 'json', true );
		$res = Http::request( $url, $args );

		if ( is_wp_error( $res ) ) {
			throw new RuntimeException( esc_html( $res->get_error_message() ) );
		}

		$body = wp_remote_retrieve_body( $res );
		$data = json_decode( $body, true );
		/**
		 * Filters the decoded OpenAI response payload.
		 *
		 * @since 0.2.0
		 *
		 * @param mixed $data            Decoded response payload.
		 * @param array $request_context Context used for the request.
		 * @param array $res             Raw HTTP response.
		 */
		$data = apply_filters( 'wp_banana_provider_decoded_response', $data, $request_context, $res );
		$data = apply_filters( 'wp_banana_openai_decoded_response', $data, $request_context, $res );
		if ( ! is_array( $data ) ) {
			throw new RuntimeException( 'Invalid response from OpenAI.' );
		}
		if ( isset( $data['error'] ) ) {
			throw new RuntimeException( esc_html( $this->stringify_error( $data['error'] ) ) );
		}

		return $data;
	}

	/**
	 * Execute multipart request for edits.
	 *
	 * @param string $url      Endpoint.
	 * @param array  $fields   Multipart fields.
	 * @param array  $context  Request context metadata.
	 * @return array
	 * @throws RuntimeException If the request fails or response is invalid.
	 */
	private function perform_multipart_request( string $url, array $fields, array $context ): array {
		if ( ! class_exists( '\\CURLFile' ) ) {
			throw new RuntimeException( 'CURLFile support is required for OpenAI image edits.' );
		}
		if ( ! wp_http_supports( [ 'curl' ] ) ) {
			throw new RuntimeException( 'The cURL transport is required for OpenAI image edits.' );
		}

		$args = [
			'method'  => 'POST',
			'timeout' => $this->timeout,
			'headers' => [
				'Authorization' => 'Bearer ' . $this->api_key,
			],
		];
		list( $url, $args, $fields, $request_context ) = $this->prepare_request( $url, $args, $fields, $context, 'multipart', true );
		$body_payload                                  = $args['body'] ?? $fields;

		$hook = static function ( $handle ) use ( $body_payload ) {
			if ( ! is_array( $body_payload ) ) {
				return;
			}
			$has_file = false;
			foreach ( $body_payload as $value ) {
				if ( $value instanceof \CURLFile ) {
					$has_file = true;
					break;
				}
			}
			if ( ! $has_file ) {
				return;
			}
			if ( is_resource( $handle ) || ( class_exists( '\\CurlHandle' ) && $handle instanceof \CurlHandle ) ) {
				curl_setopt( $handle, CURLOPT_POSTFIELDS, $body_payload ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt -- Required to send multipart payload.
			}
		};

		add_action( 'http_api_curl', $hook, 10, 3 );

		try {
			$res = Http::request( $url, $args );
		} finally {
			remove_action( 'http_api_curl', $hook, 10 );
		}

		if ( is_wp_error( $res ) ) {
			throw new RuntimeException( esc_html( $res->get_error_message() ) );
		}

		$body = wp_remote_retrieve_body( $res );
		$data = json_decode( $body, true );
		/**
		 * Filters the decoded OpenAI response payload.
		 *
		 * @since 0.2.0
		 *
		 * @param mixed $data            Decoded response payload.
		 * @param array $request_context Context used for the request.
		 * @param array $res             Raw HTTP response.
		 */
		$data = apply_filters( 'wp_banana_provider_decoded_response', $data, $request_context, $res );
		$data = apply_filters( 'wp_banana_openai_decoded_response', $data, $request_context, $res );
		if ( ! is_array( $data ) ) {
			throw new RuntimeException( 'Invalid response from OpenAI.' );
		}
		if ( isset( $data['error'] ) ) {
			throw new RuntimeException( esc_html( $this->stringify_error( $data['error'] ) ) );
		}

		return $data;
	}

	/**
	 * Apply provider hooks and finish request preparation.
	 *
	 * @since 0.2.0
	 *
	 * @param string $url       Endpoint URL.
	 * @param array  $args      Baseline HTTP arguments.
	 * @param mixed  $payload   Raw payload before transport encoding.
	 * @param array  $context   Request context metadata.
	 * @param string $format    Transport format identifier.
	 * @param bool   $auto_body Whether to populate args['body'] automatically.
	 * @return array{0:string,1:array,2:mixed,3:array}
	 */
	private function prepare_request( string $url, array $args, $payload, array $context, string $format, bool $auto_body ): array {
		$context = $this->normalize_request_context( $context, $format );
		$request = [
			'url'     => $url,
			'args'    => $args,
			'payload' => $payload,
		];
		/**
		 * Filters the outbound OpenAI request before it is dispatched.
		 *
		 * @since 0.2.0
		 *
		 * @param array $request Request data (URL, args, payload).
		 * @param array $context Request metadata.
		 */
		$request = apply_filters( 'wp_banana_provider_http_request', $request, $context );
		$request = apply_filters( 'wp_banana_openai_http_request', $request, $context );
		$url     = isset( $request['url'] ) ? (string) $request['url'] : $url;
		$args    = isset( $request['args'] ) && is_array( $request['args'] ) ? $request['args'] : $args;
		$payload = array_key_exists( 'payload', $request ) ? $request['payload'] : $payload;
		if ( $auto_body && ! isset( $args['body'] ) ) {
			$args['body'] = $this->encode_body( $payload, $format );
		}
		return [ $url, $args, $payload, $context ];
	}

	/**
	 * Normalise request context passed to filters.
	 *
	 * @since 0.2.0
	 *
	 * @param array  $context Context provided by caller.
	 * @param string $format  Transport format identifier.
	 * @return array
	 */
	private function normalize_request_context( array $context, string $format ): array {
		$context['provider']       = 'openai';
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
	 * Encode payload for the given transport format.
	 *
	 * @since 0.2.0
	 *
	 * @param mixed  $payload Payload before transport.
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
	 * Convert response payload to Binary_Image.
	 *
	 * @param array $data Response payload.
	 * @return Binary_Image
	 * @throws RuntimeException If generation fails.
	 */
	private function image_from_payload( array $data ): Binary_Image {
		if ( empty( $data['data'] ) || ! isset( $data['data'][0] ) || ! is_array( $data['data'][0] ) ) {
			throw new RuntimeException( 'OpenAI response did not include image data.' );
		}
		$entry = $data['data'][0];
		$bytes = '';
		$mime  = '';
		if ( isset( $entry['b64_json'] ) && is_string( $entry['b64_json'] ) && '' !== $entry['b64_json'] ) {
			$decoded = base64_decode( $entry['b64_json'] ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- API returns base64 data.
			if ( false === $decoded ) {
				throw new RuntimeException( 'Failed to decode base64 image data from OpenAI.' );
			}
			$bytes = $decoded;
		} elseif ( isset( $entry['url'] ) && is_string( $entry['url'] ) && '' !== $entry['url'] ) {
			list( $bytes, $mime ) = $this->download_from_url( $entry['url'] );
		} else {
			throw new RuntimeException( 'OpenAI response did not include image data.' );
		}

		$size = getimagesizefromstring( $bytes );
		if ( false === $size || ! is_array( $size ) ) {
			throw new RuntimeException( 'Failed to determine image size.' );
		}
		if ( '' === $mime && isset( $size['mime'] ) && is_string( $size['mime'] ) ) {
			$mime = $size['mime'];
		}
		if ( '' === $mime ) {
			$mime = 'image/png';
		}

		return new Binary_Image( $bytes, $mime, (int) $size[0], (int) $size[1] );
	}

	/**
	 * Download image bytes from a remote URL.
	 *
	 * @param string $url Image URL.
	 * @return array{0:string,1:string}
	 * @throws RuntimeException If download fails.
	 */
	private function download_from_url( string $url ): array {
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
		if ( $code < 200 || $code >= 300 ) {
			$message = sprintf( 'OpenAI image download failed (HTTP %d).', (int) $code );
			throw new RuntimeException( esc_html( $message ) );
		}
		$body = wp_remote_retrieve_body( $res );
		if ( '' === $body ) {
			throw new RuntimeException( 'OpenAI image download returned empty body.' );
		}
		$header = wp_remote_retrieve_header( $res, 'content-type' );
		$mime   = '';
		if ( is_string( $header ) && '' !== $header ) {
			$parts = explode( ';', $header );
			$mime  = trim( (string) $parts[0] );
		}
		return [ $body, $mime ];
	}

	/**
	 * Normalize prompt for request payloads.
	 *
	 * @param string $prompt Prompt text.
	 * @return string
	 */
	private function normalize_prompt( string $prompt ): string {
		return trim( preg_replace( '/[\r\n]+/', ' ', $prompt ) );
	}

	/**
	 * Determine best-fit size string for OpenAI API.
	 *
	 * @param int         $width         Requested width.
	 * @param int         $height        Requested height.
	 * @param string|null $aspect_ratio  Requested aspect ratio (generate only).
	 * @return string
	 */
	private function size_for_request( int $width, int $height, ?string $aspect_ratio ): string {
		if ( is_string( $aspect_ratio ) && '' !== $aspect_ratio ) {
			$ratio = strtoupper( trim( $aspect_ratio ) );
			if ( '1:1' === $ratio ) {
				return '1024x1024';
			}
			if ( '3:2' === $ratio ) {
				return '1536x1024';
			}
			if ( '2:3' === $ratio ) {
				return '1024x1536';
			}
			$parts = explode( ':', $ratio );
			if ( 2 === count( $parts ) ) {
				$left  = max( 1.0, (float) $parts[0] );
				$right = max( 1.0, (float) $parts[1] );
				if ( abs( $left - $right ) < 0.01 ) {
					return '1024x1024';
				}
				if ( $left > $right ) {
					return '1536x1024';
				}
				return '1024x1536';
			}
		}

		$width  = max( 256, min( 1792, $width ) );
		$height = max( 256, min( 1792, $height ) );

		if ( $width === $height ) {
			if ( $width >= 1024 && $height >= 1024 ) {
				return '1024x1024';
			}
			if ( $width >= 512 && $height >= 512 ) {
				return '512x512';
			}
			return '256x256';
		}

		if ( $width > $height ) {
			if ( $width >= 1792 && $height >= 1024 ) {
				return '1792x1024';
			}
			return '1024x1024';
		}

		if ( $height >= 1792 && $width >= 1024 ) {
			return '1024x1792';
		}

		return '1024x1024';
	}

	/**
	 * Create CURLFile for a path.
	 *
	 * @param string $path File path.
	 * @return \CURLFile
	 */
	private function curl_file_for_path( string $path ): \CURLFile {
		$type = wp_check_filetype( basename( $path ) );
		$mime = is_array( $type ) && ! empty( $type['type'] ) ? $type['type'] : ( function_exists( 'mime_content_type' ) ? (string) mime_content_type( $path ) : 'image/png' );
		return new \CURLFile( $path, $mime, basename( $path ) );
	}

	/**
	 * Convert API error payload to string.
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
		return 'OpenAI API error';
	}
}
