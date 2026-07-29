<?php
/**
 * WP-EMail standalone e-mail page.
 *
 * Loaded by WP_Email::maybe_render_email_page() when the /email/ endpoint is hit.
 * Renders through the theme so the page inherits the site's header, footer and
 * styling.
 *
 * @package WP-EMail
 */

defined( 'ABSPATH' ) || exit;

add_action( 'wp_head', array( 'WP_Email', 'noindex' ) );
add_filter( 'wp_title', array( 'WP_Email', 'filter_page_title' ) );
add_filter( 'document_title_parts', 'email_document_title_parts' );
add_action( 'loop_start', array( 'WP_Email', 'add_filters' ) );
add_filter( 'comments_open', '__return_false' );

/**
 * Mark the document title as the e-mail page.
 *
 * Themes using add_theme_support( 'title-tag' ) go through this filter rather
 * than wp_title(), which has been deprecated since WordPress 4.4.
 *
 * @param array $parts Title parts.
 *
 * @return array
 */
function email_document_title_parts( $parts ) {
	if ( isset( $parts['title'] ) ) {
		$parts['title'] .= ' &raquo; ' . __( 'E-Mail', 'wp-email' );
	}

	return $parts;
}

$email_template = locate_template( 'email.php' );

if ( ! $email_template ) {
	$email_template = get_page_template();
}

if ( ! $email_template ) {
	$email_template = get_single_template();
}

if ( ! $email_template ) {
	$email_template = get_index_template();
}

if ( $email_template ) {
	require $email_template;
}
