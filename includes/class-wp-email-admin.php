<?php
/**
 * WP-EMail class-wp-email-admin.php
 *
 * @package WP-EMail
 */

defined( 'ABSPATH' ) || exit;

/**
 * The admin menu and the e-mail logs screen.
 *
 * @since 3.0.0
 */
class WP_Email_Admin {

	/**
	 * Menu and page slug for the logs screen.
	 */
	const PAGE = 'wp-email';

	/**
	 * Capability required for the logs screen.
	 *
	 * A data screen, so it keeps the plugin's own capability rather than
	 * falling back to manage_options. The settings screen is a settings screen
	 * and takes manage_options, per STANDARDS.md 2.7.
	 */
	const CAPABILITY = 'manage_email';

	/**
	 * The logs list table, once the screen is loaded.
	 *
	 * @var WP_Email_Logs_Table|null
	 */
	private $table;

	/**
	 * Hook the admin screens up.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
	}

	/**
	 * The capability the logs screen is gated on.
	 *
	 * Read through one filter so a site can hand the screen to a role of its
	 * own without redefining the capability everywhere it is checked.
	 *
	 * @param string $context Where the check is being made.
	 *
	 * @return string
	 */
	public static function capability( $context = 'logs' ) {
		/**
		 * Filters the capability a WP-EMail screen is gated on.
		 *
		 * @since 3.0.0
		 *
		 * @param string $capability Capability required.
		 * @param string $context    Screen being gated: 'logs' or 'settings'.
		 */
		return (string) apply_filters( 'wp_email_capability', self::CAPABILITY, $context );
	}

	/**
	 * Build the logs list table.
	 *
	 * Loads WP_List_Table's subclass on demand rather than at plugin boot,
	 * since it is only ever needed on this one screen.
	 *
	 * @return WP_Email_Logs_Table
	 */
	private function table() {
		if ( ! $this->table ) {
			require_once WP_EMAIL_DIR . 'includes/class-wp-email-logs-table.php';

			$this->table = new WP_Email_Logs_Table();
		}

		return $this->table;
	}

	/**
	 * Register the menu and its two screens.
	 *
	 * @return void
	 */
	public function add_menu() {
		$logs = add_menu_page(
			__( 'E-Mail', 'wp-email' ),
			__( 'E-Mail', 'wp-email' ),
			self::capability(),
			self::PAGE,
			array( $this, 'render_logs' ),
			'dashicons-email-alt'
		);

		add_submenu_page(
			self::PAGE,
			__( 'Manage E-Mail', 'wp-email' ),
			__( 'Manage E-Mail', 'wp-email' ),
			self::capability(),
			self::PAGE,
			array( $this, 'render_logs' )
		);

		add_submenu_page(
			self::PAGE,
			__( 'Settings', 'wp-email' ),
			__( 'Settings', 'wp-email' ),
			WP_Email_Settings::capability(),
			WP_Email_Settings::PAGE,
			array( 'WP_Email_Settings', 'render' )
		);

		add_action( 'load-' . $logs, array( $this, 'load_logs' ) );
	}

	/**
	 * Prepare the logs screen: handle deletion, add the screen option.
	 *
	 * Runs on load- so a delete can redirect before any output.
	 *
	 * @return void
	 */
	public function load_logs() {
		if ( ! current_user_can( self::capability() ) ) {
			return;
		}

		$this->maybe_delete_logs();

		add_screen_option(
			'per_page',
			array(
				'label'   => __( 'Logs per page', 'wp-email' ),
				'default' => 20,
				'option'  => 'email_logs_per_page',
			)
		);

		$this->table();
	}

	/**
	 * Delete every log row, if that was asked for and confirmed.
	 *
	 * @return void
	 */
	private function maybe_delete_logs() {
		if ( empty( $_POST['delete_logs'] ) ) {
			return;
		}

		check_admin_referer( 'wp-email_delete-logs' );

		$confirmed = isset( $_POST['delete_logs_yes'] ) && 'yes' === sanitize_text_field( wp_unslash( $_POST['delete_logs_yes'] ) );

		if ( ! $confirmed ) {
			$this->redirect_with_notice( 'not-confirmed' );
		}

		WP_Email_Logs::delete_all();

		$this->redirect_with_notice( 'deleted' );
	}

	/**
	 * Redirect back to the logs screen carrying a notice key.
	 *
	 * @param string $notice Notice key.
	 *
	 * @return void
	 */
	private function redirect_with_notice( $notice ) {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'            => self::PAGE,
					'wp-email-notice' => $notice,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Render the logs screen.
	 *
	 * @return void
	 */
	public function render_logs() {
		if ( ! current_user_can( self::capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to manage e-mail.', 'wp-email' ) );
		}

		$table = $this->table();
		$table->prepare_items();

		$total   = WP_Email_Logs::count_all();
		$success = WP_Email_Logs::count_by_status( WP_Email_Logs::STATUS_SUCCESS );
		$failed  = WP_Email_Logs::count_by_status( WP_Email_Logs::STATUS_FAILED );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Manage E-Mail', 'wp-email' ); ?></h1>

			<?php $this->render_notice(); ?>

			<h2><?php esc_html_e( 'E-Mail Logs', 'wp-email' ); ?></h2>

			<form method="get">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE ); ?>" />
				<?php $table->display(); ?>
			</form>

			<h2><?php esc_html_e( 'E-Mail Logs Stats', 'wp-email' ); ?></h2>
			<table class="widefat striped">
				<tbody>
					<tr>
						<th scope="row"><?php esc_html_e( 'Total E-Mails:', 'wp-email' ); ?></th>
						<td><?php echo esc_html( number_format_i18n( $total ) ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Total E-Mail Sent:', 'wp-email' ); ?></th>
						<td><?php echo esc_html( number_format_i18n( $success ) ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Total E-Mail Failed:', 'wp-email' ); ?></th>
						<td><?php echo esc_html( number_format_i18n( $failed ) ); ?></td>
					</tr>
				</tbody>
			</table>

			<h2><?php esc_html_e( 'Delete E-Mail Logs', 'wp-email' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE ) ); ?>">
				<?php wp_nonce_field( 'wp-email_delete-logs' ); ?>
				<p><strong><?php esc_html_e( 'Are You Sure You Want To Delete All E-Mail Logs?', 'wp-email' ); ?></strong></p>
				<p>
					<label>
						<input type="checkbox" name="delete_logs_yes" value="yes" />
						<?php esc_html_e( 'Yes', 'wp-email' ); ?>
					</label>
				</p>
				<p>
					<input
						type="submit"
						name="delete_logs"
						value="<?php esc_attr_e( 'Delete', 'wp-email' ); ?>"
						class="button button-secondary"
						data-wp-email-confirm="<?php esc_attr_e( 'You are about to delete all e-mail logs. This action is not reversible.', 'wp-email' ); ?>"
					/>
				</p>
			</form>
		</div>
		<?php
	}

	/**
	 * Show the outcome of a deletion.
	 *
	 * @return void
	 */
	private function render_notice() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only; the action itself was nonce checked.
		$notice = isset( $_GET['wp-email-notice'] ) ? sanitize_key( wp_unslash( $_GET['wp-email-notice'] ) ) : '';

		if ( 'deleted' === $notice ) {
			printf(
				'<div id="message" class="notice notice-success is-dismissible"><p>%s</p></div>',
				esc_html__( 'All E-Mail Logs Have Been Deleted.', 'wp-email' )
			);
		} elseif ( 'not-confirmed' === $notice ) {
			printf(
				'<div id="message" class="notice notice-warning is-dismissible"><p>%s</p></div>',
				esc_html__( 'No E-Mail Logs Were Deleted: the confirmation box was not ticked.', 'wp-email' )
			);
		}
	}
}
