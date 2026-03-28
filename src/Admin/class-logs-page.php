<?php
/**
 * Hidden admin page for API logs.
 *
 * @package WPBanana\Admin
 * @since   0.3.0
 */

namespace WPBanana\Admin;

use WPBanana\Plugin;
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
	 * Base plugin URL.
	 *
	 * @var string
	 */
	private $plugin_url;

	/**
	 * Prepared table instance.
	 *
	 * @var Logs_List_Table|null
	 */
	private $table;

	/**
	 * Constructor.
	 *
	 * @param Logging_Service $logger     Logging service.
	 * @param string          $plugin_url Base plugin URL.
	 */
	public function __construct( Logging_Service $logger, string $plugin_url ) {
		$this->logger     = $logger;
		$this->plugin_url = $plugin_url;
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
			'-', // Passing null or an empty string causes PHP warnings, so we use a dash.
			__( 'WP Banana Logs', 'wp-banana' ),
			__( 'WP Banana Logs', 'wp-banana' ),
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
		add_filter( 'admin_title', [ $this, 'filter_admin_title' ], 10, 2 );
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

		$style_handle = 'wp-banana-logs-styles';
		wp_register_style( $style_handle, $this->plugin_url . 'assets/css/logs-page.css', [], Plugin::VERSION );
		wp_enqueue_style( $style_handle );

		$script_handle = 'wp-banana-logs-script';
		wp_register_script( $script_handle, $this->plugin_url . 'assets/js/logs-page.js', [], Plugin::VERSION, true );
		wp_enqueue_script( $script_handle );

		wp_localize_script(
			$script_handle,
			'wpBananaLogs',
			[
				'labels' => [
					'request'  => __( 'Input (normalized)', 'wp-banana' ),
					'response' => __( 'Output (normalized)', 'wp-banana' ),
					'error'    => __( 'Error', 'wp-banana' ),
				],
			]
		);
	}

	/**
	 * Inject meaningful document title for the hidden logs page.
	 *
	 * @param string $admin_title Existing admin title.
	 * @param string $title       Original page title.
	 * @return string
	 */
	public function filter_admin_title( string $admin_title, string $title ): string {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'admin_page_' . self::SLUG !== $screen->id ) {
			return $admin_title;
		}

		$page_title = __( 'WP Banana Logs', 'wp-banana' );
		$site_title = get_bloginfo( 'name', 'display' );

		return sprintf(
			/* translators: 1: Page title, 2: Site name. */
			__( '%1$s ‹ %2$s — WordPress', 'wp-banana' ),
			$page_title,
			$site_title
		);
	}
}
