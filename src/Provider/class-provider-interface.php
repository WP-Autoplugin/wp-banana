<?php
/**
 * Provider interface abstraction.
 *
 * @package WPBanana\Provider
 * @since   0.1.0
 */

namespace WPBanana\Provider;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WPBanana\Domain\Binary_Image;
use WPBanana\Domain\Edit_Params;
use WPBanana\Domain\Image_Params;

/**
 * Contract for image providers.
 */
interface Provider_Interface {

	/**
	 * Generate an image from prompt and parameters.
	 *
	 * @param Image_Params $p Parameters.
	 * @return Binary_Image
	 */
	public function generate( Image_Params $p ): Binary_Image;

	/**
	 * Edit an existing image based on parameters.
	 *
	 * @param Edit_Params $p Parameters.
	 * @return Binary_Image
	 */
	public function edit( Edit_Params $p ): Binary_Image;

	/**
	 * Capability introspection.
	 *
	 * @param string $capability Capability string.
	 * @return bool
	 */
	public function supports( string $capability ): bool;
}
