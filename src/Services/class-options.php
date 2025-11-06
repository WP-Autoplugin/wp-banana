<?php
/**
 * Options service: storage, defaults, connection state.
 *
 * @package WPBanana\Services
 * @since   0.1.0
 */

namespace WPBanana\Services;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages plugin options and defaults.
 */
final class Options {

	public const OPTION_NAME = 'wp_banana_options';

	/**
	 * Provider secret overrides keyed by provider slug.
	 *
	 * @var array<string,array<string,string>>
	 */
	public const PROVIDER_SECRET_KEYS = [
		'gemini'    => [
			'constant'   => 'WP_BANANA_GOOGLE_API_KEY',
			'option_key' => 'api_key',
		],
		'openai'    => [
			'constant'   => 'WP_BANANA_OPENAI_API_KEY',
			'option_key' => 'api_key',
		],
		'replicate' => [
			'constant'   => 'WP_BANANA_REPLICATE_API_KEY',
			'option_key' => 'api_token',
		],
	];

	/**
	 * Options array with environment overrides applied.
	 *
	 * @var array
	 */
	private $options;

	/**
	 * Raw stored options without environment overrides.
	 *
	 * @var array
	 */
	private $stored_options;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$stored = get_option( self::OPTION_NAME, [] );
		$merged = $this->merge_defaults( is_array( $stored ) ? $stored : [] );
		$this->hydrate( $merged );
	}

	/**
	 * Get all options (merged with defaults).
	 *
	 * @return array
	 */
	public function get_all(): array {
		return $this->options;
	}

	/**
	 * Get stored options without environment overrides.
	 *
	 * @return array
	 */
	public function get_stored(): array {
		return $this->stored_options;
	}

	/**
	 * Get nested option by dot path.
	 *
	 * @param string     $path     Dot-notated key path.
	 * @param mixed|null $fallback Default value when path not found.
	 * @return mixed
	 */
	public function get( $path, $fallback = null ) {
		$segments = explode( '.', (string) $path );
		$value    = $this->options;
		foreach ( $segments as $seg ) {
			if ( ! is_array( $value ) || ! array_key_exists( $seg, $value ) ) {
				return $fallback;
			}
			$value = $value[ $seg ];
		}
		return $value;
	}

	/**
	 * Update and persist options.
	 *
	 * @param array $updates Partial options to merge.
	 * @return void
	 */
	public function update( array $updates ): void {
		$merged = $this->merge_defaults( $updates );
		update_option( self::OPTION_NAME, $merged, false );
		$this->hydrate( $merged );
	}

	/**
	 * Whether at least one provider credential is present.
	 *
	 * @return bool
	 */
	public function is_connected(): bool {
		$providers = $this->get( 'providers', [] );
		if ( ! is_array( $providers ) ) {
			return false;
		}
		$gemini    = isset( $providers['gemini']['api_key'] ) ? $providers['gemini']['api_key'] : '';
		$replicate = isset( $providers['replicate']['api_token'] ) ? $providers['replicate']['api_token'] : '';
		$openai    = isset( $providers['openai']['api_key'] ) ? $providers['openai']['api_key'] : '';
		return ( is_string( $gemini ) && '' !== $gemini )
			|| ( is_string( $replicate ) && '' !== $replicate )
			|| ( is_string( $openai ) && '' !== $openai );
	}

	/**
	 * Get provider configuration sub-array.
	 *
	 * @param string $provider Provider slug.
	 * @return array
	 */
	public function get_provider_config( string $provider ): array {
		$providers = $this->get( 'providers', [] );
		return isset( $providers[ $provider ] ) && is_array( $providers[ $provider ] ) ? $providers[ $provider ] : [];
	}

	/**
	 * Merge stored values onto defaults.
	 *
	 * @param array $stored Stored options.
	 * @return array
	 */
	private function merge_defaults( array $stored ): array {
		$defaults = [
			// Global default models used when no explicit model is provided.
			'default_generator_model' => 'gemini-2.5-flash-image-preview',
			'default_editor_model'    => 'gemini-2.5-flash-image-preview',
			'providers'               => [
				'gemini'    => [
					'api_key'       => '',
					'default_model' => 'gemini-2.5-flash-image-preview',
				],
				'openai'    => [
					'api_key'       => '',
					'default_model' => 'gpt-image-1',
				],
				'replicate' => [
					'api_token'     => '',
					'default_model' => 'black-forest-labs/flux',
				],
			],
			'generation_defaults'     => [
				'aspect_ratio' => '1:1',
				'format'       => 'png',
			],
			'privacy'                 => [
				'store_history' => false,
			],
			'logging'                 => [
				'enabled' => false,
			],
			'option_version'          => 1,
		];

		return $this->deep_merge( $defaults, $stored );
	}

	/**
	 * Apply environment overrides and set runtime/stored state.
	 *
	 * @param array $stored Stored options merged with defaults.
	 * @return void
	 */
	private function hydrate( array $stored ): void {
		$this->stored_options = $stored;
		$this->options        = $this->apply_env_overrides( $stored );
	}

	/**
	 * Overlay wp-config defined constants onto stored options.
	 *
	 * @param array $options Options array.
	 * @return array
	 */
	private function apply_env_overrides( array $options ): array {
		foreach ( self::PROVIDER_SECRET_KEYS as $provider => $config ) {
			if ( isset( $config['constant'], $config['option_key'] ) && defined( $config['constant'] ) ) {
				$constant_value = constant( $config['constant'] );
				$options['providers'][ $provider ][ $config['option_key'] ] = is_string( $constant_value ) ? $constant_value : strval( $constant_value );
			}
		}

		return $options;
	}

	/**
	 * Whether a provider secret constant is defined.
	 *
	 * @param string $provider Provider slug.
	 * @return bool
	 */
	public function provider_constant_defined( string $provider ): bool {
		return isset( self::PROVIDER_SECRET_KEYS[ $provider ]['constant'] )
			&& defined( self::PROVIDER_SECRET_KEYS[ $provider ]['constant'] );
	}

	/**
	 * Deep array merge preserving scalar overrides.
	 *
	 * @param array $defaults  Default array.
	 * @param array $overrides Overrides.
	 * @return array
	 */
	private function deep_merge( array $defaults, array $overrides ): array {
		foreach ( $overrides as $k => $v ) {
			if ( array_key_exists( $k, $defaults ) && is_array( $defaults[ $k ] ) && is_array( $v ) ) {
				$defaults[ $k ] = $this->deep_merge( $defaults[ $k ], $v );
			} else {
				$defaults[ $k ] = $v;
			}
		}
		return $defaults;
	}
}
