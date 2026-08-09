<?php
/**
 * Uninstall WP-EMail.
 *
 * Runs with the plugin inactive, so nothing here may assume the plugin has
 * booted. The three class files it needs are required explicitly and declare
 * nothing but a class, which is also what keeps the table work -- and the
 * query that finds the flood records, whose names are hashes -- inside
 * includes/ where the rest of the plugin's schema lives.
 *
 * @package WP-EMail
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/includes/class-wp-email-admin.php';
require_once __DIR__ . '/includes/class-wp-email-logs.php';
require_once __DIR__ . '/includes/class-wp-email-form.php';

/**
 * Delete the plugin's options for the current site.
 *
 * The list is longer than the plugin now writes: 3.0.0 folded eighteen rows
 * into wp_email_options and wp_email_version, but an install that never reached
 * the migration still has the originals, and uninstall has to clear those too.
 *
 * The two WP-Stats rows this plugin used to read -- stats_display and
 * stats_mostlimit -- are deliberately absent. They were shared with six other
 * plugins rather than owned, so removing WP-EMail must not clear a setting a
 * sibling is still reading. The migration deletes them once it has folded them
 * in; uninstall never touches them.
 *
 * @return void
 */
function wp_email_delete_options() {
	$option_names = array(
		'wp_email_options',
		'wp_email_version',
		'widget_email',
		'widget_email_most_emailed',
		// Pre-3.0.0 rows.
		'email_options',
		'email_db_version',
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
	);

	foreach ( $option_names as $option_name ) {
		delete_option( $option_name );
	}

	WP_Email_Form::delete_flood_records();
}


/**
 * Take the plugin's capability back off every role that has it.
 *
 * @return void
 */
function wp_email_remove_capability() {
	// One expression, asked and answered: the capability the screens check is
	// the one granted and the one taken back. Testing the constant while
	// removing the filtered value would walk straight past a site that filters
	// wp_email_capability, leaving a capability nothing removes.
	$capability = WP_Email_Admin::capability();

	foreach ( wp_roles()->role_objects as $role ) {
		if ( $role->has_cap( $capability ) ) {
			$role->remove_cap( $capability );
		}
	}
}

if ( is_multisite() ) {
	// 'number' => 0 lifts WP_Site_Query's default cap of 100, which would
	// otherwise leave the options and the table behind on every site past the
	// hundredth while still reporting a clean uninstall.
	$wp_email_site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);

	foreach ( $wp_email_site_ids as $wp_email_site_id ) {
		switch_to_blog( (int) $wp_email_site_id );

		wp_email_delete_options();
		WP_Email_Logs::drop_table();
		wp_email_remove_capability();

		// Inside the loop: switch_to_blog() pushes onto a stack, so restoring
		// once after the loop leaves it unwound by exactly one.
		restore_current_blog();
	}
} else {
	wp_email_delete_options();
	WP_Email_Logs::drop_table();
	wp_email_remove_capability();
}
