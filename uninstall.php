<?php
/*
 * Uninstall plugin
 */
if ( !defined( 'WP_UNINSTALL_PLUGIN' ) )
	exit ();

$option_names = array(
	  'email_smtp'
	, 'email_contenttype'
	, 'email_mailer'
	, 'email_template_subject'
	, 'email_template_body'
	, 'email_template_bodyalt'
	, 'email_template_sentsuccess'
	, 'email_template_sentfailed'
	, 'email_template_error'
	, 'email_interval'
	, 'email_snippet'
	, 'email_multiple'
	, 'email_imageverify'
	, 'email_options'
	, 'email_fields'
	, 'email_template_title'
	, 'email_template_subtitle'
	, 'widget_email_most_emailed'
);

/**
 * Delete the plugin's options and drop its table for the current site.
 *
 * @param array $option_names Options to remove.
 *
 * @return void
 */
function email_uninstall_site( $option_names ) {
	global $wpdb;

	foreach ( $option_names as $option_name ) {
		delete_option( $option_name );
	}

	$email_table = $wpdb->prefix . 'email';
	$wpdb->query( "DROP TABLE IF EXISTS `{$email_table}`" );
}

if ( is_multisite() ) {
	// 'number' => 0 lifts WP_Site_Query's default cap of 100, which would
	// otherwise leave the options and the table behind on every site past the
	// hundredth while still reporting a clean uninstall.
	$site_ids = get_sites( array( 'fields' => 'ids', 'number' => 0 ) );

	foreach ( $site_ids as $site_id ) {
		switch_to_blog( (int) $site_id );

		email_uninstall_site( $option_names );

		// Inside the loop: switch_to_blog() pushes onto a stack, so restoring
		// once after the loop leaves it unwound by exactly one.
		restore_current_blog();
	}
} else {
	email_uninstall_site( $option_names );
}