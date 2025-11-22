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

	/**
	 * Return the full models catalog keyed by purpose and provider.
	 *
	 * @return array{generate:array{gemini:array<int,string>,openai:array<int,string>,replicate:array<int,string>},edit:array{gemini:array<int,string>,openai:array<int,string>,replicate:array<int,string>}}
	 */
	public static function all(): array {
		$catalog = [
			'generate' => [
				'gemini'    => [
					'gemini-2.5-flash-image-preview',
					'gemini-3-pro-image-preview',
					'gemini-3-pro-image-preview-1k',
					'gemini-3-pro-image-preview-2k',
					'gemini-3-pro-image-preview-4k',
					'imagen-4.0-generate-001',
					'imagen-4.0-ultra-generate-001',
					'imagen-4.0-fast-generate-001',
				],
				'openai'    => [
					'gpt-image-1',
					'gpt-image-1-mini',
				],
				'replicate' => [
					'google/gemini-2.5-flash-image',
					'google/imagen-4',
					'google/imagen-4-ultra',
					'google/imagen-4-fast',
					'black-forest-labs/flux-1.1-pro',
					'black-forest-labs/flux-dev',
					'black-forest-labs/flux-schnell',
					'recraft-ai/recraft-v3',
					'reve/create',
					'ideogram-ai/ideogram-v3-turbo',
					'ideogram-ai/ideogram-v3-quality',
					'ideogram-ai/ideogram-v3-balanced',
					'stability-ai/stable-diffusion-3.5-large',
					'bytedance/seedream-4',
					'tencent/hunyuan-image-3',
					'qwen/qwen-image',
					'minimax/image-01',
				],
			],
			'edit'     => [
				'gemini'    => [
					'gemini-2.5-flash-image-preview',
					'gemini-3-pro-image-preview',
				],
				'openai'    => [
					'gpt-image-1',
					'gpt-image-1-mini',
				],
				'replicate' => [
					'qwen/qwen-image-edit',
					'bytedance/seededit-3.0',
					'bytedance/seedream-4',
					'google/nano-banana',
					'black-forest-labs/flux-kontext-max',
					'black-forest-labs/flux-kontext-dev',
					'reve/edit',
					'reve/remix',
				],
			],
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
}
