<?php
/**
 * Uninstall WP-EMail.
 *
 * Runs with the plugin inactive, so nothing here may depend on the plugin's
 * own classes or functions being loaded.
 *
 * @package WP-EMail
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit();
}

/**
 * Delete the plugin's options for the current site.
 *
 * The list is longer than the plugin now writes: 3.0.0 folded fifteen rows
 * into 'email_options', but an install that never reached the migration still
 * has the originals, and uninstall has to clear those too.
 *
 * @return void
 */
function email_delete_options() {
	$option_names = array(
		'email_options',
		'email_db_version',
		'widget_email',
		// Pre-3.0.0 rows.
		'email_contenttype',
		'email_snippet',
		'email_interval',
		'email_multiple',
		'email_imageverify',
		'email_fields',
		'email_template_title',
		'email_template_subtitle',
		'email_template_subject',
		'email_template_body',
		'email_template_bodyalt',
		'email_template_sentsuccess',
		'email_template_sentfailed',
		'email_template_error',
		'email_smtp',
		'email_mailer',
		'widget_email_most_emailed',
	);

	foreach ( $option_names as $option_name ) {
		delete_option( $option_name );
	}
}

/**
 * Drop the plugin's table for the current site.
 *
 * @return void
 */
function email_drop_table() {
	global $wpdb;

	// Built entirely from $wpdb->prefix and a literal, so there is no user
	// input to prepare. Identifiers cannot be bound anyway.
	$table = $wpdb->prefix . 'email';

	$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );
}

/**
 * Take the manage_email capability back off every role that has it.
 *
 * @return void
 */
function email_remove_capability() {
	foreach ( wp_roles()->role_objects as $role ) {
		if ( $role->has_cap( 'manage_email' ) ) {
			$role->remove_cap( 'manage_email' );
		}
	}
}

if ( is_multisite() ) {
	// 'number' => 0 lifts WP_Site_Query's default cap of 100, which would
	// otherwise leave the options and the table behind on every site past the
	// hundredth while still reporting a clean uninstall.
	$site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);

	foreach ( $site_ids as $site_id ) {
		switch_to_blog( (int) $site_id );

		email_delete_options();
		email_drop_table();
		email_remove_capability();

		// Inside the loop: switch_to_blog() pushes onto a stack, so restoring
		// once after the loop leaves it unwound by exactly one.
		restore_current_blog();
	}
} else {
	email_delete_options();
	email_drop_table();
	email_remove_capability();
}
