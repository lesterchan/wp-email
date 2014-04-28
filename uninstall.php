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

if ( is_multisite() ) {
	$ms_sites = wp_get_sites();

	if( 0 < sizeof( $ms_sites ) ) {
		foreach ( $ms_sites as $ms_site ) {
			switch_to_blog( $ms_site['blog_id'] );
			if( sizeof( $option_names ) > 0 ) {
				foreach( $option_names as $option_name ) {
					delete_option( $option_name );
					plugin_uninstalled();
				}
			}
		}
	}

	restore_current_blog();
} else {
	if( sizeof( $option_names ) > 0 ) {
		foreach( $option_names as $option_name ) {
			delete_option( $option_name );
			plugin_uninstalled();
		}
	}
}

/**
 * Delete plugin table when uninstalled
 *
 * @access public
 * @return void
 */
function plugin_uninstalled() {
	global $wpdb;

	$email_table = $wpdb->prefix . 'email';
	$wpdb->query( "DROP TABLE IF EXISTS $email_table" );
}