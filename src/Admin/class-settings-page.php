<?php
/**
 * Admin settings page.
 *
 * @package WPBanana\Admin
 * @since   0.1.0
 */

namespace WPBanana\Admin;

use WPBanana\Domain\Aspect_Ratios;
use WPBanana\Plugin;
use WPBanana\Services\Models_Catalog;
use WPBanana\Services\Options;
use WPBanana\Util\Http;
use WP_Error;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and renders the plugin settings page.
 */
final class Settings_Page {

	/**
	 * Options service instance.
	 *
	 * @var Options
	 */
	private $options;
	/**
	 * Main plugin file path.
	 *
	 * @var string
	 */
	private $plugin_file;
	/**
	 * Base plugin URL.
	 *
	 * @var string
	 */
	private $plugin_url;

	/**
	 * Constructor.
	 *
	 * @param Options $options     Options service.
	 * @param string  $plugin_file  Main plugin file.
	 * @param string  $plugin_url   Base plugin URL.
	 */
	public function __construct( Options $options, string $plugin_file, string $plugin_url ) {
		$this->options     = $options;
		$this->plugin_file = $plugin_file;
		$this->plugin_url  = $plugin_url;
	}

	/**
	 * Hook menu and settings registration.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_menu', [ $this, 'add_menu' ] );
		add_action( 'admin_init', [ $this, 'register_settings' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		add_action( 'wp_ajax_wp_banana_test_provider', [ $this, 'ajax_test_provider' ] );
		add_filter(
			'plugin_action_links_' . plugin_basename( $this->plugin_file ),
			[ self::class, 'plugin_action_link' ]
		);
	}

	/**
	 * Add settings menu entry.
	 *
	 * @return void
	 */
	public function add_menu(): void {
		add_options_page(
			__( 'WP Nano Banana', 'wp-banana' ),
			__( 'WP Nano Banana (AI Images)', 'wp-banana' ),
			'manage_options',
			'wp-banana',
			[ $this, 'render' ]
		);
	}

	/**
	 * Register the options group and sanitization callback.
	 *
	 * @return void
	 */
	public function register_settings(): void {
		register_setting(
			'wp_banana_options_group',
			Options::OPTION_NAME,
			[
				'type'              => 'array',
				'sanitize_callback' => [ $this, 'sanitize_options' ],
				'show_in_rest'      => false,
			]
		);
	}

	/**
	 * Sanitize and normalize submitted options.
	 *
	 * @param mixed $input Raw form input.
	 * @return array Sanitized options array.
	 */
	public function sanitize_options( $input ): array {
		// phpcs:disable Generic.Formatting.MultipleStatementAlignment
		$current = $this->options->get_stored();
		$input   = is_array( $input ) ? $input : [];
		// Sanitize critical fields minimally.
		$providers = isset( $input['providers'] ) && is_array( $input['providers'] ) ? $input['providers'] : [];

		if ( $this->options->provider_constant_defined( 'gemini' ) ) {
			$current['providers']['gemini']['api_key'] = '';
		} else {
			$current['providers']['gemini']['api_key'] = isset( $providers['gemini']['api_key'] ) ? sanitize_text_field( $providers['gemini']['api_key'] ) : ( isset( $current['providers']['gemini']['api_key'] ) ? $current['providers']['gemini']['api_key'] : '' );
		}
		// Keep legacy field if present (hidden in UI), but don't require it.
		$current['providers']['gemini']['default_model'] = isset( $providers['gemini']['default_model'] ) ? sanitize_text_field( $providers['gemini']['default_model'] ) : ( isset( $current['providers']['gemini']['default_model'] ) ? $current['providers']['gemini']['default_model'] : 'gemini-2.5-flash-image-preview' );

		if ( $this->options->provider_constant_defined( 'openai' ) ) {
			$current['providers']['openai']['api_key'] = '';
		} else {
			$current['providers']['openai']['api_key'] = isset( $providers['openai']['api_key'] ) ? sanitize_text_field( $providers['openai']['api_key'] ) : ( isset( $current['providers']['openai']['api_key'] ) ? $current['providers']['openai']['api_key'] : '' );
		}
		$current['providers']['openai']['default_model'] = isset( $providers['openai']['default_model'] ) ? sanitize_text_field( $providers['openai']['default_model'] ) : ( isset( $current['providers']['openai']['default_model'] ) ? $current['providers']['openai']['default_model'] : 'gpt-image-1' );

		if ( $this->options->provider_constant_defined( 'replicate' ) ) {
			$current['providers']['replicate']['api_token'] = '';
		} else {
			$current['providers']['replicate']['api_token'] = isset( $providers['replicate']['api_token'] ) ? sanitize_text_field( $providers['replicate']['api_token'] ) : ( isset( $current['providers']['replicate']['api_token'] ) ? $current['providers']['replicate']['api_token'] : '' );
		}

		$current['providers']['replicate']['default_model'] = isset( $providers['replicate']['default_model'] ) ? sanitize_text_field( $providers['replicate']['default_model'] ) : ( isset( $current['providers']['replicate']['default_model'] ) ? $current['providers']['replicate']['default_model'] : 'black-forest-labs/flux' );

		$gen             = isset( $input['generation_defaults'] ) && is_array( $input['generation_defaults'] ) ? $input['generation_defaults'] : [];
		$aspect          = isset( $gen['aspect_ratio'] ) ? (string) $gen['aspect_ratio'] : $current['generation_defaults']['aspect_ratio'];
		$sanitized_ratio = Aspect_Ratios::sanitize( $aspect );
		$current['generation_defaults']['aspect_ratio'] = '' !== $sanitized_ratio ? $sanitized_ratio : Aspect_Ratios::default();

		$fmt = isset( $gen['format'] ) ? sanitize_key( $gen['format'] ) : $current['generation_defaults']['format'];
		$current['generation_defaults']['format'] = in_array( $fmt, [ 'png', 'webp', 'jpeg' ], true ) ? $fmt : 'png';

		$privacy = isset( $input['privacy'] ) && is_array( $input['privacy'] ) ? $input['privacy'] : [];
		$current['privacy']['store_history'] = ! empty( $privacy['store_history'] );

		// New: default model selections.
		$catalog = Models_Catalog::all();

		$allowed_generate = [];
		if ( isset( $catalog['generate'] ) && is_array( $catalog['generate'] ) ) {
			foreach ( $catalog['generate'] as $models ) {
				if ( is_array( $models ) ) {
					foreach ( $models as $model_slug ) {
						$allowed_generate[] = (string) $model_slug;
					}
				}
			}
		}
		$allowed_generate = array_values( array_unique( $allowed_generate ) );

		$allowed_edit = [];
		if ( isset( $catalog['edit'] ) && is_array( $catalog['edit'] ) ) {
			foreach ( $catalog['edit'] as $models ) {
				if ( is_array( $models ) ) {
					foreach ( $models as $model_slug ) {
						$allowed_edit[] = (string) $model_slug;
					}
				}
			}
		}
		$allowed_edit = array_values( array_unique( $allowed_edit ) );

		$default_generate_fallback = ! empty( $allowed_generate ) ? (string) $allowed_generate[0] : 'gemini-2.5-flash-image-preview';
		$default_edit_fallback     = ! empty( $allowed_edit ) ? (string) $allowed_edit[0] : 'gemini-2.5-flash-image-preview';

		$gen_model_raw = isset( $input['default_generator_model'] ) ? sanitize_text_field( $input['default_generator_model'] ) : ( isset( $current['default_generator_model'] ) ? $current['default_generator_model'] : 'gemini-2.5-flash-image-preview' );

		$edit_model_raw = isset( $input['default_editor_model'] ) ? sanitize_text_field( $input['default_editor_model'] ) : ( isset( $current['default_editor_model'] ) ? $current['default_editor_model'] : 'gemini-2.5-flash-image-preview' );
		$current['default_generator_model'] = in_array( $gen_model_raw, $allowed_generate, true ) ? $gen_model_raw : $default_generate_fallback;
		$current['default_editor_model']    = in_array( $edit_model_raw, $allowed_edit, true ) ? $edit_model_raw : $default_edit_fallback;

		// phpcs:enable Generic.Formatting.MultipleStatementAlignment
		return $current;
	}

	/**
	 * Render the settings page.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'wp-banana' ) );
		}

		$opts            = $this->options->get_all();
		$gemini_state    = $this->get_constant_state( 'gemini' );
		$openai_state    = $this->get_constant_state( 'openai' );
		$replicate_state = $this->get_constant_state( 'replicate' );
		$gemini_value    = $gemini_state['defined']

			? ( $gemini_state['has_value'] ? '********' : '' )
			: ( isset( $opts['providers']['gemini']['api_key'] ) ? (string) $opts['providers']['gemini']['api_key'] : '' );
		$openai_value    = $openai_state['defined']
			? ( $openai_state['has_value'] ? '********' : '' )
			: ( isset( $opts['providers']['openai']['api_key'] ) ? (string) $opts['providers']['openai']['api_key'] : '' );
		$replicate_value = $replicate_state['defined']
			? ( $replicate_state['has_value'] ? '********' : '' )
			: ( isset( $opts['providers']['replicate']['api_token'] ) ? (string) $opts['providers']['replicate']['api_token'] : '' );

		$gemini_input_id    = 'wp-banana-gemini-api-key';
		$openai_input_id    = 'wp-banana-openai-api-key';
		$replicate_input_id = 'wp-banana-replicate-api-token';
		$gemini_label       = $this->provider_label( 'gemini' );
		$openai_label       = $this->provider_label( 'openai' );
		$replicate_label    = $this->provider_label( 'replicate' );

		// Translators: %s is provider name.
		$gemini_test_label = sprintf( esc_html__( 'Test connection for %s', 'wp-banana' ), $gemini_label );

		// Translators: %s is provider name.
		$openai_test_label = sprintf( esc_html__( 'Test connection for %s', 'wp-banana' ), $openai_label );

		// Translators: %s is provider name.
		$replicate_test_label = sprintf( esc_html__( 'Test connection for %s', 'wp-banana' ), $replicate_label );

		// Translators: %s is provider name.
		$gemini_loading_label = sprintf( esc_html__( 'Testing connection to %s...', 'wp-banana' ), $gemini_label );

		// Translators: %s is provider name.
		$openai_loading_label = sprintf( esc_html__( 'Testing connection to %s...', 'wp-banana' ), $openai_label );

		// Translators: %s is provider name.
		$replicate_loading_label = sprintf( esc_html__( 'Testing connection to %s...', 'wp-banana' ), $replicate_label );

		// Translators: %s is provider name.
		$gemini_success_label = sprintf( esc_html__( 'Connected to %s successfully.', 'wp-banana' ), $gemini_label );

		// Translators: %s is provider name.
		$openai_success_label = sprintf( esc_html__( 'Connected to %s successfully.', 'wp-banana' ), $openai_label );

		// Translators: %s is provider name.
		$replicate_success_label = sprintf( esc_html__( 'Connected to %s successfully.', 'wp-banana' ), $replicate_label );

		// Translators: %s is provider name.
		$gemini_error_label = sprintf( esc_html__( 'Connection to %s failed.', 'wp-banana' ), $gemini_label );

		// Translators: %s is provider name.
		$openai_error_label = sprintf( esc_html__( 'Connection to %s failed.', 'wp-banana' ), $openai_label );

		// Translators: %s is provider name.
		$replicate_error_label = sprintf( esc_html__( 'Connection to %s failed.', 'wp-banana' ), $replicate_label );
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'WP Nano Banana', 'wp-banana' ); ?></h1>

			<?php if ( ! $this->options->is_connected() ) : ?>
				<div class="notice notice-warning"><p><?php echo esc_html__( 'No provider key set. The Media Library UI will appear after adding a key below.', 'wp-banana' ); ?></p></div>
			<?php endif; ?>

			<form method="post" action="options.php">
				<?php settings_fields( 'wp_banana_options_group' ); ?>
				<h2 class="title"><?php esc_html_e( 'API Setup', 'wp-banana' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">Gemini API Key</th>
						<td>
							<div class="wp-banana-provider-credentials">
								<input
									id="<?php echo esc_attr( $gemini_input_id ); ?>"
									type="password"
									style="width:420px"
									name="<?php echo esc_attr( Options::OPTION_NAME ); ?>[providers][gemini][api_key]"
									value="<?php echo esc_attr( $gemini_value ); ?>"
									placeholder="AIza..."
									<?php echo $gemini_state['defined'] ? ' disabled="disabled" aria-disabled="true"' : ''; ?>
								/>
								<button
									type="button"
									class="button wp-banana-test-provider"
									data-provider="gemini"
									data-target="<?php echo esc_attr( $gemini_input_id ); ?>"
									data-label-default="<?php esc_attr_e( 'Test', 'wp-banana' ); ?>"
									data-label-loading="<?php esc_attr_e( 'Testing...', 'wp-banana' ); ?>"
									data-label-success="<?php esc_attr_e( 'Success', 'wp-banana' ); ?>"
									data-label-error="<?php esc_attr_e( 'Retry', 'wp-banana' ); ?>"
									data-status-loading="<?php echo esc_attr( $gemini_loading_label ); ?>"
									data-status-success="<?php echo esc_attr( $gemini_success_label ); ?>"
									data-status-error="<?php echo esc_attr( $gemini_error_label ); ?>"
									data-aria-default="<?php echo esc_attr( $gemini_test_label ); ?>"
									data-aria-loading="<?php echo esc_attr( $gemini_loading_label ); ?>"
									data-aria-success="<?php echo esc_attr( $gemini_success_label ); ?>"
									data-aria-error="<?php echo esc_attr( $gemini_error_label ); ?>"
								><?php esc_html_e( 'Test', 'wp-banana' ); ?></button>
								<span class="wp-banana-test-status description" aria-live="polite"></span>
							</div>
							<?php if ( $gemini_state['defined'] ) : ?>
								<?php $this->render_constant_notice( $gemini_state['constant'], $gemini_state['has_value'] ); ?>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row">OpenAI API Key</th>
						<td>
							<div class="wp-banana-provider-credentials">
								<input
									id="<?php echo esc_attr( $openai_input_id ); ?>"
									type="password"
									style="width:420px"
									name="<?php echo esc_attr( Options::OPTION_NAME ); ?>[providers][openai][api_key]"
									value="<?php echo esc_attr( $openai_value ); ?>"
									placeholder="sk-..."
									<?php echo $openai_state['defined'] ? ' disabled="disabled" aria-disabled="true"' : ''; ?>
								/>
								<button
									type="button"
									class="button wp-banana-test-provider"
									data-provider="openai"
									data-target="<?php echo esc_attr( $openai_input_id ); ?>"
									data-label-default="<?php esc_attr_e( 'Test', 'wp-banana' ); ?>"
									data-label-loading="<?php esc_attr_e( 'Testing...', 'wp-banana' ); ?>"
									data-label-success="<?php esc_attr_e( 'Success', 'wp-banana' ); ?>"
									data-label-error="<?php esc_attr_e( 'Retry', 'wp-banana' ); ?>"
									data-status-loading="<?php echo esc_attr( $openai_loading_label ); ?>"
									data-status-success="<?php echo esc_attr( $openai_success_label ); ?>"
									data-status-error="<?php echo esc_attr( $openai_error_label ); ?>"
									data-aria-default="<?php echo esc_attr( $openai_test_label ); ?>"
									data-aria-loading="<?php echo esc_attr( $openai_loading_label ); ?>"
									data-aria-success="<?php echo esc_attr( $openai_success_label ); ?>"
									data-aria-error="<?php echo esc_attr( $openai_error_label ); ?>"
								><?php esc_html_e( 'Test', 'wp-banana' ); ?></button>
								<span class="wp-banana-test-status description" aria-live="polite"></span>
							</div>
							<?php if ( $openai_state['defined'] ) : ?>
								<?php $this->render_constant_notice( $openai_state['constant'], $openai_state['has_value'] ); ?>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row">Replicate API Token</th>
						<td>
							<div class="wp-banana-provider-credentials">
								<input
									id="<?php echo esc_attr( $replicate_input_id ); ?>"
									type="password"
									style="width:420px"
									name="<?php echo esc_attr( Options::OPTION_NAME ); ?>[providers][replicate][api_token]"
									value="<?php echo esc_attr( $replicate_value ); ?>"
									placeholder="r8_..."
									<?php echo $replicate_state['defined'] ? ' disabled="disabled" aria-disabled="true"' : ''; ?>
								/>
								<button
									type="button"
									class="button wp-banana-test-provider"
									data-provider="replicate"
									data-target="<?php echo esc_attr( $replicate_input_id ); ?>"
									data-label-default="<?php esc_attr_e( 'Test', 'wp-banana' ); ?>"
									data-label-loading="<?php esc_attr_e( 'Testing...', 'wp-banana' ); ?>"
									data-label-success="<?php esc_attr_e( 'Success', 'wp-banana' ); ?>"
									data-label-error="<?php esc_attr_e( 'Retry', 'wp-banana' ); ?>"
									data-status-loading="<?php echo esc_attr( $replicate_loading_label ); ?>"
									data-status-success="<?php echo esc_attr( $replicate_success_label ); ?>"
									data-status-error="<?php echo esc_attr( $replicate_error_label ); ?>"
									data-aria-default="<?php echo esc_attr( $replicate_test_label ); ?>"
									data-aria-loading="<?php echo esc_attr( $replicate_loading_label ); ?>"
									data-aria-success="<?php echo esc_attr( $replicate_success_label ); ?>"
									data-aria-error="<?php echo esc_attr( $replicate_error_label ); ?>"
								><?php esc_html_e( 'Test', 'wp-banana' ); ?></button>
								<span class="wp-banana-test-status description" aria-live="polite"></span>
							</div>
							<?php if ( $replicate_state['defined'] ) : ?>
								<?php $this->render_constant_notice( $replicate_state['constant'], $replicate_state['has_value'] ); ?>
							<?php endif; ?>
						</td>
					</tr>
				</table>

				<h2 class="title"><?php esc_html_e( 'Defaults', 'wp-banana' ); ?></h2>
				<table class="form-table" role="presentation">
					<?php
					$catalog         = Models_Catalog::all();
					$generate_groups = isset( $catalog['generate'] ) && is_array( $catalog['generate'] ) ? $catalog['generate'] : [];
					$edit_groups     = isset( $catalog['edit'] ) && is_array( $catalog['edit'] ) ? $catalog['edit'] : [];
					?>
					<tr>
						<th scope="row"><?php esc_html_e( 'Default Generator Model', 'wp-banana' ); ?></th>
						<td>
							<select style="min-width:420px" name="<?php echo esc_attr( Options::OPTION_NAME ); ?>[default_generator_model]">
								<?php
								foreach ( $generate_groups as $provider_slug => $models ) :
									if ( empty( $models ) || ! is_array( $models ) ) {
										continue;
									}
									$label = $this->provider_label( (string) $provider_slug );
									?>
									<optgroup label="<?php echo esc_attr( $label ); ?>">
										<?php foreach ( $models as $model_slug ) : ?>
											<option value="<?php echo esc_attr( $model_slug ); ?>" <?php selected( $opts['default_generator_model'], $model_slug ); ?>><?php echo esc_html( $model_slug ); ?></option>
										<?php endforeach; ?>
									</optgroup>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Default Editor Model', 'wp-banana' ); ?></th>
						<td>
							<select style="min-width:420px" name="<?php echo esc_attr( Options::OPTION_NAME ); ?>[default_editor_model]">
								<?php
								foreach ( $edit_groups as $provider_slug => $models ) :
									if ( empty( $models ) || ! is_array( $models ) ) {
										continue;
									}
									$label = $this->provider_label( (string) $provider_slug );
									?>
									<optgroup label="<?php echo esc_attr( $label ); ?>">
										<?php foreach ( $models as $model_slug ) : ?>
											<option value="<?php echo esc_attr( $model_slug ); ?>" <?php selected( $opts['default_editor_model'], $model_slug ); ?>><?php echo esc_html( $model_slug ); ?></option>
										<?php endforeach; ?>
									</optgroup>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Preferred Aspect Ratio', 'wp-banana' ); ?></th>
						<td>
							<select name="<?php echo esc_attr( Options::OPTION_NAME ); ?>[generation_defaults][aspect_ratio]">
								<?php
								$aspect_options = Aspect_Ratios::all();
								foreach ( $aspect_options as $aspect_option ) :
									?>
									<option value="<?php echo esc_attr( $aspect_option ); ?>" <?php selected( $opts['generation_defaults']['aspect_ratio'], $aspect_option ); ?>><?php echo esc_html( $aspect_option ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Preferred Output Format', 'wp-banana' ); ?></th>
						<td>
							<select name="<?php echo esc_attr( Options::OPTION_NAME ); ?>[generation_defaults][format]">
								<option value="png" <?php selected( $opts['generation_defaults']['format'], 'png' ); ?>>PNG</option>
								<option value="webp" <?php selected( $opts['generation_defaults']['format'], 'webp' ); ?>>WebP</option>
								<option value="jpeg" <?php selected( $opts['generation_defaults']['format'], 'jpeg' ); ?>>JPEG</option>
							</select>
						</td>
					</tr>
				</table>

				<h2 class="title"><?php esc_html_e( 'Advanced', 'wp-banana' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Store history', 'wp-banana' ); ?></th>
						<td><label><input type="checkbox" name="<?php echo esc_attr( Options::OPTION_NAME ); ?>[privacy][store_history]" value="1" <?php checked( ! empty( $opts['privacy']['store_history'] ) ); ?> /> <?php esc_html_e( 'Enable attachment history metadata', 'wp-banana' ); ?></label></td>
					</tr>
				</table>

				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Enqueue inline assets for the settings page.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public function enqueue_assets( string $hook ): void {
		if ( 'settings_page_wp-banana' !== $hook ) {
			return;
		}

		$script_handle = 'wp-banana-settings-page';
		wp_register_script( $script_handle, false, [], Plugin::VERSION, true );
		wp_enqueue_script( $script_handle );

		$config = [
			'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
			'nonce'        => wp_create_nonce( 'wp_banana_test_provider' ),
			'testing'      => __( 'Testing connection...', 'wp-banana' ),
			'success'      => __( 'Connection successful.', 'wp-banana' ),
			'genericError' => __( 'Connection test failed. Check the key and try again.', 'wp-banana' ),
			'resetDelay'   => 4000,
		];

		$script  = 'window.wpBananaTestProvider = ' . wp_json_encode( $config ) . ";\n";
		$script .= <<<JS
(function() {
	const cfg = window.wpBananaTestProvider;
	if (!cfg || typeof window.fetch !== 'function' || typeof window.URLSearchParams !== 'function') {
		return;
	}

	const ajaxUrl = cfg.ajaxUrl || (typeof window.ajaxurl === 'string' ? window.ajaxurl : '');
	if (!ajaxUrl) {
		return;
	}

	const buttons = document.querySelectorAll('.wp-banana-test-provider');
	if (!buttons.length) {
		return;
	}

	const resetTimers = new WeakMap();

	const getDatasetValue = function(button, key, fallback) {
		if (!button || !button.dataset) {
			return fallback || '';
		}
		return button.dataset[key] || fallback || '';
	};

	const clearResetTimer = function(button) {
		if (resetTimers.has(button)) {
			window.clearTimeout(resetTimers.get(button));
			resetTimers.delete(button);
		}
	};

	const textForState = function(button, state) {
		const attr = 'label' + state.charAt(0).toUpperCase() + state.slice(1);
		switch (state) {
			case 'loading':
				return getDatasetValue(button, attr, cfg.testing);
			case 'success':
				return getDatasetValue(button, attr, cfg.success);
			case 'error':
				return getDatasetValue(button, attr, cfg.genericError);
			default:
				return getDatasetValue(button, 'labelDefault', 'Test');
		}
	};

	const ariaForState = function(button, state, message) {
		if (message) {
			return message;
		}
		const attr = 'aria' + state.charAt(0).toUpperCase() + state.slice(1);
		switch (state) {
			case 'loading':
				return getDatasetValue(button, attr, cfg.testing);
			case 'success':
				return getDatasetValue(button, attr, cfg.success);
			case 'error':
				return getDatasetValue(button, attr, cfg.genericError);
			default:
				return getDatasetValue(button, 'ariaDefault', '');
		}
	};

	const statusForState = function(button, state, message) {
		if ('default' === state) {
			return '';
		}
		if (message) {
			return message;
		}
		const attr = 'status' + state.charAt(0).toUpperCase() + state.slice(1);
		switch (state) {
			case 'loading':
				return getDatasetValue(button, attr, cfg.testing);
			case 'success':
				return getDatasetValue(button, attr, cfg.success);
			case 'error':
				return getDatasetValue(button, attr, cfg.genericError);
			default:
				return '';
		}
	};

	const targetInput = function(button) {
		const targetId = getDatasetValue(button, 'target');
		return targetId ? document.getElementById(targetId) : null;
	};

	const setAvailabilityFromInput = function(button) {
		if (!button) {
			return;
		}
		const state = button.dataset.state || 'default';
		if ('default' !== state) {
			return;
		}
		const input = targetInput(button);
		if (!input || input.disabled) {
			button.disabled = false;
			return;
		}
		const value = typeof input.value === 'string' ? input.value.trim() : '';
		button.disabled = value === '';
	};

	const scheduleReset = function(button) {
		const delay = parseInt(cfg.resetDelay, 10);
		if (!button || Number.isNaN(delay) || delay <= 0) {
			return;
		}
		const timer = window.setTimeout(function() {
			resetTimers.delete(button);
			updateState(button, 'default', '');
		}, delay);
		resetTimers.set(button, timer);
	};

	var updateState = function(button, state, message) {
		if (!button) {
			return;
		}
		state = state || 'default';
		clearResetTimer(button);
		button.dataset.state = state;

		const buttonText = textForState(button, state);
		button.textContent = buttonText;

		const ariaLabel = ariaForState(button, state, message);
		if (ariaLabel) {
			button.setAttribute('aria-label', ariaLabel);
		} else {
			button.removeAttribute('aria-label');
		}

		const status = button.parentElement ? button.parentElement.querySelector('.wp-banana-test-status') : null;
		if (status) {
			if (status.dataset) {
				status.dataset.state = state;
			}
			status.textContent = statusForState(button, state, message);
		}

		if ('loading' === state) {
			button.disabled = true;
			button.classList.add('is-loading');
		} else {
			button.classList.remove('is-loading');
			if ('default' === state) {
				setAvailabilityFromInput(button);
			} else {
				button.disabled = false;
			}
		}

		if ('success' === state || 'error' === state) {
			scheduleReset(button);
		}
	};

	Array.prototype.forEach.call(buttons, function(button) {
		const input = targetInput(button);
		updateState(button, 'default', '');
		setAvailabilityFromInput(button);

		if (input) {
			['input', 'change'].forEach(function(eventName) {
				input.addEventListener(eventName, function() {
					if ((button.dataset.state || 'default') === 'default') {
						setAvailabilityFromInput(button);
					}
				});
			});
		}

		button.addEventListener('click', function() {
			if (button.disabled) {
				return;
			}

			const provider = getDatasetValue(button, 'provider');
			if (!provider) {
				return;
			}

			const inputEl = targetInput(button);
			let apiKey = '';
			if (inputEl && !inputEl.disabled) {
				apiKey = typeof inputEl.value === 'string' ? inputEl.value.trim() : '';
				if (!apiKey) {
					setAvailabilityFromInput(button);
					return;
				}
			}

			updateState(button, 'loading', getDatasetValue(button, 'statusLoading', cfg.testing));

			const payload = new URLSearchParams();
			payload.append('action', 'wp_banana_test_provider');
			payload.append('nonce', cfg.nonce || '');
			payload.append('provider', provider);
			payload.append('apiKey', apiKey);

			const handleError = function(fallbackMessage) {
				updateState(button, 'error', fallbackMessage || cfg.genericError);
			};

			window.fetch(ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: {
					'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
				},
				body: payload.toString()
			})
				.then(function(response) {
					if (!response.ok) {
						throw response;
					}
					return response.json();
				})
				.then(function(data) {
					if (data && data.success) {
						const message = data.data && data.data.message ? data.data.message : getDatasetValue(button, 'statusSuccess', cfg.success);
						updateState(button, 'success', message);
					} else {
						const message = data && data.data && data.data.message ? data.data.message : getDatasetValue(button, 'statusError', cfg.genericError);
						updateState(button, 'error', message);
					}
				})
				.catch(function(error) {
					if (error && typeof error.json === 'function') {
						error.json().then(function(errorData) {
							const message = errorData && errorData.data && errorData.data.message ? errorData.data.message : getDatasetValue(button, 'statusError', cfg.genericError);
							handleError(message);
						}).catch(function() {
							handleError(getDatasetValue(button, 'statusError', cfg.genericError));
						});
					} else {
						handleError(getDatasetValue(button, 'statusError', cfg.genericError));
					}
				});
		});
	});
})();
JS;

		wp_add_inline_script( $script_handle, $script );

		$style_handle = 'wp-banana-settings-page-style';
		wp_register_style( $style_handle, false, [], Plugin::VERSION );
		wp_enqueue_style( $style_handle );
		$styles = <<<CSS
.wp-banana-provider-credentials {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
	align-items: center;
	position: relative;
}

.wp-banana-provider-credentials input[type="password"] {
	flex: 1 1 320px;
	min-width: 200px;
}

.wp-banana-provider-credentials .wp-banana-test-provider {
	min-width: 72px;
	height: 30px;
	padding: 0 12px;
	display: inline-flex;
	align-items: center;
	justify-content: center;
	transition: color 0.2s ease-in-out, border-color 0.2s ease-in-out, background-color 0.2s ease-in-out;
}

.wp-banana-provider-credentials .wp-banana-test-provider[data-state="success"] {
	border-color: #008a20;
	color: #008a20;
}

.wp-banana-provider-credentials .wp-banana-test-provider[data-state="error"] {
	border-color: #b32d2e;
	color: #b32d2e;
}

.wp-banana-provider-credentials .wp-banana-test-provider.is-loading {
	cursor: progress;
}

.wp-banana-provider-credentials .wp-banana-test-provider:disabled {
	opacity: 0.5;
	cursor: not-allowed;
}

.wp-banana-provider-credentials .wp-banana-test-status {
	position: absolute;
	top: 0;
	left: 0;
	width: 1px;
	height: 1px;
	padding: 0;
	margin: -1px;
	overflow: hidden;
	clip: rect(0, 0, 0, 0);
	clip-path: inset(50%);
	border: 0;
}
CSS;
		wp_add_inline_style( $style_handle, $styles );
	}

	/**
	 * Handle AJAX requests to test provider connectivity.
	 *
	 * @return void
	 */
	public function ajax_test_provider(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				[
					'message' => esc_html__( 'You are not allowed to perform this action.', 'wp-banana' ),
				],
				403
			);
		}

		if ( ! check_ajax_referer( 'wp_banana_test_provider', 'nonce', false ) ) {
			wp_send_json_error(
				[
					'message' => esc_html__( 'Security check failed. Reload the page and try again.', 'wp-banana' ),
				],
				403
			);
		}

		$provider = isset( $_POST['provider'] ) ? sanitize_key( wp_unslash( $_POST['provider'] ) ) : '';

		if ( '' === $provider || ! isset( Options::PROVIDER_SECRET_KEYS[ $provider ] ) ) {
			wp_send_json_error(
				[
					'message' => esc_html__( 'Unknown provider.', 'wp-banana' ),
				],
				400
			);
		}

		$submitted_key = isset( $_POST['apiKey'] ) ? trim( (string) wp_unslash( $_POST['apiKey'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$secret        = $this->resolve_provider_secret( $provider, $submitted_key );

		if ( '' === $secret ) {
			if ( $this->options->provider_constant_defined( $provider ) ) {
				wp_send_json_error(
					[
						'message' => sprintf(
							// Translators: %s is the provider label.
							esc_html__( 'Add a value for the %s key in wp-config.php before testing.', 'wp-banana' ),
							$this->provider_label( $provider )
						),
					],
					400
				);
			}

			wp_send_json_error(
				[
					'message' => esc_html__( 'Enter an API key before testing.', 'wp-banana' ),
				],
				400
			);
		}

		$result = $this->ping_provider( $provider, $secret );

		if ( is_wp_error( $result ) ) {
			$code    = $result->get_error_code();
			$message = wp_strip_all_tags( $result->get_error_message() );

			if ( 'rest_forbidden' === $code ) {
				$message = esc_html__( 'Authentication failed. Double-check the API key.', 'wp-banana' );
			} elseif ( 'wp_banana_provider_timeout' === $code ) {
				$message = esc_html__( 'The provider timed out. Try again shortly.', 'wp-banana' );
			} elseif ( '' === $message ) {
				$message = esc_html__( 'Connection test failed. Check the key and try again.', 'wp-banana' );
			}

			wp_send_json_error(
				[
					'code'    => $code,
					'message' => $message,
				],
				400
			);
		}

		wp_send_json_success(
			[
				'message' => sprintf(
					// Translators: %s is the provider label.
					esc_html__( 'Connected to %s successfully.', 'wp-banana' ),
					$this->provider_label( $provider )
				),
			]
		);
	}

	/**
	 * Resolve the API key/token that should be used for a provider.
	 *
	 * @param string $provider      Provider slug.
	 * @param string $submitted_key Submitted key from the UI (may be empty).
	 * @return string
	 */
	private function resolve_provider_secret( string $provider, string $submitted_key ): string {
		$config = Options::PROVIDER_SECRET_KEYS[ $provider ];

		if ( isset( $config['constant'] ) && defined( $config['constant'] ) ) {
			$constant_value = constant( $config['constant'] );
			return trim( is_scalar( $constant_value ) ? (string) $constant_value : '' );
		}

		if ( '' !== $submitted_key ) {
			return trim( $submitted_key );
		}

		$option_key = isset( $config['option_key'] ) ? $config['option_key'] : '';
		if ( '' === $option_key ) {
			return '';
		}

		$stored = $this->options->get_provider_config( $provider );
		if ( isset( $stored[ $option_key ] ) && is_scalar( $stored[ $option_key ] ) ) {
			return trim( (string) $stored[ $option_key ] );
		}

		return '';
	}

	/**
	 * Attempt a lightweight API call to confirm provider connectivity.
	 *
	 * @param string $provider Provider slug.
	 * @param string $secret   API key/token.
	 * @return true|WP_Error
	 */
	private function ping_provider( string $provider, string $secret ) {
		$secret = trim( $secret );

		if ( '' === $secret ) {
			return new WP_Error( 'wp_banana_missing_secret', esc_html__( 'Missing API key.', 'wp-banana' ) );
		}

		switch ( $provider ) {
			case 'gemini':
				$url     = 'https://generativelanguage.googleapis.com/v1beta/models';
				$headers = [
					'x-goog-api-key' => $secret,
				];
				break;
			case 'openai':
				$url     = 'https://api.openai.com/v1/models';
				$headers = [
					'Authorization' => 'Bearer ' . $secret,
				];
				break;
			case 'replicate':
				$url     = 'https://api.replicate.com/v1/models';
				$headers = [
					'Authorization' => 'Bearer ' . $secret,
				];
				break;
			default:
				return new WP_Error( 'wp_banana_unknown_provider', esc_html__( 'Unknown provider.', 'wp-banana' ) );
		}

		$args     = [
			'method'  => 'GET',
			'timeout' => 20,
			'headers' => $headers,
		];
		$response = Http::request( $url, $args );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return true;
	}

	/**
	 * Build a human-friendly label for provider slugs.
	 *
	 * @since 0.2.0
	 *
	 * @param string $provider Provider slug.
	 * @return string
	 */
	private function provider_label( string $provider ): string {
		$provider = strtolower( trim( $provider ) );
		$map      = [
			'gemini'    => __( 'Google', 'wp-banana' ),
			'google'    => __( 'Google', 'wp-banana' ),
			'openai'    => __( 'OpenAI', 'wp-banana' ),
			'replicate' => __( 'Replicate', 'wp-banana' ),
		];
		if ( isset( $map[ $provider ] ) ) {
			return $map[ $provider ];
		}
		$label = str_replace( [ '-', '_' ], ' ', $provider );
		return ucwords( $label );
	}

	/**
	 * Determine wp-config override state for a provider.
	 *
	 * @param string $provider Provider slug.
	 * @return array{defined:bool,has_value:bool,constant:string}
	 */
	private function get_constant_state( string $provider ): array {
		$constant_name = isset( Options::PROVIDER_SECRET_KEYS[ $provider ]['constant'] ) ? (string) Options::PROVIDER_SECRET_KEYS[ $provider ]['constant'] : '';
		$defined       = ( '' !== $constant_name ) && defined( $constant_name );
		$has_value     = false;

		if ( $defined ) {
			$raw_value = constant( $constant_name );
			$value     = is_scalar( $raw_value ) ? (string) $raw_value : '';
			$has_value = '' !== trim( $value );
		}

		return [
			'defined'   => $defined,
			'has_value' => $has_value,
			'constant'  => $constant_name,
		];
	}

	/**
	 * Render a description highlighting wp-config provider overrides.
	 *
	 * @param string $constant_name Constant identifier.
	 * @param bool   $has_value     Whether the constant currently has a non-empty value.
	 * @return void
	 */
	private function render_constant_notice( string $constant_name, bool $has_value ): void {
		if ( '' === $constant_name ) {
			return;
		}

		// Translators: %s is "wp-config.php" in a code tag.
		echo '<p class="description">' . sprintf( esc_html__( 'Managed via constant in %s.', 'wp-banana' ), '<code>wp-config.php</code>' );

		if ( ! $has_value ) {
			echo ' ' . esc_html__( 'The constant is currently empty.', 'wp-banana' );
		}

		echo '</p>';
	}

	/**
	 * Add a plugin action link to the settings page.
	 *
	 * @param array $links Existing action links.
	 * @return array
	 */
	public static function plugin_action_link( array $links ): array {
		$settings_url  = admin_url( 'options-general.php?page=wp-banana' );
		$settings_link = '<a href="' . esc_url( $settings_url ) . '">' . esc_html__( 'Settings', 'wp-banana' ) . '</a>';
		array_unshift( $links, $settings_link );
		return $links;
	}
}
