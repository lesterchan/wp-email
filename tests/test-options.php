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

		$this->assertStringContainsString( '%POST_TYPE%', WP_Email_Options::get( 'link', 'html' ) );
		$this->assertSame( 1, WP_Email_Options::get( 'link', 'type' ) );
		$this->assertSame( 'text/html', WP_Email_Options::get( 'sending', 'contenttype' ) );
		$this->assertSame( 10, WP_Email_Options::get( 'sending', 'interval' ) );
	}

	public function test_stored_values_merge_over_defaults_group_by_group() {
		update_option( WP_Email_Options::OPTION, array( 'link' => array( 'html' => '<a href="%EMAIL_URL%">Mine</a>' ) ) );
		WP_Email_Options::flush();

		$this->assertSame( '<a href="%EMAIL_URL%">Mine</a>', WP_Email_Options::get( 'link', 'html' ) );
		// Untouched keys in the same group still resolve.
		$this->assertSame( 1, WP_Email_Options::get( 'link', 'type' ) );
		// So do whole groups the stored value never mentioned.
		$this->assertSame( 10, WP_Email_Options::get( 'sending', 'interval' ) );
	}

	public function test_unknown_setting_is_null_rather_than_a_warning() {
		$this->assertNull( WP_Email_Options::get( 'link', 'nope' ), 'An unknown key inside a known group reads back null.' );
		$this->assertNull( WP_Email_Options::get( 'nope', 'nope' ), 'An unknown group reads back null rather than raising a notice.' );
	}

	public function test_sanitize_rejects_an_out_of_range_link_type() {
		$clean = WP_Email_Options::sanitize( array( 'link' => array( 'type' => 99 ) ) );

		$this->assertSame( 1, $clean['link']['type'] );
	}

	/**
	 * The three settings one template replaced. A row still carrying them -- a
	 * restored backup, or a plugin written against the old shape -- must not be
	 * able to post them back into the stored settings.
	 */
	public function test_sanitize_drops_the_three_retired_link_settings() {
		$clean = WP_Email_Options::sanitize(
			array(
				'link' => array(
					'post_text' => 'Email This Post',
					'page_text' => 'Email This Page',
					'style'     => 2,
					'html'      => '<a href="%EMAIL_URL%">go</a>',
				),
			)
		);

		foreach ( WP_Email_Options::RETIRED_LINK_KEYS as $key ) {
			$this->assertArrayNotHasKey( $key, $clean['link'], "{$key} is retired and must not be stored." );
		}

		$this->assertSame( array( 'type', 'html' ), array_keys( $clean['link'] ) );
	}

	/**
	 * The template is the whole of the link's appearance now, so a submission
	 * that did not carry the field must leave what is stored alone rather than
	 * resetting it to the shipped default.
	 */
	public function test_sanitize_keeps_the_stored_template_when_the_form_posts_none() {
		$options                 = WP_Email_Options::defaults();
		$options['link']['html'] = '<a href="%EMAIL_URL%">Mine</a>';
		WP_Email_Options::update( $options );

		$clean = WP_Email_Options::sanitize( array( 'link' => array( 'type' => 1 ) ) );

		$this->assertSame( '<a href="%EMAIL_URL%">Mine</a>', $clean['link']['html'] );
	}

	public function test_sanitize_drops_the_retired_icon_setting() {
		$clean = WP_Email_Options::sanitize( array( 'link' => array( 'icon' => '../../../wp-config.php' ) ) );

		$this->assertArrayNotHasKey( 'icon', $clean['link'], 'The retired icon key is dropped by the sanitiser.' );
	}

	public function test_sanitize_rejects_a_bogus_ip_header() {
		$clean = WP_Email_Options::sanitize( array( 'sending' => array( 'ip_header' => 'not a header!' ) ) );

		$this->assertSame( '', $clean['sending']['ip_header'] );

		$clean = WP_Email_Options::sanitize( array( 'sending' => array( 'ip_header' => 'http_x_real_ip' ) ) );

		$this->assertSame( 'HTTP_X_REAL_IP', $clean['sending']['ip_header'] );
	}

	public function test_sanitize_keeps_the_friend_email_field_mandatory() {
		$clean = WP_Email_Options::sanitize(
			array(
				'fields' => array(
					'yourname'    => 0,
					'friendemail' => 0,
				),
			)
		);

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

		// email_style 3 was "text link only" and the two texts were customised and
		// differ, so the post wording wins verbatim and no icon token appears.
		$html = WP_Email_Options::get( 'link', 'html' );

		$this->assertStringContainsString( 'Legacy Post Text', $html );
		$this->assertStringNotContainsString( '%EMAIL_ICON%', $html );
		$this->assertSame( 2, WP_Email_Options::get( 'link', 'type' ) );

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
		$this->assertIsArray( get_option( WP_Email_Options::OPTION ), 'The migration writes the new row.' );
		$this->assertFalse( get_option( 'email_options' ), 'The migration deletes the legacy row once it has been folded in.' );
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
		$this->assertStringContainsString( 'Legacy Post Text', WP_Email_Options::get( 'link', 'html' ) );
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

		$this->assertStringContainsString( 'Already Nested', WP_Email_Options::get( 'link', 'html' ) );
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

		$this->assertStringContainsString( 'Legacy Post Text', WP_Email_Options::get( 'link', 'html' ) );
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

		$this->assertArrayNotHasKey( 'version', $stored, 'The version marker is not stored inside the settings array.' );
		$this->assertArrayNotHasKey( 'versions', $stored, 'No plural version key is stored inside the settings array either.' );
	}

	public function test_the_registered_sanitize_callback_runs_on_save() {
		$settings = new WP_Email_Settings();
		$settings->register();

		update_option(
			WP_Email_Options::OPTION,
			array( 'link' => array( 'type' => 99 ) )
		);

		// register_setting()'s sanitize_callback only runs through the Settings
		// API, so apply it the way options.php would.
		$clean = apply_filters( 'sanitize_option_' . WP_Email_Options::OPTION, array( 'link' => array( 'type' => 99 ) ), WP_Email_Options::OPTION, '' );

		$this->assertSame( 1, $clean['link']['type'] );
	}

	public function test_sanitize_keeps_the_wp_stats_settings() {
		$clean = WP_Email_Options::sanitize(
			array(
				'stats_display'    => '1',
				'stats_most_limit' => '25',
			)
		);

		$this->assertTrue( $clean['stats_display'], 'A ticked WP-Stats checkbox stores as boolean true.' );
		$this->assertSame( 25, $clean['stats_most_limit'] );
	}

	/**
	 * The hidden 0 the screen prints in front of the box is what makes this
	 * expressible: an unticked checkbox on its own posts nothing, and nothing
	 * has to mean "the other tab was saved" rather than "switch this off".
	 */
	public function test_sanitize_reads_a_posted_zero_as_off() {
		$clean = WP_Email_Options::sanitize( array( 'stats_display' => '0' ) );

		$this->assertFalse( $clean['stats_display'], 'An unticked WP-Stats checkbox stores as boolean false.' );
	}

	public function test_sanitize_leaves_a_setting_the_submission_never_mentioned() {
		$options                  = WP_Email_Options::defaults();
		$options['stats_display'] = false;
		WP_Email_Options::update( $options );

		$clean = WP_Email_Options::sanitize( array( 'link' => array( 'type' => 1 ) ) );

		$this->assertFalse( $clean['stats_display'], 'An absent WP-Stats checkbox stores as false rather than being left out.' );
	}

	/**
	 * The regression this screen's two tabs most easily introduce, and the
	 * expensive one: the Settings API hands the sanitizer only what the
	 * submitting form posted, so a sanitizer rebuilding the whole shape wipes
	 * eight written templates the first time somebody saves the other tab.
	 */
	public function test_saving_one_tab_leaves_the_other_tab_settings_alone() {
		$options                          = WP_Email_Options::defaults();
		$options['templates']['subject']  = 'A subject somebody wrote';
		$options['templates']['body']     = '<p>A body somebody wrote</p>';
		$options['link']['html']          = '<a href="%EMAIL_URL%">Mine</a>';
		$options['sending']['interval']   = 42;
		$options['fields']['yourremarks'] = 0;
		WP_Email_Options::update( $options );

		// What the Settings tab posts: no templates key at all.
		$clean = WP_Email_Options::sanitize(
			array(
				'link'             => array(
					'type' => 2,
					'html' => '<a href="%EMAIL_URL%">Mine</a>',
				),
				'fields'           => array(
					'yourname'    => 1,
					'youremail'   => 1,
					'yourremarks' => 0,
					'friendname'  => 1,
				),
				'sending'          => array(
					'contenttype' => 'text/html',
					'snippet'     => 0,
					'interval'    => 42,
					'multiple'    => 5,
					'imageverify' => 1,
					'ip_header'   => '',
				),
				'stats_display'    => '1',
				'stats_most_limit' => 10,
			)
		);

		$this->assertSame( 'A subject somebody wrote', $clean['templates']['subject'] );
		$this->assertSame( '<p>A body somebody wrote</p>', $clean['templates']['body'] );

		// And the other way round: what the Templates tab posts, with none of
		// the Settings tab's fields in it.
		WP_Email_Options::update( $clean );

		$clean = WP_Email_Options::sanitize(
			array( 'templates' => array( 'subject' => 'A newer subject' ) )
		);

		$this->assertSame( 'A newer subject', $clean['templates']['subject'] );
		$this->assertSame( 2, $clean['link']['type'] );
		$this->assertSame( '<a href="%EMAIL_URL%">Mine</a>', $clean['link']['html'] );
		$this->assertSame( 42, $clean['sending']['interval'] );
		$this->assertSame( 0, $clean['fields']['yourremarks'] );
		$this->assertSame( '<p>A body somebody wrote</p>', $clean['templates']['body'] );
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

		$this->assertTrue( WP_Email_Options::stats_display(), 'With the shared row missing, the block stays switched on.' );
	}

	public function test_the_shared_stats_row_is_carried_across_when_it_is_still_there() {
		$this->seed_legacy_install();
		update_option( 'stats_display', array( 'email' => 1 ) );
		update_option( 'stats_mostlimit', 25 );

		WP_Email_Options::flush();
		WP_Email_Options::maybe_upgrade();

		$this->assertTrue( WP_Email_Options::stats_display(), 'The shared row is carried across while it is still there.' );
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

		$this->assertTrue( WP_Email_Options::stats_display(), 'Any one of the three old toggles keeps the block on.' );
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

		$this->assertFalse( WP_Email_Options::stats_display(), 'All three old toggles off switches the block off.' );
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

		$this->assertFalse( get_option( 'stats_display' ), 'The shared stats_display row is deleted by the migration that folded it in.' );
		$this->assertFalse( get_option( 'stats_mostlimit' ), 'The shared stats_mostlimit row is deleted by the migration that folded it in.' );
	}

	public function test_the_stats_limit_never_falls_below_one() {
		$this->seed_legacy_install();
		update_option( 'stats_mostlimit', 0 );

		WP_Email_Options::flush();
		WP_Email_Options::maybe_upgrade();

		$this->assertSame( 1, WP_Email_Options::stats_most_limit() );
	}

	public function test_the_cache_is_dropped_when_the_settings_are_written() {
		$this->assertStringContainsString( '%POST_TYPE%', WP_Email_Options::get( 'link', 'html' ) );

		$options                 = WP_Email_Options::defaults();
		$options['link']['html'] = '<a href="%EMAIL_URL%">Send This</a>';

		WP_Email_Options::update( $options );

		$this->assertSame( '<a href="%EMAIL_URL%">Send This</a>', WP_Email_Options::get( 'link', 'html' ) );
	}
}
