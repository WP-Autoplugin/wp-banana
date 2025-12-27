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

		register_activation_hook( self::$plugin_file, [ self::class, 'activate' ] );
		add_action( 'init', [ self::class, 'run_upgrades' ] );

		// Handle new sites created on multisite after network activation.
		add_action( 'wp_initialize_site', [ self::class, 'on_new_site' ], 10, 1 );

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
		if ( is_multisite() ) {
			self::run_on_all_sites( [ self::class, 'uninstall_single_site' ] );
		} else {
			self::uninstall_single_site();
		}
	}

	/**
	 * Remove plugin data for a single site.
	 *
	 * @return void
	 */
	private static function uninstall_single_site(): void {
		// Remove options and transients; keep attachments by default.
		delete_option( \WPBanana\Services\Options::OPTION_NAME );
		delete_option( self::VERSION_OPTION );

		// Remove custom capabilities from administrator role.
		$role = get_role( 'administrator' );
		if ( $role ) {
			foreach ( Caps::all() as $cap ) {
				$role->remove_cap( $cap );
			}
		}

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
	 * Run initial setup on plugin activation.
	 *
	 * @param bool $network_wide Whether this is a network-wide activation.
	 * @return void
	 */
	public static function activate( bool $network_wide = false ): void {
		if ( is_multisite() && $network_wide ) {
			self::run_on_all_sites( [ self::class, 'activate_single_site' ] );
		} else {
			self::activate_single_site();
		}
	}

	/**
	 * Activate plugin for a single site.
	 *
	 * @return void
	 */
	private static function activate_single_site(): void {
		// Only set version on fresh install; upgrades handled by run_upgrades().
		if ( ! get_option( self::VERSION_OPTION ) ) {
			self::grant_caps();
			update_option( self::VERSION_OPTION, self::VERSION );
		}
	}

	/**
	 * Handle new site creation on multisite.
	 *
	 * @param \WP_Site $new_site New site object.
	 * @return void
	 */
	public static function on_new_site( \WP_Site $new_site ): void {
		// Only run if plugin is network-activated.
		if ( ! is_plugin_active_for_network( plugin_basename( self::$plugin_file ) ) ) {
			return;
		}

		switch_to_blog( (int) $new_site->blog_id );
		self::activate_single_site();
		restore_current_blog();
	}

	/**
	 * Run version-specific upgrade callbacks when updating from older version.
	 *
	 * @return void
	 */
	public static function run_upgrades(): void {
		$stored_version = get_option( self::VERSION_OPTION, '' );

		// Already up to date.
		if ( version_compare( $stored_version, self::VERSION, '>=' ) ) {
			return;
		}

		$upgrades = self::upgrade_steps();
		foreach ( $upgrades as $version => $callback ) {
			if ( version_compare( $stored_version, $version, '>=' ) ) {
				continue;
			}
			call_user_func( $callback );
		}

		update_option( self::VERSION_OPTION, self::VERSION );
	}

	/**
	 * Grant custom capabilities to administrators.
	 *
	 * @return void
	 */
	private static function grant_caps(): void {
		$role = get_role( 'administrator' );
		if ( $role ) {
			foreach ( Caps::all() as $cap ) {
				$role->add_cap( $cap );
			}
		}
	}

	/**
	 * Upgrade callbacks keyed by version.
	 *
	 * @return array<string,callable>
	 */
	private static function upgrade_steps(): array {
		return [
			'0.7' => [ self::class, 'grant_caps' ],
		];
	}

	/**
	 * Run a callback on all sites in the network.
	 *
	 * @param callable $callback Callback to run on each site.
	 * @return void
	 */
	private static function run_on_all_sites( callable $callback ): void {
		$site_ids = get_sites(
			[
				'fields'     => 'ids',
				'network_id' => get_current_network_id(),
				'number'     => 0, // All sites.
			]
		);

		foreach ( $site_ids as $site_id ) {
			switch_to_blog( $site_id );
			call_user_func( $callback );
			restore_current_blog();
		}
	}
}
