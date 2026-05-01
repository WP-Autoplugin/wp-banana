<?php
/**
 * WP Banana Updater class.
 *
 * @package WPBanana
 */

namespace WPBanana\Admin;

use WPBanana\GitHub_Updater;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class that handles the GitHub updater.
 */
class Updater {

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'init', [ $this, 'github_updater_init' ] );
	}

	/**
	 * Initialize the GitHub updater.
	 *
	 * @return void
	 */
	public function github_updater_init() {
		if ( ! is_admin() ) {
			return;
		}

		// Allow opting out via constant or filter. Define WP_BANANA_DISABLE_GITHUB_UPDATER as true,
		// or return false from the 'wp_banana_github_updater_enabled' filter to suppress all API requests.
		if ( defined( 'WP_BANANA_DISABLE_GITHUB_UPDATER' ) && WP_BANANA_DISABLE_GITHUB_UPDATER ) {
			return;
		}
		if ( ! apply_filters( 'wp_banana_github_updater_enabled', true ) ) {
			return;
		}

		$config = [
			'slug'               => plugin_basename( WP_BANANA_DIR . 'wp-banana.php' ),
			'proper_folder_name' => dirname( plugin_basename( WP_BANANA_DIR . 'wp-banana.php' ) ),
			'api_url'            => 'https://api.github.com/repos/WP-Autoplugin/wp-banana',
			'raw_url'            => 'https://raw.githubusercontent.com/WP-Autoplugin/wp-banana/main/',
			'github_url'         => 'https://github.com/WP-Autoplugin/wp-banana',
			'zip_url'            => 'https://github.com/WP-Autoplugin/wp-banana/archive/refs/heads/main.zip',
			'requires'           => '6.0',
			'tested'             => '6.9',
			'description'        => esc_html__( 'AI image generation and editing with 30+ AI models across 4 platforms, right in your WordPress media library.', 'wp-banana' ),
			'homepage'           => 'https://github.com/WP-Autoplugin/wp-banana',
			'version'            => WP_BANANA_VERSION,
		];

		// Instantiate the updater class.
		new GitHub_Updater( $config );
	}
}
