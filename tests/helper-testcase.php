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
