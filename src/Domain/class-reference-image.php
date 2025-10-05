<?php
/**
 * DTO representing a temporary reference image used for AI generation.
 *
 * @package WPBanana\Domain
 */

namespace WPBanana\Domain;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Immutable value object describing a reference image.
 */
final class Reference_Image {

	/**
	 * Absolute file path to the temporary image.
	 *
	 * @var string
	 */
	public $path;

	/**
	 * MIME type for the image.
	 *
	 * @var string
	 */
	public $mime;

	/**
	 * Original width in pixels.
	 *
	 * @var int
	 */
	public $width;

	/**
	 * Original height in pixels.
	 *
	 * @var int
	 */
	public $height;

	/**
	 * Original filename supplied by the user.
	 *
	 * @var string
	 */
	public $filename;

	/**
	 * Constructor.
	 *
	 * @param string $path     Absolute path to the image file.
	 * @param string $mime     MIME type.
	 * @param int    $width    Width in pixels.
	 * @param int    $height   Height in pixels.
	 * @param string $filename Original filename.
	 */
	public function __construct( string $path, string $mime, int $width, int $height, string $filename ) {
		$this->path     = $path;
		$this->mime     = $mime;
		$this->width    = $width;
		$this->height   = $height;
		$this->filename = $filename;
	}
}
