<?php
/**
 * REST controller: /models endpoint.
 *
 * @package WPBanana\REST
 * @since   0.1.0
 */

namespace WPBanana\REST;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WPBanana\Services\Models_Catalog;
use WPBanana\Services\Options;

/**
 * Returns available provider models (static for now).
 */
final class Models_Controller {

	/**
	 * Options service instance.
	 *
	 * @var Options
	 */
	private $options;

	/**
	 * Constructor.
	 *
	 * @param Options $options Options service.
	 */
	public function __construct( Options $options ) {
		$this->options = $options;
	}

	/**
	 * Register route definition.
	 *
	 * @param string $ns REST namespace.
	 * @return void
	 */
	public function register( $ns ): void {
		register_rest_route(
			$ns,
			'/models',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'handle' ],
				'permission_callback' => [ $this, 'can_access' ],
				'args'                => [
					'provider' => [
						'type'     => 'string',
						'required' => false,
					],
					'purpose'  => [
						'type'     => 'string', // generate|edit.
						'required' => false,
					],
				],
			]
		);
	}

	/**
	 * Permission check for route access.
	 *
	 * @return bool
	 */
	public function can_access(): bool {
		return current_user_can( 'upload_files' );
	}

	/**
	 * Handle models list request.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle( WP_REST_Request $req ) {
		if ( ! $this->options->is_connected() ) {
			return new WP_Error( 'wp_banana_not_connected', __( 'No provider configured. Add a key in settings.', 'wp-banana' ) );
		}
		$provider = $req->get_param( 'provider' );
		$provider = is_string( $provider ) ? sanitize_key( $provider ) : 'gemini';
		if ( ! in_array( $provider, [ 'gemini', 'replicate', 'openai' ], true ) ) {
			return new WP_Error( 'wp_banana_invalid_provider', __( 'Unsupported provider.', 'wp-banana' ) );
		}

		$purpose = $req->get_param( 'purpose' );
		$purpose = is_string( $purpose ) ? sanitize_key( $purpose ) : 'generate';
		if ( ! in_array( $purpose, [ 'generate', 'edit' ], true ) ) {
			$purpose = 'generate';
		}

		$models = Models_Catalog::get( $purpose, $provider );

		return new WP_REST_Response( [ 'models' => array_values( $models ) ], 200 );
	}
}
