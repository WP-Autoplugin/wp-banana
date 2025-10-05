<?php
/**
 * Binary image value object.
 *
 * @package WPBanana\Domain
 * @since   0.1.0
 */

namespace WPBanana\Domain;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Holds image bytes, mime type, and dimensions.
 */
final class Binary_Image {

	/**
	 * Raw image bytes.
	 *
	 * @var string
	 */
	public $bytes;
	/**
	 * MIME type.
	 *
	 * @var string
	 */
	public $mime;
	/**
	 * Width in pixels.
	 *
	 * @var int
	 */
	public $width;
	/**
	 * Height in pixels.
	 *
	 * @var int
	 */
	public $height;

	/**
	 * Constructor.
	 *
	 * @param string $bytes Raw bytes.
	 * @param string $mime  MIME type.
	 * @param int    $width Width.
	 * @param int    $height Height.
	 */
	public function __construct( string $bytes, string $mime, int $width, int $height ) {
		$this->bytes  = $bytes;
		$this->mime   = $mime;
		$this->width  = $width;
		$this->height = $height;
	}
}
