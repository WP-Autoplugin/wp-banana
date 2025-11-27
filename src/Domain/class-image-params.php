<?php
/**
 * DTO for image generation parameters.
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
 * Image generation parameters.
 */
final class Image_Params {

	/**
	 * Prompt text.
	 *
	 * @var string
	 */
	public $prompt;
	/**
	 * Provider slug.
	 *
	 * @var string
	 */
	public $provider;
	/**
	 * Model name.
	 *
	 * @var string
	 */
	public $model;
	/**
	 * Target width in pixels.
	 *
	 * @var int
	 */
	public $width;
	/**
	 * Target height in pixels.
	 *
	 * @var int
	 */
	public $height;
	/**
	 * Output format.
	 *
	 * @var string png|webp|jpeg
	 */
	public $format;
	/**
	 * Requested aspect ratio if provided.
	 *
	 * @var string|null
	 */
	public $aspect_ratio;
	/**
	 * Requested resolution if provided (e.g. 1K, 2K, 4K).
	 *
	 * @var string|null
	 */
	public $resolution;
	/**
	 * Reference images supplied alongside the prompt.
	 *
	 * @var Reference_Image[]
	 */
	public $reference_images;

	/**
	 * Constructor.
	 *
	 * @param string            $prompt            Prompt text.
	 * @param string            $provider          Provider slug.
	 * @param string            $model             Model name.
	 * @param int               $width             Target width.
	 * @param int               $height            Target height.
	 * @param string            $format            Output format.
	 * @param string|null       $aspect_ratio      Optional aspect ratio (e.g. 16:9).
	 * @param string|null       $resolution        Optional resolution (e.g. 1K, 2K, 4K).
	 * @param Reference_Image[] $reference_images  Optional array of reference images.
	 */
	public function __construct( string $prompt, string $provider, string $model, int $width, int $height, string $format, ?string $aspect_ratio = null, ?string $resolution = null, array $reference_images = [] ) {
		$this->prompt           = $prompt;
		$this->provider         = $provider;
		$this->model            = $model;
		$this->width            = $width;
		$this->height           = $height;
		$this->format           = $format;
		$this->aspect_ratio     = $aspect_ratio;
		$this->resolution       = $resolution;
		$this->reference_images = array_values( $reference_images );
	}
}
