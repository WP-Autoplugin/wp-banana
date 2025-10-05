<?php
/**
 * Models list caching service.
 *
 * @package WPBanana\Services
 * @since   0.1.0
 */

namespace WPBanana\Services;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Caches model lists per provider using transients.
 */
final class Models_Cache {

	/**
	 * Get cached models list.
	 *
	 * @param string $provider Provider slug.
	 * @return array|null
	 */
	public function get( string $provider ): ?array {
		$key    = 'wp_banana_models_' . $provider;
		$cached = get_transient( $key );
		return is_array( $cached ) ? $cached : null;
	}

	/**
	 * Store models list.
	 *
	 * @param string $provider Provider slug.
	 * @param array  $models   List of model slugs.
	 * @param int    $ttl      TTL seconds.
	 * @return void
	 */
	public function set( string $provider, array $models, int $ttl = 86400 ): void {
		set_transient( 'wp_banana_models_' . $provider, $models, $ttl );
	}

	/**
	 * Clear cached list for provider.
	 *
	 * @param string $provider Provider slug.
	 * @return void
	 */
	public function clear( string $provider ): void {
		delete_transient( 'wp_banana_models_' . $provider );
	}
}
