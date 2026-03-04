<?php
/**
 * HTTP utility wrapper around WordPress Requests.
 *
 * @package WPBanana\Util
 * @since   0.1.0
 */

namespace WPBanana\Util;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WP_Error;

/**
 * Provides request and error mapping helpers.
 */
final class Http {

	/**
	 * Perform an HTTP request with sane defaults and error mapping.
	 *
	 * @param string $url  URL.
	 * @param array  $args Arguments for wp_remote_request.
	 * @return array|WP_Error
	 */
	public static function request( string $url, array $args ) {
		$defaults = [
			'method'  => 'GET',
			'timeout' => 300,
			'headers' => [
				'Content-Type' => 'application/json',
			],
		];
		$args     = array_merge( $defaults, $args );
		$res      = wp_remote_request( $url, $args );

		if ( is_wp_error( $res ) ) {
			return self::map_error( $res );
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		if ( $code >= 200 && $code < 300 ) {
			return $res;
		}
		$body       = wp_remote_retrieve_body( $res );
		$message    = self::safe_message_from_body( $body, $code );
		$error_code = self::error_code_for_status( $code );
		return new WP_Error( $error_code, $message, [ 'status' => $code ] );
	}

	/**
	 * Map WP_Error to plugin-specific error codes.
	 *
	 * @param WP_Error $e Error.
	 * @return WP_Error
	 */
	private static function map_error( WP_Error $e ): WP_Error {
		$code = $e->get_error_code();
		if ( 'http_request_failed' === $code ) {
			$msg = $e->get_error_message();
			if ( false !== strpos( strtolower( $msg ), 'timed out' ) ) {
				return new WP_Error( 'wp_banana_provider_timeout', __( 'The provider timed out.', 'wp-banana' ) );
			}
		}
		return new WP_Error( 'wp_banana_http_error', $e->get_error_message() );
	}

	/**
	 * Map HTTP status to WP Error code.
	 *
	 * @param int $status HTTP status.
	 * @return string
	 */
	private static function error_code_for_status( int $status ): string {
		if ( 400 === $status ) {
			return 'wp_banana_invalid_prompt';
		}
		if ( 401 === $status || 403 === $status ) {
			return 'rest_forbidden';
		}
		if ( 404 === $status ) {
			return 'wp_banana_model_unsupported';
		}
		if ( 408 === $status || 504 === $status ) {
			return 'wp_banana_provider_timeout';
		}
		if ( 429 === $status ) {
			return 'wp_banana_rate_limited';
		}
		if ( $status >= 500 ) {
			return 'wp_banana_provider_error';
		}
		return 'wp_banana_http_error';
	}

	/**
	 * Produce a safe error message from an HTTP response body.
	 *
	 * @param string $body   Body string.
	 * @param int    $status HTTP status.
	 * @return string
	 */
	private static function safe_message_from_body( string $body, int $status ): string {
		$message = 'HTTP ' . $status;
		$decoded = json_decode( $body, true );
		if ( is_array( $decoded ) ) {
			$maybe = self::extract_message( $decoded );
			if ( '' !== $maybe ) {
				$message .= ': ' . $maybe;
			}
		}
		return $message;
	}

	/**
	 * Extract a readable error message from structured API payloads.
	 *
	 * @param mixed $value Any decoded JSON value.
	 * @return string
	 */
	private static function extract_message( $value ): string {
		if ( is_string( $value ) ) {
			return trim( $value );
		}

		if ( is_numeric( $value ) ) {
			return (string) $value;
		}

		if ( ! is_array( $value ) ) {
			return '';
		}

		$priority_keys = [ 'message', 'error', 'detail', 'reason', 'title', 'type', 'msg', 'code' ];
		foreach ( $priority_keys as $key ) {
			if ( ! array_key_exists( $key, $value ) ) {
				continue;
			}

			$candidate = self::extract_message( $value[ $key ] );
			if ( '' !== $candidate ) {
				return $candidate;
			}
		}

		// Handle list-style error payloads (e.g. detail:[{msg,type}, ...]).
		if ( self::is_list_array( $value ) ) {
			$parts = [];
			foreach ( $value as $item ) {
				if ( ! is_array( $item ) ) {
					$candidate = self::extract_message( $item );
					if ( '' !== $candidate ) {
						$parts[] = $candidate;
					}
					continue;
				}

				$type = '';
				if ( isset( $item['type'] ) && is_string( $item['type'] ) ) {
					$type = trim( $item['type'] );
				}

				$text = '';
				if ( isset( $item['msg'] ) && is_string( $item['msg'] ) ) {
					$text = trim( $item['msg'] );
				} else {
					$text = self::extract_message( $item );
				}

				if ( '' === $text ) {
					continue;
				}

				$loc = self::location_to_string( $item['loc'] ?? null );

				if ( '' !== $loc ) {
					$text = $loc . ': ' . $text;
				}

				if ( '' !== $type && strtolower( $type ) !== strtolower( $text ) ) {
					$parts[] = $type . ': ' . $text;
				} else {
					$parts[] = $text;
				}
			}

			if ( ! empty( $parts ) ) {
				return implode( '; ', array_slice( $parts, 0, 3 ) );
			}
		}

		return '';
	}

	/**
	 * Convert an error location array to dotted field notation.
	 *
	 * @param mixed $loc Location payload (often ["body","field"]).
	 * @return string
	 */
	private static function location_to_string( $loc ): string {
		if ( ! is_array( $loc ) || empty( $loc ) ) {
			return '';
		}

		$parts = [];
		foreach ( $loc as $part ) {
			if ( ! is_string( $part ) && ! is_numeric( $part ) ) {
				continue;
			}

			$value = trim( (string) $part );
			if ( '' === $value || 'body' === strtolower( $value ) ) {
				continue;
			}

			$parts[] = $value;
		}

		return implode( '.', $parts );
	}

	/**
	 * Polyfill-style check for array_is_list on older PHP versions.
	 *
	 * @param array $value Candidate array.
	 * @return bool
	 */
	private static function is_list_array( array $value ): bool {
		if ( function_exists( 'array_is_list' ) ) {
			return array_is_list( $value );
		}

		$index = 0;
		foreach ( array_keys( $value ) as $key ) {
			if ( $key !== $index ) {
				return false;
			}
			++$index;
		}

		return true;
	}
}
