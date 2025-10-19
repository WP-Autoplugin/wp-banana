<?php
/**
 * Admin settings page.
 *
 * @package WPBanana\Admin
 * @since   0.1.0
 */

namespace WPBanana\Admin;

use WPBanana\Domain\Aspect_Ratios;
use WPBanana\Services\Models_Catalog;
use WPBanana\Services\Options;

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

		$allowed_generate = array_merge( $catalog['generate']['gemini'], $catalog['generate']['openai'], $catalog['generate']['replicate'] );

		$allowed_edit = array_merge( $catalog['edit']['gemini'], $catalog['edit']['openai'], $catalog['edit']['replicate'] );

		$gen_model_raw = isset( $input['default_generator_model'] ) ? sanitize_text_field( $input['default_generator_model'] ) : ( isset( $current['default_generator_model'] ) ? $current['default_generator_model'] : 'gemini-2.5-flash-image-preview' );

		$edit_model_raw = isset( $input['default_editor_model'] ) ? sanitize_text_field( $input['default_editor_model'] ) : ( isset( $current['default_editor_model'] ) ? $current['default_editor_model'] : 'gemini-2.5-flash-image-preview' );
		$current['default_generator_model'] = in_array( $gen_model_raw, $allowed_generate, true ) ? $gen_model_raw : 'gemini-2.5-flash-image-preview';
		$current['default_editor_model']    = in_array( $edit_model_raw, $allowed_edit, true ) ? $edit_model_raw : 'gemini-2.5-flash-image-preview';

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

		$opts             = $this->options->get_all();
		$gemini_state     = $this->get_constant_state( 'gemini' );
		$openai_state     = $this->get_constant_state( 'openai' );
		$replicate_state  = $this->get_constant_state( 'replicate' );
		$gemini_value     = $gemini_state['defined']
			? ( $gemini_state['has_value'] ? '********' : '' )
			: ( isset( $opts['providers']['gemini']['api_key'] ) ? (string) $opts['providers']['gemini']['api_key'] : '' );
		$openai_value     = $openai_state['defined']
			? ( $openai_state['has_value'] ? '********' : '' )
			: ( isset( $opts['providers']['openai']['api_key'] ) ? (string) $opts['providers']['openai']['api_key'] : '' );
		$replicate_value  = $replicate_state['defined']
			? ( $replicate_state['has_value'] ? '********' : '' )
			: ( isset( $opts['providers']['replicate']['api_token'] ) ? (string) $opts['providers']['replicate']['api_token'] : '' );
		$gemini_disabled  = $gemini_state['defined'] ? ' disabled="disabled" aria-disabled="true"' : '';
		$openai_disabled  = $openai_state['defined'] ? ' disabled="disabled" aria-disabled="true"' : '';
		$replicate_disabled = $replicate_state['defined'] ? ' disabled="disabled" aria-disabled="true"' : '';
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
							<input type="password" style="width:420px" name="<?php echo esc_attr( Options::OPTION_NAME ); ?>[providers][gemini][api_key]" value="<?php echo esc_attr( $gemini_value ); ?>" placeholder="AIza..."<?php echo $gemini_disabled; ?> />
							<?php if ( $gemini_state['defined'] ) : ?>
								<?php $this->render_constant_notice( $gemini_state['constant'], $gemini_state['has_value'] ); ?>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row">OpenAI API Key</th>
						<td>
							<input type="password" style="width:420px" name="<?php echo esc_attr( Options::OPTION_NAME ); ?>[providers][openai][api_key]" value="<?php echo esc_attr( $openai_value ); ?>" placeholder="sk-..."<?php echo $openai_disabled; ?> />
							<?php if ( $openai_state['defined'] ) : ?>
								<?php $this->render_constant_notice( $openai_state['constant'], $openai_state['has_value'] ); ?>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row">Replicate API Token</th>
						<td>
							<input type="password" style="width:420px" name="<?php echo esc_attr( Options::OPTION_NAME ); ?>[providers][replicate][api_token]" value="<?php echo esc_attr( $replicate_value ); ?>" placeholder="r8_..."<?php echo $replicate_disabled; ?> />
							<?php if ( $replicate_state['defined'] ) : ?>
								<?php $this->render_constant_notice( $replicate_state['constant'], $replicate_state['has_value'] ); ?>
							<?php endif; ?>
						</td>
					</tr>
					<?php // phpcs:disable Generic.Formatting.MultipleStatementAlignment
					$catalog     = Models_Catalog::all();
					$gen_gemini  = $catalog['generate']['gemini'];
					$gen_openai  = $catalog['generate']['openai'];
					$gen_rep     = $catalog['generate']['replicate'];
					$edit_gemini = $catalog['edit']['gemini'];
					$edit_openai = $catalog['edit']['openai'];
					$edit_rep    = $catalog['edit']['replicate'];
						// phpcs:enable Generic.Formatting.MultipleStatementAlignment
					?>
					<tr>
						<th scope="row"><?php esc_html_e( 'Default Generator Model', 'wp-banana' ); ?></th>
						<td>
							<select style="min-width:420px" name="<?php echo esc_attr( Options::OPTION_NAME ); ?>[default_generator_model]">
								<optgroup label="Google">
									<?php foreach ( $gen_gemini as $m ) : ?>
										<option value="<?php echo esc_attr( $m ); ?>" <?php selected( $opts['default_generator_model'], $m ); ?>><?php echo esc_html( $m ); ?></option>
									<?php endforeach; ?>
								</optgroup>
								<optgroup label="OpenAI">
									<?php foreach ( $gen_openai as $m ) : ?>
										<option value="<?php echo esc_attr( $m ); ?>" <?php selected( $opts['default_generator_model'], $m ); ?>><?php echo esc_html( $m ); ?></option>
									<?php endforeach; ?>
								</optgroup>
								<optgroup label="Replicate">
									<?php foreach ( $gen_rep as $m ) : ?>
										<option value="<?php echo esc_attr( $m ); ?>" <?php selected( $opts['default_generator_model'], $m ); ?>><?php echo esc_html( $m ); ?></option>
									<?php endforeach; ?>
								</optgroup>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Default Editor Model', 'wp-banana' ); ?></th>
						<td>
							<select style="min-width:420px" name="<?php echo esc_attr( Options::OPTION_NAME ); ?>[default_editor_model]">
								<optgroup label="Google">
									<?php foreach ( $edit_gemini as $m ) : ?>
										<option value="<?php echo esc_attr( $m ); ?>" <?php selected( $opts['default_editor_model'], $m ); ?>><?php echo esc_html( $m ); ?></option>
									<?php endforeach; ?>
								</optgroup>
								<optgroup label="OpenAI">
									<?php foreach ( $edit_openai as $m ) : ?>
										<option value="<?php echo esc_attr( $m ); ?>" <?php selected( $opts['default_editor_model'], $m ); ?>><?php echo esc_html( $m ); ?></option>
									<?php endforeach; ?>
								</optgroup>
								<optgroup label="Replicate">
									<?php foreach ( $edit_rep as $m ) : ?>
										<option value="<?php echo esc_attr( $m ); ?>" <?php selected( $opts['default_editor_model'], $m ); ?>><?php echo esc_html( $m ); ?></option>
									<?php endforeach; ?>
								</optgroup>
							</select>
						</td>
					</tr>
				</table>

				<h2 class="title"><?php esc_html_e( 'Defaults', 'wp-banana' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Preferred Aspect ratio', 'wp-banana' ); ?></th>
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

		echo '<p class="description">' . sprintf( esc_html__( 'Managed via constant in %s.', 'wp-banana' ), '<code>wp-config.php</code>' ) ;

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
