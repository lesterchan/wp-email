<?php
/**
 * The release invariants, asserted from the source and from the stored rows.
 *
 * These are the house rules every plugin in this family shares, and every one
 * of them has been broken by an ordinary edit at some point: a header field
 * that drifted out of the canonical order, a new directory shipped without its
 * silence guard, a version bumped in one file of three, a readme header line
 * that lost the two trailing spaces holding it apart from the next.
 *
 * They are the things a restructuring quietly breaks and nothing notices until
 * a release fails its pre-flight months later, so catching them here is far
 * cheaper than catching them there.
 *
 * @package WP-EMail
 */

/**
 * @coversNothing
 */
class WP_Email_Metadata_Test extends WP_Email_TestCase {

	const VERSION = '3.0.0';

	/**
	 * The main plugin file.
	 *
	 * @return string
	 */
	protected function plugin_file() {
		return wp_email_test_read( 'wp-email.php' );
	}

	/**
	 * The readme.
	 *
	 * @return string
	 */
	protected function readme() {
		return wp_email_test_read( 'README.md' );
	}

	/**
	 * Every directory in the repo that holds at least one PHP file.
	 *
	 * The iterator prunes vendor/ and node_modules/ as it walks rather than
	 * filtering them out afterwards: a plain filter descends into both before
	 * discarding them, which is slow enough to look like a hang.
	 *
	 * @return string[] Absolute paths, plugin root included.
	 */
	protected function php_directories() {
		$root  = dirname( __DIR__ );
		$found = array();

		$directories = new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS );

		$pruned = new RecursiveCallbackFilterIterator(
			$directories,
			static function ( $current ) {
				if ( ! $current->isDir() ) {
					return true;
				}

				return ! in_array( $current->getFilename(), array( 'vendor', 'node_modules', '.git', 'artifacts' ), true );
			}
		);

		foreach ( new RecursiveIteratorIterator( $pruned ) as $file ) {
			if ( 'php' === strtolower( $file->getExtension() ) ) {
				$found[ dirname( $file->getPathname() ) ] = true;
			}
		}

		return array_keys( $found );
	}

	/**
	 * Every option row the plugin owns, read straight from the table.
	 *
	 * @return string[]
	 */
	protected function stored_option_names() {
		global $wpdb;

		return (array) $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
				$wpdb->esc_like( 'wp_email_' ) . '%'
			)
		);
	}

	/**
	 * A field from the main plugin file's header docblock.
	 *
	 * @param string $field Field name.
	 * @return string
	 */
	protected function header_field( $field ) {
		$data = get_file_data( dirname( __DIR__ ) . '/wp-email.php', array( $field => $field ) );

		return $data[ $field ];
	}

	/**
	 * A field from the readme's header block.
	 *
	 * @param string $field Field name.
	 * @return string
	 */
	protected function readme_field( $field ) {
		preg_match( '/^' . preg_quote( $field, '/' ) . ':\s*(.+?)\s*$/m', $this->readme(), $matches );

		return isset( $matches[1] ) ? $matches[1] : '';
	}

	public function test_version_matches_everywhere() {
		$this->assertStringContainsString( ' * Version: ' . self::VERSION, $this->plugin_file() );
		$this->assertStringContainsString( "define( 'WP_EMAIL_VERSION', '" . self::VERSION . "' );", $this->plugin_file() );
		$this->assertStringContainsString( 'Stable tag: ' . self::VERSION, $this->readme() );
	}

	public function test_the_changelog_has_a_section_for_this_version() {
		$this->assertStringContainsString( '### ' . self::VERSION . "\n", $this->readme() );
	}

	/**
	 * The order is neither alphabetical nor intuitive -- Requires at least and
	 * Requires PHP sit before Author -- so it is copied, never composed.
	 */
	public function test_the_plugin_header_fields_are_in_the_canonical_order() {
		$expected = array(
			'Plugin Name',
			'Plugin URI',
			'Description',
			'Version',
			'Requires at least',
			'Requires PHP',
			'Author',
			'Author URI',
			'License',
			'License URI',
			'Text Domain',
			'Domain Path',
		);

		preg_match( '#^<\?php\s*/\*\*(.+?)\*/#s', $this->plugin_file(), $matches );
		$this->assertNotEmpty( $matches, 'The plugin file must open with a docblock header.' );

		preg_match_all( '/^\s*\*\s*([A-Z][A-Za-z ]*?):\s/m', $matches[1], $fields );

		$this->assertSame( $expected, $fields[1] );
	}

	/**
	 * The readme order differs from the PHP one on purpose: Requires PHP comes
	 * after Stable tag here. They are not to be harmonised.
	 */
	public function test_the_readme_header_fields_are_in_the_canonical_order() {
		$expected = array(
			'Contributors',
			'Donate link',
			'Tags',
			'Requires at least',
			'Tested up to',
			'Stable tag',
			'Requires PHP',
			'License',
			'License URI',
		);

		$header = substr( $this->readme(), 0, (int) strpos( $this->readme(), "\n\n" ) );

		preg_match_all( '/^([A-Z][A-Za-z ]*?):\s/m', $header, $fields );

		$this->assertSame( $expected, $fields[1] );
	}

	public function test_requires_headers_match_readme() {
		$this->assertStringContainsString( ' * Requires at least: 6.8', $this->plugin_file() );
		$this->assertStringContainsString( ' * Requires PHP: 8.2', $this->plugin_file() );
		$this->assertStringContainsString( 'Requires at least: 6.8', $this->readme() );
		$this->assertStringContainsString( 'Requires PHP: 8.2', $this->readme() );
	}

	/**
	 * Header lines need two trailing spaces to render as separate lines.
	 *
	 * Markdown joins consecutive lines into one paragraph unless each is ended
	 * with a hard line break, so a missing pair renders as
	 * "License: GPLv2 or later License URI: https://..." on GitHub -- which is
	 * exactly what this readme did until 3.0.0. It is invisible in the source
	 * and in a diff, which is why it wants a test. The last line needs none,
	 * having nothing after it to run into.
	 */
	public function test_every_readme_header_line_keeps_its_line_break() {
		$header = substr( $this->readme(), 0, (int) strpos( $this->readme(), "\n\n" ) );
		$lines  = explode( "\n", $header );

		// The first line is the "# WP-EMail" heading, not a header field.
		$fields = array_slice( $lines, 1 );
		$last   = array_pop( $fields );

		$this->assertCount( 8, $fields, 'The readme header carries nine fields.' );

		foreach ( $fields as $line ) {
			$this->assertStringEndsWith(
				'  ',
				$line,
				"Needs two trailing spaces or it merges with the line below: {$line}"
			);
		}

		$this->assertStringStartsWith( 'License URI:', $last );
		$this->assertSame( rtrim( $last ), $last, 'The last header line takes no trailing spaces.' );
	}

	public function test_the_readme_lists_exactly_five_tags() {
		preg_match( '/^Tags:\s*(.+?)\s*$/m', $this->readme(), $matches );

		$this->assertNotEmpty( $matches, 'The readme must carry a Tags line.' );
		$this->assertCount( 5, explode( ',', $matches[1] ) );
	}

	public function test_every_changelog_heading_is_a_bare_version() {
		$this->assertSame( 0, preg_match( '/^### Version /m', $this->readme() ) );
	}

	public function test_canonical_lesterchan_urls() {
		$this->assertSame(
			'https://lesterchan.net/portfolio/programming/php/',
			$this->header_field( 'Plugin URI' )
		);
		$this->assertSame( 'https://lesterchan.net', $this->header_field( 'Author URI' ) );
		$this->assertSame( 'https://lesterchan.net/site/donation/', $this->readme_field( 'Donate link' ) );
		$this->assertSame(
			'https://www.gnu.org/licenses/gpl-2.0.html',
			$this->header_field( 'License URI' )
		);
		$this->assertSame( 'https://www.gnu.org/licenses/gpl-2.0.html', $this->readme_field( 'License URI' ) );
	}

	/**
	 * One name, in every plugin. A second contributor has to be added on
	 * wordpress.org as well, so a name here that is not on the listing silently
	 * does nothing.
	 */
	public function test_contributors_is_gamerz_only() {
		$this->assertSame( 'GamerZ', $this->readme_field( 'Contributors' ) );
	}

	public function test_text_domain_is_the_plugin_slug() {
		$this->assertSame( 'wp-email', $this->header_field( 'Text Domain' ) );
		$this->assertSame( '/languages', $this->header_field( 'Domain Path' ) );
		$this->assertSame( 'wp-email', WP_EMAIL_SLUG );
	}

	/**
	 * The licence statement has to agree with itself.
	 *
	 * Five plugins in this family carried a version-2-only GPL block directly
	 * under a "GPLv2 or later" header and a GPL-2.0-or-later composer.json,
	 * which is a self-contradicting licence statement rather than a typo.
	 */
	public function test_the_gpl_block_is_the_or_later_variant() {
		$this->assertSame( 'GPLv2 or later', $this->header_field( 'License' ) );
		$this->assertStringContainsString(
			'either version 2 of the License, or',
			$this->plugin_file(),
			'The GPL comment block must be the "or later" variant.'
		);
		$this->assertStringContainsString( '(at your option) any later version.', $this->plugin_file() );
		$this->assertStringContainsString( '"license": "GPL-2.0-or-later"', wp_email_test_read( 'composer.json' ) );
	}

	public function test_the_gpl_licence_is_shipped() {
		$licence = wp_email_test_read( 'LICENSE' );

		$this->assertStringContainsString( 'GNU GENERAL PUBLIC LICENSE', $licence );
		$this->assertStringContainsString( 'Version 2, June 1991', $licence );
	}

	/**
	 * The catalogue comes from translate.wordpress.org, and since WP 6.7 calling
	 * load_plugin_textdomain() this early trips _doing_it_wrong.
	 */
	public function test_the_plugin_does_not_load_its_own_textdomain() {
		$this->assertStringNotContainsString( 'load_plugin_textdomain', wp_email_test_source_code() );
	}

	public function test_every_translation_call_uses_the_plugin_text_domain() {
		$code = wp_email_test_source_code();

		preg_match_all( '/(?:__|_n)\((.*?)\);/s', $code, $calls );

		foreach ( $calls[1] as $arguments ) {
			$this->assertStringContainsString(
				"'wp-email'",
				$arguments,
				"A translation call is missing the text domain: {$arguments}"
			);
		}
	}

	/**
	 * The second-level headings are a closed set in a fixed order.
	 *
	 * Third-level ones are not: Features, Donations, the usage subsections and
	 * every changelog version live below these.
	 */
	public function test_readme_sections_are_the_canonical_set() {
		preg_match_all( '/^## (.+?)\s*$/m', $this->readme(), $sections );

		$this->assertSame(
			array(
				'Description',
				'Usage',
				'Frequently Asked Questions',
				'Screenshots',
				'Changelog',
				'Upgrade Notice',
			),
			$sections[1]
		);
	}

	public function test_the_readme_carries_the_family_donations_paragraph() {
		$this->assertStringContainsString(
			"### Donations\nI spent most of my free time creating, updating, maintaining and supporting"
			. ' these plugins, if you really love my plugins and could spare me a couple of bucks,'
			. ' I will really appreciate it. If not feel free to use it without any obligations.',
			$this->readme()
		);
	}

	/**
	 * Five prefixes, and nothing else.
	 *
	 * The listing on wordpress.org renders the changelog verbatim, so a stray
	 * "IMPORTANT:" -- which this readme carried on two lines until 3.0.0 -- is
	 * visible to every reader of it.
	 */
	public function test_changelog_prefixes_are_canonical() {
		$readme    = $this->readme();
		$changelog = substr( $readme, (int) strpos( $readme, '## Changelog' ) );
		$changelog = substr( $changelog, 0, (int) strpos( $changelog, "\n## Upgrade Notice" ) );

		preg_match_all( '/^\* (.+?):/m', $changelog, $bullets );

		$this->assertNotEmpty( $bullets[1], 'The changelog must carry bullets.' );

		foreach ( $bullets[1] as $prefix ) {
			$this->assertContains(
				$prefix . ':',
				array( 'BREAKING:', 'NEW:', 'CHANGED:', 'FIXED:', 'NOTE:' ),
				"'{$prefix}:' is not one of the five allowed changelog prefixes."
			);
		}
	}

	/**
	 * Every break a site owner updating from the released 2.69.4 would notice
	 * has to be findable under Upgrade Notice, not only in the changelog.
	 */
	public function test_the_upgrade_notice_covers_the_breaking_changes() {
		$readme = $this->readme();
		$notice = substr( $readme, (int) strpos( $readme, '## Upgrade Notice' ) );

		$subjects = array(
			'6.8',
			'8.2',
			'wp_email_options',
			'wp_email_version',
			'wp_email_form_field_values',
			'wp-email-settings',
			'manage_options',
			'manage_email',
			'%EMAIL_ICON%',
			'email-css-rtl.css',
			'email_popup(',
			'stats_display',
		);

		foreach ( $subjects as $subject ) {
			$this->assertStringContainsString(
				$subject,
				$notice,
				"The Upgrade Notice must tell a site owner about {$subject}."
			);
		}
	}

	/**
	 * Seven plugins read the shared stats_display row and each deletes it, so
	 * the readme has to say to update them together.
	 */
	public function test_the_upgrade_notice_says_to_update_the_wp_stats_plugins_together() {
		$notice = substr( $this->readme(), (int) strpos( $this->readme(), '## Upgrade Notice' ) );

		$this->assertStringContainsString( 'Update all seven WP-Stats plugins together', $notice );
	}

	public function test_every_directory_has_an_index_php() {
		foreach ( $this->php_directories() as $directory ) {
			$this->assertFileExists(
				$directory . '/index.php',
				"{$directory} ships PHP and so needs an index.php silence guard."
			);
		}
	}

	public function test_the_guards_use_the_docblock_form() {
		foreach ( $this->php_directories() as $directory ) {
			$guard = (string) file_get_contents( $directory . '/index.php' );

			// phpcbf cannot fix the one-line "// Silence is golden." form.
			$this->assertStringContainsString( '/**', $guard, "{$directory}/index.php must use the docblock form." );
			$this->assertStringContainsString( 'Silence is golden.', $guard );
		}
	}

	/**
	 * Nothing the plugin enqueues may pull the library in, and nothing it ships
	 * may reach for one at runtime.
	 *
	 * Both halves matter: a source grep alone passes a dependency array built at
	 * runtime, and a dependency check alone passes a script that reaches for
	 * window.jQuery once it is loaded.
	 */
	public function test_no_jquery_is_enqueued() {
		do_action( 'wp_enqueue_scripts' );

		$this->assertSame( array(), wp_scripts()->registered['wp-email']->deps );

		$this->assertStringNotContainsStringIgnoringCase( 'jquery', wp_email_test_source_code() );
		$this->assertStringNotContainsStringIgnoringCase( 'jquery', wp_email_test_script_code() );

		foreach ( (array) glob( dirname( __DIR__ ) . '/js/*.js' ) as $script ) {
			$this->assertStringNotContainsString( '$(', (string) file_get_contents( $script ) );
		}
	}

	/**
	 * No plugin in this family ships a second, mirrored stylesheet: the front
	 * end uses CSS logical properties instead, so one sheet serves both
	 * directions.
	 */
	public function test_no_rtl_stylesheet_is_registered() {
		$root = dirname( __DIR__ );

		$this->assertSame( array(), (array) glob( $root . '/*-rtl.css' ) );
		$this->assertSame( array(), (array) glob( $root . '/css/*-rtl.css' ) );
		$this->assertStringNotContainsString(
			'wp_style_add_data',
			wp_email_test_source_code(),
			"No plugin registers 'rtl' style data."
		);
	}

	/**
	 * The catalogue is built by translate.wordpress.org, and Travis has been
	 * dead for these repos for years.
	 */
	public function test_no_abandoned_build_or_translation_artefacts_ship() {
		$root = dirname( __DIR__ );

		$this->assertFileDoesNotExist( $root . '/.travis.yml' );
		$this->assertDirectoryDoesNotExist( $root . '/languages' );
		$this->assertDirectoryDoesNotExist( $root . '/images' );

		foreach ( array( 'pot', 'po', 'mo', 'gif', 'png', 'jpg' ) as $extension ) {
			$this->assertSame(
				array(),
				(array) glob( $root . '/*.' . $extension ),
				"No .{$extension} files ship: the catalogue is built upstream and the glyphs are inline SVG."
			);
		}
	}

	public function test_no_loose_php_css_or_js_at_the_plugin_root() {
		$root = dirname( __DIR__ );

		$this->assertSame(
			array( 'index.php', 'uninstall.php', 'wp-email.php' ),
			array_values( array_map( 'basename', (array) glob( $root . '/*.php' ) ) )
		);

		$this->assertSame( array(), (array) glob( $root . '/*.css' ) );

		// playwright.config.js is the one exception, and it is not a script the
		// plugin ships: section 7.5 puts it at the plugin root because that is
		// where Playwright looks for it, and the SVN deploy excludes it along with
		// the rest of the dev tooling.
		$this->assertSame(
			array( 'playwright.config.js' ),
			array_values( array_map( 'basename', (array) glob( $root . '/*.js' ) ) )
		);
	}

	/**
	 * The upgrade markers live in their own row, holding those two keys and no
	 * others. Anything else in here means a marker has drifted back into the
	 * settings array, which is the bug this shape exists to make impossible.
	 */
	public function test_version_row_holds_exactly_plugin_and_db() {
		WP_Email_Options::maybe_upgrade();

		$markers = get_option( WP_Email_Options::VERSION );

		$this->assertIsArray( $markers, 'wp_email_version must be an array.' );

		$keys = array_keys( $markers );
		sort( $keys );

		$this->assertSame( array( 'db', 'plugin' ), $keys );
		$this->assertSame( WP_EMAIL_VERSION, $markers['plugin'] );
		$this->assertSame( WP_EMAIL_DB_VERSION, $markers['db'] );
	}

	/**
	 * The regression guard for the wp-useronline bug: it fails the moment
	 * someone moves an upgrade marker back into the settings array, where the
	 * sanitiser would have to rescue it by hand on every save.
	 */
	public function test_settings_sanitizer_never_stores_version_markers() {
		$clean = WP_Email_Options::sanitize( WP_Email_Options::all() );

		foreach ( array( 'version', 'db_version', 'versions', 'plugin', 'db' ) as $key ) {
			$this->assertArrayNotHasKey(
				$key,
				$clean,
				"The sanitiser stored a '{$key}' key; the upgrade markers belong in wp_email_version."
			);
		}
	}

	/**
	 * Deleting the plugin leaves nothing behind.
	 *
	 * The assertion is deliberately a LIKE over wp_options rather than two
	 * delete_option() checks: a row added later and forgotten in uninstall.php
	 * is exactly the failure this is here to catch. The multisite config runs
	 * the same test through uninstall.php's get_sites() branch.
	 *
	 * This is the only test file that may require the uninstaller: it declares
	 * global functions, so a second one fatals on redeclare.
	 */
	public function test_uninstall_removes_every_option_row() {
		global $wpdb;

		WP_Email_Options::maybe_upgrade();

		$this->assertNotEmpty(
			$this->stored_option_names(),
			'There should be rows to remove before uninstall runs.'
		);

		// The test suite rewrites CREATE/DROP TABLE to their TEMPORARY forms so
		// each test rolls back. The plugin's table is real -- bootstrap.php
		// installs it before those filters matter -- so DROP TEMPORARY TABLE
		// would quietly do nothing here.
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );

		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'wp-email/wp-email.php' );
		}

		require_once dirname( __DIR__ ) . '/uninstall.php';

		wp_cache_flush();

		$this->assertSame(
			array(),
			$this->stored_option_names(),
			'uninstall.php must remove every wp_email_* row.'
		);

		$this->assertNull(
			$wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', WP_Email_Logs::table() ) ),
			'uninstall.php must drop the log table.'
		);

		$this->assertFalse( get_role( 'administrator' )->has_cap( WP_Email_Admin::CAPABILITY ) );

		// Put the schema and the capability back for whatever runs next; the
		// transaction rollback does not cover DROP TABLE.
		WP_Email_Logs::install();
		get_role( 'administrator' )->add_cap( WP_Email_Admin::CAPABILITY );
	}

	/**
	 * The two shared WP-Stats rows are not the plugin's to delete.
	 *
	 * Six sibling plugins read them, so an uninstall that cleared them would
	 * take a setting away from a plugin that is still installed.
	 */
	public function test_uninstall_leaves_the_shared_wp_stats_rows_alone() {
		$source = wp_email_test_read( 'uninstall.php' );

		$this->assertStringNotContainsString( "'stats_display'", $source );
		$this->assertStringNotContainsString( "'stats_mostlimit'", $source );
	}
}
