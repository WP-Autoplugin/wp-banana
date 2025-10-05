<?php
/**
 * Attachment AI metadata helpers.
 *
 * @package WPBanana\Services
 * @since   0.1.0
 */

namespace WPBanana\Services;

use WP_Post;
use function delete_post_meta;
use function get_attachment_link;
use function get_current_user_id;
use function get_edit_post_link;
use function get_post;
use function get_post_meta;
use function get_the_title;
use function get_user_by;
use function sanitize_key;
use function sanitize_text_field;
use function sanitize_textarea_field;
use function time;
use function update_post_meta;
use function wp_json_encode;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Centralizes storage and retrieval of AI attachment metadata.
 */
final class Attachment_Metadata {

	private const META_KEY    = '_ai_meta';
	private const HISTORY_KEY = '_ai_history';

	/**
	 * Update AI flag/meta for an attachment.
	 *
	 * @param int   $attachment_id Attachment ID.
	 * @param array $context       Context values (action, provider, model, timestamp, user_id, prompt, derived_from).
	 * @return void
	 */
	public static function update_generated_meta( int $attachment_id, array $context = [] ): void {
		$meta              = self::get_meta( $attachment_id );
		$meta['generated'] = true;
		$meta['last']      = [
			'action'    => isset( $context['action'] ) ? sanitize_key( (string) $context['action'] ) : 'generate',
			'provider'  => isset( $context['provider'] ) ? sanitize_key( (string) $context['provider'] ) : '',
			'model'     => isset( $context['model'] ) ? sanitize_text_field( (string) $context['model'] ) : '',
			'timestamp' => isset( $context['timestamp'] ) ? (int) $context['timestamp'] : time(),
			'user_id'   => isset( $context['user_id'] ) ? (int) $context['user_id'] : get_current_user_id(),
			'mode'      => isset( $context['mode'] ) ? sanitize_key( (string) $context['mode'] ) : '',
			'prompt'    => isset( $context['prompt'] ) ? sanitize_textarea_field( (string) $context['prompt'] ) : '',
		];
		if ( isset( $context['derived_from'] ) ) {
			$derived = (int) $context['derived_from'];
			if ( $derived > 0 ) {
				$meta['derived_from'] = $derived;
			} else {
				unset( $meta['derived_from'] );
			}
		}
		update_post_meta( $attachment_id, self::META_KEY, $meta );
		self::cleanup_legacy_meta( $attachment_id );
	}

	/**
	 * Append an AI history event to the attachment log.
	 *
	 * @param int   $attachment_id Attachment ID.
	 * @param array $event         Event payload (type, provider, model, timestamp, user_id, derived_from, mode, prompt).
	 * @return void
	 */
	public static function append_event( int $attachment_id, array $event ): void {
		$event['timestamp']     = isset( $event['timestamp'] ) ? (int) $event['timestamp'] : time();
		$event['user_id']       = isset( $event['user_id'] ) ? (int) $event['user_id'] : get_current_user_id();
		$event['type']          = isset( $event['type'] ) ? sanitize_key( (string) $event['type'] ) : '';
		$event['provider']      = isset( $event['provider'] ) ? sanitize_key( (string) $event['provider'] ) : '';
		$event['model']         = isset( $event['model'] ) ? sanitize_text_field( (string) $event['model'] ) : '';
		$event['mode']          = isset( $event['mode'] ) ? sanitize_key( (string) $event['mode'] ) : '';
		$event['prompt']        = isset( $event['prompt'] ) ? sanitize_textarea_field( (string) $event['prompt'] ) : '';
		$event['derived_from']  = isset( $event['derived_from'] ) ? (int) $event['derived_from'] : 0;
		$event['attachment_id'] = isset( $event['attachment_id'] ) ? (int) $event['attachment_id'] : $attachment_id;

		if ( ! self::should_store_history() ) {
			return;
		}

		$history   = self::get_history( $attachment_id );
		$history[] = [
			'type'          => $event['type'],
			'provider'      => $event['provider'],
			'model'         => $event['model'],
			'timestamp'     => $event['timestamp'],
			'user_id'       => $event['user_id'],
			'mode'          => $event['mode'],
			'prompt'        => $event['prompt'],
			'derived_from'  => $event['derived_from'],
			'attachment_id' => $event['attachment_id'],
		];

		// Keep the history log capped to avoid unbounded growth.
		$history = array_slice( $history, -50 );

		update_post_meta( $attachment_id, self::HISTORY_KEY, wp_json_encode( $history ) );
	}

	/**
	 * Retrieve decoded AI history entries.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array<int,array<string,mixed>>
	 */
	public static function get_history( int $attachment_id ): array {
		$raw = get_post_meta( $attachment_id, self::HISTORY_KEY, true );
		if ( is_string( $raw ) && '' !== $raw ) {
			$decoded = json_decode( $raw, true );
			if ( is_array( $decoded ) ) {
				return array_values( array_filter( array_map( [ self::class, 'sanitize_history_entry' ], $decoded ) ) );
			}
		}
		return [];
	}

	/**
	 * Prepare AI metadata for JS representation.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array<string,mixed>
	 */
	public static function prepare_for_js( int $attachment_id ): array {
		$meta         = self::get_meta( $attachment_id );
		$is_generated = ! empty( $meta['generated'] );
		$last         = isset( $meta['last'] ) && is_array( $meta['last'] ) ? $meta['last'] : [];
		$action       = isset( $last['action'] ) ? sanitize_key( (string) $last['action'] ) : '';
		$provider     = isset( $last['provider'] ) ? sanitize_key( (string) $last['provider'] ) : '';
		$model        = isset( $last['model'] ) ? sanitize_text_field( (string) $last['model'] ) : '';
		$timestamp    = isset( $last['timestamp'] ) ? (int) $last['timestamp'] : 0;
		$user_id      = isset( $last['user_id'] ) ? (int) $last['user_id'] : 0;
		$mode         = isset( $last['mode'] ) ? sanitize_key( (string) $last['mode'] ) : '';
		$last_prompt  = isset( $last['prompt'] ) ? sanitize_textarea_field( (string) $last['prompt'] ) : '';
		$derived_id   = isset( $meta['derived_from'] ) ? (int) $meta['derived_from'] : 0;

		$derived = null;
		if ( $derived_id > 0 ) {
			$source = get_post( $derived_id );
			if ( $source instanceof WP_Post ) {
				$derived = [
					'id'       => $derived_id,
					'title'    => get_the_title( $source ),
					'editLink' => get_edit_post_link( $derived_id, '' ),
					'viewLink' => get_attachment_link( $derived_id ),
				];
			}
		}

		$history           = self::get_history( $attachment_id );
		$history_formatted = [];
		if ( ! empty( $history ) ) {
			$history_formatted = array_map( [ self::class, 'format_history_for_js' ], $history );
		}

		$user = null;
		if ( $user_id > 0 ) {
			$user_obj = get_user_by( 'id', $user_id );
			if ( $user_obj ) {
				$user = [
					'id'   => $user_id,
					'name' => $user_obj->display_name,
				];
			}
		}

		return [
			'isGenerated'    => $is_generated,
			'lastAction'     => $action,
			'lastProvider'   => $provider,
			'lastModel'      => $model,
			'lastTimestamp'  => $timestamp,
			'lastUser'       => $user,
			'lastPrompt'     => $last_prompt,
			'lastMode'       => $mode,
			'derivedFrom'    => $derived,
			'historyEnabled' => self::should_store_history(),
			'history'        => $history_formatted,
			'historyCount'   => count( $history_formatted ),
		];
	}

	/**
	 * Whether the site configuration allows storing history.
	 *
	 * @return bool
	 */
	public static function should_store_history(): bool {
		$options = new Options();
		return (bool) $options->get( 'privacy.store_history', false );
	}

	/**
	 * Sanitize a single history entry when reading from meta.
	 *
	 * @param mixed $entry Raw entry.
	 * @return array<string,mixed>|null
	 */
	private static function sanitize_history_entry( $entry ) {
		if ( ! is_array( $entry ) ) {
			return null;
		}

		$type      = isset( $entry['type'] ) ? sanitize_key( (string) $entry['type'] ) : '';
		$provider  = isset( $entry['provider'] ) ? sanitize_key( (string) $entry['provider'] ) : '';
		$model     = isset( $entry['model'] ) ? sanitize_text_field( (string) $entry['model'] ) : '';
		$timestamp = isset( $entry['timestamp'] ) ? (int) $entry['timestamp'] : 0;
		$user_id   = isset( $entry['user_id'] ) ? (int) $entry['user_id'] : 0;
		$mode      = isset( $entry['mode'] ) ? sanitize_key( (string) $entry['mode'] ) : '';
		$prompt    = isset( $entry['prompt'] ) ? sanitize_textarea_field( (string) $entry['prompt'] ) : '';
		$derived   = isset( $entry['derived_from'] ) ? (int) $entry['derived_from'] : 0;
		$target    = isset( $entry['attachment_id'] ) ? (int) $entry['attachment_id'] : 0;

		return [
			'type'          => $type,
			'provider'      => $provider,
			'model'         => $model,
			'timestamp'     => $timestamp,
			'user_id'       => $user_id,
			'mode'          => $mode,
			'prompt'        => $prompt,
			'derived_from'  => $derived,
			'attachment_id' => $target,
		];
	}

	/**
	 * Format history entry for JS including resolved user names.
	 *
	 * @param array<string,mixed> $entry History entry.
	 * @return array<string,mixed>
	 */
	private static function format_history_for_js( array $entry ): array {
		$user = null;
		if ( isset( $entry['user_id'] ) && (int) $entry['user_id'] > 0 ) {
			$user_obj = get_user_by( 'id', (int) $entry['user_id'] );
			if ( $user_obj ) {
				$user = [
					'id'   => (int) $entry['user_id'],
					'name' => $user_obj->display_name,
				];
			}
		}

		$derived = null;
		if ( isset( $entry['derived_from'] ) && (int) $entry['derived_from'] > 0 ) {
			$source = get_post( (int) $entry['derived_from'] );
			if ( $source instanceof WP_Post ) {
				$derived = [
					'id'       => (int) $entry['derived_from'],
					'title'    => get_the_title( $source ),
					'editLink' => get_edit_post_link( (int) $entry['derived_from'], '' ),
					'viewLink' => get_attachment_link( (int) $entry['derived_from'] ),
				];
			}
		}

		return [
			'type'         => isset( $entry['type'] ) ? $entry['type'] : '',
			'provider'     => isset( $entry['provider'] ) ? $entry['provider'] : '',
			'model'        => isset( $entry['model'] ) ? $entry['model'] : '',
			'timestamp'    => isset( $entry['timestamp'] ) ? (int) $entry['timestamp'] : 0,
			'mode'         => isset( $entry['mode'] ) ? $entry['mode'] : '',
			'prompt'       => isset( $entry['prompt'] ) ? $entry['prompt'] : '',
			'user'         => $user,
			'derivedFrom'  => $derived,
			'attachmentId' => isset( $entry['attachment_id'] ) ? (int) $entry['attachment_id'] : 0,
		];
	}

	/**
	 * Retrieve combined AI meta for an attachment.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array
	 */
	private static function get_meta( int $attachment_id ): array {
		$meta = get_post_meta( $attachment_id, self::META_KEY, true );
		if ( is_array( $meta ) ) {
			return $meta;
		}

		// Legacy fallback for previously stored discrete meta.
		$generated = '1' === (string) get_post_meta( $attachment_id, '_ai_generated', true );
		$last      = [
			'action'    => sanitize_key( (string) get_post_meta( $attachment_id, '_ai_last_action', true ) ),
			'provider'  => sanitize_key( (string) get_post_meta( $attachment_id, '_ai_last_provider', true ) ),
			'model'     => sanitize_text_field( (string) get_post_meta( $attachment_id, '_ai_last_model', true ) ),
			'timestamp' => (int) get_post_meta( $attachment_id, '_ai_last_timestamp', true ),
			'user_id'   => (int) get_post_meta( $attachment_id, '_ai_last_user', true ),
			'mode'      => '',
			'prompt'    => '',
		];
		$derived   = (int) get_post_meta( $attachment_id, '_ai_derived_from', true );

		if ( ! $generated && empty( array_filter( $last ) ) && 0 === $derived ) {
			return [];
		}

		return [
			'generated'    => $generated,
			'last'         => $last,
			'derived_from' => $derived > 0 ? $derived : null,
		];
	}

	/**
	 * Remove legacy `_ai_last_*` meta now that values are consolidated.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return void
	 */
	private static function cleanup_legacy_meta( int $attachment_id ): void {
		static $keys = [
			'_ai_generated',
			'_ai_last_action',
			'_ai_last_provider',
			'_ai_last_model',
			'_ai_last_timestamp',
			'_ai_last_user',
			'_ai_derived_from',
		];
		foreach ( $keys as $key ) {
			delete_post_meta( $attachment_id, $key );
		}
	}
}
