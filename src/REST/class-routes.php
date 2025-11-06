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
use WPBanana\Services\Logging_Service;

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
	 * Logging service.
	 *
	 * @var Logging_Service
	 */
	private $logger;

	/**
	 * Constructor.
	 *
	 * @param Options         $options Options service.
	 * @param Edit_Buffer     $buffer  Edit buffer store.
	 * @param Logging_Service $logger  Logging service.
	 */
	public function __construct( Options $options, Edit_Buffer $buffer, Logging_Service $logger ) {
		$this->options = $options;
		$this->buffer  = $buffer;
		$this->logger  = $logger;
	}

	/**
	 * Register plugin REST routes.
	 *
	 * @return void
	 */
	public function register(): void {
		( new Generate_Controller( $this->options, $this->logger ) )->register( self::NAMESPACE );
		( new Edit_Controller( $this->options, $this->buffer, $this->logger ) )->register( self::NAMESPACE );
		( new Models_Controller( $this->options ) )->register( self::NAMESPACE );
	}
}
