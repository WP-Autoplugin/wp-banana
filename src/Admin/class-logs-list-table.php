<?php
/**
 * WP_List_Table implementation for API logs.
 *
 * @package WPBanana\Admin
 * @since   0.3.0
 */

namespace WPBanana\Admin;

use WPBanana\Services\Logging_Service;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( '\WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Displays API logs in the WordPress admin.
 */
class Logs_List_Table extends \WP_List_Table {

	/**
	 * Logging service.
	 *
	 * @var Logging_Service
	 */
	private $logger;

	/**
	 * Cache of loaded user display names.
	 *
	 * @var array<int,string>
	 */
	private $user_cache = [];

	/**
	 * Constructor.
	 *
	 * @param Logging_Service $logger Logging service.
	 */
	public function __construct( Logging_Service $logger ) {
		parent::__construct(
			[
				'singular' => 'wp_banana_log',
				'plural'   => 'wp_banana_logs',
				'ajax'     => false,
			]
		);

		$this->logger = $logger;
	}

	/**
	 * Define the table columns.
	 *
	 * @return array<string,string>
	 */
	public function get_columns(): array {
		return [
			'operation'        => __( 'Operation', 'wp-banana' ),
			'provider'         => __( 'Provider', 'wp-banana' ),
			'model'            => __( 'Model', 'wp-banana' ),
			'status'           => __( 'Status', 'wp-banana' ),
			'response_time_ms' => __( 'Response Time', 'wp-banana' ),
			'user_id'          => __( 'User', 'wp-banana' ),
			'attachment_id'    => __( 'Attachment', 'wp-banana' ),
			'prompt_excerpt'   => __( 'Prompt', 'wp-banana' ),
			'created_at'       => __( 'Timestamp', 'wp-banana' ),
			'details'          => __( 'Details', 'wp-banana' ),
		];
	}

	/**
	 * Sortable columns.
	 *
	 * @return array<string,array>
	 */
	protected function get_sortable_columns(): array {
		return [
			'created_at'       => [ 'created_at', true ],
			'operation'        => [ 'operation', false ],
			'provider'         => [ 'provider', false ],
			'model'            => [ 'model', false ],
			'user_id'          => [ 'user_id', false ],
			'response_time_ms' => [ 'response_time_ms', false ],
		];
	}

	/**
	 * Default column renderer.
	 *
	 * @param array  $item        Current item.
	 * @param string $column_name Column name.
	 * @return string
	 */
	public function column_default( $item, $column_name ) { // phpcs:ignore Generic.Metrics.CyclomaticComplexity.TooHigh -- manageable switch for column rendering.
		switch ( $column_name ) {
			case 'operation':
			case 'provider':
			case 'model':
				return esc_html( isset( $item[ $column_name ] ) ? (string) $item[ $column_name ] : '' );

			case 'status':
				return $this->format_status( isset( $item['status'] ) ? (string) $item['status'] : '' );

			case 'response_time_ms':
				return $this->format_duration( isset( $item['response_time_ms'] ) ? (int) $item['response_time_ms'] : 0 );

			case 'user_id':
				return $this->format_user( isset( $item['user_id'] ) ? (int) $item['user_id'] : 0 );

			case 'attachment_id':
				return $this->format_attachment( isset( $item['attachment_id'] ) ? (int) $item['attachment_id'] : 0 );

			case 'prompt_excerpt':
				return esc_html( isset( $item['prompt_excerpt'] ) ? (string) $item['prompt_excerpt'] : '' );

			case 'created_at':
				return $this->format_timestamp( isset( $item['created_at'] ) ? (string) $item['created_at'] : '' );

			case 'details':
				return $this->format_details( $item );
		}

		return '';
	}

	/**
	 * Render notice when there are no items.
	 *
	 * @return void
	 */
	public function no_items() {
		esc_html_e( 'No API activity recorded yet.', 'wp-banana' );
	}

	/**
	 * Render filters above the table.
	 *
	 * @param string $which Top or bottom.
	 * @return void
	 */
	protected function extra_tablenav( $which ) {
		if ( 'top' !== $which ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only filters.
		$current_provider  = isset( $_GET['provider_filter'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['provider_filter'] ) ) : '';
		$current_operation = isset( $_GET['operation_filter'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['operation_filter'] ) ) : '';
		$current_status    = isset( $_GET['status_filter'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['status_filter'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$provider_options  = [
			''          => __( 'All providers', 'wp-banana' ),
			'gemini'    => 'gemini',
			'openai'    => 'openai',
			'replicate' => 'replicate',
		];
		$operation_options = [
			''                         => __( 'All operations', 'wp-banana' ),
			'generate'                 => 'generate',
			'generate-reference'       => 'generate-reference',
			'generate-reference-multi' => 'generate-reference-multi',
			'edit'                     => 'edit',
			'edit-save-as'             => 'edit-save-as',
		];
		$status_options    = [
			''        => __( 'All statuses', 'wp-banana' ),
			'success' => __( 'Success', 'wp-banana' ),
			'error'   => __( 'Error', 'wp-banana' ),
			'info'    => __( 'Info', 'wp-banana' ),
		];
		?>
		<div class="alignleft actions">
			<label class="screen-reader-text" for="wp-banana-provider-filter"><?php esc_html_e( 'Filter by provider', 'wp-banana' ); ?></label>
			<select name="provider_filter" id="wp-banana-provider-filter">
				<?php foreach ( $provider_options as $value => $label ) : ?>
					<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $current_provider, $value ); ?>>
						<?php echo esc_html( $label ); ?>
					</option>
				<?php endforeach; ?>
			</select>

			<label class="screen-reader-text" for="wp-banana-operation-filter"><?php esc_html_e( 'Filter by operation', 'wp-banana' ); ?></label>
			<select name="operation_filter" id="wp-banana-operation-filter">
				<?php foreach ( $operation_options as $value => $label ) : ?>
					<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $current_operation, $value ); ?>>
						<?php echo esc_html( $label ); ?>
					</option>
				<?php endforeach; ?>
			</select>

			<label class="screen-reader-text" for="wp-banana-status-filter"><?php esc_html_e( 'Filter by status', 'wp-banana' ); ?></label>
			<select name="status_filter" id="wp-banana-status-filter">
				<?php foreach ( $status_options as $value => $label ) : ?>
					<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $current_status, $value ); ?>>
						<?php echo esc_html( $label ); ?>
					</option>
				<?php endforeach; ?>
			</select>

			<?php submit_button( __( 'Filter', 'wp-banana' ), 'secondary', 'filter_action', false ); ?>
		</div>
		<?php
	}

	/**
	 * Prepare table items.
	 *
	 * @return void
	 */
	public function prepare_items() {
		$per_page = 20;

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only filters.
		$current_page = $this->get_pagenum();
		$search       = isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( (string) $_REQUEST['s'] ) ) : '';
		$orderby      = isset( $_REQUEST['orderby'] ) ? sanitize_text_field( wp_unslash( (string) $_REQUEST['orderby'] ) ) : 'created_at';
		$order        = isset( $_REQUEST['order'] ) ? sanitize_text_field( wp_unslash( (string) $_REQUEST['order'] ) ) : 'DESC';
		$provider     = isset( $_REQUEST['provider_filter'] ) ? sanitize_text_field( wp_unslash( (string) $_REQUEST['provider_filter'] ) ) : '';
		$operation    = isset( $_REQUEST['operation_filter'] ) ? sanitize_text_field( wp_unslash( (string) $_REQUEST['operation_filter'] ) ) : '';
		$status       = isset( $_REQUEST['status_filter'] ) ? sanitize_text_field( wp_unslash( (string) $_REQUEST['status_filter'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$query = $this->logger->query(
			[
				'per_page'  => $per_page,
				'paged'     => $current_page,
				'search'    => $search,
				'orderby'   => $orderby,
				'order'     => $order,
				'provider'  => $provider,
				'operation' => $operation,
				'status'    => $status,
			]
		);

		$this->items = isset( $query['items'] ) && is_array( $query['items'] ) ? $query['items'] : [];

		$total_items = isset( $query['total'] ) ? (int) $query['total'] : 0;

		$this->_column_headers = [
			$this->get_columns(),
			[],
			$this->get_sortable_columns(),
		];

		$this->set_pagination_args(
			[
				'total_items' => $total_items,
				'per_page'    => $per_page,
			]
		);
	}

	/**
	 * Render status badge.
	 *
	 * @param string $status Raw status.
	 * @return string
	 */
	private function format_status( string $status ): string {
		$status = strtolower( $status );
		$label  = '';
		if ( 'success' === $status ) {
			$label = __( 'Success', 'wp-banana' );
		} elseif ( 'error' === $status ) {
			$label = __( 'Error', 'wp-banana' );
		} else {
			$status = 'info';
			$label  = __( 'Info', 'wp-banana' );
		}

		return sprintf(
			'<span class="wp-banana-log-status status-%1$s">%2$s</span>',
			esc_attr( $status ),
			esc_html( $label )
		);
	}

	/**
	 * Format response time.
	 *
	 * @param int $milliseconds Duration in milliseconds.
	 * @return string
	 */
	private function format_duration( int $milliseconds ): string {
		if ( $milliseconds <= 0 ) {
			return __( '—', 'wp-banana' );
		}
		return sprintf( _n( '%d ms', '%d ms', $milliseconds, 'wp-banana' ), $milliseconds ); // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralSingle
	}

	/**
	 * Resolve user display text.
	 *
	 * @param int $user_id User ID.
	 * @return string
	 */
	private function format_user( int $user_id ): string {
		if ( $user_id <= 0 ) {
			return __( 'System', 'wp-banana' );
		}

		if ( isset( $this->user_cache[ $user_id ] ) ) {
			return esc_html( $this->user_cache[ $user_id ] );
		}

		$user = get_userdata( $user_id );
		if ( $user && isset( $user->display_name ) ) {
			$label = sprintf( '#%d — %s', $user_id, (string) $user->display_name );
		} else {
			$label = '#' . $user_id;
		}
		$this->user_cache[ $user_id ] = $label;
		return esc_html( $label );
	}

	/**
	 * Format attachment column with edit link when available.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return string
	 */
	private function format_attachment( int $attachment_id ): string {
		if ( $attachment_id <= 0 ) {
			return '—';
		}

		$link = get_edit_post_link( $attachment_id );
		if ( $link ) {
			return sprintf(
				'<a href="%s">#%d</a>',
				esc_url( $link ),
				$attachment_id
			);
		}

		return sprintf( '#%d', $attachment_id );
	}

	/**
	 * Format timestamp to site locale.
	 *
	 * @param string $timestamp Timestamp string.
	 * @return string
	 */
	private function format_timestamp( string $timestamp ): string {
		if ( '' === $timestamp ) {
			return '—';
		}

		$time = strtotime( $timestamp );
		if ( false === $time ) {
			return esc_html( $timestamp );
		}

		if ( function_exists( 'wp_date' ) ) {
			return esc_html( wp_date( 'Y-m-d H:i:s', $time ) );
		}

		return esc_html( date_i18n( 'Y-m-d H:i:s', $time, false ) );
	}

	/**
	 * Render request/response details.
	 *
	 * @param array $item Item data.
	 * @return string
	 */
	private function format_details( array $item ): string {
		$request  = isset( $item['request_payload'] ) ? $this->format_json( (string) $item['request_payload'] ) : '';
		$response = isset( $item['response_payload'] ) ? $this->format_json( (string) $item['response_payload'] ) : '';
		$error    = isset( $item['error_message'] ) ? trim( (string) $item['error_message'] ) : '';

		if ( '' === $request && '' === $response && '' === $error ) {
			return '—';
		}

		ob_start();
		?>
		<details class="wp-banana-log-details">
			<summary><?php esc_html_e( 'View', 'wp-banana' ); ?></summary>
			<div class="wp-banana-log-details__body">
				<?php if ( '' !== $request ) : ?>
					<p><strong><?php esc_html_e( 'Request', 'wp-banana' ); ?></strong></p>
					<pre><?php echo esc_html( $request ); ?></pre>
				<?php endif; ?>

				<?php if ( '' !== $response ) : ?>
					<p><strong><?php esc_html_e( 'Response', 'wp-banana' ); ?></strong></p>
					<pre><?php echo esc_html( $response ); ?></pre>
				<?php endif; ?>

				<?php if ( '' !== $error ) : ?>
					<p><strong><?php esc_html_e( 'Error', 'wp-banana' ); ?></strong></p>
					<pre><?php echo esc_html( $error ); ?></pre>
				<?php endif; ?>
			</div>
		</details>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Pretty-print a JSON string if possible.
	 *
	 * @param string $json Raw JSON.
	 * @return string
	 */
	private function format_json( string $json ): string {
		if ( '' === trim( $json ) ) {
			return '';
		}

		$decoded = json_decode( $json, true );
		if ( JSON_ERROR_NONE === json_last_error() && is_array( $decoded ) ) {
			return wp_json_encode( $decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		}

		return $json;
	}
}

