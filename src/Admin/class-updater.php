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

		$config = [
			'slug'               => plugin_basename( WP_BANANA_DIR . 'wp-banana.php' ),
			'proper_folder_name' => dirname( plugin_basename( WP_BANANA_DIR . 'wp-banana.php' ) ),
			'api_url'            => 'https://api.github.com/repos/WP-Autoplugin/wp-banana',
			'raw_url'            => 'https://raw.githubusercontent.com/WP-Autoplugin/wp-banana/main/',
			'github_url'         => 'https://github.com/WP-Autoplugin/wp-banana',
			'zip_url'            => 'https://github.com/WP-Autoplugin/wp-banana/archive/refs/heads/main.zip',
			'requires'           => '6.0',
			'tested'             => '6.8',
			'description'        => esc_html__( 'AI image generation and editing via Gemini, Replicate and OpenAI, right in your WordPress media library.', 'wp-banana' ),
			'homepage'           => 'https://github.com/WP-Autoplugin/wp-banana',
			'version'            => WP_BANANA_VERSION,
		];

		// Instantiate the updater class.
		new GitHub_Updater( $config );
	}
}
