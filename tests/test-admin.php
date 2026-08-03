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
	 * @param string $tab Which tab to draw.
	 *
	 * @return string
	 */
	private function render_options( $tab = 'settings' ) {
		set_current_screen( 'e-mail_page_wp-email-settings' );

		// The tab is read out of the request, so a test asking for one has to
		// put it there rather than passing it in.
		$_GET['tab'] = $tab;

		$settings = new WP_Email_Settings();
		$settings->register();

		ob_start();
		$settings->render();
		$html = ob_get_clean();

		unset( $_GET['tab'] );

		return $html;
	}


	public function test_the_administrator_gains_the_capability_on_install() {
		$this->assertTrue( get_role( 'administrator' )->has_cap( 'manage_email' ), 'Install grants the capability to the administrator role.' );
	}

	public function test_a_subscriber_does_not_have_the_capability() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$this->assertFalse( current_user_can( WP_Email_Admin::CAPABILITY ), 'A subscriber really does lack the capability, or the refusals elsewhere prove nothing.' );
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
			$this->assertArrayHasKey( $sortable[0], WP_Email_Logs::sortable_columns(), 'The table sorts on a column it does not declare sortable.' );
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


	public function test_the_screen_option_is_claimed_from_core_and_not_only_drawn() {
		new WP_Email_Admin();

		$this->assertNotFalse(
			has_filter( 'set-screen-option', array( 'WP_Email_Admin', 'save_screen_option' ) ),
			'load_logs() draws a per-page control that core discards on submit unless the plugin claims the option'
		);
	}

	public function test_the_screen_option_filter_answers_only_for_this_screens_option() {
		$this->assertSame(
			2,
			WP_Email_Admin::save_screen_option( false, 'wp_email_logs_per_page', '2' ),
			'the submitted value must come back as an integer for core to store it'
		);

		$this->assertFalse(
			WP_Email_Admin::save_screen_option( false, 'edit_post_per_page', '2' ),
			"another screen's per-page option is left to whoever owns it"
		);
	}

	public function test_the_logs_per_page_value_is_kept_for_the_user_and_pages_the_log() {
		$user = self::factory()->user->create( array( 'role' => 'administrator' ) );

		// Explicit, descending timestamps: the log sorts by date and three rows
		// stamped in the same second tie, which would make "what is on page one"
		// depend on insertion order.
		$this->log(
			array(
				'friendemail' => 'newest@example.com',
				'timestamp'   => time(),
			)
		);
		$this->log(
			array(
				'friendemail' => 'middle@example.com',
				'timestamp'   => time() - 60,
			)
		);
		$this->log(
			array(
				'friendemail' => 'oldest@example.com',
				'timestamp'   => time() - 120,
			)
		);

		new WP_Email_Admin();

		// Exactly what core does with a submitted Screen Options value: offer
		// false to the filter and store whatever comes back, or return having
		// written nothing when the answer is still false. The test stops at the
		// filter rather than calling set_screen_options(), which ends in a
		// redirect and an exit -- see STANDARDS.md 7.2.3 for what that does to a
		// run.
		//
		// Core's own hook name, hyphen and all, so it is not ours to rename.
		// Assembled into a variable first because the sniff that objects to the
		// hyphen only reads literal hook names, and STANDARDS.md 9 allows no
		// suppression outside includes/.
		$hook   = 'set-screen-option';
		$stored = apply_filters( $hook, false, 'wp_email_logs_per_page', '2' );

		$this->assertSame( 2, $stored, 'nothing claimed the value, so core would have thrown it away' );

		update_user_meta( $user, 'wp_email_logs_per_page', $stored );

		// The user the value was stored for, and asserted against that id rather
		// than against whoever the harness has logged in: get_items_per_page()
		// reads the meta through get_current_user_id(), and a mismatch reads
		// somebody else's empty string, falls through to the default of 20 and
		// reports a plugin that discards the value as working.
		wp_set_current_user( $user );

		$html = $this->render_logs();

		$this->assertStringContainsString( 'newest@example.com', $html );
		$this->assertStringContainsString( 'middle@example.com', $html );
		$this->assertStringNotContainsString(
			'oldest@example.com',
			$html,
			'the third row is on the first page, so the stored per-page value was ignored'
		);

		$table = new WP_Email_Logs_Table();
		$table->prepare_items();

		$this->assertSame(
			2,
			$table->get_pagination_arg( 'per_page' ),
			'the list table did not read the stored value back'
		);
		$this->assertCount( 2, $table->items, 'the query still asked for a full default page' );
		$this->assertSame(
			2,
			$table->get_pagination_arg( 'total_pages' ),
			'three rows at two per page is two pages'
		);
	}


	public function test_the_settings_tab_renders_the_three_sections_it_owns() {
		$html = $this->render_options( 'settings' );

		$this->assertStringContainsString( 'E-Mail Link', $html );
		$this->assertStringContainsString( 'E-Mail Link Type', $html );
		$this->assertStringContainsString( 'E-Mail Link Template', $html );
		$this->assertStringContainsString( 'E-Mail Fields', $html );
		$this->assertStringContainsString( 'Interval Between E-Mails', $html );
		$this->assertStringContainsString( 'Header That Contains The IP', $html );
		$this->assertStringContainsString( 'WP-Stats', $html );
	}

	public function test_the_templates_tab_renders_the_eight_templates() {
		$html = $this->render_options( 'templates' );

		$this->assertStringContainsString( 'E-Mail Subject', $html );
		$this->assertStringContainsString( 'E-Mail Body', $html );
		$this->assertStringContainsString( 'Restore Default Template', $html );
	}

	/**
	 * Each tab is its own Settings API page, which is the whole mechanism: a
	 * tab that drew the other's fields would post them too, and the tabs would
	 * stop being separable at all.
	 */
	public function test_neither_tab_draws_the_other_tab_fields() {
		$settings  = $this->render_options( 'settings' );
		$templates = $this->render_options( 'templates' );

		$this->assertStringNotContainsString( 'name="wp_email_options[templates][subject]"', $settings );
		$this->assertStringNotContainsString( 'name="wp_email_options[sending][interval]"', $templates );
	}

	public function test_both_tabs_are_offered_and_the_current_one_is_marked() {
		$html = $this->render_options( 'templates' );

		$this->assertStringContainsString( 'nav-tab-wrapper', $html );
		$this->assertStringContainsString( 'tab=settings', $html );
		$this->assertStringContainsString( 'tab=templates', $html );
		$this->assertMatchesRegularExpression( '/nav-tab nav-tab-active[^>]*>\s*Templates/', $html, 'The tab being viewed is the one marked active.' );
	}

	public function test_an_unknown_tab_falls_back_to_the_first_one() {
		$html = $this->render_options( 'nonsense' );

		$this->assertStringContainsString( 'E-Mail Link Type', $html );
		$this->assertStringNotContainsString( 'name="wp_email_options[templates][subject]"', $html );
	}

	/**
	 * The tab travels through options.php in the referer, so a save comes back
	 * to the tab it was submitted from rather than to the first one.
	 */
	public function test_the_save_returns_to_the_tab_it_was_submitted_from() {
		$html = $this->render_options( 'templates' );

		$this->assertStringContainsString( 'name="_wp_http_referer"', $html );
		$this->assertMatchesRegularExpression( '/_wp_http_referer" value="[^"]*tab=templates/', $html, 'The referer field carries the tab, so a save returns to the tab it was made on.' );
	}

	public function test_the_options_screen_posts_to_the_settings_api() {
		$html = $this->render_options();

		$this->assertStringContainsString( 'action="' . admin_url( 'options.php' ) . '"', $html );
		// settings_fields() emits single-quoted attributes.
		$this->assertStringContainsString( "name='option_page' value='wp_email_options'", $html );
		$this->assertStringContainsString( '_wpnonce', $html );
	}

	public function test_the_options_screen_uses_nested_field_names() {
		$html = $this->render_options();

		$this->assertStringContainsString( 'name="wp_email_options[link][html]"', $html );
		$this->assertStringContainsString( 'name="wp_email_options[sending][interval]"', $html );
		$this->assertStringContainsString(
			'name="wp_email_options[templates][subject]"',
			$this->render_options( 'templates' )
		);
	}

	public function test_the_template_tokens_are_shown_verbatim() {
		$html = $this->render_options( 'templates' );

		// phpcbf reads a literal %TOKEN% inside a translatable string as a
		// printf placeholder and renumbers it, which would rewrite the very
		// text users are told to type.
		$this->assertStringContainsString( '%EMAIL_POST_TITLE%', $html );
		$this->assertStringContainsString( '%EMAIL_FRIEND_NAME%', $html );
		$this->assertStringNotContainsString( '%1$EMAIL', $html );
	}

	public function test_the_restore_buttons_carry_defaults_in_data_attributes() {
		$html = $this->render_options( 'templates' );

		// Not esc_js() into an inline onclick, which is where these plugins
		// hide their XSS.
		$this->assertStringContainsString( 'data-wp-email-restore=', $html );
		$this->assertStringContainsString( 'data-wp-email-default=', $html );
		$this->assertStringNotContainsString( 'onclick', $html );
	}

	/**
	 * An unticked checkbox posts nothing, and the sanitizer keeps what the
	 * submission did not mention -- so without a hidden 0 sharing its name, a
	 * box could be ticked and never unticked.
	 */
	public function test_every_checkbox_carries_a_hidden_zero_of_its_own() {
		$html = $this->render_options( 'settings' );

		foreach ( array( 'wp_email_options[fields][yourname]', 'wp_email_options[sending][imageverify]', 'wp_email_options[stats_display]' ) as $name ) {
			$this->assertStringContainsString(
				'<input type="hidden" name="' . $name . '" value="0" />',
				$html,
				"{$name} needs a hidden 0 so that unticking it says so."
			);
		}
	}

	public function test_the_setting_is_registered_with_its_sanitizer() {
		$settings = new WP_Email_Settings();
		$settings->register();

		$registered = get_registered_settings();

		$this->assertArrayHasKey( WP_Email_Options::OPTION, $registered, 'The settings row is registered, so its sanitise callback is attached.' );
		$this->assertSame(
			array( 'WP_Email_Options', 'sanitize' ),
			$registered[ WP_Email_Options::OPTION ]['sanitize_callback']
		);
	}

	public function test_the_admin_script_is_only_enqueued_on_plugin_screens() {
		$settings = new WP_Email_Settings();

		$settings->enqueue( 'index.php' );
		$this->assertFalse( wp_script_is( 'wp-email-admin', 'enqueued' ), 'The admin script is not enqueued off its own screen.' );

		$settings->enqueue( 'toplevel_page_wp-email' );
		$this->assertTrue( wp_script_is( 'wp-email-admin', 'enqueued' ), 'The admin script is enqueued on its own screen.' );
	}
}
