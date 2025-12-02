<?php
/**
 * Central models catalog.
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
 * Provides static catalogs of available models per provider/purpose.
 */
final class Models_Catalog {

	/**
	 * Disallow instantiation.
	 */
	private function __construct() {}

	public const GEMINI_FLASH_IMAGE_PREVIEW     = 'gemini-2.5-flash-image-preview';
	public const GEMINI_FLASH_IMAGE             = 'gemini-2.5-flash-image';
	public const GEMINI_3_PRO_IMAGE_PREVIEW     = 'gemini-3-pro-image-preview';
	public const IMAGEN_4_GENERATE              = 'imagen-4.0-generate-001';
	public const IMAGEN_4_ULTRA_GENERATE        = 'imagen-4.0-ultra-generate-001';
	public const IMAGEN_4_FAST_GENERATE         = 'imagen-4.0-fast-generate-001';
	public const OPENAI_GPT_IMAGE_1             = 'gpt-image-1';
	public const OPENAI_GPT_IMAGE_1_MINI        = 'gpt-image-1-mini';
	public const REPLICATE_GEMINI_FLASH_IMAGE   = 'google/gemini-2.5-flash-image';
	public const REPLICATE_IMAGEN_4             = 'google/imagen-4';
	public const REPLICATE_IMAGEN_4_ULTRA       = 'google/imagen-4-ultra';
	public const REPLICATE_IMAGEN_4_FAST        = 'google/imagen-4-fast';
	public const REPLICATE_NANO_BANANA_PRO      = 'google/nano-banana-pro';
	public const REPLICATE_FLUX_11_PRO          = 'black-forest-labs/flux-1.1-pro';
	public const REPLICATE_FLUX_DEV             = 'black-forest-labs/flux-dev';
	public const REPLICATE_FLUX_SCHNELL         = 'black-forest-labs/flux-schnell';
	public const REPLICATE_RECRAFT_V3           = 'recraft-ai/recraft-v3';
	public const REPLICATE_REVE_CREATE          = 'reve/create';
	public const REPLICATE_IDEOGRAM_V3_TURBO    = 'ideogram-ai/ideogram-v3-turbo';
	public const REPLICATE_IDEOGRAM_V3_QUALITY  = 'ideogram-ai/ideogram-v3-quality';
	public const REPLICATE_IDEOGRAM_V3_BALANCED = 'ideogram-ai/ideogram-v3-balanced';
	public const REPLICATE_SD35_LARGE           = 'stability-ai/stable-diffusion-3.5-large';
	public const REPLICATE_SEEDREAM_4           = 'bytedance/seedream-4';
	public const REPLICATE_HUNYUAN_IMAGE_3      = 'tencent/hunyuan-image-3';
	public const REPLICATE_QWEN_IMAGE           = 'qwen/qwen-image';
	public const REPLICATE_MINIMAX_IMAGE        = 'minimax/image-01';
	public const REPLICATE_QWEN_IMAGE_EDIT      = 'qwen/qwen-image-edit';
	public const REPLICATE_SEEDEDIT_30          = 'bytedance/seededit-3.0';
	public const REPLICATE_NANO_BANANA          = 'google/nano-banana';
	public const REPLICATE_FLUX_KONTEXT_MAX     = 'black-forest-labs/flux-kontext-max';
	public const REPLICATE_FLUX_KONTEXT_DEV     = 'black-forest-labs/flux-kontext-dev';
	public const REPLICATE_REVE_EDIT            = 'reve/edit';
	public const REPLICATE_REVE_REMIX           = 'reve/remix';
	public const REPLICATE_FLUX                 = 'black-forest-labs/flux';

	public const DEFAULT_GENERATOR_MODEL = self::GEMINI_FLASH_IMAGE_PREVIEW;
	public const DEFAULT_EDITOR_MODEL    = self::GEMINI_FLASH_IMAGE_PREVIEW;

	/**
	 * Return the full models catalog keyed by purpose and provider.
	 *
	 * @return array{generate:array{gemini:array<int,string>,openai:array<int,string>,replicate:array<int,string>},edit:array{gemini:array<int,string>,openai:array<int,string>,replicate:array<int,string>}}
	 */
	public static function all(): array {
		$catalog = [
			'generate' => self::generation_catalog(),
			'edit'     => self::edit_catalog(),
		];
		/**
		 * Filter the full models catalog for both purposes and providers.
		 *
		 * @since 0.2.0
		 *
		 * @param array $catalog Complete catalog keyed by purpose => provider => models.
		 */
		$catalog = apply_filters( 'wp_banana_models_catalog_all', $catalog );
		return is_array( $catalog ) ? $catalog : [
			'generate' => [],
			'edit'     => [],
		];
	}

	/**
	 * Return default models keyed by provider slug.
	 *
	 * @return array<string,string>
	 */
	public static function provider_default_models(): array {
		return [
			'gemini'    => self::GEMINI_FLASH_IMAGE_PREVIEW,
			'openai'    => self::OPENAI_GPT_IMAGE_1,
			'replicate' => self::REPLICATE_FLUX,
		];
	}

	/**
	 * Default generator model when no provider/model is specified.
	 *
	 * @return string
	 */
	public static function default_generator_model(): string {
		return self::DEFAULT_GENERATOR_MODEL;
	}

	/**
	 * Default editor model when no provider/model is specified.
	 *
	 * @return string
	 */
	public static function default_editor_model(): string {
		return self::DEFAULT_EDITOR_MODEL;
	}

	/**
	 * Default model for a given provider.
	 *
	 * @param string $provider Provider slug.
	 * @return string
	 */
	public static function provider_default_model( string $provider ): string {
		$defaults = self::provider_default_models();
		return isset( $defaults[ $provider ] ) ? $defaults[ $provider ] : self::default_generator_model();
	}

	/**
	 * Return models for a given purpose/provider pair.
	 *
	 * @param string $purpose  Purpose (generate|edit).
	 * @param string $provider Provider slug (gemini|openai|replicate).
	 * @return array<int,string>
	 */
	public static function get( string $purpose, string $provider ): array {
		$catalog = self::all();
		$models  = [];
		if ( isset( $catalog[ $purpose ][ $provider ] ) && is_array( $catalog[ $purpose ][ $provider ] ) ) {
			$models = $catalog[ $purpose ][ $provider ];
		}
		/**
		 * Filter the models list for a given purpose and provider.
		 *
		 * @since 0.2.0
		 *
		 * @param array  $models   Model identifiers.
		 * @param string $purpose  Purpose (generate|edit).
		 * @param string $provider Provider slug.
		 * @param array  $catalog  Full models catalog (post-filter).
		 */
		$models = apply_filters( 'wp_banana_models_catalog', $models, $purpose, $provider, $catalog );
		if ( ! is_array( $models ) ) {
			return [];
		}
		return array_values( array_map( 'strval', $models ) );
	}

	/**
	 * Models that support multi-image reference uploads keyed by provider.
	 *
	 * @return array<string,array<int,string>>
	 */
	public static function multi_image_allowlist(): array {
		return [
			'gemini'    => [
				self::GEMINI_FLASH_IMAGE_PREVIEW,
				self::GEMINI_FLASH_IMAGE,
				self::GEMINI_3_PRO_IMAGE_PREVIEW,
			],
			'openai'    => [
				self::OPENAI_GPT_IMAGE_1,
				self::OPENAI_GPT_IMAGE_1_MINI,
			],
			'replicate' => [
				self::REPLICATE_NANO_BANANA,
				self::REPLICATE_NANO_BANANA_PRO,
				self::REPLICATE_SEEDREAM_4,
				self::REPLICATE_REVE_REMIX,
			],
		];
	}

	/**
	 * Models that support custom resolution selection keyed by provider.
	 *
	 * @return array<string,array<int,string>>
	 */
	public static function resolution_model_allowlist(): array {
		return [
			'gemini'    => [
				self::GEMINI_3_PRO_IMAGE_PREVIEW,
			],
			'replicate' => [
				self::REPLICATE_NANO_BANANA_PRO,
			],
		];
	}

	/**
	 * Generation catalog keyed by provider.
	 *
	 * @return array{gemini:array<int,string>,openai:array<int,string>,replicate:array<int,string>}
	 */
	private static function generation_catalog(): array {
		return [
			'gemini'    => [
				self::GEMINI_FLASH_IMAGE_PREVIEW,
				self::GEMINI_3_PRO_IMAGE_PREVIEW,
				self::IMAGEN_4_GENERATE,
				self::IMAGEN_4_ULTRA_GENERATE,
				self::IMAGEN_4_FAST_GENERATE,
			],
			'openai'    => [
				self::OPENAI_GPT_IMAGE_1,
				self::OPENAI_GPT_IMAGE_1_MINI,
			],
			'replicate' => [
				self::REPLICATE_GEMINI_FLASH_IMAGE,
				self::REPLICATE_IMAGEN_4,
				self::REPLICATE_IMAGEN_4_ULTRA,
				self::REPLICATE_IMAGEN_4_FAST,
				self::REPLICATE_NANO_BANANA_PRO,
				self::REPLICATE_FLUX_11_PRO,
				self::REPLICATE_FLUX_DEV,
				self::REPLICATE_FLUX_SCHNELL,
				self::REPLICATE_RECRAFT_V3,
				self::REPLICATE_REVE_CREATE,
				self::REPLICATE_IDEOGRAM_V3_TURBO,
				self::REPLICATE_IDEOGRAM_V3_QUALITY,
				self::REPLICATE_IDEOGRAM_V3_BALANCED,
				self::REPLICATE_SD35_LARGE,
				self::REPLICATE_SEEDREAM_4,
				self::REPLICATE_HUNYUAN_IMAGE_3,
				self::REPLICATE_QWEN_IMAGE,
				self::REPLICATE_MINIMAX_IMAGE,
			],
		];
	}

	/**
	 * Edit catalog keyed by provider.
	 *
	 * @return array{gemini:array<int,string>,openai:array<int,string>,replicate:array<int,string>}
	 */
	private static function edit_catalog(): array {
		return [
			'gemini'    => [
				self::GEMINI_FLASH_IMAGE_PREVIEW,
				self::GEMINI_3_PRO_IMAGE_PREVIEW,
			],
			'openai'    => [
				self::OPENAI_GPT_IMAGE_1,
				self::OPENAI_GPT_IMAGE_1_MINI,
			],
			'replicate' => [
				self::REPLICATE_QWEN_IMAGE_EDIT,
				self::REPLICATE_SEEDEDIT_30,
				self::REPLICATE_SEEDREAM_4,
				self::REPLICATE_NANO_BANANA_PRO,
				self::REPLICATE_NANO_BANANA,
				self::REPLICATE_FLUX_KONTEXT_MAX,
				self::REPLICATE_FLUX_KONTEXT_DEV,
				self::REPLICATE_REVE_EDIT,
				self::REPLICATE_REVE_REMIX,
			],
		];
	}
}
