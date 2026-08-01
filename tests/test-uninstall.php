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
class WP_Email_Uninstall_Test extends WP_Email_TestCase {

	/**
	 * The uninstall script's source.
	 *
	 * @return string
	 */
	private function source() {
		return file_get_contents( dirname( __DIR__ ) . '/uninstall.php' );
	}

	public function test_it_refuses_to_run_outside_an_uninstall() {
		$this->assertStringContainsString( 'WP_UNINSTALL_PLUGIN', $this->source() );
	}

	public function test_it_lifts_the_site_query_row_cap() {
		// get_sites() defaults 'number' to 100, so without this the options and
		// the table are left behind on every site past the hundredth and the
		// uninstall still reports success.
		$this->assertMatchesRegularExpression( "/'number'\s*=>\s*0/", $this->source() );
	}

	public function test_it_does_not_call_the_deprecated_wp_get_sites() {
		// Deprecated in WordPress 4.6 and capped at 100 sites, so a larger network
		// uninstalls in part and still reports success.
		$this->assertStringNotContainsString( 'wp_get_sites', $this->source() );
	}

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

	public function test_it_only_fetches_the_ids_it_needs() {
		$this->assertMatchesRegularExpression( "/'fields'\s*=>\s*'ids'/", $this->source() );
	}

	public function test_it_drops_the_table_once_per_site_not_once_per_option() {
		$source = $this->source();

		// The old version called the drop inside the option loop, so it ran
		// eighteen times per site. The statement itself lives on
		// WP_Email_Logs::drop_table() now, so uninstall.php names it once.
		$this->assertSame( 2, substr_count( $source, 'WP_Email_Logs::drop_table()' ) );
	}

	public function test_it_clears_the_consolidated_option_and_the_legacy_rows() {
		$source = $this->source();

		$this->assertStringContainsString( "'wp_email_options'", $source );
		$this->assertStringContainsString( "'wp_email_version'", $source );
		$this->assertStringContainsString( "'email_options'", $source );
		$this->assertStringContainsString( "'email_db_version'", $source );

		// An install that never reached the migration still has the originals.
		foreach ( WP_Email_Options::LEGACY_ROWS as $name ) {
			$this->assertStringContainsString( "'{$name}'", $source, "uninstall.php should clear {$name}" );
		}
	}

	public function test_it_takes_the_capability_back() {
		$this->assertStringContainsString( 'remove_cap', $this->source() );
	}

	/**
	 * The behavioural half of this is reached through run_uninstall().
	 *
	 * The uninstaller declares global functions, so only one file in the suite
	 * may require it -- a second fatals on redeclare, and a require_once that
	 * has already fired is a silent no-op, which proves nothing. That file is
	 * helper-testcase.php, whose run_uninstall() requires it once for the
	 * declarations and then drives the work itself, so every caller gets the
	 * same thing. Nothing else in tests/ may reach for the file directly.
	 */
	public function test_the_uninstaller_is_required_by_exactly_one_file() {
		$requiring = array();

		foreach ( (array) glob( __DIR__ . '/*.php' ) as $file ) {
			if ( preg_match( "#require(_once)?\\s+dirname\\( __DIR__ \\) \\. '/uninstall\\.php'#", (string) file_get_contents( $file ) ) ) {
				$requiring[] = basename( $file );
			}
		}

		$this->assertSame( array( 'helper-testcase.php' ), $requiring );
	}
}
