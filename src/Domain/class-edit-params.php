<?php
/**
 * DTO for image edit parameters.
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
 * Image edit parameters.
 */
final class Edit_Params {

	/**
	 * Attachment ID.
	 *
	 * @var int
	 */
	public $attachment_id;
	/**
	 * Edit prompt text.
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
	 * Output format.
	 *
	 * @var string png|webp|jpeg
	 */
	public $format;
	/**
	 * Source absolute file path.
	 *
	 * @var string absolute path
	 */
	public $source_file;
	/**
	 * Target width in pixels.
	 *
	 * @var int
	 */
	public $target_width;
	/**
	 * Target height in pixels.
	 *
	 * @var int
	 */
	public $target_height;
	/**
	 * Save mode behavior.
	 *
	 * @var string new|replace
	 */
	public $save_mode;
	/**
	 * Additional reference images supplied by the client.
	 *
	 * @var Reference_Image[]
	 */
	public $reference_images;

	/**
	 * Constructor.
	 *
	 * @param int               $attachment_id Attachment ID.
	 * @param string            $prompt       Edit prompt.
	 * @param string            $provider     Provider slug.
	 * @param string            $model        Model name.
	 * @param string            $format       Output format.
	 * @param string            $source_file     Source absolute path.
	 * @param int               $target_width    Target width.
	 * @param int               $target_height   Target height.
	 * @param string            $save_mode       Save mode.
	 * @param Reference_Image[] $reference_images Optional reference images.
	 */
	public function __construct(
		int $attachment_id,
		string $prompt,
		string $provider,
		string $model,
		string $format,
		string $source_file,
		int $target_width,
		int $target_height,
		string $save_mode,
		array $reference_images = []
	) {
		$this->attachment_id    = $attachment_id;
		$this->prompt           = $prompt;
		$this->provider         = $provider;
		$this->model            = $model;
		$this->format           = $format;
		$this->source_file      = $source_file;
		$this->target_width     = $target_width;
		$this->target_height    = $target_height;
		$this->save_mode        = $save_mode;
		$this->reference_images = array_values( $reference_images );
	}
}
