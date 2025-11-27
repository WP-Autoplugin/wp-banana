<?php
/**
 * Standalone Generate Image admin page.
 *
 * @package WPBanana\\Admin
 * @since   0.1.0
 */

namespace WPBanana\Admin;

use WPBanana\Plugin;
use WPBanana\Services\Options;
use WPBanana\Services\Models_Catalog;
use WPBanana\Domain\Aspect_Ratios;
use WPBanana\Domain\Resolutions;

use function __;
use function admin_url;
use function current_user_can;
use function esc_html__;
use function esc_url;
use function wp_die;
use function wp_localize_script;
use function wp_register_script;
use function wp_script_is;
use function wp_enqueue_script;
use function wp_style_is;
use function wp_enqueue_style;
use function trailingslashit;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the Generate Image submenu under Media and renders the page.
 */
final class Generate_Page {

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
	 * Hook suffix returned when registering the admin page.
	 *
	 * @var string
	 */
	private $hook_suffix = '';

	/**
	 * Constructor.
	 *
	 * @param Options $options    Options service.
	 * @param string  $plugin_url Base plugin URL.
	 */
	public function __construct( Options $options, string $plugin_url ) {
		$this->options    = $options;
		$this->plugin_url = rtrim( $plugin_url, '/' );
		$this->plugin_dir = dirname( __DIR__, 2 );
	}

	/**
	 * Hook into admin menu and enqueue events.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_menu', [ $this, 'add_menu' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
	}

	/**
	 * Add the Generate Image submenu under Media.
	 *
	 * @return void
	 */
	public function add_menu(): void {
		$this->hook_suffix = add_media_page(
			__( 'Generate Image', 'wp-banana' ),
			__( 'Generate Image', 'wp-banana' ),
			'upload_files',
			'wp-banana-generate',
			[ $this, 'render' ]
		);
	}

	/**
	 * Enqueue scripts and localized data when viewing the Generate Image page.
	 *
	 * @param string $hook Current admin hook.
	 * @return void
	 */
	public function enqueue_assets( $hook ): void {
		if ( empty( $this->hook_suffix ) || $hook !== $this->hook_suffix ) {
			return;
		}
		if ( ! $this->options->is_connected() ) {
			return;
		}

		$handle = 'wp-banana-generate-page';
		if ( ! wp_script_is( $handle, 'registered' ) ) {
			$asset = $this->get_asset_metadata();
			$src   = trailingslashit( $this->plugin_url ) . 'build/generate-page.js';
			$deps  = isset( $asset['dependencies'] ) && is_array( $asset['dependencies'] ) ? $asset['dependencies'] : [];
			$ver   = isset( $asset['version'] ) ? (string) $asset['version'] : Plugin::VERSION;

			wp_register_script( $handle, $src, $deps, $ver, true );
		}

		$default_generator_model = (string) $this->options->get( 'default_generator_model', 'gemini-2.5-flash-image-preview' );
		$default_aspect_ratio    = (string) $this->options->get( 'generation_defaults.aspect_ratio', Aspect_Ratios::default() );
		$default_aspect_ratio    = Aspect_Ratios::sanitize( $default_aspect_ratio );
		if ( '' === $default_aspect_ratio ) {
			$default_aspect_ratio = Aspect_Ratios::default();
		}
		$default_resolution = (string) $this->options->get( 'generation_defaults.resolution', Resolutions::default() );
		$default_resolution = Resolutions::sanitize( $default_resolution );
		if ( '' === $default_resolution ) {
			$default_resolution = Resolutions::default();
		}

		wp_localize_script(
			$handle,
			'wpBananaGeneratePage',
			[
				'restNamespace'            => 'wp-banana/v1',
				'providers'                => $this->build_providers_payload(),
				'redirectUrl'              => admin_url( 'upload.php' ),
				'defaultGeneratorModel'    => $default_generator_model,
				'defaultGeneratorProvider' => $this->detect_provider_for_model( $default_generator_model ),
				'defaultAspectRatio'       => $default_aspect_ratio,
				'aspectRatioOptions'       => Aspect_Ratios::all(),
				'defaultResolution'        => $default_resolution,
				'resolutionOptions'        => Resolutions::all(),
			]
		);

		wp_enqueue_script( $handle );

		// Ensure WordPress component styles are present so UI elements render correctly.
		$this->ensure_component_styles();
	}

	/**
	 * Render the Generate Image admin page.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( 'upload_files' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'wp-banana' ) );
		}

		echo '<div class="wrap">';
		echo '<h1 class="wp-heading-inline">' . esc_html__( 'Generate Image', 'wp-banana' ) . '</h1>';
		echo '<p class="description" style="margin-bottom: 1em;">' . esc_html__( 'Create AI-generated images and they will appear in your Media Library automatically. Use the "Edit Image" button to modify them with AI.', 'wp-banana' ) . '</p>';
		echo '<hr class="wp-header-end" />';

		if ( ! $this->options->is_connected() ) {
			echo '<div class="notice notice-warning"><p>';
			printf(
				/* translators: 1: opening anchor tag linking to the plugin settings page, 2: closing anchor tag */
				esc_html__( 'Connect a provider in %1$sSettings → AI Images%2$s to start generating images.', 'wp-banana' ),
				'<a href="' . esc_url( admin_url( 'options-general.php?page=wp-banana' ) ) . '">',
				'</a>'
			);
			echo '</p></div>';
		} else {
			echo '<div id="wp-banana-generate-page" class="wp-banana-generate-page"></div>';
		}

		echo '</div>';
	}

	/**
	 * Retrieve generated asset metadata for the generate page bundle.
	 *
	 * @return array<string,mixed>
	 */
	private function get_asset_metadata(): array {
		$asset_path = trailingslashit( $this->plugin_dir ) . 'build/generate-page.asset.php';
		if ( is_readable( $asset_path ) ) {
			$asset = include $asset_path;
			if ( is_array( $asset ) ) {
				return $asset;
			}
		}

		return [
			'dependencies' => [ 'wp-element', 'wp-components', 'wp-api-fetch', 'wp-i18n' ],
			'version'      => Plugin::VERSION,
		];
	}

	/**
	 * Build providers payload consumed by the React panel.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function build_providers_payload(): array {
		$providers   = [];
		$gemini      = $this->options->get_provider_config( 'gemini' );
		$providers[] = [
			'slug'          => 'gemini',
			'label'         => __( 'Gemini', 'wp-banana' ),
			'connected'     => ! empty( $gemini['api_key'] ),
			'default_model' => isset( $gemini['default_model'] ) ? (string) $gemini['default_model'] : 'gemini-2.5-flash-image-preview',
		];
		$openai      = $this->options->get_provider_config( 'openai' );
		$providers[] = [
			'slug'          => 'openai',
			'label'         => __( 'OpenAI', 'wp-banana' ),
			'connected'     => ! empty( $openai['api_key'] ),
			'default_model' => isset( $openai['default_model'] ) ? (string) $openai['default_model'] : 'gpt-image-1',
		];
		$replicate   = $this->options->get_provider_config( 'replicate' );
		$providers[] = [
			'slug'          => 'replicate',
			'label'         => __( 'Replicate', 'wp-banana' ),
			'connected'     => ! empty( $replicate['api_token'] ),
			'default_model' => isset( $replicate['default_model'] ) ? (string) $replicate['default_model'] : 'black-forest-labs/flux',
		];

		return $providers;
	}

	/**
	 * Attempt to infer the provider slug for a generator model.
	 *
	 * @param string $model Model identifier.
	 * @return string
	 */
	private function detect_provider_for_model( string $model ): string {
		$model = trim( $model );
		if ( '' === $model ) {
			return '';
		}

		$catalog = Models_Catalog::all();
		if ( isset( $catalog['generate'] ) && is_array( $catalog['generate'] ) ) {
			foreach ( $catalog['generate'] as $provider => $models ) {
				if ( is_array( $models ) && in_array( $model, $models, true ) ) {
					return (string) $provider;
				}
			}
		}

		$providers = $this->options->get( 'providers', [] );
		if ( is_array( $providers ) ) {
			foreach ( $providers as $provider => $config ) {
				if ( isset( $config['default_model'] ) && $config['default_model'] === $model ) {
					return (string) $provider;
				}
			}
		}

		return '';
	}

	/**
	 * Ensure WordPress component styles are present for shared UI elements.
	 *
	 * @return void
	 */
	private function ensure_component_styles(): void {
		if ( wp_style_is( 'wp-components', 'registered' ) || wp_style_is( 'wp-components', 'enqueued' ) ) {
			wp_enqueue_style( 'wp-components' );
		}

		if ( wp_style_is( 'wp-components-theme', 'registered' ) || wp_style_is( 'wp-components-theme', 'enqueued' ) ) {
			wp_enqueue_style( 'wp-components-theme' );
		}

		if ( wp_style_is( 'media-views', 'registered' ) || wp_style_is( 'media-views', 'enqueued' ) ) {
			wp_enqueue_style( 'media-views' );
		}
	}
}
