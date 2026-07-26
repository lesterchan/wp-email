<?php
/**
 * Plugin Name: WP-EMail
 * Plugin URI: https://lesterchan.net/portfolio/programming/php/
 * Description: Allows people to recommend/send your WordPress blog's post/page to a friend.
 * Version: 3.0.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author: Lester 'GaMerZ' Chan
 * Author URI: https://lesterchan.net
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: wp-email
 * Domain Path: /languages
 *
 * @package WP-EMail
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WP-EMail version.
 */
define( 'WP_EMAIL_VERSION', '3.0.0' );

/**
 * Data version. Bump when the table definition or the option layout changes.
 */
define( 'WP_EMAIL_DB_VERSION', '1' );

/**
 * WP-EMail main file.
 */
define( 'WP_EMAIL_MAIN_FILE', __FILE__ );

/**
 * Whether the logs screen shows the sender's remarks.
 *
 * Guarded so it can be overridden from wp-config.php.
 */
if ( ! defined( 'EMAIL_SHOW_REMARKS' ) ) {
	define( 'EMAIL_SHOW_REMARKS', true );
}

require_once __DIR__ . '/includes/class-email-options.php';
require_once __DIR__ . '/includes/class-email-template.php';
require_once __DIR__ . '/includes/class-email-logs.php';
require_once __DIR__ . '/includes/class-email-captcha.php';
require_once __DIR__ . '/includes/class-email-link.php';
require_once __DIR__ . '/includes/class-email-form.php';
require_once __DIR__ . '/includes/class-email.php';
require_once __DIR__ . '/includes/template-tags.php';
require_once __DIR__ . '/includes/deprecated.php';

Email::get_instance();
