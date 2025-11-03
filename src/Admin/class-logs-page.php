<?php
/**
 * Hidden admin page for API logs.
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

/**
 * Registers and renders the logs listing screen.
 */
final class Logs_Page {

	public const SLUG = 'wp-banana-logs';

	/**
	 * Logging service.
	 *
	 * @var Logging_Service
	 */
	private $logger;

	/**
	 * Prepared table instance.
	 *
	 * @var Logs_List_Table|null
	 */
	private $table;

	/**
	 * Constructor.
	 *
	 * @param Logging_Service $logger  Logging service.
	 */
	public function __construct( Logging_Service $logger ) {
		$this->logger = $logger;
	}

	/**
	 * Hook into WordPress.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_menu', [ $this, 'add_page' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
	}

	/**
	 * Add submenu page without a menu entry.
	 *
	 * @return void
	 */
	public function add_page(): void {
		$hook = add_submenu_page(
			null,
			__( 'WP Nano Banana Logs', 'wp-banana' ),
			__( 'WP Nano Banana Logs', 'wp-banana' ),
			'manage_options',
			self::SLUG,
			[ $this, 'render' ]
		);

		if ( $hook ) {
			add_action( "load-{$hook}", [ $this, 'prepare_table' ] );
		}
	}

	/**
	 * Prepare table items for display.
	 *
	 * @return void
	 */
	public function prepare_table(): void {
		$this->table = new Logs_List_Table( $this->logger );
		$this->table->prepare_items();
	}

	/**
	 * Render the logs page.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'wp-banana' ) );
		}

		$table = $this->table instanceof Logs_List_Table ? $this->table : new Logs_List_Table( $this->logger );
		if ( $table === $this->table ) {
			// Already prepared.
		} else {
			$table->prepare_items();
		}

		$logging_enabled = $this->logger->is_enabled();
		$table_exists    = Logging_Service::table_exists();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'API Logs', 'wp-banana' ); ?></h1>

			<?php if ( ! $logging_enabled ) : ?>
				<div class="notice notice-info">
					<p><?php esc_html_e( 'Logging is currently disabled. Enable it in the plugin settings to capture new events.', 'wp-banana' ); ?></p>
				</div>
			<?php endif; ?>

			<?php if ( ! $table_exists ) : ?>
				<div class="notice notice-warning">
					<p><?php esc_html_e( 'The logging table does not exist yet. Enable logging to create it automatically.', 'wp-banana' ); ?></p>
				</div>
			<?php else : ?>
				<form method="get">
					<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>" />
					<?php
					$table->search_box( __( 'Search logs', 'wp-banana' ), 'wp-banana-logs' );
					$table->display();
					?>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Enqueue lightweight styles for the logs table.
	 *
	 * @param string $hook Current admin hook.
	 * @return void
	 */
	public function enqueue_assets( string $hook ): void {
		if ( 'admin_page_' . self::SLUG !== $hook ) {
			return;
		}

		$handle = 'wp-banana-logs-styles';
		wp_register_style( $handle, false, [], false );
		wp_enqueue_style( $handle );

		$css = <<<CSS
.wp-banana-log-status {
	display: inline-block;
	padding: 2px 6px;
	border-radius: 4px;
	font-size: 12px;
	line-height: 1.5;
	text-transform: uppercase;
}
.wp-banana-log-status.status-success {
	background: #e7f7ed;
	color: #1f7a36;
}
.wp-banana-log-status.status-error {
	background: #fdecea;
	color: #b32d2e;
}
.wp-banana-log-status.status-info {
	background: #eef5ff;
	color: #1d4ed8;
}
.wp-banana-log-details summary {
	cursor: pointer;
	font-weight: 600;
}
.wp-banana-log-details__body {
	margin-top: 10px;
}
.wp-banana-log-details pre {
	max-height: 220px;
	overflow: auto;
	background: #f6f7f7;
	padding: 12px;
	border-radius: 4px;
}
CSS;
		wp_add_inline_style( $handle, $css );
	}
}
