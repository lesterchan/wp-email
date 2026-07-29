<?php
/**
 * Base class for the tests that drive the plugin's AJAX endpoints.
 *
 * Identical in intent to WP_Email_TestCase, but rooted in
 * WP_Ajax_UnitTestCase: the send endpoint ends in wp_die(), and only that base
 * class installs the handler which turns it into a catchable exception rather
 * than taking the test runner with it. PHP has no multiple inheritance, so the
 * small shared surface is repeated here rather than made into a trait nothing
 * else would use.
 *
 * @package WP-EMail
 */

/**
 * Resets the plugin's stored state between AJAX tests.
 */
abstract class WP_Email_Ajax_TestCase extends WP_Ajax_UnitTestCase {

	/**
	 * Set up.
	 */
	public function set_up() {
		parent::set_up();

		$_SERVER['REMOTE_ADDR'] = '198.51.100.5';

		delete_option( WP_Email_Options::OPTION );
		delete_option( WP_Email_Options::VERSION );

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
	 * @return int
	 */
	protected function local_timestamp() {
		return time() + (int) ( (float) get_option( 'gmt_offset' ) * HOUR_IN_SECONDS );
	}
}
