<?php
/**
 * Media Library hooks and enqueues.
 *
 * @package WPBanana\Admin
 * @since   0.1.0
 */

namespace WPBanana\Admin;

use WPBanana\Plugin;
use WPBanana\Services\Options;
use WPBanana\Services\Models_Catalog;
use WPBanana\Services\Attachment_Metadata;
use WPBanana\Domain\Aspect_Ratios;
use WPBanana\Domain\Resolutions;
use WPBanana\Util\Caps;
use WP_Post;
use function current_user_can;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Injects buttons and scripts into Media Library screens.
 */
final class Media_Hooks {

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
	 * Handles that have received localized data.
	 *
	 * @var array<string,bool>
	 */
	private $localized_handles = [];

	/**
	 * Constructor.
	 *
	 * @param Options $options    Options service.
	 * @param string  $plugin_url  Base plugin URL.
	 */
	public function __construct( Options $options, string $plugin_url ) {
		$this->options    = $options;
		$this->plugin_url = rtrim( $plugin_url, '/' );
		$this->plugin_dir = dirname( __DIR__, 2 );
	}

	/**
	 * Enqueue assets within the block editor so the media modal gains AI controls.
	 *
	 * @return void
	 */
	public function enqueue_block_editor_assets(): void {
		if ( $this->options->is_connected() && $this->user_can_access_ai() ) {
			$this->enqueue_block_editor_script();
		}
		if ( $this->user_can_access_ai() ) {
			$this->enqueue_modal_style();
		}
	}

	/**
	 * Hook admin actions for enqueue and notices.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ] );
		add_action( 'enqueue_block_editor_assets', [ $this, 'enqueue_block_editor_assets' ] );
		add_action( 'elementor/editor/after_enqueue_scripts', [ $this, 'enqueue_elementor_editor_assets' ] );
		add_action( 'wp_enqueue_media', [ $this, 'enqueue_media_modal_assets' ] );
		add_action( 'admin_notices', [ $this, 'maybe_show_notice' ] );
		add_action( 'attachment_submitbox_misc_actions', [ $this, 'render_submitbox_metadata' ] );
		add_filter( 'wp_prepare_attachment_for_js', [ $this, 'add_ai_metadata' ], 10, 2 );
	}

	/**
	 * Attach WP Banana metadata to attachment JS responses.
	 *
	 * @param array        $response   Prepared attachment response.
	 * @param WP_Post|null $attachment Attachment object.
	 * @return array
	 */
	public function add_ai_metadata( array $response, $attachment ): array {
		if ( ! ( $attachment instanceof WP_Post ) || 'attachment' !== $attachment->post_type ) {
			return $response;
		}

		$current_id = (int) $attachment->ID;
		$meta       = Attachment_Metadata::prepare_for_js( $current_id );
		if ( empty( $meta['isGenerated'] ) && empty( $meta['historyCount'] ) && empty( $meta['derivedFrom'] ) && empty( $meta['lastPrompt'] ) ) {
			return $response;
		}

		$response['wpBanana'] = $meta;
		return $response;
	}

	/**
	 * Enqueue scripts on Media Library screen.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public function enqueue( $hook ): void {
		$is_upload_screen     = ( 'upload.php' === $hook );
		$is_attachment_screen = false;
		$is_post_editor       = false;
		$can_generate         = $this->user_can_generate();
		$can_edit             = $this->user_can_edit();
		$can_access_ai        = $can_generate || $can_edit;

		if ( 'post.php' === $hook ) {
			$attachment_id = isset( $_GET['post'] ) ? (int) $_GET['post'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( $attachment_id > 0 && 'attachment' === get_post_type( $attachment_id ) ) {
				$is_attachment_screen = true;
			} else {
				$is_post_editor = true;
			}
		} elseif ( 'post-new.php' === $hook ) {
			$is_post_editor = true;
		}

		if ( $is_upload_screen && $this->options->is_connected() && $can_access_ai ) {
			$this->enqueue_media_library_script();
		} elseif ( $is_attachment_screen && $this->options->is_connected() && $can_edit ) {
			$this->enqueue_attachment_editor_script();
		} elseif ( $is_post_editor && $this->options->is_connected() && $can_access_ai ) {
			$this->enqueue_block_editor_script();
		}

		if ( ( $is_upload_screen || $is_attachment_screen || $is_post_editor ) && $can_access_ai ) {
			$this->enqueue_modal_style();
		}

		if ( $is_upload_screen || $is_attachment_screen ) {
			wp_enqueue_style(
				'wp-banana-submitbox',
				trailingslashit( $this->plugin_url ) . 'assets/admin/submitbox.css',
				[],
				'0.1.0'
			);
		}
	}

	/**
	 * Register, localize, and enqueue the Media Library bundle.
	 *
	 * @return void
	 */
	private function enqueue_media_library_script(): void {
		$handle = 'wp-banana-media-library';
		$this->register_script( $handle, 'media-library' );
		$this->localize_media_data( $handle );
		wp_enqueue_script( $handle );
		$this->ensure_component_styles();
	}

	/**
	 * Register, localize, and enqueue the attachment editor bundle.
	 *
	 * @return void
	 */
	private function enqueue_attachment_editor_script(): void {
		$handle = 'wp-banana-attachment-editor';
		$this->register_script( $handle, 'attachment-editor' );
		$this->localize_media_data( $handle );
		wp_enqueue_script( $handle );
		$this->ensure_component_styles();
	}

	/**
	 * Register, localize, and enqueue the block editor/modal bundle.
	 *
	 * @return void
	 */
	private function enqueue_block_editor_script(): void {
		$handle = 'wp-banana-block-editor';
		$this->register_script( $handle, 'block-editor' );
		$this->localize_media_data( $handle );
		wp_enqueue_script( $handle );
		$this->ensure_component_styles();
	}

	/**
	 * Ensure Elementor's editor also loads our media modal integrations.
	 *
	 * @return void
	 */
	public function enqueue_elementor_editor_assets(): void {
		if ( $this->options->is_connected() && $this->user_can_access_ai() ) {
			$this->enqueue_block_editor_script();
		}
		if ( $this->user_can_access_ai() ) {
			$this->enqueue_modal_style();
		}
	}

	/**
	 * Enqueue assets whenever wp_enqueue_media() is called.
	 *
	 * @return void
	 */
	public function enqueue_media_modal_assets(): void {
		if ( $this->options->is_connected() && $this->user_can_access_ai() ) {
			$this->enqueue_block_editor_script();
		}
		if ( $this->user_can_access_ai() ) {
			$this->enqueue_modal_style();
		}
	}

	/**
	 * Register a generated script if needed.
	 *
	 * @param string $handle   Script handle.
	 * @param string $basename Script base filename.
	 * @return void
	 */
	private function register_script( string $handle, string $basename ): void {
		if ( ! wp_script_is( $handle, 'registered' ) ) {
			$asset = $this->get_asset_metadata( $basename );
			$src   = trailingslashit( $this->plugin_url ) . 'build/' . $basename . '.js';
			$deps  = isset( $asset['dependencies'] ) && is_array( $asset['dependencies'] ) ? $asset['dependencies'] : [];
			$ver   = isset( $asset['version'] ) ? (string) $asset['version'] : Plugin::VERSION;

			wp_register_script( $handle, $src, $deps, $ver, true );
            wp_set_script_translations( $handle, 'wp-banana', $this->plugin_dir . '/languages' );
		}
	}

	/**
	 * Localize shared provider data once per handle.
	 *
	 * @param string $handle Script handle.
	 * @return void
	 */
	private function localize_media_data( string $handle ): void {
		if ( isset( $this->localized_handles[ $handle ] ) ) {
			return;
		}

		wp_localize_script( $handle, 'wpBananaMedia', $this->get_localized_data() );
		$this->localized_handles[ $handle ] = true;
	}

	/**
	 * Retrieve build-generated asset metadata.
	 *
	 * @param string $basename Script base filename.
	 * @return array<string,mixed>
	 */
	private function get_asset_metadata( string $basename ): array {
		$asset_path = trailingslashit( $this->plugin_dir ) . 'build/' . $basename . '.asset.php';
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
	 * Register and enqueue modal styles shared across admin/editor contexts.
	 *
	 * @return void
	 */
	private function enqueue_modal_style(): void {
		if ( ! wp_style_is( 'wp-banana-media-modal', 'registered' ) ) {
			wp_register_style(
				'wp-banana-media-modal',
				trailingslashit( $this->plugin_url ) . 'assets/admin/media-modal.css',
				[],
				'0.1.0'
			);
		}

		wp_enqueue_style( 'wp-banana-media-modal' );
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
	}

	/**
	 * Build localized data payload shared with JS integrations.
	 *
	 * @return array<string,mixed>
	 */
	private function get_localized_data(): array {
		$can_generate = $this->user_can_generate();
		$can_edit     = $this->user_can_edit();
		$providers    = [];
		$gemini       = $this->options->get_provider_config( 'gemini' );
		$providers[]  = [
			'slug'          => 'gemini',
			'label'         => __( 'Gemini', 'wp-banana' ),
			'connected'     => ! empty( $gemini['api_key'] ),
			'default_model' => isset( $gemini['default_model'] ) ? (string) $gemini['default_model'] : Models_Catalog::provider_default_model( 'gemini' ),
		];
		$openai       = $this->options->get_provider_config( 'openai' );
		$providers[]  = [
			'slug'          => 'openai',
			'label'         => __( 'OpenAI', 'wp-banana' ),
			'connected'     => ! empty( $openai['api_key'] ),
			'default_model' => isset( $openai['default_model'] ) ? (string) $openai['default_model'] : Models_Catalog::provider_default_model( 'openai' ),
		];
		$replicate    = $this->options->get_provider_config( 'replicate' );
		$providers[]  = [
			'slug'          => 'replicate',
			'label'         => __( 'Replicate', 'wp-banana' ),
			'connected'     => ! empty( $replicate['api_token'] ),
			'default_model' => isset( $replicate['default_model'] ) ? (string) $replicate['default_model'] : Models_Catalog::provider_default_model( 'replicate' ),
		];

		$default_generator_model = (string) $this->options->get( 'default_generator_model', Models_Catalog::default_generator_model() );
		$default_editor_model    = (string) $this->options->get( 'default_editor_model', Models_Catalog::default_editor_model() );
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

		return [
			'restNamespace'            => 'wp-banana/v1',
			'canGenerate'              => $can_generate,
			'canEdit'                  => $can_edit,
			'providers'                => $providers,
			'defaultGeneratorModel'    => $default_generator_model,
			'defaultGeneratorProvider' => $this->detect_provider_for_model( $default_generator_model, 'generate' ),
			'defaultAspectRatio'       => $default_aspect_ratio,
			'aspectRatioOptions'       => Aspect_Ratios::all(),
			'defaultResolution'        => $default_resolution,
			'resolutionOptions'        => Resolutions::all(),
			'defaultEditorModel'       => $default_editor_model,
			'defaultEditorProvider'    => $this->detect_provider_for_model( $default_editor_model, 'edit' ),
			'multiImageModelAllowlist' => Models_Catalog::multi_image_allowlist(),
			'resolutionModelAllowlist' => Models_Catalog::resolution_model_allowlist(),
			'iconUrl'                  => trailingslashit( $this->plugin_url ) . 'assets/images/banana-icon-2.svg',
		];
	}

	/**
	 * Determine if the current user can access any AI UI.
	 *
	 * @return bool
	 */
	private function user_can_access_ai(): bool {
		return $this->user_can_generate() || $this->user_can_edit();
	}

	/**
	 * Determine if the current user can generate AI images.
	 *
	 * @return bool
	 */
	private function user_can_generate(): bool {
		return current_user_can( Caps::GENERATE );
	}

	/**
	 * Determine if the current user can edit AI images.
	 *
	 * @return bool
	 */
	private function user_can_edit(): bool {
		return current_user_can( Caps::EDIT );
	}

	/**
	 * Attempt to infer the provider slug for a given model.
	 *
	 * @param string $model   Model identifier.
	 * @param string $purpose Model purpose (generate|edit).
	 * @return string
	 */
	private function detect_provider_for_model( string $model, string $purpose ): string {
		$model = trim( $model );
		if ( '' === $model ) {
			return '';
		}

		$catalog = Models_Catalog::all();
		if ( isset( $catalog[ $purpose ] ) && is_array( $catalog[ $purpose ] ) ) {
			foreach ( $catalog[ $purpose ] as $provider => $models ) {
				if ( is_array( $models ) && in_array( $model, $models, true ) ) {
					return (string) $provider;
				}
			}
		}

		$providers = $this->options->get( 'providers', [] );
		if ( is_array( $providers ) ) {
			foreach ( $providers as $provider => $config ) {
				if ( isset( $config['default_model'] ) && $model === $config['default_model'] ) {
					return (string) $provider;
				}
			}
		}

		return '';
	}

	/**
	 * Show connection notice when on Media Library and not connected.
	 *
	 * @return void
	 */
	public function maybe_show_notice(): void {
		$screen          = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$is_media_screen = $screen && ( 'upload' === $screen->id );
		if ( $is_media_screen && $this->user_can_access_ai() && ! $this->options->is_connected() ) {
			echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html__( 'WP Banana: No provider configured. Add a key in Settings → AI Images to enable the Media UI.', 'wp-banana' ) . '</p></div>';
		}
	}

	/**
	 * Render AI provenance details in the attachment submit box.
	 *
	 * @param WP_Post $attachment Attachment being edited.
	 * @return void
	 */
	public function render_submitbox_metadata( WP_Post $attachment ): void {
		if ( 'attachment' !== $attachment->post_type ) {
			return;
		}

		$current_id = (int) $attachment->ID;
		$meta       = Attachment_Metadata::prepare_for_js( $current_id );

		if (
			empty( $meta['isGenerated'] )
			&& empty( $meta['derivedFrom'] )
			&& empty( $meta['historyCount'] )
		) {
			return;
		}

		$derived = isset( $meta['derivedFrom'] ) && is_array( $meta['derivedFrom'] ) ? $meta['derivedFrom'] : null;
		$history = isset( $meta['history'] ) && is_array( $meta['history'] ) ? $meta['history'] : [];
		$count   = isset( $meta['historyCount'] ) ? (int) $meta['historyCount'] : count( $history );
		$enabled = isset( $meta['historyEnabled'] ) ? (bool) $meta['historyEnabled'] : true;
		$limit   = 5;
		$recent  = array_slice( array_reverse( $history ), 0, $limit );

		echo '<div class="misc-pub-section misc-pub-wp-banana">';
		echo '<p class="wp-banana-submitbox-heading"><strong>' . esc_html__( 'WP Banana', 'wp-banana' ) . '</strong></p>';

		if ( $derived && ! empty( $derived['id'] ) && (int) $derived['id'] !== $current_id ) {
			$title = ! empty( $derived['title'] ) ? (string) $derived['title'] : '#' . (int) $derived['id'];
			$link  = ! empty( $derived['editLink'] ) ? (string) $derived['editLink'] : ( ! empty( $derived['viewLink'] ) ? (string) $derived['viewLink'] : '' );
			echo '<p class="wp-banana-submitbox-derived"><strong>' . esc_html__( 'Derived from:', 'wp-banana' ) . '</strong> ';
			if ( $link ) {
				echo '<a href="' . esc_url( $link ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $title ) . '</a>';
			} else {
				echo esc_html( $title );
			}
			echo '</p>';
		}

		if ( ! empty( $recent ) ) {
			$summary_text = sprintf(
				// translators: 1: Number of entries.
				_n( 'AI history (%s entry)', 'AI history (%s entries)', $count, 'wp-banana' ),
				number_format_i18n( $count )
			);
			$open_attr = $count <= 2 ? ' open' : '';
			echo '<details class="wp-banana-submitbox-history"' . esc_attr( $open_attr ) . '>';
			echo '<summary class="wp-banana-submitbox-history__summary-label">' . esc_html( $summary_text ) . '</summary>';
			echo '<div class="wp-banana-submitbox-history__body">';
			echo '<ul class="wp-banana-submitbox-history__list">';
			foreach ( $recent as $entry ) {
				$summary_parts = [];
				$action_label  = $this->get_action_label( isset( $entry['type'] ) ? (string) $entry['type'] : '' );
				if ( $action_label ) {
					$summary_parts[] = $action_label;
				}
				$provider_label = $this->get_provider_label( isset( $entry['provider'] ) ? (string) $entry['provider'] : '' );
				if ( $provider_label ) {
					// translators: %s: Provider name.
					$summary_parts[] = sprintf( esc_html__( 'via %s', 'wp-banana' ), $provider_label );
				}
				$model = isset( $entry['model'] ) ? (string) $entry['model'] : '';
				if ( '' !== $model ) {
					$summary_parts[] = $model;
				}
				$mode_label = $this->get_mode_label( isset( $entry['mode'] ) ? (string) $entry['mode'] : '' );
				if ( $mode_label ) {
					$summary_parts[] = $mode_label;
				}

				$meta_parts = [];
				$timestamp  = isset( $entry['timestamp'] ) ? (int) $entry['timestamp'] : 0;
				if ( $timestamp > 0 ) {
					$meta_parts[] = $this->format_timestamp( $timestamp );
				}
				$user_name = isset( $entry['user']['name'] ) ? (string) $entry['user']['name'] : '';
				if ( '' !== $user_name ) {
					// translators: %s: User name.
					$meta_parts[] = sprintf( esc_html__( 'by %s', 'wp-banana' ), $user_name );
				}

				$derived_entry = isset( $entry['derivedFrom'] ) && is_array( $entry['derivedFrom'] ) ? $entry['derivedFrom'] : null;
				if ( $derived_entry && (int) $derived_entry['id'] === $current_id ) {
					$derived_entry = null;
				}
				$prompt = isset( $entry['prompt'] ) ? (string) $entry['prompt'] : '';
				echo '<li class="wp-banana-submitbox-history__item">';
				if ( ! empty( $summary_parts ) ) {
					echo '<div class="wp-banana-submitbox-history__summary">' . esc_html( implode( ', ', $summary_parts ) ) . '</div>';
				}
				if ( ! empty( $meta_parts ) ) {
					echo '<div class="wp-banana-submitbox-history__meta">' . esc_html( implode( ' | ', $meta_parts ) ) . '</div>';
				}
				if ( $derived_entry && ! empty( $derived_entry['id'] ) ) {
					$source_title = ! empty( $derived_entry['title'] ) ? (string) $derived_entry['title'] : '#' . (int) $derived_entry['id'];
					$source_link  = ! empty( $derived_entry['editLink'] ) ? (string) $derived_entry['editLink'] : ( ! empty( $derived_entry['viewLink'] ) ? (string) $derived_entry['viewLink'] : '' );
					echo '<div class="wp-banana-submitbox-history__derived"><span>' . esc_html__( 'Source:', 'wp-banana' ) . '</span> ';
					if ( $source_link ) {
						echo '<a href="' . esc_url( $source_link ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $source_title ) . '</a>';
					} else {
						echo esc_html( $source_title );
					}
					echo '</div>';
				}
				if ( '' !== $prompt ) {
					$excerpt = wp_trim_words( $prompt, 24, '...' );
					echo '<div class="wp-banana-submitbox-history__prompt">"' . esc_html( $excerpt ) . '"</div>';
				}
				echo '</li>';
			}
			echo '</ul>';
			if ( $count > $limit ) {
				// translators: 1: Number of entries, 2: Total number of entries.
				echo '<p class="description wp-banana-submitbox-history__note">' . esc_html( sprintf( __( 'Showing latest %1$s of %2$s entries.', 'wp-banana' ), number_format_i18n( $limit ), number_format_i18n( $count ) ) ) . '</p>';
			}
			echo '</div>';
			echo '</details>';
		} elseif ( $enabled ) {
			echo '<p class="wp-banana-submitbox-history-empty">' . esc_html__( 'No AI history recorded for this attachment yet.', 'wp-banana' ) . '</p>';
		} else {
			echo '<p class="wp-banana-submitbox-history-disabled">' . esc_html__( 'AI history logging is disabled for this site.', 'wp-banana' ) . '</p>';
		}

		echo '</div>';
	}

	/**
	 * Map internal action slug to a readable label.
	 *
	 * @param string $action Action slug.
	 * @return string
	 */
	private function get_action_label( string $action ): string {
		switch ( $action ) {
			case 'generate':
				return __( 'Generated', 'wp-banana' );
			case 'edit':
				return __( 'Edited', 'wp-banana' );
		}
		return $action;
	}

	/**
	 * Map provider slug to human label.
	 *
	 * @param string $provider Provider slug.
	 * @return string
	 */
	private function get_provider_label( string $provider ): string {
		switch ( $provider ) {
			case 'gemini':
				return __( 'Gemini', 'wp-banana' );
			case 'replicate':
				return __( 'Replicate', 'wp-banana' );
			case 'openai':
				return __( 'OpenAI', 'wp-banana' );
		}
		return $provider;
	}

	/**
	 * Map edit mode slug to label.
	 *
	 * @param string $mode Mode slug.
	 * @return string
	 */
	private function get_mode_label( string $mode ): string {
		switch ( $mode ) {
			case 'new':
				return __( 'New copy', 'wp-banana' );
			case 'replace':
				return __( 'Replaced original', 'wp-banana' );
			case 'save_as':
				return __( 'Saved as copy', 'wp-banana' );
			case 'buffer':
				return __( 'Buffered edit', 'wp-banana' );
		}
		return $mode;
	}

	/**
	 * Format a timestamp using site date/time preferences.
	 *
	 * @param int $timestamp Unix timestamp.
	 * @return string
	 */
	private function format_timestamp( int $timestamp ): string {
		$date_format = get_option( 'date_format', 'M j, Y' );
		$time_format = get_option( 'time_format', 'H:i' );
		return wp_date( trim( $date_format . ' ' . $time_format ), $timestamp );
	}
}
