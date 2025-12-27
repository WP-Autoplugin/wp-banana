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
use WPBanana\Services\Models_Catalog;
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
	 * Poll interval in seconds when waiting on async predictions.
	 *
	 * @var int
	 */
	private $poll_interval = 2;

	/**
	 * Maximum additional poll duration in seconds.
	 *
	 * @var int
	 */
	private $poll_timeout = 60;

	/**
	 * Constructor.
	 *
	 * @param string $api_token     API token.
	 * @param string $default_model Default model.
	 */
	public function __construct( string $api_token, string $default_model ) {
		$this->api_token     = $api_token;
		$this->default_model = $default_model;
		$this->timeout       = 65; // Replicate synchronous wait limit is 60 seconds, adding some buffer.
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
		$config = $this->resolve_model_config( $model, ! empty( $p->reference_images ), $p->resolution, $p->width, $p->height );
		$model  = $config['api_model'];
		if ( '' === $model ) {
			throw new RuntimeException( 'Replicate model not configured.' );
		}

		$input = [
			'prompt' => $prompt,
		];
		if ( ! empty( $config['resolution'] ) ) {
			$input['resolution'] = $config['resolution'];
		}
		if ( ! empty( $config['size'] ) ) {
			$input['size'] = $config['size'];
		}
		if ( ! empty( $config['width'] ) && ! empty( $config['height'] ) ) {
			$input['width']  = (int) $config['width'];
			$input['height'] = (int) $config['height'];
		}
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
		$model  = '' !== $p->model ? $p->model : $this->default_model;
		$config = $this->resolve_model_config( $model, true, null, $p->target_width, $p->target_height );
		$model  = $config['api_model'];
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
	 * Resolve model to base id and resolution hints.
	 *
	 * @param string      $model            Selected model identifier.
	 * @param bool        $has_references   Whether request includes reference images.
	 * @param string|null $resolution_param Optional resolution parameter (e.g. 1K, 2K, 4K).
	 * @param int|null    $target_width     Target width when provided by the request.
	 * @param int|null    $target_height    Target height when provided by the request.
	 * @return array{api_model:string,resolution:?string,width:?int,height:?int,size:?string}
	 */
	private function resolve_model_config( string $model, bool $has_references, ?string $resolution_param = null, ?int $target_width = null, ?int $target_height = null ): array {
		$config          = [
			'api_model'  => $model,
			'resolution' => null,
			'width'      => null,
			'height'     => null,
			'size'       => null,
		];
		$normalized      = strtolower( trim( $model ) );
		$nano_banana_pro = strtolower( Models_Catalog::REPLICATE_NANO_BANANA_PRO );
		$flux_2_dev      = strtolower( Models_Catalog::REPLICATE_FLUX_2_DEV );
		$flux_2_pro      = strtolower( Models_Catalog::REPLICATE_FLUX_2_PRO );
		$flux_2_flex     = strtolower( Models_Catalog::REPLICATE_FLUX_2_FLEX );
		$seedream_45     = strtolower( Models_Catalog::REPLICATE_SEEDREAM_45 );

		if ( 0 === strpos( $normalized, $flux_2_dev ) ) {
			$config['api_model']                        = Models_Catalog::REPLICATE_FLUX_2_DEV;
			list( $config['width'], $config['height'] ) = $this->resolve_flux_2_dev_dimensions( $target_width, $target_height, $resolution_param );
		} elseif ( 0 === strpos( $normalized, $flux_2_pro ) ) {
			$config['api_model'] = Models_Catalog::REPLICATE_FLUX_2_PRO;
			if ( ! $has_references && null !== $resolution_param && '' !== $resolution_param ) {
				$config['resolution'] = $this->resolution_to_megapixels( $resolution_param );
			}
		} elseif ( 0 === strpos( $normalized, $flux_2_flex ) ) {
			$config['api_model'] = Models_Catalog::REPLICATE_FLUX_2_FLEX;
			if ( ! $has_references && null !== $resolution_param && '' !== $resolution_param ) {
				$config['resolution'] = $this->resolution_to_megapixels( $resolution_param );
			}
		} elseif ( 0 === strpos( $normalized, $seedream_45 ) ) {
			$config['api_model'] = Models_Catalog::REPLICATE_SEEDREAM_45;
			if ( null !== $resolution_param && '' !== $resolution_param ) {
				$config['size'] = $this->resolution_to_seedream_size( $resolution_param );
			}
		}

		if ( 0 === strpos( $normalized, $nano_banana_pro ) ) {
			$config['api_model'] = Models_Catalog::REPLICATE_NANO_BANANA_PRO;
			if ( ! $has_references && null !== $resolution_param && '' !== $resolution_param ) {
				$config['resolution'] = $resolution_param;
			}
		}
		return $config;
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
		if ( $this->should_poll_status( $data['status'] ?? '' ) && empty( $data['output'] ) ) {
			$data = $this->poll_prediction(
				$data,
				$response,
				$context
			);
		}
		if ( empty( $data['output'] ) ) {
			if ( $this->should_poll_status( $data['status'] ?? '' ) ) {
				throw new RuntimeException( 'Replicate prediction did not finish in time.' );
			}
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
	 * Poll a pending prediction until completion or timeout.
	 *
	 * @param array $data      Initial prediction payload.
	 * @param array $response  Initial HTTP response.
	 * @param array $context   Request context metadata.
	 * @return array
	 */
	private function poll_prediction( array $data, array $response, array $context ): array {
		$poll_url = '';
		if ( isset( $data['urls']['get'] ) && is_string( $data['urls']['get'] ) ) {
			$poll_url = $data['urls']['get'];
		}
		if ( '' === $poll_url ) {
			$location = wp_remote_retrieve_header( $response, 'location' );
			if ( is_string( $location ) && '' !== $location ) {
				$poll_url = $location;
			}
		}
		if ( '' === $poll_url ) {
			return $data;
		}

		$deadline = time() + $this->poll_timeout;
		$attempt  = 0;

		while ( $this->should_poll_status( $data['status'] ?? '' ) && time() < $deadline ) {
			++$attempt;
			$args = [
				'method'  => 'GET',
				'timeout' => $this->timeout,
				'headers' => [
					'Authorization' => 'Bearer ' . $this->api_token,
					'Content-Type'  => 'application/json',
				],
			];
			list( $url, $args, $_payload, $request_context ) = $this->prepare_request(
				$poll_url,
				$args,
				null,
				array_merge(
					$context,
					[
						'operation'    => $context['operation'] ?? '',
						'polling'      => true,
						'poll_attempt' => $attempt,
					]
				),
				'get',
				false
			);
			$res = Http::request( $url, $args );
			if ( is_wp_error( $res ) ) {
				throw new RuntimeException( esc_html( $res->get_error_message() ) );
			}
			$body    = wp_remote_retrieve_body( $res );
			$decoded = json_decode( $body, true );
			$decoded = apply_filters( 'wp_banana_provider_decoded_response', $decoded, $request_context, $res );
			$decoded = apply_filters( 'wp_banana_replicate_decoded_response', $decoded, $request_context, $res );
			if ( ! is_array( $decoded ) ) {
				break;
			}
			$data = $decoded;
			if ( isset( $data['error'] ) && ! empty( $data['error'] ) ) {
				$message = 'Replicate API error: ' . $this->stringify_error( $data['error'] );
				throw new RuntimeException( esc_html( $message ) );
			}
			if ( ! empty( $data['output'] ) ) {
				return $data;
			}
			if ( ! $this->should_poll_status( $data['status'] ?? '' ) ) {
				break;
			}
			sleep( $this->poll_interval );
		}

		return $data;
	}

	/**
	 * Determine if a prediction status warrants polling.
	 *
	 * @param mixed $status Status string.
	 * @return bool
	 */
	private function should_poll_status( $status ): bool {
		if ( ! is_string( $status ) || '' === $status ) {
			return false;
		}
		$status = strtolower( $status );
		return in_array( $status, [ 'starting', 'processing' ], true );
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
	 * Check if a haystack contains any of the provided needles.
	 *
	 * @param string   $haystack Lowercased haystack string.
	 * @param string[] $needles  Lowercased needles to search for.
	 * @return bool
	 */
	private function needle_contains_any( string $haystack, array $needles ): bool {
		foreach ( $needles as $needle ) {
			if ( '' !== $needle && false !== strpos( $haystack, $needle ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Map generic resolution choices to Replicate megapixel strings.
	 *
	 * @param string|null $resolution Resolution label.
	 * @return string|null
	 */
	private function resolution_to_megapixels( ?string $resolution ): ?string {
		if ( null === $resolution ) {
			return null;
		}
		$canonical = strtoupper( trim( $resolution ) );
		if ( '' === $canonical ) {
			return null;
		}
		if ( '1K' === $canonical ) {
			return '1 MP';
		}
		if ( '2K' === $canonical ) {
			return '2 MP';
		}
		if ( '4K' === $canonical ) {
			return '4 MP';
		}
		return null;
	}

	/**
	 * Map generic resolution choices to Seedream size strings.
	 *
	 * @param string|null $resolution Resolution label.
	 * @return string|null
	 */
	private function resolution_to_seedream_size( ?string $resolution ): ?string {
		if ( null === $resolution ) {
			return null;
		}
		$canonical = strtoupper( trim( $resolution ) );
		if ( '' === $canonical ) {
			return null;
		}
		if ( '4K' === $canonical ) {
			return '4K';
		}
		if ( '2K' === $canonical || '1K' === $canonical ) {
			return '2K';
		}
		return null;
	}

	/**
	 * Determine the long edge to use for flux-2-dev given a resolution choice.
	 *
	 * @param string|null $resolution Resolution label.
	 * @return int|null
	 */
	private function long_edge_for_resolution( ?string $resolution ): ?int {
		$canonical = strtoupper( trim( (string) $resolution ) );
		if ( '1K' === $canonical ) {
			return 1024;
		}
		if ( '2K' === $canonical || '4K' === $canonical ) {
			return 1440;
		}
		return null;
	}

	/**
	 * Clamp flux-2-dev dimensions to supported bounds and optional resolution targets.
	 *
	 * @param int|null    $target_width  Requested width.
	 * @param int|null    $target_height Requested height.
	 * @param string|null $resolution    Resolution label.
	 * @return array{0:int,1:int}
	 */
	private function resolve_flux_2_dev_dimensions( ?int $target_width, ?int $target_height, ?string $resolution ): array {
		$min = 256;
		$max = 1440;

		$width  = is_int( $target_width ) ? $target_width : 1024;
		$height = is_int( $target_height ) ? $target_height : 1024;

		$width  = max( $min, min( $max, $width ) );
		$height = max( $min, min( $max, $height ) );

		$long_edge = $this->long_edge_for_resolution( $resolution );
		if ( $long_edge ) {
			if ( $width >= $height ) {
				$scale  = $width > 0 ? ( $height / $width ) : 1.0;
				$width  = $long_edge;
				$height = (int) round( $long_edge * $scale );
			} else {
				$scale  = $height > 0 ? ( $width / $height ) : 1.0;
				$width  = (int) round( $long_edge * $scale );
				$height = $long_edge;
			}
			$width  = max( $min, min( $max, $width ) );
			$height = max( $min, min( $max, $height ) );
		}

		return [ $width, $height ];
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

		$needle           = strtolower( $model );
		$banana_variants  = [
			strtolower( Models_Catalog::REPLICATE_NANO_BANANA ),
			strtolower( Models_Catalog::REPLICATE_NANO_BANANA_PRO ),
		];
		$flux_2_models    = [
			strtolower( Models_Catalog::REPLICATE_FLUX_2_DEV ),
			strtolower( Models_Catalog::REPLICATE_FLUX_2_PRO ),
			strtolower( Models_Catalog::REPLICATE_FLUX_2_FLEX ),
		];
		$seedream_needles = [
			strtolower( Models_Catalog::REPLICATE_SEEDREAM_4 ),
			strtolower( Models_Catalog::REPLICATE_SEEDREAM_45 ),
		];
		$reve_remix_lower = strtolower( Models_Catalog::REPLICATE_REVE_REMIX );

		if ( $this->needle_contains_any( $needle, $flux_2_models ) ) {
			$input['input_images'] = $data_uris;
		} elseif ( $this->needle_contains_any( $needle, $banana_variants ) || $this->needle_contains_any( $needle, $seedream_needles ) ) {
			$input['image_input'] = $data_uris;
			$input['image_input'] = array_reverse( $input['image_input'] );
		} elseif ( $needle === $reve_remix_lower ) {
			$input['reference_images'] = $data_uris;
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
		$output_format = $this->format_for_request( $format );
		$defaults      = [
			'prompt'         => $prompt,
			'output_format'  => '' !== $output_format ? $output_format : 'png',
			'output_quality' => 80,
		];

		$needle          = strtolower( $model );
		$banana_variants = [
			strtolower( Models_Catalog::REPLICATE_NANO_BANANA ),
			strtolower( Models_Catalog::REPLICATE_NANO_BANANA_PRO ),
		];
		$seedream        = [
			strtolower( Models_Catalog::REPLICATE_SEEDREAM_4 ),
			strtolower( Models_Catalog::REPLICATE_SEEDREAM_45 ),
		];
		$flux_kontext    = [
			strtolower( Models_Catalog::REPLICATE_FLUX_KONTEXT_MAX ),
			strtolower( Models_Catalog::REPLICATE_FLUX_KONTEXT_DEV ),
		];
		$flux_2_models   = [
			strtolower( Models_Catalog::REPLICATE_FLUX_2_DEV ),
			strtolower( Models_Catalog::REPLICATE_FLUX_2_PRO ),
			strtolower( Models_Catalog::REPLICATE_FLUX_2_FLEX ),
		];
		$seededit        = strtolower( Models_Catalog::REPLICATE_SEEDEDIT_30 );
		$qwen_edit       = strtolower( Models_Catalog::REPLICATE_QWEN_IMAGE_EDIT );
		$reve_remix      = strtolower( Models_Catalog::REPLICATE_REVE_REMIX );

		if ( $this->needle_contains_any( $needle, $flux_2_models ) ) {
			$defaults['input_images'] = [ $data_uri ];
			$defaults['aspect_ratio'] = 'match_input_image';
			if ( false !== strpos( $needle, strtolower( Models_Catalog::REPLICATE_FLUX_2_DEV ) ) ) {
				$defaults['go_fast'] = true;
			}
		} elseif ( $needle === $reve_remix ) {
			$defaults['reference_images'] = [
				$data_uri,
			];
		} elseif ( $this->needle_contains_any( $needle, $banana_variants ) || $this->needle_contains_any( $needle, $seedream ) ) {
			$defaults['image_input'] = [ $data_uri ];
		} elseif ( $this->needle_contains_any( $needle, $flux_kontext ) ) {
			$defaults['input_image']   = $data_uri;
			$defaults['aspect_ratio']  = 'match_input_image';
			$defaults['output_format'] = 'jpg';
			if ( false !== strpos( $needle, $flux_kontext[0] ) ) {
				$defaults['safety_tolerance'] = 2;
			} elseif ( false !== strpos( $needle, $flux_kontext[1] ) ) {
				$defaults['go_fast']             = true;
				$defaults['guidance']            = 2.5;
				$defaults['num_inference_steps'] = 30;
			}
		} elseif ( false !== strpos( $needle, $seededit ) ) {
			$defaults['image'] = $data_uri;
		} else {
			$defaults['image'] = $data_uri;
			if ( false !== strpos( $needle, $qwen_edit ) ) {
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
