<?php
/**
 * Plugin Name: WP Nano Banana
 * Description: AI image generation and editing via Gemini, Replicate and OpenAI, right in your WordPress media library.
 * Version: 0.6
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author: Balázs Piller
 * Text Domain: wp-banana
 * Domain Path: /languages
 * License: GPLv2 or later
 *
 * @package WPBanana
 * @since   0.1.0
 */

// Abort if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Define plugin constants.
define( 'WP_BANANA_VERSION', '0.6' );
define( 'WP_BANANA_DIR', trailingslashit( plugin_dir_path( __FILE__ ) ) );
define( 'WP_BANANA_URL', trailingslashit( plugin_dir_url( __FILE__ ) ) );
define( 'WP_BANANA_FILE', __FILE__ );
define( 'WP_BANANA_BASENAME', plugin_basename( __FILE__ ) );

// Minimal environment checks before anything else.
if ( version_compare( PHP_VERSION, '7.2', '<' ) ) {
	add_action(
		'admin_notices',
		function () {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'WP Nano Banana requires PHP 7.2 or higher.', 'wp-banana' ) . '</p></div>';
		}
	);
	return;
}

global $wp_version;
if ( ! isset( $wp_version ) || version_compare( $wp_version, '6.6', '<' ) ) {
	add_action(
		'admin_notices',
		function () {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'WP Nano Banana requires WordPress 6.6 or higher.', 'wp-banana' ) . '</p></div>';
		}
	);
	return;
}

if ( ! extension_loaded( 'imagick' ) ) {
	add_action(
		'admin_notices',
		function () {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'WP Nano Banana requires the Imagick PHP extension. Please enable Imagick to use this plugin.', 'wp-banana' ) . '</p></div>';
		}
	);
	return;
}

// Load Composer autoloader.
require __DIR__ . '/vendor/autoload.php';

// Bootstrap the plugin.
add_action(
	'plugins_loaded',
	static function () {
		// Initialize plugin services.
		try {
			\WPBanana\Plugin::init( __FILE__ );
		} catch ( \Throwable $e ) {
			if ( is_admin() ) {
				add_action(
					'admin_notices',
					function () use ( $e ) {
						echo '<div class="notice notice-error"><p>' . esc_html__( 'WP Nano Banana failed to initialize:', 'wp-banana' ) . ' ' . esc_html( $e->getMessage() ) . '</p></div>';
					}
				);
			}
		}
	}
);
