<?php
/**
 * REST API route registration.
 *
 * @package WPBanana\REST
 * @since   0.1.0
 */

namespace WPBanana\REST;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WPBanana\Services\Options;
use WPBanana\Services\Edit_Buffer;

/**
 * Registers REST controllers under the plugin namespace.
 */
final class Routes {

	public const NAMESPACE = 'wp-banana/v1';

	/**
	 * Options service instance.
	 *
	 * @var Options
	 */
	private $options;

	/**
	 * Edit buffer store.
	 *
	 * @var Edit_Buffer
	 */
	private $buffer;

	/**
	 * Constructor.
	 *
	 * @param Options     $options Options service.
	 * @param Edit_Buffer $buffer  Edit buffer store.
	 */
	public function __construct( Options $options, Edit_Buffer $buffer ) {
		$this->options = $options;
		$this->buffer  = $buffer;
	}

	/**
	 * Register plugin REST routes.
	 *
	 * @return void
	 */
	public function register(): void {
		( new Generate_Controller( $this->options ) )->register( self::NAMESPACE );
		( new Edit_Controller( $this->options, $this->buffer ) )->register( self::NAMESPACE );
		( new Models_Controller( $this->options ) )->register( self::NAMESPACE );
	}
}
