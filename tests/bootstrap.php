<?php
/**
 * PHPUnit bootstrap for WP-EMail.
 *
 * Runs inside the wp-env "tests" container, where WP_TESTS_DIR is already
 * exported and the WordPress test library is present.
 *
 * @package WP-EMail
 */

$_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( ! $_tests_dir ) {
	$_tests_dir = '/wordpress-phpunit';
}

if ( ! file_exists( $_tests_dir . '/includes/functions.php' ) ) {
	echo "Could not find the WordPress test library at {$_tests_dir}." . PHP_EOL;
	echo 'Run the suite through wp-env: bash bin/test.sh' . PHP_EOL;
	exit( 1 );
}

require_once $_tests_dir . '/includes/functions.php';

/**
 * Load the plugin under test before WordPress finishes booting.
 *
 * @return void
 */
function _wp_email_manually_load_plugin() {
	require dirname( __DIR__ ) . '/wp-email.php';
}
tests_add_filter( 'muplugins_loaded', '_wp_email_manually_load_plugin' );

require $_tests_dir . '/includes/bootstrap.php';

// After the test library, not before: the two base classes extend
// WP_UnitTestCase and WP_Ajax_UnitTestCase, neither of which exists until the
// bootstrap above has run. Required by name rather than found by a glob, so a
// helper is never silently left unloaded when discovery changes.
require_once __DIR__ . '/helper-testcase.php';
require_once __DIR__ . '/helper-ajax-testcase.php';
require_once __DIR__ . '/helper-source.php';

// The shared metadata contract. helper-metadata-testcase.php is a byte-identical
// copy of _standards/templates/helper-metadata-testcase.php in all nineteen
// plugins, so it cannot name WP_Email_TestCase; it extends Plugin_TestCase and
// this alias is the one per-plugin line the mechanism needs.
class_alias( 'WP_Email_TestCase', 'Plugin_TestCase' );
require_once __DIR__ . '/helper-metadata-testcase.php';

// The activation hook does not fire under the test bootstrap, so create the
// table and seed the options the way a real install would.
WP_Email::get_instance()->install();
