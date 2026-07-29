<?php
/**
 * Settings storage, sanitizing and the 2.x -> 3.0.0 migration.
 *
 * @package WP-EMail
 */

/**
 * Settings storage, sanitizing and the migration.
 *
 * @covers WP_Email_Options
 */
class Test_Email_Options extends WP_UnitTestCase {

	/**
	 * Put the database back into the shape a 2.69.4 install leaves behind.
	 *
	 * @return void
	 */
	private function seed_legacy_install() {
		delete_option( WP_Email_Options::VERSION );
		delete_option( WP_Email_Options::OPTION );
		WP_Email_Options::flush();

		update_option(
			'email_options',
			array(
				'post_text'   => 'Legacy Post Text',
				'page_text'   => 'Legacy Page Text',
				'email_icon'  => 'email.gif',
				'email_type'  => 2,
				'email_style' => 3,
				'email_html'  => '<a href="%EMAIL_URL%">legacy</a>',
				'ip_header'   => 'HTTP_X_REAL_IP',
			)
		);

		update_option(
			'email_fields',
			array(
				'yourname'    => 1,
				'youremail'   => 1,
				'yourremarks' => 0,
				'friendname'  => 0,
				'friendemail' => 1,
			)
		);

		update_option( 'email_contenttype', 'text/plain' );
		update_option( 'email_snippet', 42 );
		update_option( 'email_interval', 7 );
		update_option( 'email_multiple', 3 );
		update_option( 'email_imageverify', 0 );
		update_option( 'email_template_subject', 'Legacy subject for %EMAIL_POST_TITLE%' );
		update_option( 'email_template_title', 'Legacy title %EMAIL_POST_TITLE%' );
		update_option( 'email_smtp', 'should-be-removed' );
	}

	/**
	 * Defaults are returned for a fresh install.
	 */
	public function test_defaults_are_returned_for_a_fresh_install() {
		delete_option( WP_Email_Options::OPTION );

		$this->assertSame( 'Email This Post', WP_Email_Options::get( 'link', 'post_text' ) );
		$this->assertSame( 1, WP_Email_Options::get( 'link', 'type' ) );
		$this->assertSame( 'text/html', WP_Email_Options::get( 'sending', 'contenttype' ) );
		$this->assertSame( 10, WP_Email_Options::get( 'sending', 'interval' ) );
	}

	/**
	 * Stored values merge over defaults group by group.
	 */
	public function test_stored_values_merge_over_defaults_group_by_group() {
		update_option( WP_Email_Options::OPTION, array( 'link' => array( 'post_text' => 'Mine' ) ) );

		$this->assertSame( 'Mine', WP_Email_Options::get( 'link', 'post_text' ) );
		// Untouched keys in the same group still resolve.
		$this->assertSame( 'email_famfamfam.png', WP_Email_Options::get( 'link', 'icon' ) );
		// So do whole groups the stored value never mentioned.
		$this->assertSame( 10, WP_Email_Options::get( 'sending', 'interval' ) );
	}

	/**
	 * Unknown setting is null rather than a warning.
	 */
	public function test_unknown_setting_is_null_rather_than_a_warning() {
		$this->assertNull( WP_Email_Options::get( 'link', 'nope' ) );
		$this->assertNull( WP_Email_Options::get( 'nope', 'nope' ) );
	}

	/**
	 * Sanitize rejects an out of range link style.
	 */
	public function test_sanitize_rejects_an_out_of_range_link_style() {
		$clean = WP_Email_Options::sanitize( array( 'link' => array( 'style' => 99 ) ) );

		$this->assertSame( 1, $clean['link']['style'] );
	}

	/**
	 * Sanitize rejects an icon that does not ship.
	 */
	public function test_sanitize_rejects_an_icon_that_does_not_ship() {
		$clean = WP_Email_Options::sanitize( array( 'link' => array( 'icon' => '../../../wp-config.php' ) ) );

		$this->assertContains( $clean['link']['icon'], WP_Email_Options::available_icons() );
	}

	/**
	 * Sanitize rejects a bogus ip header.
	 */
	public function test_sanitize_rejects_a_bogus_ip_header() {
		$clean = WP_Email_Options::sanitize( array( 'sending' => array( 'ip_header' => 'not a header!' ) ) );

		$this->assertSame( '', $clean['sending']['ip_header'] );

		$clean = WP_Email_Options::sanitize( array( 'sending' => array( 'ip_header' => 'http_x_real_ip' ) ) );

		$this->assertSame( 'HTTP_X_REAL_IP', $clean['sending']['ip_header'] );
	}

	/**
	 * Sanitize keeps the friend email field mandatory.
	 */
	public function test_sanitize_keeps_the_friend_email_field_mandatory() {
		$clean = WP_Email_Options::sanitize( array( 'fields' => array() ) );

		$this->assertSame( 1, $clean['fields']['friendemail'] );
		$this->assertSame( 0, $clean['fields']['yourname'] );
	}

	/**
	 * Sanitize strips markup from the subject.
	 */
	public function test_sanitize_strips_markup_from_the_subject() {
		$clean = WP_Email_Options::sanitize(
			array( 'templates' => array( 'subject' => 'Hi <b>there</b><script>x</script>' ) )
		);

		// wp_strip_all_tags() removes script and style contents as well as the
		// tags themselves, which is what a mail header needs.
		$this->assertSame( 'Hi there', $clean['templates']['subject'] );
	}

	/**
	 * Sanitize keeps markup in the body but drops scripts.
	 */
	public function test_sanitize_keeps_markup_in_the_body_but_drops_scripts() {
		$clean = WP_Email_Options::sanitize(
			array( 'templates' => array( 'body' => '<p>Hi</p><script>alert(1)</script>' ) )
		);

		$this->assertStringContainsString( '<p>Hi</p>', $clean['templates']['body'] );
		$this->assertStringNotContainsString( '<script>', $clean['templates']['body'] );
	}

	/**
	 * Sanitize never lowers the recipient maximum below one.
	 */
	public function test_sanitize_never_lowers_the_recipient_maximum_below_one() {
		$clean = WP_Email_Options::sanitize( array( 'sending' => array( 'multiple' => 0 ) ) );

		$this->assertSame( 1, $clean['sending']['multiple'] );
	}

	/**
	 * Migration carries every legacy value across.
	 */
	public function test_migration_carries_every_legacy_value_across() {
		$this->seed_legacy_install();

		WP_Email_Options::flush();
		WP_Email_Options::maybe_upgrade();

		$this->assertSame( 'Legacy Post Text', WP_Email_Options::get( 'link', 'post_text' ) );
		$this->assertSame( 'Legacy Page Text', WP_Email_Options::get( 'link', 'page_text' ) );
		$this->assertSame( 'email.gif', WP_Email_Options::get( 'link', 'icon' ) );
		$this->assertSame( 2, WP_Email_Options::get( 'link', 'type' ) );
		$this->assertSame( 3, WP_Email_Options::get( 'link', 'style' ) );

		$this->assertSame( 'HTTP_X_REAL_IP', WP_Email_Options::get( 'sending', 'ip_header' ) );
		$this->assertSame( 'text/plain', WP_Email_Options::get( 'sending', 'contenttype' ) );
		$this->assertSame( 42, WP_Email_Options::get( 'sending', 'snippet' ) );
		$this->assertSame( 7, WP_Email_Options::get( 'sending', 'interval' ) );
		$this->assertSame( 3, WP_Email_Options::get( 'sending', 'multiple' ) );
		$this->assertSame( 0, WP_Email_Options::get( 'sending', 'imageverify' ) );

		$this->assertSame( 0, WP_Email_Options::get( 'fields', 'yourremarks' ) );
		$this->assertSame( 0, WP_Email_Options::get( 'fields', 'friendname' ) );
		$this->assertSame( 1, WP_Email_Options::get( 'fields', 'friendemail' ) );

		$this->assertStringContainsString( 'Legacy subject', WP_Email_Options::template( 'subject' ) );
		$this->assertStringContainsString( 'Legacy title', WP_Email_Options::template( 'title' ) );
	}

	/**
	 * Migration takes defaults for templates the install never customised.
	 */
	public function test_migration_takes_defaults_for_templates_the_install_never_customised() {
		$this->seed_legacy_install();

		WP_Email_Options::flush();
		WP_Email_Options::maybe_upgrade();

		$this->assertStringContainsString( '%EMAIL_ERROR_MSG%', WP_Email_Options::template( 'error' ) );
	}

	/**
	 * Migration unslashes templates stored by the old screen.
	 */
	public function test_migration_unslashes_templates_stored_by_the_old_screen() {
		$this->seed_legacy_install();
		update_option( 'email_template_body', "Legacy body O\\'Brien %EMAIL_POST_CONTENT%" );

		WP_Email_Options::flush();
		WP_Email_Options::maybe_upgrade();

		$this->assertStringContainsString( "O'Brien", WP_Email_Options::template( 'body' ) );
		$this->assertStringNotContainsString( "O\\'Brien", WP_Email_Options::template( 'body' ) );
	}

	/**
	 * Migration deletes the legacy rows.
	 */
	public function test_migration_deletes_the_legacy_rows() {
		$this->seed_legacy_install();

		WP_Email_Options::flush();
		WP_Email_Options::maybe_upgrade();

		foreach ( WP_Email_Options::LEGACY_ROWS as $name ) {
			$this->assertFalse( get_option( $name ), "{$name} should have been deleted" );
		}
	}

	/**
	 * The consolidated row is a new, prefixed one rather than the old name.
	 */
	public function test_migration_writes_the_prefixed_row_and_removes_the_old_one() {
		$this->seed_legacy_install();

		WP_Email_Options::flush();
		WP_Email_Options::maybe_upgrade();

		$this->assertSame( 'wp_email_options', WP_Email_Options::OPTION );
		$this->assertIsArray( get_option( WP_Email_Options::OPTION ) );
		$this->assertFalse( get_option( 'email_options' ) );
	}

	/**
	 * Running the migration again must not write defaults over real settings.
	 */
	public function test_migration_is_idempotent() {
		$this->seed_legacy_install();

		WP_Email_Options::flush();
		WP_Email_Options::maybe_upgrade();
		WP_Email_Options::flush();
		WP_Email_Options::maybe_upgrade();
		WP_Email_Options::flush();
		WP_Email_Options::maybe_upgrade();

		// The failure mode is a second run finding no legacy rows and writing
		// defaults straight over the settings it migrated a moment ago.
		$this->assertSame( 'Legacy Post Text', WP_Email_Options::get( 'link', 'post_text' ) );
		$this->assertSame( 7, WP_Email_Options::get( 'sending', 'interval' ) );
	}

	/**
	 * Migration leaves an already nested option alone.
	 */
	public function test_migration_carries_an_unreleased_nested_row_across() {
		delete_option( WP_Email_Options::VERSION );
		update_option(
			'email_options',
			array(
				'link'      => array( 'post_text' => 'Already Nested' ),
				'templates' => array( 'subject' => 'Mine' ),
			)
		);

		WP_Email_Options::flush();
		WP_Email_Options::maybe_upgrade();

		$this->assertSame( 'Already Nested', WP_Email_Options::get( 'link', 'post_text' ) );
		$this->assertSame( 'Mine', WP_Email_Options::template( 'subject' ) );
	}

	/**
	 * Upgrade writes the version marker and stops repeating.
	 */
	public function test_upgrade_writes_the_version_marker_and_stops_repeating() {
		$this->seed_legacy_install();

		WP_Email::get_instance()->maybe_upgrade();

		$this->assertSame(
			array(
				'plugin' => WP_EMAIL_VERSION,
				'db'     => WP_EMAIL_DB_VERSION,
			),
			get_option( WP_Email_Options::VERSION )
		);

		WP_Email::get_instance()->maybe_upgrade();

		$this->assertSame( 'Legacy Post Text', WP_Email_Options::get( 'link', 'post_text' ) );
	}

	/**
	 * The plugin owns at most two option rows after upgrading.
	 */
	public function test_the_plugin_owns_at_most_two_option_rows_after_upgrading() {
		global $wpdb;

		$this->seed_legacy_install();

		WP_Email::get_instance()->maybe_upgrade();

		$rows = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE 'wp\\_email\\_%'"
		);

		$this->assertSame( 2, $rows );
	}

	/**
	 * The marker decides whether the option needs migrating, so it lives elsewhere.
	 */
	public function test_the_version_marker_is_not_stored_inside_the_option_it_gates() {
		WP_Email::get_instance()->maybe_upgrade();

		$stored = get_option( WP_Email_Options::OPTION );

		$this->assertArrayNotHasKey( 'version', $stored );
		$this->assertArrayNotHasKey( 'versions', $stored );
	}

	/**
	 * The registered sanitize callback runs on save.
	 */
	public function test_the_registered_sanitize_callback_runs_on_save() {
		$settings = new WP_Email_Settings();
		$settings->register();

		update_option(
			WP_Email_Options::OPTION,
			array( 'link' => array( 'style' => 99 ) )
		);

		// register_setting()'s sanitize_callback only runs through the Settings
		// API, so apply it the way options.php would.
		$clean = apply_filters( 'sanitize_option_' . WP_Email_Options::OPTION, array( 'link' => array( 'style' => 99 ) ), WP_Email_Options::OPTION, '' );

		$this->assertSame( 1, $clean['link']['style'] );
	}
}
