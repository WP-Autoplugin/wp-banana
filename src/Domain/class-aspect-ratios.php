<?php
/**
 * Enumerates supported aspect ratios for generation controls.
 *
 * @package WPBanana\Domain
 */

namespace WPBanana\Domain;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shared helper for supported aspect ratios.
 */
final class Aspect_Ratios {

	/**
	 * Get the list of supported ratios in canonical form.
	 *
	 * @return array<int,string>
	 */
	public static function all(): array {
		$ratios = [
			'1:1',
			'16:9',
			'21:9',
			'2:1',
			'4:1',
			'8:1',
			'3:2',
			'2:3',
			'4:5',
			'5:4',
			'3:4',
			'4:3',
			'1:2',
			'1:4',
			'1:8',
			'9:16',
			'9:21',
		];

		/**
		 * Filter the supported generation aspect ratios.
		 *
		 * Values must be ratio strings in `W:H` format. Unsupported ratios may still
		 * be rejected by individual providers.
		 *
		 * @param array<int,string> $ratios Canonical aspect ratio options.
		 */
		$ratios = apply_filters( 'wp_banana_aspect_ratios', $ratios );
		if ( ! is_array( $ratios ) ) {
			return [];
		}

		$normalized = [];
		foreach ( $ratios as $ratio ) {
			if ( ! is_scalar( $ratio ) ) {
				continue;
			}

			$canonical = strtoupper( trim( (string) $ratio ) );
			if ( ! preg_match( '/^[1-9][0-9]*:[1-9][0-9]*$/', $canonical ) ) {
				continue;
			}

			$normalized[] = $canonical;
		}

		return array_values( array_unique( $normalized ) );
	}

	/**
	 * Return the default aspect ratio string.
	 *
	 * @return string
	 */
	public static function default(): string {
		return '1:1';
	}

	/**
	 * Sanitize a raw ratio against the supported list.
	 *
	 * @param string $ratio Raw ratio value.
	 * @return string Sanitized ratio or empty string when invalid.
	 */
	public static function sanitize( string $ratio ): string {
		$canonical = strtoupper( trim( $ratio ) );
		return in_array( $canonical, self::all(), true ) ? $canonical : '';
	}
}
