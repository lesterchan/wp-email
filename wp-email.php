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
	Copyright 2026 Lester Chan  (email : lesterchan@gmail.com)

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
	Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
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
