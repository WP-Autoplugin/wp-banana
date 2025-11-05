<?php
/**
 * API logging service.
 *
 * @package WPBanana\Services
 * @since   0.3.0
 */

namespace WPBanana\Services;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Persists and retrieves API log entries.
 */
final class Logging_Service {

	public const TABLE_SLUG = 'wp_banana_api_logs';

	/**
	 * Cache table existence between requests.
	 *
	 * @var bool|null
	 */
	private static $table_verified = null;

	/**
	 * Options store reference.
	 *
	 * @var Options
	 */
	private $options;

	/**
	 * Constructor.
	 *
	 * @param Options $options Options service.
	 */
	public function __construct( Options $options ) {
		$this->options = $options;
	}

	/**
	 * Whether logging is enabled.
	 *
	 * @return bool
	 */
	public function is_enabled(): bool {
		return (bool) $this->options->get( 'logging.enabled', false );
	}

	/**
	 * Insert a log entry if logging is enabled.
	 *
	 * @param array $data Log payload.
	 * @return void
	 */
	public function record( array $data ): void {
		if ( ! $this->is_enabled() ) {
			return;
		}

		if ( ! self::ensure_table() ) {
			return;
		}

		global $wpdb;

		$defaults = [
			'operation'        => '',
			'provider'         => '',
			'model'            => '',
			'status'           => '',
			'response_time_ms' => 0,
			'user_id'          => get_current_user_id(),
			'attachment_id'    => 0,
			'prompt_excerpt'   => '',
			'request_payload'  => null,
			'response_payload' => null,
			'error_code'       => '',
			'error_message'    => '',
			'reference_count'  => 0,
			'save_mode'        => '',
			'created_at'       => current_time( 'mysql' ),
		];

		$data = wp_parse_args( $data, $defaults );

		$table = self::table_name();

		$response_time = (int) $data['response_time_ms'];
		if ( $response_time < 0 ) {
			$response_time = 0;
		}

		$reference_count = max( 0, (int) $data['reference_count'] );

		$insert_data = [
			'operation'        => sanitize_text_field( (string) $data['operation'] ),
			'provider'         => sanitize_text_field( (string) $data['provider'] ),
			'model'            => sanitize_text_field( (string) $data['model'] ),
			'status'           => $this->sanitize_status( (string) $data['status'] ),
			'response_time_ms' => $response_time,
			'user_id'          => (int) $data['user_id'],
			'attachment_id'    => (int) $data['attachment_id'],
			'prompt_excerpt'   => $this->sanitize_excerpt( (string) $data['prompt_excerpt'] ),
			'request_payload'  => $this->encode_payload( $data['request_payload'] ),
			'response_payload' => $this->encode_payload( $data['response_payload'] ),
			'error_code'       => sanitize_text_field( (string) $data['error_code'] ),
			'error_message'    => $this->sanitize_message( (string) $data['error_message'] ),
			'reference_count'  => $reference_count,
			'save_mode'        => sanitize_text_field( (string) $data['save_mode'] ),
			'created_at'       => $this->sanitize_datetime( (string) $data['created_at'] ),
		];

		$formats = [
			'%s',
			'%s',
			'%s',
			'%s',
			'%d',
			'%d',
			'%d',
			'%s',
			'%s',
			'%s',
			'%s',
			'%s',
			'%d',
			'%s',
			'%s',
		];

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Direct insert required for logging.
		$wpdb->insert(
			$table,
			$insert_data,
			$formats
		);
	}

	/**
	 * Fetch log entries for display.
	 *
	 * @param array $args Query args.
	 * @return array{items:array<int,array<string,mixed>>,total:int}
	 */
	public function query( array $args ): array {
		if ( ! self::table_exists() ) {
			return [
				'items' => [],
				'total' => 0,
			];
		}

		global $wpdb;

		$defaults = [
			'per_page'  => 20,
			'paged'     => 1,
			'search'    => '',
			'orderby'   => 'created_at',
			'order'     => 'DESC',
			'provider'  => '',
			'operation' => '',
			'status'    => '',
		];

		$args = wp_parse_args( $args, $defaults );

		$per_page = max( 1, (int) $args['per_page'] );
		$paged    = max( 1, (int) $args['paged'] );
		$offset   = ( $paged - 1 ) * $per_page;

		$orderby_whitelist = [
			'id',
			'operation',
			'provider',
			'model',
			'status',
			'response_time_ms',
			'user_id',
			'attachment_id',
			'created_at',
		];

		$orderby = in_array( $args['orderby'], $orderby_whitelist, true ) ? $args['orderby'] : 'created_at';
		$order   = ( 'ASC' === strtoupper( $args['order'] ) ) ? 'ASC' : 'DESC';

		$where_sql  = 'WHERE 1=1';
		$where_args = [];

		$search = trim( (string) $args['search'] );
		if ( '' !== $search ) {
			$like       = '%' . $wpdb->esc_like( $search ) . '%';
			$where_sql .= ' AND (prompt_excerpt LIKE %s OR request_payload LIKE %s OR response_payload LIKE %s OR error_message LIKE %s)';
			$where_args = array_merge( $where_args, [ $like, $like, $like, $like ] );
		}

		if ( ! empty( $args['provider'] ) ) {
			$where_sql   .= ' AND provider = %s';
			$where_args[] = sanitize_text_field( (string) $args['provider'] );
		}

		if ( ! empty( $args['operation'] ) ) {
			$where_sql   .= ' AND operation = %s';
			$where_args[] = sanitize_text_field( (string) $args['operation'] );
		}

		if ( ! empty( $args['status'] ) ) {
			$where_sql   .= ' AND status = %s';
			$where_args[] = $this->sanitize_status( (string) $args['status'] );
		}

		$table = self::table_name();

		$sql        = "SELECT * FROM {$table} {$where_sql} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d";
		$query_args = array_merge( $where_args, [ $per_page, max( 0, (int) $offset ) ] );

		$prepared = $wpdb->prepare( $sql, $query_args );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only admin query.
		$items = $wpdb->get_results( $prepared, ARRAY_A );

		$count_sql = "SELECT COUNT(*) FROM {$table} {$where_sql}";
		if ( empty( $where_args ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$total = (int) $wpdb->get_var( $count_sql );
		} else {
			$prepared_count = $wpdb->prepare( $count_sql, $where_args );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$total = (int) $wpdb->get_var( $prepared_count );
		}

		return [
			'items' => is_array( $items ) ? $items : [],
			'total' => $total,
		];
	}

	/**
	 * Create logs table.
	 *
	 * @return bool
	 */
	public static function create_table(): bool {
		global $wpdb;

		$table           = self::table_name();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			operation VARCHAR(50) NOT NULL DEFAULT '',
			provider VARCHAR(60) NOT NULL DEFAULT '',
			model VARCHAR(191) NOT NULL DEFAULT '',
			status VARCHAR(20) NOT NULL DEFAULT '',
			response_time_ms INT UNSIGNED NOT NULL DEFAULT 0,
			user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			attachment_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			prompt_excerpt TEXT NOT NULL,
			request_payload LONGTEXT NULL,
			response_payload LONGTEXT NULL,
			error_code VARCHAR(50) NOT NULL DEFAULT '',
			error_message TEXT NULL,
			reference_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			save_mode VARCHAR(20) NOT NULL DEFAULT '',
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY provider_idx (provider),
			KEY model_idx (model),
			KEY status_idx (status),
			KEY created_idx (created_at)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		self::$table_verified = null;

		return self::table_exists();
	}

	/**
	 * Drop logs table.
	 *
	 * @return void
	 */
	public static function drop_table(): void {
		global $wpdb;

		$table = self::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Needed during uninstall.
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
		self::$table_verified = null;
	}

	/**
	 * Remove all rows from the logs table.
	 *
	 * @return bool
	 */
	public static function truncate(): bool {
		if ( ! self::table_exists() ) {
			return false;
		}

		global $wpdb;

		$table = self::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Required for log maintenance.
		return false !== $wpdb->query( "TRUNCATE TABLE {$table}" );
	}

	/**
	 * Whether any log entries exist.
	 *
	 * @return bool
	 */
	public static function has_logs(): bool {
		if ( ! self::table_exists() ) {
			return false;
		}

		global $wpdb;

		$table = self::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Lightweight existence check.
		$value = $wpdb->get_var( "SELECT 1 FROM {$table} LIMIT 1" );

		return ! empty( $value );
	}

	/**
	 * Determine if the logs table exists.
	 *
	 * @return bool
	 */
	public static function table_exists(): bool {
		if ( true === self::$table_verified ) {
			return true;
		}

		if ( false === self::$table_verified ) {
			return false;
		}

		global $wpdb;

		$table = self::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Lightweight metadata query.
		$result = $wpdb->get_var(
			$wpdb->prepare(
				'SHOW TABLES LIKE %s',
				$table
			)
		);

		$exists               = ( $result === $table );
		self::$table_verified = $exists;

		return $exists;
	}

	/**
	 * Ensure the table exists.
	 *
	 * @return bool
	 */
	private static function ensure_table(): bool {
		if ( self::table_exists() ) {
			return true;
		}

		return self::create_table();
	}

	/**
	 * Resolve full table name with prefix.
	 *
	 * @return string
	 */
	public static function table_name(): string {
		global $wpdb;

		return $wpdb->prefix . self::TABLE_SLUG;
	}

	/**
	 * Trim and sanitize free-form excerpt strings.
	 *
	 * @param string $value Raw value.
	 * @param int    $max   Maximum length.
	 * @return string
	 */
	private function sanitize_excerpt( string $value, int $max = 500 ): string {
		$value = wp_strip_all_tags( $value );
		$value = trim( preg_replace( '/\s+/', ' ', $value ) );
		if ( function_exists( 'mb_substr' ) ) {
			$value = mb_substr( $value, 0, $max );
		} else {
			$value = substr( $value, 0, $max );
		}
		return $value;
	}

	/**
	 * Sanitize error/status messages.
	 *
	 * @param string $value Raw message.
	 * @param int    $max   Maximum length.
	 * @return string
	 */
	private function sanitize_message( string $value, int $max = 2000 ): string {
		if ( '' === $value ) {
			return '';
		}
		$value = wp_strip_all_tags( $value );
		$value = trim( preg_replace( '/\s+/', ' ', $value ) );
		if ( function_exists( 'mb_substr' ) ) {
			$value = mb_substr( $value, 0, $max );
		} else {
			$value = substr( $value, 0, $max );
		}
		return $value;
	}

	/**
	 * Encode payload data into JSON for storage.
	 *
	 * @param mixed $payload Payload.
	 * @return string|null
	 */
	private function encode_payload( $payload ) {
		if ( is_null( $payload ) ) {
			return null;
		}

		if ( is_scalar( $payload ) ) {
			$payload = [ 'value' => $payload ];
		}

		if ( is_object( $payload ) ) {
			$payload = (array) $payload;
		}

		if ( ! is_array( $payload ) ) {
			return null;
		}

		return wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	}

	/**
	 * Normalize status values.
	 *
	 * @param string $status Raw status.
	 * @return string
	 */
	private function sanitize_status( string $status ): string {
		$status = strtolower( sanitize_text_field( $status ) );
		return in_array( $status, [ 'success', 'error' ], true ) ? $status : 'info';
	}

	/**
	 * Ensure timestamp aligns with WordPress datetime format.
	 *
	 * @param string $datetime Raw datetime.
	 * @return string
	 */
	private function sanitize_datetime( string $datetime ): string {
		$datetime = trim( $datetime );
		if ( '' === $datetime ) {
			return current_time( 'mysql' );
		}

		$timestamp = strtotime( $datetime );
		if ( false === $timestamp ) {
			return current_time( 'mysql' );
		}

		// Return in site local time.
		if ( function_exists( 'wp_date' ) ) {
			return wp_date( 'Y-m-d H:i:s', $timestamp );
		}

		return date_i18n( 'Y-m-d H:i:s', $timestamp, false );
	}
}
