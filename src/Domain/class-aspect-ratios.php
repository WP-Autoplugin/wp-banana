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
		return [ '1:1', '16:9', '21:9', '3:2', '2:3', '4:5', '5:4', '3:4', '4:3', '9:16', '9:21' ];
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
