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
		return [ '1K', '2K', '4K' ];
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
