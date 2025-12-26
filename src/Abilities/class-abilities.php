<?php
/**
 * Abilities API registration for WP Nano Banana.
 *
 * @package WPBanana\Abilities
 * @since   0.7.0
 */

namespace WPBanana\Abilities;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WP_REST_Request;
use WP_REST_Response;
use WPBanana\REST\Edit_Controller;
use WPBanana\REST\Generate_Controller;
use WPBanana\REST\Models_Controller;
use WPBanana\Services\Edit_Buffer;
use WPBanana\Services\Logging_Service;
use WPBanana\Services\Options;
use WPBanana\Util\Caps;

/**
 * Registers WP Banana abilities for the Abilities API.
 */
final class Abilities {

	/**
	 * Options service instance.
	 *
	 * @var Options
	 */
	private $options;

	/**
	 * Edit buffer store.
	 *
	 * @var Edit_Buffer
	 */
	private $buffer;

	/**
	 * Logging service instance.
	 *
	 * @var Logging_Service
	 */
	private $logger;

	/**
	 * Constructor.
	 *
	 * @param Options         $options Options service.
	 * @param Edit_Buffer     $buffer  Edit buffer store.
	 * @param Logging_Service $logger  Logging service.
	 */
	public function __construct( Options $options, Edit_Buffer $buffer, Logging_Service $logger ) {
		$this->options = $options;
		$this->buffer  = $buffer;
		$this->logger  = $logger;
	}

	/**
	 * Register Abilities API hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'wp_abilities_api_categories_init', [ $this, 'register_categories' ] );
		add_action( 'wp_abilities_api_init', [ $this, 'register_abilities' ] );
	}

	/**
	 * Register the WP Banana ability category.
	 *
	 * @return void
	 */
	public function register_categories(): void {
		if ( ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}

		wp_register_ability_category(
			'ai-images',
			[
				'label'       => __( 'AI Images', 'wp-banana' ),
				'description' => __( 'Abilities for generating and editing images with AI.', 'wp-banana' ),
			]
		);
	}

	/**
	 * Register WP Banana abilities.
	 *
	 * @return void
	 */
	public function register_abilities(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		wp_register_ability(
			'wp-banana/generate-image',
			[
				'label'               => __( 'Generate Image', 'wp-banana' ),
				'description'         => __( 'Generate an image from a prompt and save it as a WordPress attachment.', 'wp-banana' ),
				'category'            => 'ai-images',
				'input_schema'        => $this->generate_input_schema(),
				'output_schema'       => $this->attachment_output_schema(),
				'execute_callback'    => function ( array $input = [] ) {
					return $this->execute_generate( $input );
				},
				'permission_callback' => function ( array $input = [] ) {
					return current_user_can( Caps::GENERATE );
				},
				'meta'                => [
					'annotations' => [
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => false,
					],
				],
				'show_in_rest'        => false,
			]
		);

		wp_register_ability(
			'wp-banana/edit-image',
			[
				'label'               => __( 'Edit Image', 'wp-banana' ),
				'description'         => __( 'Apply an AI edit to an existing attachment and save the result.', 'wp-banana' ),
				'category'            => 'ai-images',
				'input_schema'        => $this->edit_input_schema(),
				'output_schema'       => $this->attachment_output_schema(),
				'execute_callback'    => function ( array $input = [] ) {
					return $this->execute_edit( $input );
				},
				'permission_callback' => function ( array $input = [] ) {
					return current_user_can( Caps::EDIT );
				},
				'meta'                => [
					'annotations' => [
						'readonly'    => false,
						'destructive' => true,
						'idempotent'  => false,
					],
				],
				'show_in_rest'        => false,
			]
		);

		wp_register_ability(
			'wp-banana/list-models',
			[
				'label'               => __( 'List Models', 'wp-banana' ),
				'description'         => __( 'List available provider models for image generation or editing.', 'wp-banana' ),
				'category'            => 'ai-images',
				'input_schema'        => $this->models_input_schema(),
				'output_schema'       => $this->models_output_schema(),
				'execute_callback'    => function ( array $input = [] ) {
					return $this->execute_models( $input );
				},
				'permission_callback' => function ( array $input = [] ) {
					return current_user_can( Caps::MODELS );
				},
				'meta'                => [
					'annotations' => [
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					],
				],
				'show_in_rest'        => false,
			]
		);
	}

	/**
	 * Execute the generate controller for Abilities API.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	private function execute_generate( array $input ) {
		$controller = new Generate_Controller( $this->options, $this->logger );
		$request    = new WP_REST_Request( 'POST', '/wp-banana/v1/generate' );
		$request->set_body_params( $input );

		return $this->normalize_response( $controller->handle( $request ) );
	}

	/**
	 * Execute the edit controller for Abilities API.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	private function execute_edit( array $input ) {
		$controller = new Edit_Controller( $this->options, $this->buffer, $this->logger );
		$request    = new WP_REST_Request( 'POST', '/wp-banana/v1/edit' );
		$request->set_body_params( $input );

		return $this->normalize_response( $controller->handle( $request ) );
	}

	/**
	 * Execute the models controller for Abilities API.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	private function execute_models( array $input ) {
		$controller = new Models_Controller( $this->options );
		$request    = new WP_REST_Request( 'GET', '/wp-banana/v1/models' );
		$request->set_query_params( $input );

		return $this->normalize_response( $controller->handle( $request ) );
	}

	/**
	 * Normalize controller responses for Abilities API.
	 *
	 * @param WP_REST_Response|WP_Error|mixed $response Controller response.
	 * @return array|WP_Error|mixed
	 */
	private function normalize_response( $response ) {
		if ( $response instanceof WP_REST_Response ) {
			return $response->get_data();
		}

		return $response;
	}

	/**
	 * Input schema for image generation.
	 *
	 * @return array
	 */
	private function generate_input_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'prompt'              => [
					'type'        => 'string',
					'description' => 'Text prompt for image generation.',
				],
				'provider'            => [
					'type'        => 'string',
					'description' => 'Provider slug (gemini, openai, replicate).',
				],
				'model'               => [
					'type'        => 'string',
					'description' => 'Model identifier to use.',
				],
				'aspect_ratio'        => [
					'type'        => 'string',
					'description' => 'Aspect ratio preset.',
				],
				'resolution'          => [
					'type'        => 'string',
					'description' => 'Resolution preset.',
				],
				'width'               => [
					'type'        => 'integer',
					'description' => 'Requested width in pixels.',
				],
				'height'              => [
					'type'        => 'integer',
					'description' => 'Requested height in pixels.',
				],
				'format'              => [
					'type'        => 'string',
					'description' => 'Output format (png, jpeg, webp).',
				],
				'preview_only'        => [
					'type'        => 'boolean',
					'description' => 'Generate preview without saving.',
				],
				'reference_image_ids' => [
					'type'        => 'array',
					'description' => 'Attachment IDs for reference images.',
					'items'       => [
						'type' => 'integer',
					],
					'maxItems'    => 4,
				],
			],
			'required'   => [ 'prompt' ],
		];
	}

	/**
	 * Input schema for image edits.
	 *
	 * @return array
	 */
	private function edit_input_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'attachment_id'       => [
					'type'        => 'integer',
					'description' => 'Attachment ID to edit.',
				],
				'prompt'              => [
					'type'        => 'string',
					'description' => 'Edit prompt.',
				],
				'provider'            => [
					'type'        => 'string',
					'description' => 'Provider slug (gemini, openai, replicate).',
				],
				'model'               => [
					'type'        => 'string',
					'description' => 'Model identifier to use.',
				],
				'format'              => [
					'type'        => 'string',
					'description' => 'Output format (png, jpeg, webp).',
				],
				'save_mode'           => [
					'type'        => 'string',
					'description' => 'Save mode (new, replace, buffer).',
					'enum'        => [ 'new', 'replace', 'buffer' ],
				],
				'base_buffer_key'     => [
					'type'        => 'string',
					'description' => 'Optional buffer key to chain edits.',
				],
				'reference_image_ids' => [
					'type'        => 'array',
					'description' => 'Attachment IDs for reference images.',
					'items'       => [
						'type' => 'integer',
					],
					'maxItems'    => 4,
				],
			],
			'required'   => [ 'attachment_id', 'prompt' ],
		];
	}

	/**
	 * Input schema for models listing.
	 *
	 * @return array
	 */
	private function models_input_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'provider' => [
					'type'        => 'string',
					'description' => 'Provider slug (gemini, openai, replicate).',
				],
				'purpose'  => [
					'type'        => 'string',
					'description' => 'Purpose filter (generate or edit).',
					'enum'        => [ 'generate', 'edit' ],
				],
			],
		];
	}

	/**
	 * Output schema for attachment responses.
	 *
	 * @return array
	 */
	private function attachment_output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'attachment_id' => [
					'type'        => 'integer',
					'description' => 'Attachment ID for the generated image.',
				],
				'url'           => [
					'type'        => 'string',
					'description' => 'Attachment URL.',
				],
				'parent_id'     => [
					'type'        => 'integer',
					'description' => 'Parent attachment ID (when editing).',
				],
				'filename'      => [
					'type'        => 'string',
					'description' => 'Filename of the generated asset.',
				],
			],
		];
	}

	/**
	 * Output schema for models listing.
	 *
	 * @return array
	 */
	private function models_output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'models' => [
					'type'  => 'array',
					'items' => [
						'type' => 'string',
					],
				],
			],
		];
	}
}
