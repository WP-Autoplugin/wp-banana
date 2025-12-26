<?php
/**
 * Core plugin bootstrap and service wiring.
 *
 * @package WPBanana
 * @since   0.1.0
 */

namespace WPBanana;

use WPBanana\Admin\Media_Hooks;
use WPBanana\Admin\Settings_Page;
use WPBanana\Admin\Generate_Page;
use WPBanana\Admin\Logs_Page;
use WPBanana\Admin\AI_Editor_Integration;
use WPBanana\Admin\Updater;
use WPBanana\Abilities\Abilities;
use WPBanana\REST\Routes;
use WPBanana\Services\Options;
use WPBanana\Services\Edit_Buffer;
use WPBanana\Services\Logging_Service;
use WPBanana\Util\Caps;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Main plugin container.
 */
final class Plugin {

	public const VERSION         = WP_BANANA_VERSION;
	private const VERSION_OPTION = 'wp_banana_version';

	/**
	 * Main plugin file path.
	 *
	 * @var string
	 */
	private static $plugin_file;

	/**
	 * Base plugin directory path.
	 *
	 * @var string
	 */
	private static $plugin_dir;

	/**
	 * Base plugin URL.
	 *
	 * @var string
	 */
	private static $plugin_url;

	/**
	 * Initialize plugin services and hooks.
	 *
	 * @param string $plugin_file Absolute path to main plugin file.
	 * @return void
	 */
	public static function init( string $plugin_file ): void {
		self::$plugin_file = $plugin_file;
		self::$plugin_dir  = plugin_dir_path( $plugin_file );
		self::$plugin_url  = plugin_dir_url( $plugin_file );

		$options = new Options();
		$buffer  = new Edit_Buffer();
		$logger  = new Logging_Service( $options );

		add_action( 'init', [ self::class, 'run_upgrades' ] );

		// Register Abilities API definitions (WP 6.9+).
		( new Abilities( $options, $buffer, $logger ) )->register();

		// Register REST routes.
		add_action(
			'rest_api_init',
			static function () use ( $options, $buffer, $logger ) {
				( new Routes( $options, $buffer, $logger ) )->register();
			}
		);

		// Admin UI wiring.
		if ( is_admin() ) {
			( new Settings_Page( $options, self::$plugin_file, self::$plugin_url ) )->register();
			( new Media_Hooks( $options, self::$plugin_url ) )->register();
			( new Generate_Page( $options, self::$plugin_url ) )->register();
			( new Logs_Page( $logger ) )->register();
			( new AI_Editor_Integration( $buffer ) )->register();

			// GitHub plugin updater.
			new Updater();
		}

		// Register uninstall cleanup.
		register_uninstall_hook( self::$plugin_file, [ self::class, 'uninstall' ] );
	}

	/**
	 * Get plugin main file path.
	 *
	 * @return string
	 */
	public static function file(): string {
		return self::$plugin_file;
	}

	/**
	 * Get plugin directory path.
	 *
	 * @return string
	 */
	public static function dir(): string {
		return self::$plugin_dir;
	}

	/**
	 * Get plugin base URL.
	 *
	 * @return string
	 */
	public static function url(): string {
		return self::$plugin_url;
	}

	/**
	 * Remove plugin options and transients on uninstall.
	 *
	 * @return void
	 */
	public static function uninstall(): void {
		// Remove options and transients; keep attachments by default.
		delete_option( \WPBanana\Services\Options::OPTION_NAME );
		delete_option( self::VERSION_OPTION );
		// Remove models cache transients.
		global $wpdb;
		$transient_like         = $wpdb->esc_like( '_transient_wp_banana_models_' ) . '%';
		$transient_timeout_like = str_replace( '_transient_', '_transient_timeout_', $transient_like );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$transient_like,
				$transient_timeout_like
			)
		);

		// Remove logging table.
		Logging_Service::drop_table();
	}

	/**
	 * Grant custom capabilities to administrators on upgrade.
	 *
	 * @return void
	 */
	public static function run_upgrades(): void {
		$stored_version = get_option( self::VERSION_OPTION, '' );
		$from_version   = is_string( $stored_version ) && '' !== $stored_version ? $stored_version : '0.0.0';

		$upgrades = self::upgrade_steps();
		if ( empty( $upgrades ) ) {
			update_option( self::VERSION_OPTION, self::VERSION );
			return;
		}

		foreach ( $upgrades as $version => $callback ) {
			if ( version_compare( $from_version, $version, '>=' ) ) {
				continue;
			}
			call_user_func( $callback );
		}

		update_option( self::VERSION_OPTION, self::VERSION );
	}

	/**
	 * Upgrade callbacks keyed by version.
	 *
	 * @return array<string,callable>
	 */
	private static function upgrade_steps(): array {
		return [
			'0.7.0' => static function (): void {
				$role = get_role( 'administrator' );
				if ( $role ) {
					foreach ( Caps::all() as $cap ) {
						$role->add_cap( $cap );
					}
				}
			},
		];
	}
}
