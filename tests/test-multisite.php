<?php
/**
 * Network activation: the install has to reach every site.
 *
 * Only runs under WP_MULTISITE=1 (see bin/test-multisite.sh). The log table,
 * the capability grant and the settings rows are all per-site artifacts, so an
 * activation that installs only on whichever site happened to be current
 * leaves every other site without a table to log into and with administrators
 * who cannot open a single screen. The loop that prevents this was never
 * covered: on a single site the network branch is dead code, and a subsite
 * only heals itself the first time somebody opens its dashboard -- which a
 * network of front-end-only subsites never does.
 *
 * @package WP-EMail
 */

/**
 * WP_Email::activate() across a network.
 *
 * @group ms-required
 */
class WP_Email_Multisite_Test extends WP_Email_TestCase {

	/**
	 * Skip the whole class on a single site install.
	 *
	 * @return void
	 */
	public function set_up() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Requires a multisite install. Run bin/test-multisite.sh.' );
		}

		parent::set_up();
	}

	/**
	 * Create extra sites with the plugin's artifacts torn down.
	 *
	 * Torn down so activation has something to do: a leftover table or grant
	 * would let a loop that never reaches the site pass anyway. The drop is
	 * rewritten to its TEMPORARY form by the harness, which is enough -- a
	 * fresh site has no real table, only whatever an earlier test's activation
	 * left in the session.
	 *
	 * @param int $count How many sites to create.
	 * @return int[] Site IDs.
	 */
	protected function make_torn_down_sites( $count = 2 ) {
		global $wpdb;

		$site_ids = array();

		for ( $i = 0; $i < $count; $i++ ) {
			$site_ids[] = (int) self::factory()->blog->create();
		}

		foreach ( $site_ids as $site_id ) {
			switch_to_blog( $site_id );

			$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->email}" );

			$role = get_role( 'administrator' );
			if ( $role instanceof WP_Role ) {
				$role->remove_cap( WP_Email_Admin::capability() );
			}

			delete_option( WP_Email_Options::OPTION );
			delete_option( WP_Email_Options::VERSION );

			restore_current_blog();
		}

		WP_Email_Options::flush();

		return $site_ids;
	}

	/**
	 * Network activation installs on every site, not just the current one.
	 *
	 * @return void
	 */
	public function test_network_activation_installs_on_every_site() {
		global $wpdb;

		$site_ids = $this->make_torn_down_sites( 2 );

		WP_Email::get_instance()->activate( true );

		foreach ( $site_ids as $site_id ) {
			switch_to_blog( $site_id );

			// Both sides have to be read inside the switch: $wpdb->email is
			// re-prefixed on the way back out, and the role set is per-site.
			$expected = WP_Email_Logs::table();
			$exists   = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $expected ) );
			$role     = get_role( 'administrator' );
			$granted  = $role instanceof WP_Role && $role->has_cap( WP_Email_Admin::capability() );

			restore_current_blog();

			$this->assertSame( $expected, $exists, "Site {$site_id} did not get its log table." );
			$this->assertTrue( $granted, "Site {$site_id}'s administrators were never granted the capability." );
		}
	}

	/**
	 * Activating on one site does not touch the rest of the network.
	 *
	 * @return void
	 */
	public function test_single_site_activation_leaves_other_sites_alone() {
		global $wpdb;

		$site_ids = $this->make_torn_down_sites( 1 );
		$other    = $site_ids[0];

		WP_Email::get_instance()->activate( false );

		switch_to_blog( $other );

		$exists  = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', WP_Email_Logs::table() ) );
		$role    = get_role( 'administrator' );
		$granted = $role instanceof WP_Role && $role->has_cap( WP_Email_Admin::capability() );

		restore_current_blog();

		$this->assertNull( $exists, "A per-site activation installed the log table on site {$other}." );
		$this->assertFalse( $granted, "A per-site activation granted the capability on site {$other}." );
	}

	/**
	 * The site query is uncapped and asks only for IDs.
	 *
	 * Asserted by reading the arguments the query was given rather than by
	 * building a 101 site fixture: get_sites() defaults to 100, so a larger
	 * network silently skips every site past the hundredth, and the cheap
	 * version of that assertion is the only one worth running per suite.
	 *
	 * @return void
	 */
	public function test_network_activation_queries_sites_without_a_cap() {
		$this->make_torn_down_sites( 2 );

		$captured = array();
		add_action(
			'pre_get_sites',
			function ( $query ) use ( &$captured ) {
				$captured[] = $query->query_vars;
			}
		);

		WP_Email::get_instance()->activate( true );

		$this->assertNotEmpty( $captured, 'Activation never queried the site list.' );
		$this->assertSame( 0, (int) $captured[0]['number'], 'get_sites() was left at its default cap of 100 sites.' );
		$this->assertSame( 'ids', $captured[0]['fields'], 'Only the site IDs are needed.' );
	}

	/**
	 * The blog stack is left unwound and the original site is current.
	 *
	 * Calling switch_to_blog() pushes onto a stack. Restoring once after the loop
	 * rather than once per iteration leaves the stack short, so whatever runs next
	 * operates against the last site visited instead of the one it thinks it is on.
	 *
	 * @return void
	 */
	public function test_network_activation_unwinds_the_blog_stack() {
		$original = get_current_blog_id();
		$this->make_torn_down_sites( 2 );

		WP_Email::get_instance()->activate( true );

		$this->assertFalse( ms_is_switched(), 'The blog stack was left switched.' );
		$this->assertSame( $original, get_current_blog_id(), 'The original site is no longer current.' );
	}
}
