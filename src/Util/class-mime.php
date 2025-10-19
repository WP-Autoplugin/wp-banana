<?php
/**
 * MIME utilities.
 *
 * @package WPBanana\Util
 */

namespace WPBanana\Util;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Helper methods for MIME handling.
 */
final class Mime {

	/**
	 * Static-only class.
	 */
	private function __construct() {}

	/**
	 * Build sideload MIME overrides for allowed types.
	 *
	 * @param array $allowed_mimes Allowed MIME types.
	 * @return array<string,string>
	 */
	public static function build_sideload_overrides( array $allowed_mimes ): array {
		$map = [];

		foreach ( $allowed_mimes as $mime ) {
			$mime = strtolower( trim( (string) $mime ) );
			if ( '' === $mime ) {
				continue;
			}

			if ( 'image/jpeg' === $mime || 'image/jpg' === $mime ) {
				$map['jpg']  = 'image/jpeg';
				$map['jpeg'] = 'image/jpeg';
				continue;
			}

			if ( 'image/png' === $mime ) {
				$map['png'] = 'image/png';
				continue;
			}

			if ( 'image/webp' === $mime ) {
				$map['webp'] = 'image/webp';
				continue;
			}

			$slash = strpos( $mime, '/' );
			if ( false !== $slash ) {
				$ext = trim( substr( $mime, $slash + 1 ) );
				if ( '' !== $ext ) {
					$map[ $ext ] = $mime;
				}
			}
		}

		if ( empty( $map ) ) {
			$map = [
				'png'  => 'image/png',
				'jpg'  => 'image/jpeg',
				'jpeg' => 'image/jpeg',
				'webp' => 'image/webp',
			];
		}

		return $map;
	}
}
