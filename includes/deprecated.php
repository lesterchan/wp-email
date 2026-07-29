<?php
/**
 * WP-EMail backwards compatibility shims.
 *
 * Before 3.0.0 the plugin defined a handful of helpers at global scope under
 * unprefixed names, each wrapped in function_exists() because wp-print and
 * wp-postratings define some of the same ones and whichever plugin loaded
 * first won. The logic now lives in the plugin's classes; these wrappers stay
 * so nothing that depended on that arrangement breaks.
 *
 * Still guarded, and for the same reason: a sibling plugin may already have
 * defined the name.
 *
 * @package WP-EMail
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'get_ipaddress' ) ) {
	/**
	 * The visitor's IP address.
	 *
	 * @return string
	 */
	function get_ipaddress() {
		return WP_Email_Form::ip_address();
	}
}

if ( ! function_exists( 'is_valid_name' ) ) {
	/**
	 * Whether a string is acceptable as a person's name.
	 *
	 * @param string $name Candidate.
	 *
	 * @return bool
	 */
	function is_valid_name( $name ) {
		return WP_Email_Form::is_valid_name( $name );
	}
}

if ( ! function_exists( 'is_valid_email' ) ) {
	/**
	 * Whether a string is an e-mail address.
	 *
	 * @param string $email Candidate.
	 *
	 * @return bool
	 */
	function is_valid_email( $email ) {
		return WP_Email_Form::is_valid_email( $email );
	}
}

if ( ! function_exists( 'is_valid_remarks' ) ) {
	/**
	 * Whether a string is free of mail-header injection attempts.
	 *
	 * @param string $content Candidate.
	 *
	 * @return bool
	 */
	function is_valid_remarks( $content ) {
		return WP_Email_Form::is_valid_remarks( $content );
	}
}

if ( ! function_exists( 'snippet_words' ) ) {
	/**
	 * Trim text to a number of words.
	 *
	 * @param string $text   Text to trim.
	 * @param int    $length Word count.
	 *
	 * @return string
	 */
	function snippet_words( $text, $length = 0 ) {
		return WP_Email_Template::words( $text, $length );
	}
}

if ( ! function_exists( 'snippet_text' ) ) {
	/**
	 * Trim text to a number of characters.
	 *
	 * @param string $text   Text to trim.
	 * @param int    $length Character count.
	 *
	 * @return string
	 */
	function snippet_text( $text, $length = 0 ) {
		return WP_Email_Template::characters( $text, $length );
	}
}

/**
 * Handle a submitted e-mail form.
 *
 * The AJAX callback is registered as WP_Email_Form::process() now. This wrapper
 * only helps code that called the function directly -- anything doing
 * remove_action( 'wp_ajax_email', 'process_email_form' ) needs the
 * wp_email_template_redirect filter or its own unhook of the new callback.
 *
 * @return void
 */
function process_email_form() {
	WP_Email_Form::process();
}

/**
 * Add the filters that turn a post into the e-mail form.
 *
 * @param WP_Query|null $query Query being started, as loop_start passes it.
 *
 * @return void
 */
function email_addfilters( $query = null ) {
	WP_Email::add_filters( $query );
}

/**
 * Remove them again.
 *
 * @return void
 */
function email_removefilters() {
	WP_Email::remove_filters();
}

/**
 * Emit the noindex robots meta tag.
 *
 * @return void
 */
function email_meta_nofollow() {
	WP_Email::noindex();
}
