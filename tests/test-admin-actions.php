<?php
/**
 * The destructive action on the logs screen.
 *
 * @package WP-EMail
 */

/**
 * The destructive action on the logs screen, and the notices it leaves behind.
 *
 * @covers WP_Email_Admin
 */
class WP_Email_Admin_Actions_Test extends WP_Email_TestCase {

	/**
	 * Where the screen tried to redirect to, if it did.
	 *
	 * @var string
	 */
	private $redirected_to = '';

	/**
	 * Set up the fixtures for each test.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		if ( ! defined( 'WP_ADMIN' ) ) {
			define( 'WP_ADMIN', true );
		}

		require_once ABSPATH . 'wp-admin/includes/admin.php';

		// WP_List_Table::__construct() falls back to $GLOBALS['hook_suffix']
		// when no screen is passed, and WordPress 6.0 reads it unguarded. A real
		// admin request always has it set.
		$GLOBALS['hook_suffix'] = 'toplevel_page_wp-email';

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		set_current_screen( 'toplevel_page_wp-email' );

		$this->redirected_to = '';

		// add_settings_error() writes into a global that no transaction rolls
		// back, so a notice queued by one test would be rendered by the next one
		// and the "unknown notice renders nothing" assertion would fail on
		// execution order.
		$GLOBALS['wp_settings_errors'] = array();

		// The screen redirects and then exits; intercepting the redirect is how
		// the exit is avoided without changing the code under test.
		add_filter(
			'wp_redirect',
			function ( $location ) {
				$this->redirected_to = $location;
				throw new Exception( 'redirected' );
			}
		);
	}

	/**
	 * Clear the request state after each test.
	 *
	 * @return void
	 */
	public function tear_down() {
		$_POST    = array();
		$_GET     = array();
		$_REQUEST = array();

		parent::tear_down();
	}

	/**
	 * Insert a log row.
	 *
	 * @return void
	 */
	private function log() {
		WP_Email_Logs::insert(
			array(
				'yourname'    => 'Alice',
				'youremail'   => 'alice@example.com',
				'yourremarks' => '',
				'friendname'  => 'Friend',
				'friendemail' => 'friend@example.com',
				'postid'      => 1,
				'posttitle'   => 'Title',
				'timestamp'   => time(),
				'ip'          => '198.51.100.1',
				'host'        => '',
				'status'      => WP_Email_Logs::STATUS_SUCCESS,
			)
		);
	}

	/**
	 * Run the screen's load handler, swallowing the redirect.
	 *
	 * @return bool Whether it redirected.
	 */
	private function load() {
		try {
			( new WP_Email_Admin() )->load_logs();
		} catch ( Exception $e ) {
			unset( $e );
			return true;
		}

		return false;
	}


	public function test_a_confirmed_delete_empties_the_table() {
		$this->log();
		$this->log();

		$_POST = array(
			'delete_logs'     => 'Delete',
			'delete_logs_yes' => 'yes',
			'_wpnonce'        => wp_create_nonce( 'wp-email_delete-logs' ),
		);

		$_REQUEST = $_POST;

		$this->assertTrue( $this->load() );
		$this->assertSame( 0, WP_Email_Logs::count_all() );

		// Redirecting rather than re-rendering means a refresh cannot replay
		// the deletion.
		$this->assertStringContainsString( 'wp-email-notice=deleted', $this->redirected_to );
	}

	public function test_an_unconfirmed_delete_keeps_the_rows() {
		$this->log();

		$_POST = array(
			'delete_logs' => 'Delete',
			'_wpnonce'    => wp_create_nonce( 'wp-email_delete-logs' ),
		);

		$_REQUEST = $_POST;

		$this->assertTrue( $this->load() );
		$this->assertSame( 1, WP_Email_Logs::count_all() );
		$this->assertStringContainsString( 'wp-email-notice=not-confirmed', $this->redirected_to );
	}

	public function test_a_delete_without_a_nonce_is_refused() {
		$this->log();

		$_POST = array(
			'delete_logs'     => 'Delete',
			'delete_logs_yes' => 'yes',
			'_wpnonce'        => 'not-a-valid-nonce',
		);

		$_REQUEST = $_POST;

		// check_admin_referer() calls wp_die(), which the test suite turns into
		// an exception.
		$this->expectException( 'WPDieException' );

		( new WP_Email_Admin() )->load_logs();
	}

	public function test_no_delete_request_leaves_the_rows_alone() {
		$this->log();

		$this->assertFalse( $this->load() );
		$this->assertSame( 1, WP_Email_Logs::count_all() );
	}

	public function test_a_user_without_the_capability_cannot_delete() {
		$this->log();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$_POST = array(
			'delete_logs'     => 'Delete',
			'delete_logs_yes' => 'yes',
			'_wpnonce'        => wp_create_nonce( 'wp-email_delete-logs' ),
		);

		$_REQUEST = $_POST;

		$this->assertFalse( $this->load() );
		$this->assertSame( 1, WP_Email_Logs::count_all() );
	}


	public function test_the_deleted_notice_renders() {
		$_GET['wp-email-notice'] = 'deleted';

		$html = $this->render_logs();

		$this->assertStringContainsString( 'notice-success', $html );
		$this->assertStringContainsString( 'All E-Mail Logs Have Been Deleted.', $html );
	}

	public function test_the_not_confirmed_notice_renders() {
		$_GET['wp-email-notice'] = 'not-confirmed';

		$html = $this->render_logs();

		$this->assertStringContainsString( 'notice-warning', $html );
		$this->assertStringContainsString( 'confirmation box was not ticked', $html );
	}

	public function test_an_unknown_notice_renders_nothing() {
		$_GET['wp-email-notice'] = 'made-up';

		$html = $this->render_logs();

		$this->assertStringNotContainsString( 'notice-success', $html );
		$this->assertStringNotContainsString( 'notice-warning', $html );
	}

	/**
	 * Load and then render the logs screen, as a real request does.
	 *
	 * Both steps, on one instance. WordPress fires load-{$hook_suffix} before it
	 * calls the page callback, and that is where register_notice() turns
	 * ?wp-email-notice=… into a settings error for settings_errors() to print.
	 * Calling render_logs() alone renders a screen that was never loaded, so no
	 * notice could ever appear and an assertion about one proves nothing.
	 *
	 * @return string
	 */
	private function render_logs() {
		$admin = new WP_Email_Admin();

		$admin->load_logs();

		ob_start();
		$admin->render_logs();
		return ob_get_clean();
	}


	public function test_the_menu_registers_both_screens() {
		global $submenu, $menu;

		$menu    = array();
		$submenu = array();

		( new WP_Email_Admin() )->add_menu();

		$slugs = wp_list_pluck( $submenu[ WP_Email_Settings::PAGE ], 2 );

		// Settings first, the log last, per STANDARDS.md 4.1: the data screen
		// leads when it is what somebody came for, and a log is not.
		$this->assertSame(
			array( WP_Email_Settings::PAGE, WP_Email_Admin::PAGE ),
			$slugs,
			'Settings must come first and the log last'
		);

		$labels = wp_list_pluck( $submenu[ WP_Email_Settings::PAGE ], 0 );

		$this->assertSame( 'Settings', $labels[0], 'the first entry is not Settings' );
		$this->assertSame( 'Logs', $labels[1], 'the log is not called Logs' );

		$capabilities = wp_list_pluck( $submenu[ WP_Email_Settings::PAGE ], 1 );

		// The log keeps the plugin's own capability because it is a data screen;
		// the settings screen takes manage_options (4.2, 2.7).
		$this->assertSame(
			array( WP_Email_Settings::CAPABILITY, WP_Email_Admin::CAPABILITY ),
			$capabilities
		);
	}

	public function test_rendering_is_refused_without_the_capability() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$this->expectException( 'WPDieException' );

		( new WP_Email_Admin() )->render_logs();
	}

	public function test_the_options_screen_is_refused_without_the_capability() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$this->expectException( 'WPDieException' );

		WP_Email_Settings::render();
	}
}
