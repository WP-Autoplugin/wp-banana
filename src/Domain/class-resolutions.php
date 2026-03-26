<?php
/**
 * Enumerates supported resolution values for generation controls.
 *
 * @package WPBanana\Domain
 */

namespace WPBanana\Domain;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shared helper for supported resolution values.
 */
final class Resolutions {

	/**
	 * Get the list of supported resolutions in canonical form.
	 *
	 * @return array<int,string>
	 */
	public static function all(): array {
		$resolutions = [ '1K', '2K', '4K' ];

		/**
		 * Filter the supported generation resolutions.
		 *
		 * Values must be resolution strings such as `1K`, `2K`, or `4K`.
		 * Unsupported resolutions may still be rejected by individual providers.
		 *
		 * @param array<int,string> $resolutions Canonical resolution options.
		 */
		$resolutions = apply_filters( 'wp_banana_resolutions', $resolutions );
		if ( ! is_array( $resolutions ) ) {
			return [];
		}

		$normalized = [];
		foreach ( $resolutions as $resolution ) {
			if ( ! is_scalar( $resolution ) ) {
				continue;
			}

			$canonical = strtoupper( trim( (string) $resolution ) );
			if ( ! preg_match( '/^[1-9][0-9]*K$/', $canonical ) ) {
				continue;
			}

			$normalized[] = $canonical;
		}

		return array_values( array_unique( $normalized ) );
	}

	/**
	 * Return the default resolution string.
	 *
	 * @return string
	 */
	public static function default(): string {
		return '1K';
	}

	/**
	 * Sanitize a raw resolution against the supported list.
	 *
	 * @param string $resolution Raw resolution value.
	 * @return string Sanitized resolution or empty string when invalid.
	 */
	public static function sanitize( string $resolution ): string {
		$canonical = strtoupper( trim( $resolution ) );
		return in_array( $canonical, self::all(), true ) ? $canonical : '';
	}
}
