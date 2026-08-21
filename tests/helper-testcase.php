<?php
/**
 * Shared base class for the WP-EMail tests.
 *
 * @package WP-EMail
 */

/**
 * Resets the plugin's stored state between tests and provides the fixtures
 * every area needs: a settings writer, a log-row writer and the site-local
 * clock the email_timestamp column is measured against.
 */
abstract class WP_Email_TestCase extends WP_UnitTestCase {

	/**
	 * Creates a user who may actually reach the plugin's screens.
	 *
	 * The screens take `manage_email`, the plugin's own capability, which
	 * activation adds to the administrator role. map_meta_cap() only remaps the
	 * capabilities core knows about, so a custom one means the same thing on a
	 * network as on a single site and no grant_super_admin() is wanted. Tests
	 * that revoke the capability to assert the refusal do so explicitly.
	 *
	 * Every administrator the suite creates goes through this, so the network
	 * question is answered in one place rather than at each call site. Tests
	 * that assert the *unprivileged* path set their own subscriber or editor
	 * explicitly and must not be routed through here.
	 *
	 * @return int The new user's ID.
	 */
	protected function create_admin() {
		return self::factory()->user->create( array( 'role' => 'administrator' ) );
	}

	/**
	 * Set up.
	 */
	public function set_up() {
		parent::set_up();

		$_SERVER['REMOTE_ADDR'] = '198.51.100.5';

		delete_option( WP_Email_Options::OPTION );
		delete_option( WP_Email_Options::VERSION );

		foreach ( WP_Email_Options::LEGACY_ROWS as $legacy ) {
			delete_option( $legacy );
		}

		// Shared with six sibling plugins rather than owned, so they are cleared
		// here for the migration tests and never by uninstall.php.
		delete_option( 'stats_display' );
		delete_option( 'stats_mostlimit' );

		WP_Email_Options::flush();
	}

	/**
	 * Tear down.
	 */
	public function tear_down() {
		WP_Email_Options::flush();

		parent::tear_down();
	}

	/**
	 * Run the uninstaller, repeatably.
	 *
	 * The uninstaller does its work in the file body, and PHP will not run a file
	 * body twice - so the first caller in a process would get the real thing
	 * and every later one silently nothing at all. The require is therefore
	 * only there to guarantee the function exists, and the fan-out is
	 * driven from here: the same loop the file itself runs, with the same
	 * arguments, calling the same per-site verb. That the file's own
	 * copy of the loop is correct is asserted against the source in
	 * test-uninstall.php, which is where it belongs.
	 *
	 * The log table survives this under the harness, which rewrites DROP TABLE
	 * into its TEMPORARY form; the test that actually means to prove the drop
	 * removes that filter first.
	 *
	 * @return void
	 */
	protected function run_uninstall() {
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'wp-email/wp-email.php' );
		}

		require_once dirname( __DIR__ ) . '/uninstall.php';

		if ( is_multisite() ) {
			$site_ids = get_sites(
				array(
					'fields' => 'ids',
					'number' => 0,
				)
			);

			foreach ( $site_ids as $site_id ) {
				switch_to_blog( (int) $site_id );
				wp_email_uninstall_site();
				restore_current_blog();
			}

			return;
		}

		wp_email_uninstall_site();
	}

	/**
	 * Write settings over the defaults.
	 *
	 * Written raw rather than through update_option(), so the sanitize callback
	 * is only in play in the tests that are actually about it. The markers are
	 * written too, which marks the install as already upgraded so a later
	 * maybe_upgrade() leaves what the test just wrote alone.
	 *
	 * @param array $options Partial options, merged one group deep.
	 * @return void
	 */
	protected function set_options( $options ) {
		$merged = WP_Email_Options::defaults();

		foreach ( $options as $group => $values ) {
			$merged[ $group ] = ( is_array( $values ) && isset( $merged[ $group ] ) && is_array( $merged[ $group ] ) )
				? array_merge( $merged[ $group ], $values )
				: $values;
		}

		update_option( WP_Email_Options::OPTION, $merged );

		update_option(
			WP_Email_Options::VERSION,
			array(
				'plugin' => WP_EMAIL_VERSION,
				'db'     => WP_EMAIL_DB_VERSION,
			)
		);

		WP_Email_Options::flush();
	}

	/**
	 * Now, in the site's local time.
	 *
	 * The email_timestamp column has held local timestamps since 2.x, so a
	 * fixture has to match. Written out rather than asking current_time() for a
	 * 'timestamp', which is not a Unix timestamp and which WPCS rejects.
	 *
	 * @return int
	 */
	protected function local_timestamp() {
		return time() + (int) ( (float) get_option( 'gmt_offset' ) * HOUR_IN_SECONDS );
	}
}
