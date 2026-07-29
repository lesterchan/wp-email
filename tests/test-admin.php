<?php
/**
 * Admin screens: the menu, the logs list table and the settings registration.
 *
 * @package WP-EMail
 */

/**
 * The admin screens.
 *
 * @covers WP_Email_Admin
 * @covers WP_Email_Settings
 * @covers WP_Email_Logs_Table
 */
class WP_Email_Admin_Test extends WP_Email_TestCase {

	/**
	 * Administrator user.
	 *
	 * @var int
	 */
	private $admin;

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

		$this->admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin );
	}

	/**
	 * Insert a log row.
	 *
	 * @param array $overrides Column overrides.
	 *
	 * @return void
	 */
	private function log( array $overrides = array() ) {
		WP_Email_Logs::insert(
			array_merge(
				array(
					'yourname'    => 'Alice',
					'youremail'   => 'alice@example.com',
					'yourremarks' => 'remark',
					'friendname'  => 'Friend',
					'friendemail' => 'friend@example.com',
					'postid'      => 1,
					'posttitle'   => 'Title',
					'timestamp'   => time(),
					'ip'          => '198.51.100.1',
					'host'        => 'host.example.com',
					'status'      => WP_Email_Logs::STATUS_SUCCESS,
				),
				$overrides
			)
		);
	}

	/**
	 * Render the logs screen.
	 *
	 * Deliberately does not require class-wp-email-logs-table.php: loading it here
	 * Would hide the plugin failing to load it itself, which is exactly the
	 * Fatal this masked once already.
	 *
	 * @return string
	 */
	private function render_logs() {
		set_current_screen( 'toplevel_page_wp-email' );

		$admin = new WP_Email_Admin();

		ob_start();
		$admin->render_logs();
		return ob_get_clean();
	}

	/**
	 * Render the options screen.
	 *
	 * @return string
	 */
	private function render_options() {
		set_current_screen( 'e-mail_page_wp-email-settings' );

		$settings = new WP_Email_Settings();
		$settings->register();

		ob_start();
		$settings->render();
		return ob_get_clean();
	}


	public function test_the_administrator_gains_the_capability_on_install() {
		$this->assertTrue( get_role( 'administrator' )->has_cap( 'manage_email' ) );
	}

	public function test_a_subscriber_does_not_have_the_capability() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$this->assertFalse( current_user_can( WP_Email_Admin::CAPABILITY ) );
	}


	public function test_the_logs_screen_lists_its_rows() {
		$this->log();

		$html = $this->render_logs();

		$this->assertStringContainsString( 'Manage E-Mail', $html );
		$this->assertStringContainsString( 'Alice', $html );
		$this->assertStringContainsString( 'friend@example.com', $html );
		$this->assertStringContainsString( '198.51.100.1', $html );
		$this->assertStringContainsString( 'Total E-Mails', $html );
		$this->assertStringContainsString( 'Delete E-Mail Logs', $html );
	}

	public function test_the_logs_screen_escapes_hostile_stored_values() {
		$this->log(
			array(
				'yourname'    => 'Bad <script>alert(1)</script>',
				'yourremarks' => '<img src=x onerror=alert(1)>',
				'posttitle'   => "O'Brien & Sons <b>",
			)
		);

		$html = $this->render_logs();

		// esc_html() leaves '=' alone, so the giveaway is whether the tag
		// itself survived, not whether the attribute text appears anywhere.
		$this->assertStringNotContainsString( '<script>alert(1)</script>', $html );
		$this->assertStringNotContainsString( '<img src=x', $html );
		$this->assertStringContainsString( '&lt;img src=x', $html );
		$this->assertStringContainsString( '&amp;', $html );
	}

	public function test_the_logs_screen_says_so_when_empty() {
		$this->assertStringContainsString( 'No E-Mail Logs Found', $this->render_logs() );
	}

	public function test_the_logs_table_sorts_only_on_known_columns() {

		$table = new WP_Email_Logs_Table();

		foreach ( array_values( $table->get_sortable_columns() ) as $sortable ) {
			$this->assertArrayHasKey( $sortable[0], WP_Email_Logs::sortable_columns() );
		}
	}

	public function test_the_delete_form_carries_a_nonce() {
		$html = $this->render_logs();

		$this->assertStringContainsString( 'name="_wpnonce"', $html );
		$this->assertStringContainsString( 'name="delete_logs_yes"', $html );
		$this->assertStringContainsString( 'name="delete_logs"', $html );
	}

	public function test_the_delete_button_has_no_inline_handler() {
		$html = $this->render_logs();

		$this->assertStringNotContainsString( 'onclick', $html );
		$this->assertStringContainsString( 'data-wp-email-confirm', $html );
	}


	public function test_the_options_screen_renders_every_section() {
		$html = $this->render_options();

		$this->assertStringContainsString( 'E-Mail Settings', $html );
		$this->assertStringContainsString( 'E-Mail Link', $html );
		$this->assertStringContainsString( 'E-Mail Text Link For Post', $html );
		$this->assertStringContainsString( 'E-Mail Link Type', $html );
		$this->assertStringContainsString( 'E-Mail Fields', $html );
		$this->assertStringContainsString( 'Interval Between E-Mails', $html );
		$this->assertStringContainsString( 'Header That Contains The IP', $html );
		$this->assertStringContainsString( 'E-Mail Subject', $html );
		$this->assertStringContainsString( 'E-Mail Body', $html );
		$this->assertStringContainsString( 'WP-Stats', $html );
		$this->assertStringContainsString( 'Restore Default Template', $html );
	}

	public function test_the_options_screen_posts_to_the_settings_api() {
		$html = $this->render_options();

		$this->assertStringContainsString( 'action="options.php"', $html );
		// settings_fields() emits single-quoted attributes.
		$this->assertStringContainsString( "name='option_page' value='wp_email_options'", $html );
		$this->assertStringContainsString( '_wpnonce', $html );
	}

	public function test_the_options_screen_uses_nested_field_names() {
		$html = $this->render_options();

		$this->assertStringContainsString( 'name="wp_email_options[link][post_text]"', $html );
		$this->assertStringContainsString( 'name="wp_email_options[sending][interval]"', $html );
		$this->assertStringContainsString( 'name="wp_email_options[templates][subject]"', $html );
	}

	public function test_the_template_tokens_are_shown_verbatim() {
		$html = $this->render_options();

		// phpcbf reads a literal %TOKEN% inside a translatable string as a
		// printf placeholder and renumbers it, which would rewrite the very
		// text users are told to type.
		$this->assertStringContainsString( '%EMAIL_POST_TITLE%', $html );
		$this->assertStringContainsString( '%EMAIL_FRIEND_NAME%', $html );
		$this->assertStringNotContainsString( '%1$EMAIL', $html );
	}

	public function test_the_restore_buttons_carry_defaults_in_data_attributes() {
		$html = $this->render_options();

		// Not esc_js() into an inline onclick, which is where these plugins
		// hide their XSS.
		$this->assertStringContainsString( 'data-wp-email-restore=', $html );
		$this->assertStringContainsString( 'data-wp-email-default=', $html );
		$this->assertStringNotContainsString( 'onclick', $html );
	}

	public function test_the_setting_is_registered_with_its_sanitizer() {
		$settings = new WP_Email_Settings();
		$settings->register();

		$registered = get_registered_settings();

		$this->assertArrayHasKey( WP_Email_Options::OPTION, $registered );
		$this->assertSame(
			array( 'WP_Email_Options', 'sanitize' ),
			$registered[ WP_Email_Options::OPTION ]['sanitize_callback']
		);
	}

	public function test_the_admin_script_is_only_enqueued_on_plugin_screens() {
		$settings = new WP_Email_Settings();

		$settings->enqueue( 'index.php' );
		$this->assertFalse( wp_script_is( 'wp-email-admin', 'enqueued' ) );

		$settings->enqueue( 'toplevel_page_wp-email' );
		$this->assertTrue( wp_script_is( 'wp-email-admin', 'enqueued' ) );
	}
}
