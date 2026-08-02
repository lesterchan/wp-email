<?php
/**
 * Plugin Name: WP-EMail
 * Plugin URI: https://lesterchan.net/portfolio/programming/php/
 * Description: Allows people to recommend/send your WordPress blog's post/page to a friend.
 * Version: 3.0.0
 * Requires at least: 6.8
 * Requires PHP: 8.2
 * Author: Lester 'GaMerZ' Chan
 * Author URI: https://lesterchan.net
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: wp-email
 * Domain Path: /languages
 *
 * @package WP-EMail
 */

/*
	Copyright 2026  Lester Chan  (email : lesterchan@gmail.com)

	This program is free software; you can redistribute it and/or modify
	it under the terms of the GNU General Public License as published by
	the Free Software Foundation; either version 2 of the License, or
	(at your option) any later version.

	This program is distributed in the hope that it will be useful,
	but WITHOUT ANY WARRANTY; without even the implied warranty of
	MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
	GNU General Public License for more details.

	You should have received a copy of the GNU General Public License
	along with this program; if not, write to the Free Software
	Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301  USA
*/

defined( 'ABSPATH' ) || exit;

/**
 * WP-EMail version. The last-run value is kept in the wp_email_version row.
 */
define( 'WP_EMAIL_VERSION', '3.0.0' );

/**
 * Schema counter. Bumped only when the stored rows or the table need reshaping.
 */
define( 'WP_EMAIL_DB_VERSION', '2' );

/**
 * WP-EMail slug, which is also the text domain.
 */
define( 'WP_EMAIL_SLUG', 'wp-email' );

/**
 * WP-EMail main file.
 */
define( 'WP_EMAIL_MAIN_FILE', __FILE__ );

/**
 * WP-EMail directory, with a trailing slash.
 */
define( 'WP_EMAIL_DIR', plugin_dir_path( __FILE__ ) );

/**
 * WP-EMail URL, with a trailing slash.
 */
define( 'WP_EMAIL_URL', plugin_dir_url( __FILE__ ) );

/**
 * Whether the logs screen shows the sender's remarks.
 *
 * Guarded so it can be overridden from wp-config.php.
 */
if ( ! defined( 'WP_EMAIL_SHOW_REMARKS' ) ) {
	define( 'WP_EMAIL_SHOW_REMARKS', true );
}

/*
 * Required at file load rather than from a hook: the activation hook, the
 * template tags and the deprecated shims are all reached before any action
 * fires.
 */
require_once WP_EMAIL_DIR . 'includes/class-wp-email-options.php';
require_once WP_EMAIL_DIR . 'includes/class-wp-email-template.php';
require_once WP_EMAIL_DIR . 'includes/class-wp-email-logs.php';
require_once WP_EMAIL_DIR . 'includes/class-wp-email-captcha.php';
require_once WP_EMAIL_DIR . 'includes/class-wp-email-link.php';
require_once WP_EMAIL_DIR . 'includes/class-wp-email-form.php';
require_once WP_EMAIL_DIR . 'includes/class-wp-email-wpstats.php';
require_once WP_EMAIL_DIR . 'includes/class-wp-email-admin.php';
require_once WP_EMAIL_DIR . 'includes/class-wp-email-settings.php';
require_once WP_EMAIL_DIR . 'includes/class-wp-email.php';
require_once WP_EMAIL_DIR . 'includes/template-tags.php';
require_once WP_EMAIL_DIR . 'includes/deprecated.php';

WP_Email::get_instance();
