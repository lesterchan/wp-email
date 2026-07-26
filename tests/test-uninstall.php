<?php
/**
 * Uninstall.
 *
 * The multisite branch is asserted against the source rather than executed: a
 * single-site test suite cannot stand up a 101-site network, and the bug being
 * guarded against only shows up past the hundredth site. A source-level guard
 * is weaker than a behavioural one, but it does stop the argument being
 * dropped again, which is exactly how it went missing in the first place.
 *
 * @package WP-EMail
 */

/**
 * Uninstall.
 *
 * @coversNothing
 */
class Test_Email_Uninstall extends WP_UnitTestCase {

	/**
	 * The uninstall script's source.
	 *
	 * @return string
	 */
	private function source() {
		return file_get_contents( dirname( __DIR__ ) . '/uninstall.php' );
	}

	/**
	 * It refuses to run outside an uninstall.
	 */
	public function test_it_refuses_to_run_outside_an_uninstall() {
		$this->assertStringContainsString( 'WP_UNINSTALL_PLUGIN', $this->source() );
	}

	/**
	 * Get_sites() defaults to 100, silently orphaning data on larger networks.
	 */
	public function test_it_lifts_the_site_query_row_cap() {
		// get_sites() defaults 'number' to 100, so without this the options and
		// the table are left behind on every site past the hundredth and the
		// uninstall still reports success.
		$this->assertMatchesRegularExpression( "/'number'\s*=>\s*0/", $this->source() );
	}

	/**
	 * Removed in WordPress 5.1; calling it fatals rather than skipping.
	 */
	public function test_it_does_not_call_the_removed_wp_get_sites() {
		// Removed in WordPress 5.1; calling it fatals rather than skipping.
		$this->assertStringNotContainsString( 'wp_get_sites', $this->source() );
	}

	/**
	 * Switch_to_blog() pushes onto a stack that one restore cannot unwind.
	 */
	public function test_it_restores_the_blog_inside_the_loop() {
		$source = $this->source();

		$loop_body = substr(
			$source,
			strpos( $source, 'foreach ( $site_ids' ),
			strpos( $source, '} else {' ) - strpos( $source, 'foreach ( $site_ids' )
		);

		// switch_to_blog() pushes onto a stack, so restoring once after the
		// loop leaves it unwound by exactly one.
		$this->assertStringContainsString( 'restore_current_blog', $loop_body );
	}

	/**
	 * It only fetches the ids it needs.
	 */
	public function test_it_only_fetches_the_ids_it_needs() {
		$this->assertMatchesRegularExpression( "/'fields'\s*=>\s*'ids'/", $this->source() );
	}

	/**
	 * It drops the table once per site not once per option.
	 */
	public function test_it_drops_the_table_once_per_site_not_once_per_option() {
		$source = $this->source();

		// The old version called the drop inside the option loop, so it ran
		// eighteen times per site.
		$this->assertSame( 1, substr_count( $source, 'DROP TABLE' ) );
	}

	/**
	 * It clears the consolidated option and the legacy rows.
	 */
	public function test_it_clears_the_consolidated_option_and_the_legacy_rows() {
		$source = $this->source();

		$this->assertStringContainsString( "'email_options'", $source );
		$this->assertStringContainsString( "'email_db_version'", $source );

		// An install that never reached the migration still has the originals.
		foreach ( Email_Options::legacy_option_names() as $name ) {
			$this->assertStringContainsString( "'{$name}'", $source, "uninstall.php should clear {$name}" );
		}
	}

	/**
	 * It takes the capability back.
	 */
	public function test_it_takes_the_capability_back() {
		$this->assertStringContainsString( 'remove_cap', $this->source() );
	}

	/**
	 * Uninstalling a single site clears everything.
	 */
	public function test_uninstalling_a_single_site_clears_everything() {
		global $wpdb;

		if ( is_multisite() ) {
			$this->markTestSkipped( 'Single-site path only.' );
		}

		Email_Logs::insert(
			array(
				'yourname'    => 'Alice',
				'youremail'   => 'a@example.com',
				'yourremarks' => '',
				'friendname'  => 'F',
				'friendemail' => 'f@example.com',
				'postid'      => 1,
				'posttitle'   => 'T',
				'timestamp'   => time(),
				'ip'          => '198.51.100.1',
				'host'        => '',
				'status'      => Email_Logs::STATUS_SUCCESS,
			)
		);

		// The test suite rewrites CREATE/DROP TABLE to their TEMPORARY forms so
		// each test rolls back. The plugin's table is real -- bootstrap.php
		// installs it before those filters matter -- so DROP TEMPORARY TABLE
		// would quietly do nothing here.
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );

		define( 'WP_UNINSTALL_PLUGIN', 'wp-email/wp-email.php' );

		require dirname( __DIR__ ) . '/uninstall.php';

		$this->assertFalse( get_option( Email_Options::OPTION ) );
		$this->assertFalse( get_option( Email_Options::VERSION_OPTION ) );
		$this->assertFalse( get_role( 'administrator' )->has_cap( 'manage_email' ) );

		$table = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->email ) );
		$this->assertNull( $table );

		// Put the schema back for whatever runs next; the transaction rollback
		// does not cover DROP TABLE.
		Email_Logs::install();
		get_role( 'administrator' )->add_cap( 'manage_email' );
	}
}
