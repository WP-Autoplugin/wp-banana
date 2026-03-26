<?php
/**
 * Command Palette admin integration.
 *
 * @package WPBanana\Admin
 * @since   0.8.0
 */

namespace WPBanana\Admin;

use WPBanana\Plugin;
use WPBanana\Services\Options;
use WPBanana\Util\Caps;

use function add_action;
use function admin_url;
use function apply_filters;
use function current_user_can;
use function get_current_screen;
use function is_array;
use function is_readable;
use function trailingslashit;
use function wp_enqueue_script;
use function wp_localize_script;
use function wp_register_script;
use function wp_script_is;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Provides the WP Banana Command Palette integration for supported WordPress admin screens.
 *
 * Handles feature gating, script registration, and localized command metadata used by the
 * JavaScript command registration layer.
 *
 * @since 0.9.0
 */
final class Command_Palette {

	private const MIN_WP_VERSION = '6.9';
	private const SCRIPT_HANDLE  = 'wp-banana-command-palette';

	/**
	 * Options service instance.
	 *
	 * @var Options
	 */
	private $options;

	/**
	 * Base plugin URL.
	 *
	 * @var string
	 */
	private $plugin_url;

	/**
	 * Base plugin directory path.
	 *
	 * @var string
	 */
	private $plugin_dir;

	/**
	 * Constructor.
	 *
	 * @param Options $options    Options service.
	 * @param string  $plugin_url Base plugin URL.
	 * @since 0.9.0
	 */
	public function __construct( Options $options, string $plugin_url ) {
		$this->options    = $options;
		$this->plugin_url = rtrim( $plugin_url, '/' );
		$this->plugin_dir = dirname( __DIR__, 2 );
	}

	/**
	 * Hook into WordPress admin.
	 *
	 * @return void
	 * @since 0.9.0
	 */
	public function register(): void {
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
	}

	/**
	 * Whether the current site and user can use the admin-wide Command Palette integration.
	 *
	 * @return bool
	 * @since 0.9.0
	 */
	public function is_supported(): bool {
		global $wp_version;

		if ( ! isset( $wp_version ) || version_compare( $wp_version, self::MIN_WP_VERSION, '<' ) ) {
			return false;
		}

		if ( ! $this->user_can_generate() && ! $this->user_can_manage() ) {
			return false;
		}

		/**
		 * Filters whether the WP Banana Command Palette integration is enabled.
		 *
		 * @param bool $enabled Whether the feature is enabled.
		 */
		return (bool) apply_filters( 'wp_banana_command_palette_enabled', true );
	}

	/**
	 * Register and enqueue the Command Palette bundle when supported.
	 *
	 * @param string $hook Current admin page hook suffix.
	 * @return void
	 * @since 0.9.0
	 */
	public function enqueue_assets( string $hook ): void {
		if ( ! $this->is_supported() ) {
			return;
		}

		$script_path = $this->get_script_path();
		if ( ! is_readable( $script_path ) ) {
			return;
		}

		if ( ! wp_script_is( self::SCRIPT_HANDLE, 'registered' ) ) {
			$asset = $this->get_asset_metadata();
			$deps  = isset( $asset['dependencies'] ) && is_array( $asset['dependencies'] ) ? $asset['dependencies'] : $this->get_default_dependencies();
			$ver   = isset( $asset['version'] ) ? (string) $asset['version'] : Plugin::VERSION;

			wp_register_script(
				self::SCRIPT_HANDLE,
				trailingslashit( $this->plugin_url ) . 'build/command-palette.js',
				$deps,
				$ver,
				true
			);
		}

		$payload         = $this->get_payload();
		$payload['hook'] = $hook;

		wp_localize_script( self::SCRIPT_HANDLE, 'wpBananaCommandPalette', $payload );
		wp_enqueue_script( self::SCRIPT_HANDLE );
	}

	/**
	 * Build the localized payload for the Command Palette bundle.
	 *
	 * @return array<string,mixed>
	 * @since 0.9.0
	 */
	public function get_payload(): array {
		$payload = [
			'urls'         => $this->get_command_urls(),
			'capabilities' => [
				'canGenerate' => $this->user_can_generate(),
				'canManage'   => $this->user_can_manage(),
			],
			'flags'        => [
				'isConnected'        => $this->options->is_connected(),
				'canOpenUploadPanel' => $this->user_can_generate(),
			],
			'screen'       => $this->get_screen_flags(),
		];

		/**
		 * Filters the localized Command Palette payload.
		 *
		 * @param array<string,mixed> $payload Localized payload.
		 */
		return (array) apply_filters( 'wp_banana_command_palette_payload', $payload );
	}

	/**
	 * Build command target URLs.
	 *
	 * @return array<string,string>
	 * @since 0.9.0
	 */
	public function get_command_urls(): array {
		return [
			'generatePage' => admin_url( 'upload.php?page=wp-banana-generate' ),
			'settings'     => admin_url( 'options-general.php?page=wp-banana' ),
			'logs'         => admin_url( 'admin.php?page=' . Logs_Page::SLUG ),
		];
	}

	/**
	 * Collect the current screen flags consumed by the JS bundle.
	 *
	 * @return array<string,mixed>
	 * @since 0.9.0
	 */
	public function get_screen_flags(): array {
		$screen = get_current_screen();

		if ( ! $screen ) {
			return [
				'base'       => '',
				'id'         => '',
				'postType'   => '',
				'isUpload'   => false,
				'isSettings' => false,
				'isGenerate' => false,
				'isLogs'     => false,
			];
		}

		$base = isset( $screen->base ) ? (string) $screen->base : '';
		$id   = isset( $screen->id ) ? (string) $screen->id : '';

		return [
			'base'       => $base,
			'id'         => $id,
			'postType'   => isset( $screen->post_type ) ? (string) $screen->post_type : '',
			'isUpload'   => 'upload' === $base || 'upload' === $id,
			'isSettings' => 'settings_page_wp-banana' === $id,
			'isGenerate' => 'media_page_wp-banana-generate' === $id,
			'isLogs'     => 'admin_page_' . Logs_Page::SLUG === $id,
		];
	}

	/**
	 * Whether the current user can access generation actions.
	 *
	 * @return bool
	 */
	private function user_can_generate(): bool {
		return current_user_can( Caps::GENERATE );
	}

	/**
	 * Whether the current user can access management actions.
	 *
	 * @return bool
	 */
	private function user_can_manage(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Get the built script path.
	 *
	 * @return string
	 */
	private function get_script_path(): string {
		return trailingslashit( $this->plugin_dir ) . 'build/command-palette.js';
	}

	/**
	 * Get generated asset metadata for the Command Palette bundle.
	 *
	 * @return array<string,mixed>
	 */
	private function get_asset_metadata(): array {
		$asset_path = trailingslashit( $this->plugin_dir ) . 'build/command-palette.asset.php';
		if ( is_readable( $asset_path ) ) {
			$asset = include $asset_path;
			if ( is_array( $asset ) ) {
				return $asset;
			}
		}

		return [
			'dependencies' => $this->get_default_dependencies(),
			'version'      => Plugin::VERSION,
		];
	}

	/**
	 * Default script dependencies used before the generated asset file exists.
	 *
	 * @return string[]
	 */
	private function get_default_dependencies(): array {
		return [
			'wp-commands',
			'wp-data',
			'wp-element',
			'wp-i18n',
			'wp-icons',
		];
	}
}
