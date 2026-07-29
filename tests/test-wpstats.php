<?php
/**
 * The WP-Stats integration.
 *
 * @package WP-EMail
 */

/**
 * Tests for the four filters WP-Stats calls.
 *
 * @covers WP_Email_WPStats
 */
class Test_Email_WpStats extends WP_UnitTestCase {

	/**
	 * The integration under test.
	 *
	 * @var WP_Email_WPStats
	 */
	private $stats;

	/**
	 * Post fixture.
	 *
	 * @var int
	 */
	private $post_id;

	/**
	 * Page fixture.
	 *
	 * @var int
	 */
	private $page_id;

	/**
	 * Set up the fixtures for each test.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		require_once dirname( __DIR__ ) . '/includes/class-wp-email-wpstats.php';

		$this->stats = new WP_Email_WPStats();

		$past = gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS );

		$this->post_id = self::factory()->post->create(
			array(
				'post_title' => 'Harness Post',
				'post_date'  => $past,
			)
		);

		$this->page_id = self::factory()->post->create(
			array(
				'post_title' => 'Harness Page',
				'post_type'  => 'page',
				'post_date'  => $past,
			)
		);

		update_option( 'stats_mostlimit', 5 );
	}

	/**
	 * Insert a log row.
	 *
	 * @param int    $post_id Post the row belongs to.
	 * @param string $status  Delivery status.
	 *
	 * @return void
	 */
	private function log( $post_id, $status = WP_Email_Logs::STATUS_SUCCESS ) {
		WP_Email_Logs::insert(
			array(
				'yourname'    => 'Alice',
				'youremail'   => 'alice@example.com',
				'yourremarks' => '',
				'friendname'  => 'Friend',
				'friendemail' => 'friend@example.com',
				'postid'      => $post_id,
				'posttitle'   => 'Title',
				'timestamp'   => time(),
				'ip'          => '198.51.100.1',
				'host'        => '',
				'status'      => $status,
			)
		);
	}

	// --------------------------------------------------------- the toggles --

	/**
	 * The options screen offers a WP-EMail checkbox.
	 */
	public function test_admin_general_renders_a_checkbox() {
		$out = $this->stats->admin_general( '' );

		$this->assertStringContainsString( 'wpstats_email', $out );
		$this->assertStringContainsString( 'WP-EMail', $out );
		$this->assertStringContainsString( 'name="stats_display[]"', $out );
	}

	/**
	 * The checkbox reflects the stored setting.
	 */
	public function test_admin_general_checkbox_reflects_the_setting() {
		$this->assertStringNotContainsString( 'checked', $this->stats->admin_general( '' ) );

		update_option( 'stats_display', array( 'email' => 1 ) );

		$this->assertStringContainsString( 'checked', $this->stats->admin_general( '' ) );
	}

	/**
	 * Both most-emailed toggles are offered.
	 */
	public function test_admin_most_renders_both_checkboxes() {
		$out = $this->stats->admin_most( '' );

		$this->assertStringContainsString( 'wpstats_emailed_most_post', $out );
		$this->assertStringContainsString( 'wpstats_emailed_most_page', $out );
	}

	/**
	 * Each filter appends to what it was given rather than replacing it.
	 */
	public function test_the_filters_append_to_existing_content() {
		$this->assertStringStartsWith( 'EXISTING', $this->stats->admin_general( 'EXISTING' ) );
		$this->assertStringStartsWith( 'EXISTING', $this->stats->admin_most( 'EXISTING' ) );
		$this->assertStringStartsWith( 'EXISTING', $this->stats->general( 'EXISTING' ) );
		$this->assertStringStartsWith( 'EXISTING', $this->stats->most( 'EXISTING' ) );
	}

	// ------------------------------------------------------- general stats --

	/**
	 * Nothing is rendered while the toggle is off.
	 */
	public function test_general_renders_nothing_when_switched_off() {
		$this->log( $this->post_id );

		$this->assertSame( '', $this->stats->general( '' ) );
	}

	/**
	 * A missing stats_display option is not an error.
	 */
	public function test_general_survives_a_missing_option() {
		delete_option( 'stats_display' );

		$this->assertSame( '', $this->stats->general( '' ) );
	}

	/**
	 * A malformed stats_display option is not an error either.
	 */
	public function test_general_survives_a_malformed_option() {
		update_option( 'stats_display', 'not-an-array' );

		$this->assertSame( '', $this->stats->general( '' ) );
	}

	/**
	 * Switched on, the totals are reported.
	 */
	public function test_general_reports_the_totals() {
		update_option( 'stats_display', array( 'email' => 1 ) );

		$this->log( $this->post_id );
		$this->log( $this->post_id );
		$this->log( $this->post_id, WP_Email_Logs::STATUS_FAILED );

		$out = $this->stats->general( '' );

		$this->assertStringContainsString( 'WP-EMail', $out );
		$this->assertStringContainsString( '<strong>3</strong> emails were sent.', $out );
		$this->assertStringContainsString( '<strong>2</strong> emails were sent successfully.', $out );
		$this->assertStringContainsString( '<strong>1</strong> email failed to send.', $out );
	}

	/**
	 * Counting uses the stored status, not a translated one.
	 */
	public function test_general_counts_untranslated_statuses() {
		update_option( 'stats_display', array( 'email' => 1 ) );

		// Rows written before 3.0.0 held __( 'Success' ); the upgrade rewrites
		// them, and the counts read the canonical value.
		$this->log( $this->post_id );

		$this->assertStringContainsString( '<strong>1</strong> email was sent successfully.', $this->stats->general( '' ) );
	}

	// ---------------------------------------------------------- most stats --

	/**
	 * Nothing is rendered while both toggles are off.
	 */
	public function test_most_renders_nothing_when_switched_off() {
		$this->log( $this->post_id );

		$this->assertSame( '', $this->stats->most( '' ) );
	}

	/**
	 * The most-emailed posts block lists posts only.
	 */
	public function test_most_lists_posts_when_switched_on() {
		update_option( 'stats_display', array( 'emailed_most_post' => 1 ) );

		$this->log( $this->post_id );
		$this->log( $this->page_id );

		$out = $this->stats->most( '' );

		$this->assertStringContainsString( 'Most Emailed Post', $out );
		$this->assertStringContainsString( 'Harness Post', $out );
		$this->assertStringNotContainsString( 'Harness Page', $out );
	}

	/**
	 * The most-emailed pages block lists pages only.
	 */
	public function test_most_lists_pages_when_switched_on() {
		update_option( 'stats_display', array( 'emailed_most_page' => 1 ) );

		$this->log( $this->post_id );
		$this->log( $this->page_id );

		$out = $this->stats->most( '' );

		$this->assertStringContainsString( 'Harness Page', $out );
		$this->assertStringNotContainsString( 'Harness Post', $out );
	}

	/**
	 * Both blocks appear when both toggles are on.
	 */
	public function test_most_lists_both_when_both_are_on() {
		update_option(
			'stats_display',
			array(
				'emailed_most_post' => 1,
				'emailed_most_page' => 1,
			)
		);

		$this->log( $this->post_id );
		$this->log( $this->page_id );

		$out = $this->stats->most( '' );

		$this->assertStringContainsString( 'Harness Post', $out );
		$this->assertStringContainsString( 'Harness Page', $out );
	}

	// ------------------------------------------------------------- wiring --

	/**
	 * Constructing the class registers all four WP-Stats filters.
	 */
	public function test_the_constructor_registers_its_filters() {
		$stats = new WP_Email_WPStats();

		$this->assertNotFalse( has_filter( 'wp_stats_page_admin_plugins', array( $stats, 'admin_general' ) ) );
		$this->assertNotFalse( has_filter( 'wp_stats_page_admin_most', array( $stats, 'admin_most' ) ) );
		$this->assertNotFalse( has_filter( 'wp_stats_page_plugins', array( $stats, 'general' ) ) );
		$this->assertNotFalse( has_filter( 'wp_stats_page_most', array( $stats, 'most' ) ) );
	}

	/**
	 * The pre-3.0.0 global functions still reach the class.
	 */
	public function test_the_legacy_global_functions_still_work() {
		update_option( 'stats_display', array( 'email' => 1 ) );
		$this->log( $this->post_id );

		$this->assertStringContainsString( 'WP-EMail', email_page_general_stats( '' ) );
		$this->assertStringContainsString( 'wpstats_email', email_page_admin_general_stats( '' ) );
		$this->assertStringContainsString( 'wpstats_emailed_most_post', email_page_admin_most_stats( '' ) );

		update_option( 'stats_display', array( 'emailed_most_post' => 1 ) );
		$this->assertStringContainsString( 'Harness Post', email_page_most_stats( '' ) );
	}
}
