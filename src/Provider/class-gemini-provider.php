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

		$url     = trailingslashit( $this->api_url ) . rawurlencode( $model ) . ':generateContent';
		$payload = $this->build_generate_payload( $p->prompt, $p->reference_images );
		$data    = $this->perform_request(
			$url,
			$payload,
			[
				'operation' => 'generate',
				'model'     => $model,
				'prompt'    => $p->prompt,
			]
		);
		$img     = $this->extract_inline_image( $data );
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

		$url = trailingslashit( $this->api_url ) . rawurlencode( $model ) . ':generateContent';

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
		$payload = $this->build_edit_payload( $p->prompt, $mime, $bytes, $p->reference_images );
		$data    = $this->perform_request(
			$url,
			$payload,
			[
				'operation'  => 'edit',
				'model'      => $model,
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
	 * Build payload for image generation requests.
	 *
	 * @param string            $prompt Prompt text.
	 * @param Reference_Image[] $references Reference images.
	 * @return array
	 * @throws RuntimeException If reference image reading/encoding fails.
	 */
	private function build_generate_payload( string $prompt, array $references = [] ): array {
		$prompt = $this->normalize_prompt( $prompt );
		$parts  = [
			[ 'text' => $prompt ],
		];

		foreach ( $references as $reference ) {
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

		return [
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
	}

	/**
	 * Build payload for edit requests with inline image data.
	 *
	 * @param string            $prompt Prompt text.
	 * @param string            $mime   Source MIME type.
	 * @param string            $bytes  Source image bytes.
	 * @param Reference_Image[] $references Reference images.
	 * @return array
	 * @throws RuntimeException If reference image reading/encoding fails.
	 */
	private function build_edit_payload( string $prompt, string $mime, string $bytes, array $references ): array {
		$prompt = $this->normalize_prompt( $prompt );
		$parts  = [
			[ 'text' => $prompt ],
		];

		foreach ( $references as $reference ) {
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

		return [
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
