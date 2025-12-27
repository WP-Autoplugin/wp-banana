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
		wp_register_style( $style_handle, false, [], false );
		wp_enqueue_style( $style_handle );

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
.wp-banana-log-expanded td {
	background: #f6f7f7;
	border-top: none;
	padding: 20px;
}
.wp-banana-log-expanded__sections {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
	gap: 16px;
}
.wp-banana-log-expanded__section {
	background: #fff;
	border: 1px solid #dcdcde;
	border-radius: 4px;
	padding: 16px;
	box-shadow: inset 0 0 0 1px rgba(255,255,255,0.6);
}
.wp-banana-log-expanded__section h4 {
	margin: 0 0 8px;
	font-size: 14px;
}
.wp-banana-log-expanded__section pre {
	background: #f3f4f6;
	padding: 12px;
	border-radius: 4px;
	max-height: 420px;
	overflow: auto;
	font-size: 12px;
	line-height: 1.45;
}
.wp-banana-log-toggle {
	white-space: nowrap;
}
CSS;
		wp_add_inline_style( $style_handle, $css );

		$script_handle = 'wp-banana-logs-script';
		wp_register_script( $script_handle, '', [], false, true );
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

		$js = <<<JS
(function() {
	const table = document.querySelector('.wp-list-table');
	if (!table) {
		return;
	}

	const escapeHtml = function(value) {
		if (typeof value !== 'string') {
			return '';
		}
		return value
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;');
	};

	const buildSection = function(title, content) {
		if (!content) {
			return '';
		}
		return '<div class="wp-banana-log-expanded__section">' +
			'<h4>' + escapeHtml(title) + '</h4>' +
			'<pre>' + escapeHtml(content) + '</pre>' +
		'</div>';
	};

	table.addEventListener('click', function(event) {
		const button = event.target.closest('.wp-banana-log-toggle');
		if (!button) {
			return;
		}
		event.preventDefault();

		const row = button.closest('tr');
		if (!row) {
			return;
		}

		const existing = row.nextElementSibling;
		if (existing && existing.classList.contains('wp-banana-log-expanded')) {
			existing.remove();
			button.setAttribute('aria-expanded', 'false');
			button.textContent = button.dataset.openLabel || button.textContent;
			return;
		}

		const targetId = button.dataset.logId;
		if (!targetId) {
			return;
		}

		const container = document.getElementById(targetId);
		if (!container) {
			return;
		}

		let data;
		try {
			data = JSON.parse(container.textContent);
		} catch (error) {
			return;
		}

		const sections = [];
		const labels = (window.wpBananaLogs && window.wpBananaLogs.labels) ? window.wpBananaLogs.labels : {};

		if (data && data.request) {
			sections.push(buildSection(labels.request || 'Request', data.request));
		}
		if (data && data.response) {
			sections.push(buildSection(labels.response || 'Response', data.response));
		}
		if (data && data.error) {
			sections.push(buildSection(labels.error || 'Error', data.error));
		}

		if (!sections.length) {
			return;
		}

		const expandedRow = document.createElement('tr');
		expandedRow.className = 'wp-banana-log-expanded';

		const cell = document.createElement('td');
		cell.colSpan = row.children.length;
		cell.innerHTML = '<div class="wp-banana-log-expanded__sections">' + sections.join('') + '</div>';

		expandedRow.appendChild(cell);
		row.parentNode.insertBefore(expandedRow, row.nextSibling);

		button.setAttribute('aria-expanded', 'true');
		button.textContent = button.dataset.closeLabel || button.textContent;
	});
})();
JS;
		wp_add_inline_script( $script_handle, $js );
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
