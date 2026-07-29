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
class WP_Email_Options_Test extends WP_Email_TestCase {

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

	public function test_defaults_are_returned_for_a_fresh_install() {
		delete_option( WP_Email_Options::OPTION );
		WP_Email_Options::flush();

		$this->assertSame( 'Email This Post', WP_Email_Options::get( 'link', 'post_text' ) );
		$this->assertSame( 1, WP_Email_Options::get( 'link', 'type' ) );
		$this->assertSame( 'text/html', WP_Email_Options::get( 'sending', 'contenttype' ) );
		$this->assertSame( 10, WP_Email_Options::get( 'sending', 'interval' ) );
	}

	public function test_stored_values_merge_over_defaults_group_by_group() {
		update_option( WP_Email_Options::OPTION, array( 'link' => array( 'post_text' => 'Mine' ) ) );
		WP_Email_Options::flush();

		$this->assertSame( 'Mine', WP_Email_Options::get( 'link', 'post_text' ) );
		// Untouched keys in the same group still resolve.
		$this->assertSame( 1, WP_Email_Options::get( 'link', 'type' ) );
		// So do whole groups the stored value never mentioned.
		$this->assertSame( 10, WP_Email_Options::get( 'sending', 'interval' ) );
	}

	public function test_unknown_setting_is_null_rather_than_a_warning() {
		$this->assertNull( WP_Email_Options::get( 'link', 'nope' ) );
		$this->assertNull( WP_Email_Options::get( 'nope', 'nope' ) );
	}

	public function test_sanitize_rejects_an_out_of_range_link_style() {
		$clean = WP_Email_Options::sanitize( array( 'link' => array( 'style' => 99 ) ) );

		$this->assertSame( 1, $clean['link']['style'] );
	}

	public function test_sanitize_drops_the_retired_icon_setting() {
		$clean = WP_Email_Options::sanitize( array( 'link' => array( 'icon' => '../../../wp-config.php' ) ) );

		$this->assertArrayNotHasKey( 'icon', $clean['link'] );
	}

	public function test_sanitize_rejects_a_bogus_ip_header() {
		$clean = WP_Email_Options::sanitize( array( 'sending' => array( 'ip_header' => 'not a header!' ) ) );

		$this->assertSame( '', $clean['sending']['ip_header'] );

		$clean = WP_Email_Options::sanitize( array( 'sending' => array( 'ip_header' => 'http_x_real_ip' ) ) );

		$this->assertSame( 'HTTP_X_REAL_IP', $clean['sending']['ip_header'] );
	}

	public function test_sanitize_keeps_the_friend_email_field_mandatory() {
		$clean = WP_Email_Options::sanitize( array( 'fields' => array() ) );

		$this->assertSame( 1, $clean['fields']['friendemail'] );
		$this->assertSame( 0, $clean['fields']['yourname'] );
	}

	public function test_sanitize_strips_markup_from_the_subject() {
		$clean = WP_Email_Options::sanitize(
			array( 'templates' => array( 'subject' => 'Hi <b>there</b><script>x</script>' ) )
		);

		// wp_strip_all_tags() removes script and style contents as well as the
		// tags themselves, which is what a mail header needs.
		$this->assertSame( 'Hi there', $clean['templates']['subject'] );
	}

	public function test_sanitize_keeps_markup_in_the_body_but_drops_scripts() {
		$clean = WP_Email_Options::sanitize(
			array( 'templates' => array( 'body' => '<p>Hi</p><script>alert(1)</script>' ) )
		);

		$this->assertStringContainsString( '<p>Hi</p>', $clean['templates']['body'] );
		$this->assertStringNotContainsString( '<script>', $clean['templates']['body'] );
	}

	public function test_sanitize_never_lowers_the_recipient_maximum_below_one() {
		$clean = WP_Email_Options::sanitize( array( 'sending' => array( 'multiple' => 0 ) ) );

		$this->assertSame( 1, $clean['sending']['multiple'] );
	}

	public function test_migration_carries_every_legacy_value_across() {
		$this->seed_legacy_install();

		WP_Email_Options::flush();
		WP_Email_Options::maybe_upgrade();

		$this->assertSame( 'Legacy Post Text', WP_Email_Options::get( 'link', 'post_text' ) );
		$this->assertSame( 'Legacy Page Text', WP_Email_Options::get( 'link', 'page_text' ) );
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

	public function test_migration_takes_defaults_for_templates_the_install_never_customised() {
		$this->seed_legacy_install();

		WP_Email_Options::flush();
		WP_Email_Options::maybe_upgrade();

		$this->assertStringContainsString( '%EMAIL_ERROR_MSG%', WP_Email_Options::template( 'error' ) );
	}

	public function test_migration_unslashes_templates_stored_by_the_old_screen() {
		$this->seed_legacy_install();
		update_option( 'email_template_body', "Legacy body O\\'Brien %EMAIL_POST_CONTENT%" );

		WP_Email_Options::flush();
		WP_Email_Options::maybe_upgrade();

		$this->assertStringContainsString( "O'Brien", WP_Email_Options::template( 'body' ) );
		$this->assertStringNotContainsString( "O\\'Brien", WP_Email_Options::template( 'body' ) );
	}

	public function test_migration_deletes_the_legacy_rows() {
		$this->seed_legacy_install();

		WP_Email_Options::flush();
		WP_Email_Options::maybe_upgrade();

		foreach ( WP_Email_Options::LEGACY_ROWS as $name ) {
			$this->assertFalse( get_option( $name ), "{$name} should have been deleted" );
		}
	}

	public function test_migration_writes_the_prefixed_row_and_removes_the_old_one() {
		$this->seed_legacy_install();

		WP_Email_Options::flush();
		WP_Email_Options::maybe_upgrade();

		$this->assertSame( 'wp_email_options', WP_Email_Options::OPTION );
		$this->assertIsArray( get_option( WP_Email_Options::OPTION ) );
		$this->assertFalse( get_option( 'email_options' ) );
	}

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

	public function test_the_plugin_owns_at_most_two_option_rows_after_upgrading() {
		global $wpdb;

		$this->seed_legacy_install();

		WP_Email::get_instance()->maybe_upgrade();

		$rows = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE 'wp\\_email\\_%'"
		);

		$this->assertSame( 2, $rows );
	}

	public function test_the_version_marker_is_not_stored_inside_the_option_it_gates() {
		WP_Email::get_instance()->maybe_upgrade();

		$stored = get_option( WP_Email_Options::OPTION );

		$this->assertArrayNotHasKey( 'version', $stored );
		$this->assertArrayNotHasKey( 'versions', $stored );
	}

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

	public function test_sanitize_keeps_the_wp_stats_settings() {
		$clean = WP_Email_Options::sanitize(
			array(
				'stats_display'    => '1',
				'stats_most_limit' => '25',
			)
		);

		$this->assertTrue( $clean['stats_display'] );
		$this->assertSame( 25, $clean['stats_most_limit'] );
	}

	public function test_sanitize_reads_an_absent_stats_checkbox_as_off() {
		$clean = WP_Email_Options::sanitize( array() );

		$this->assertFalse( $clean['stats_display'] );
	}

	public function test_sanitize_never_lowers_the_stats_limit_below_one() {
		$clean = WP_Email_Options::sanitize( array( 'stats_most_limit' => 0 ) );

		$this->assertSame( 1, $clean['stats_most_limit'] );
	}

	/**
	 * The 13.2 rule: an absent shared row means a sibling plugin has already
	 * migrated and deleted it, not that the block was switched off.
	 *
	 * Read the other way round -- get_option( 'stats_display', false ) -- six of
	 * the seven plugins would lose their block with no error anywhere.
	 */
	public function test_a_missing_shared_stats_row_leaves_the_block_switched_on() {
		$this->seed_legacy_install();
		delete_option( 'stats_display' );

		WP_Email_Options::flush();
		WP_Email_Options::maybe_upgrade();

		$this->assertTrue( WP_Email_Options::stats_display() );
	}

	public function test_the_shared_stats_row_is_carried_across_when_it_is_still_there() {
		$this->seed_legacy_install();
		update_option( 'stats_display', array( 'email' => 1 ) );
		update_option( 'stats_mostlimit', 25 );

		WP_Email_Options::flush();
		WP_Email_Options::maybe_upgrade();

		$this->assertTrue( WP_Email_Options::stats_display() );
		$this->assertSame( 25, WP_Email_Options::stats_most_limit() );
	}

	/**
	 * WP-Stats 2.x kept one toggle per block and WP-EMail owned three of them,
	 * so the single section is on if any of the three was.
	 */
	public function test_any_of_the_three_old_toggles_keeps_the_block_on() {
		$this->seed_legacy_install();
		update_option( 'stats_display', array( 'emailed_most_page' => 1 ) );

		WP_Email_Options::flush();
		WP_Email_Options::maybe_upgrade();

		$this->assertTrue( WP_Email_Options::stats_display() );
	}

	public function test_all_three_old_toggles_off_switches_the_block_off() {
		$this->seed_legacy_install();
		update_option(
			'stats_display',
			array(
				'email'             => 0,
				'emailed_most_post' => 0,
				'emailed_most_page' => 0,
			)
		);

		WP_Email_Options::flush();
		WP_Email_Options::maybe_upgrade();

		$this->assertFalse( WP_Email_Options::stats_display() );
	}

	/**
	 * Deleted by the migration, because 2.1 requires every folded-in row to go.
	 * uninstall.php is the half that must leave them alone.
	 */
	public function test_the_migration_deletes_the_two_shared_stats_rows() {
		$this->seed_legacy_install();
		update_option( 'stats_display', array( 'email' => 1 ) );
		update_option( 'stats_mostlimit', 25 );

		WP_Email_Options::flush();
		WP_Email_Options::maybe_upgrade();

		$this->assertFalse( get_option( 'stats_display' ) );
		$this->assertFalse( get_option( 'stats_mostlimit' ) );
	}

	public function test_the_stats_limit_never_falls_below_one() {
		$this->seed_legacy_install();
		update_option( 'stats_mostlimit', 0 );

		WP_Email_Options::flush();
		WP_Email_Options::maybe_upgrade();

		$this->assertSame( 1, WP_Email_Options::stats_most_limit() );
	}

	public function test_the_cache_is_dropped_when_the_settings_are_written() {
		$this->assertSame( 'Email This Post', WP_Email_Options::get( 'link', 'post_text' ) );

		$options                      = WP_Email_Options::defaults();
		$options['link']['post_text'] = 'Send This';

		WP_Email_Options::update( $options );

		$this->assertSame( 'Send This', WP_Email_Options::get( 'link', 'post_text' ) );
	}
}
