<?php
/**
 * WP-EMail's half of the metadata contract.
 *
 * The contract itself is Plugin_Metadata_TestCase, a byte-identical copy of
 * _standards/templates/helper-metadata-testcase.php that every one of the
 * nineteen plugins carries. Everything shared lives there, including the
 * Upgrade Notice assertion this plugin's list of subjects was the model for.
 *
 * What is left is what a machine cannot derive from the directory, plus the
 * handful of assertions that are genuinely about WP-EMail: the five readme
 * tags, the licence block, the Donations paragraph, the text domain on every
 * translation call, the raster glyphs that must never come back, and the log
 * table and capability that uninstall has to take with it.
 *
 * @package WP-EMail
 */

/**
 * The shared contract, plus what only WP-EMail can answer.
 *
 * @coversNothing
 */
class WP_Email_Metadata_Test extends Plugin_Metadata_TestCase {

	/**
	 * The version this release ships.
	 *
	 * Written out rather than read from WP_EMAIL_VERSION, so a bump has to be
	 * made here as well and cannot happen by accident.
	 *
	 * @return string
	 */
	protected function expected_version() {
		return '3.0.0';
	}

	/**
	 * The prefix every class the plugin declares carries.
	 *
	 * @return string
	 */
	protected function class_prefix() {
		return 'WP_Email';
	}

	/**
	 * Everything a site owner updating from the released version would notice.
	 *
	 * The eighteen option rows that were folded up, the renamed form field
	 * array, the settings screen that moved, the capability that replaced
	 * manage_options, the three template variables that no longer exist, the
	 * mirrored stylesheet that is gone, the browser function custom code used
	 * to call, and the shared stats_display row.
	 *
	 * @return string[]
	 */
	protected function upgrade_notice_subjects() {
		return array(
			'6.8',
			'8.2',
			'wp_email_options',
			'wp_email_version',
			'wp_email_form_field_values',
			'wp-email-settings',
			'manage_options',
			'manage_email',
			'%EMAIL_ICON%',
			'%EMAIL_TEXT%',
			'%POST_TYPE%',
			'email-css-rtl.css',
			'email_popup(',
			'stats_display',
		);
	}

	/**
	 * WP-EMail is one of the seven sharing the WP-Stats surface.
	 *
	 * @return bool
	 */
	protected function wp_stats_family() {
		return true;
	}

	/**
	 * The unprefixed WP-Stats rows WP-EMail reads but does not own.
	 *
	 * @return string[]
	 */
	protected function shared_wp_stats_rows() {
		return array( 'stats_display', 'stats_mostlimit' );
	}

	/**
	 * Write the rows uninstall is expected to remove.
	 *
	 * The migration is the thing that creates them, so running it is both the
	 * seed and a check that it produces the rows uninstall claims to know about.
	 *
	 * @return void
	 */
	protected function seed_option_rows() {
		WP_Email_Options::maybe_upgrade();
	}

	/**
	 * Write the wp_email_version marker row.
	 *
	 * @return void
	 */
	protected function write_version_row() {
		WP_Email_Options::maybe_upgrade();
	}

	/**
	 * Round-trip the settings sanitiser.
	 *
	 * @param array $input What the settings form is pretending to have posted.
	 * @return array
	 */
	protected function sanitize_settings( array $input ) {
		return (array) WP_Email_Options::sanitize( $input );
	}

	/**
	 * The plugin's real settings, sent through the sanitiser beside the poison.
	 *
	 * The whole stored array rather than a field or two: this sanitiser rebuilds
	 * its output group by group, so an input missing a group proves less than an
	 * input carrying all of them.
	 *
	 * @return array
	 */
	protected function settings_fixture() {
		return (array) WP_Email_Options::all();
	}

	/**
	 * Register the front-end assets the way a request does.
	 *
	 * @return void
	 */
	protected function register_plugin_assets() {
		do_action( 'wp_enqueue_scripts' );
	}

	/**
	 * Exactly five tags.
	 *
	 * The listing shows five and silently ignores the rest, so a sixth is work
	 * that does nothing (§3.2).
	 *
	 * @return void
	 */
	public function test_the_readme_lists_exactly_five_tags() {
		$tags = $this->readme_field( 'Tags' );

		$this->assertNotEmpty( $tags, 'The readme must carry a Tags line.' );
		$this->assertCount( 5, explode( ',', $tags ), 'wordpress.org shows five tags: ' . $tags );
	}

	/**
	 * The licence statement does not contradict itself.
	 *
	 * The header says "or later", so the GPL block below it has to be the
	 * "or later" variant too.
	 *
	 * @return void
	 */
	public function test_the_gpl_block_is_the_or_later_variant() {
		$this->assertSame( 'GPLv2 or later', $this->header_field( 'License' ) );
		$this->assertStringContainsString(
			'either version 2 of the License, or',
			$this->plugin_file(),
			'The GPL block is the v2-only variant, which contradicts the header above it.'
		);
	}

	/**
	 * The Donations paragraph carries the family wording, to the character.
	 *
	 * @return void
	 */
	public function test_the_readme_carries_the_family_donations_paragraph() {
		$this->assertStringContainsString(
			"### Donations\nI spent most of my free time creating, updating, maintaining and supporting"
			. ' these plugins, if you really love my plugins and could spare me a couple of bucks,'
			. ' I will really appreciate it. If not feel free to use it without any obligations.',
			$this->readme()
		);
	}

	/**
	 * Every translation call names the plugin's text domain.
	 *
	 * A missing domain is not a parse error and not a phpcs error either once
	 * the call is built across two lines, so it ships and simply never
	 * translates.
	 *
	 * @return void
	 */
	public function test_every_translation_call_uses_the_plugin_text_domain() {
		preg_match_all( '/(?:__|_n)\((.*?)\);/s', wp_email_test_source_code(), $calls );

		foreach ( $calls[1] as $arguments ) {
			$this->assertStringContainsString(
				"'wp-email'",
				$arguments,
				"A translation call is missing the text domain: {$arguments}"
			);
		}
	}

	/**
	 * No raster glyphs, and no images directory to hold them.
	 *
	 * The envelope and the printer are inline SVG now. The shared artefact test
	 * covers the build and translation leftovers; this covers the twenty years
	 * of GIFs that preceded them.
	 *
	 * @return void
	 */
	public function test_no_raster_glyphs_or_images_directory_ship() {
		$root = $this->metadata_root();

		$this->assertDirectoryDoesNotExist( $root . '/images', 'The glyphs are inline SVG.' );

		foreach ( array( 'gif', 'png', 'jpg' ) as $extension ) {
			$this->assertSame(
				array(),
				(array) glob( $root . '/*.' . $extension ),
				"No .{$extension} files ship: the glyphs are inline SVG."
			);
		}
	}

	/**
	 * Uninstalling drops the log table and takes the capability back.
	 *
	 * The shared test covers the option rows. These two are WP-EMail's alone,
	 * and neither is a row, so neither would be missed by a LIKE over
	 * wp_options.
	 *
	 * The suite rewrites CREATE/DROP TABLE into their TEMPORARY forms so each
	 * test rolls back. This plugin's table is real - tests/bootstrap.php
	 * installs it before those filters are in play - so DROP TEMPORARY TABLE
	 * would quietly do nothing, and the filters have to come off for the drop
	 * to mean anything. The schema goes back afterwards, because the rollback
	 * does not cover DDL.
	 *
	 * @return void
	 */
	public function test_uninstall_drops_the_log_table_and_takes_the_capability_back() {
		global $wpdb;

		get_role( 'administrator' )->add_cap( WP_Email_Admin::CAPABILITY );

		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );

		$this->run_uninstall();

		$this->assertNull(
			$wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', WP_Email_Logs::table() ) ),
			'uninstall.php must drop the log table.'
		);
		$this->assertFalse(
			get_role( 'administrator' )->has_cap( WP_Email_Admin::CAPABILITY ),
			'uninstall.php must take the capability back off every role holding it.'
		);

		WP_Email_Logs::install();
		get_role( 'administrator' )->add_cap( WP_Email_Admin::CAPABILITY );
	}
}
