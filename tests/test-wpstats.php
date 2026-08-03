<?php
/**
 * The WP-Stats integration: one filter, one entry, one rendered block.
 *
 * @package WP-EMail
 */

/**
 * The wp_stats_sections contributor.
 *
 * @covers WP_Email_WPStats
 */
class WP_Email_WPStats_Test extends WP_Email_TestCase {

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

		$this->set_stats_options( true, 5 );
	}

	/**
	 * Write the two WP-Stats settings into the plugin's own row.
	 *
	 * @param bool $display Whether the section is contributed.
	 * @param int  $limit   How many entries the lists show.
	 *
	 * @return void
	 */
	private function set_stats_options( $display, $limit ) {
		$options                     = WP_Email_Options::defaults();
		$options['stats_display']    = $display;
		$options['stats_most_limit'] = $limit;

		WP_Email_Options::update( $options );
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

	/**
	 * Capture what render() echoes.
	 *
	 * @return string
	 */
	private function rendered() {
		ob_start();
		$this->stats->render();

		return (string) ob_get_clean();
	}


	public function test_the_section_is_keyed_by_the_plugin_slug_with_underscores() {
		$sections = $this->stats->register_section( array() );

		$this->assertArrayHasKey( 'wp_email', $sections, 'This plugin adds its own section entry.' );
	}

	public function test_the_entry_carries_a_title_a_priority_and_a_callable_render() {
		$section = $this->stats->register_section( array() )['wp_email'];

		$this->assertIsString( $section['title'], 'The section entry carries a title string.' );
		$this->assertNotSame( '', $section['title'], 'The entry is titled.' );
		$this->assertIsInt( $section['priority'], 'The section entry carries an integer priority.' );
		$this->assertTrue( is_callable( $section['render'] ), 'The section entry carries something callable to render with.' );
	}

	public function test_other_contributors_survive() {
		$sections = $this->stats->register_section( array( 'wp_polls' => array( 'title' => 'Polls' ) ) );

		$this->assertArrayHasKey( 'wp_polls', $sections, 'A sibling plugin entry survives this one being added.' );
		$this->assertArrayHasKey( 'wp_email', $sections, 'This plugin entry is added alongside it.' );
	}

	public function test_an_opted_out_site_contributes_nothing() {
		$this->set_stats_options( false, 5 );

		$this->assertSame( array(), $this->stats->register_section( array() ), 'An opted out site contributes nothing to register.' );
	}

	public function test_a_non_array_filter_value_is_tolerated() {
		$this->assertArrayHasKey( 'wp_email', $this->stats->register_section( 'nonsense' ), 'A non-array from an earlier filter is replaced rather than fatal.' );
	}

	public function test_the_constructor_registers_only_the_sections_filter() {
		$stats = new WP_Email_WPStats();

		$this->assertNotFalse( has_filter( 'wp_stats_sections', array( $stats, 'register_section' ) ), 'The constructor registers the sections filter.' );

		foreach ( array( 'wp_stats_display_defaults', 'wp_stats_page_plugins', 'wp_stats_page_most', 'wp_stats_page_admin_plugins', 'wp_stats_page_admin_most' ) as $retired ) {
			$this->assertFalse( has_filter( $retired, array( $stats, 'register_section' ) ), 'The constructor registers ' . $retired . ', which was retired.' );
		}
	}

	public function test_the_filter_is_hooked_without_probing_for_wp_stats() {
		$this->assertNotFalse( has_filter( 'wp_stats_sections' ), 'The sections filter is attached once the plugin is constructed.' );
		$this->assertStringNotContainsString( 'class_exists', file_get_contents( dirname( __DIR__ ) . '/includes/class-wp-email-wpstats.php' ), 'The filter is hooked without probing for the class.' );
		$this->assertStringNotContainsString( 'function_exists', file_get_contents( dirname( __DIR__ ) . '/includes/class-wp-email-wpstats.php' ), 'Or for a function, because a filter nobody calls costs nothing.' );
	}


	public function test_render_echoes_rather_than_returns() {
		ob_start();
		$returned = $this->stats->render();
		$echoed   = (string) ob_get_clean();

		$this->assertNull( $returned, 'Rendering the section prints rather than returning, as WP-Stats expects.' );
		$this->assertNotSame( '', $echoed, 'The renderer echoes rather than returning.' );
	}

	public function test_render_reports_the_totals() {
		$this->log( $this->post_id );
		$this->log( $this->post_id );
		$this->log( $this->post_id, WP_Email_Logs::STATUS_FAILED );

		$out = $this->rendered();

		$this->assertStringContainsString( '<strong>3</strong> emails were sent.', $out, 'The total is reported.' );
		$this->assertStringContainsString( '<strong>2</strong> emails were sent successfully.', $out, 'The successes.' );
		$this->assertStringContainsString( '<strong>1</strong> email failed to send.', $out, 'And the failures.' );
	}

	public function test_render_counts_untranslated_statuses() {
		// Rows written before 3.0.0 held __( 'Success' ); the upgrade rewrites
		// them, and the counts read the canonical value.
		$this->log( $this->post_id );

		$this->assertStringContainsString( '<strong>1</strong> email was sent successfully.', $this->rendered(), 'A row stored untranslated is still counted.' );
	}

	public function test_render_lists_the_most_emailed_posts_and_pages() {
		$this->log( $this->post_id );
		$this->log( $this->page_id );

		$out = $this->rendered();

		$this->assertStringContainsString( 'Most Emailed Post', $out, 'The posts listing is headed for posts.' );
		$this->assertStringContainsString( 'Most Emailed Page', $out, 'And the pages listing for pages.' );
		$this->assertStringContainsString( 'Harness Post', $out, 'The post is listed.' );
		$this->assertStringContainsString( 'Harness Page', $out, 'And the page, so neither has taken the other rows.' );
	}

	public function test_render_uses_the_plugins_own_copy_of_the_most_limit() {
		$this->set_stats_options( true, 3 );

		$this->assertStringContainsString( '3 Most Emailed Posts', $this->rendered(), 'The heading counts from the settings of this plugin, not a shared row.' );
	}
}
