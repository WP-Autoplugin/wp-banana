<?php
/**
 * Image conversion/normalization (Imagick).
 *
 * @package WPBanana\Services
 * @since   0.1.0
 */

namespace WPBanana\Services;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Imagick;
use ImagickException;
use WPBanana\Domain\Binary_Image;

/**
 * Wraps Imagick for resizing and format normalization.
 */
final class Convert_Service {

	/**
	 * ~64MP pixel hard limit.
	 *
	 * @var int
	 */
	public const MAX_PIXELS = 64000000;
	/**
	 * ~100MB input size guard.
	 *
	 * @var int
	 */
	public const MAX_BYTES = 100000000;

	/**
	 * Normalize an image: optional resize and format conversion.
	 *
	 * @param string   $bytes         Input image bytes.
	 * @param string   $target_format Output format slug.
	 * @param int|null $target_width  Optional target width.
	 * @param int|null $target_height Optional target height.
	 * @return Binary_Image
	 * @throws \RuntimeException If input is too large, image cannot be read, image exceeds pixel limit, or conversion fails.
	 */
	public function normalize( string $bytes, string $target_format, ?int $target_width = null, ?int $target_height = null ): Binary_Image {
		if ( strlen( $bytes ) > self::MAX_BYTES ) {
			throw new \RuntimeException( 'Input too large' );
		}
		$target_format = in_array( $target_format, [ 'png', 'webp', 'jpeg' ], true ) ? $target_format : 'png';

		try {
			$img = new \Imagick();
			$img->readImageBlob( $bytes );
		} catch ( \ImagickException $e ) {
			throw new \RuntimeException( 'Failed to read image: ' . esc_html( $e->getMessage() ) );
		}

		$img->setImageColorspace( Imagick::COLORSPACE_SRGB );
		$img->stripImage();

		$width  = $img->getImageWidth();
		$height = $img->getImageHeight();
		if ( ( $width * $height ) > self::MAX_PIXELS ) {
			$img->clear();
			$img->destroy();
			throw new \RuntimeException( 'Image exceeds pixel limit' );
		}

		if ( $target_width && $target_height && ( $target_width !== $width || $target_height !== $height ) ) {
			$img->resizeImage( $target_width, $target_height, Imagick::FILTER_LANCZOS, 1.0, true );
			$width  = $img->getImageWidth();
			$height = $img->getImageHeight();
		}

		if ( 'jpeg' === $target_format && $img->getImageAlphaChannel() ) {
			$bg      = apply_filters( 'wp_banana_convert_background_color', '#ffffff', $img );
			$flatten = new Imagick();
			$flatten->newImage( $width, $height, $bg );
			$flatten->compositeImage( $img, Imagick::COMPOSITE_OVER, 0, 0 );
			$img->clear();
			$img = $flatten;
		}

		try {
			$img->setImageFormat( $target_format );
			if ( 'jpeg' === $target_format ) {
				$img->setImageCompressionQuality( 92 );
			}
			$out = $img->getImagesBlob();
		} catch ( \ImagickException $e ) {
			$img->clear();
			$img->destroy();
			throw new \RuntimeException( 'Failed to convert image: ' . esc_html( $e->getMessage() ) );
		}

		if ( 'png' === $target_format ) {
			$mime = 'image/png';
		} elseif ( 'webp' === $target_format ) {
			$mime = 'image/webp';
		} elseif ( 'jpeg' === $target_format ) {
			$mime = 'image/jpeg';
		} else {
			$mime = 'application/octet-stream';
		}

		$bin = new Binary_Image( $out, $mime, (int) $img->getImageWidth(), (int) $img->getImageHeight() );
		$img->clear();
		$img->destroy();
		return $bin;
	}
}
