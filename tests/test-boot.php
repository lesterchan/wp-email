<?php
/**
 * Plugin bootstrap: hooks, endpoints, shortcodes, assets and install.
 *
 * @package WP-EMail
 */

/**
 * Tests for the hooks, endpoints, shortcodes, assets and install routine.
 *
 * @covers WP_Email
 */
class WP_Email_Boot_Test extends WP_Email_TestCase {

	/**
	 * Post fixture.
	 *
	 * @var int
	 */
	private $post_id;

	/**
	 * Set up the fixtures for each test.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		$this->post_id = self::factory()->post->create( array( 'post_title' => 'Harness Post' ) );
	}


	public function test_get_instance_returns_one_object() {
		$this->assertSame( WP_Email::get_instance(), WP_Email::get_instance(), 'get_instance() answers with the same object every time.' );
	}

	public function test_the_ajax_endpoints_are_registered_for_both_audiences() {
		$this->assertNotFalse( has_action( 'wp_ajax_email', array( 'WP_Email_Form', 'ajax_process' ) ), 'The send endpoint is registered for logged-in callers.' );
		$this->assertNotFalse( has_action( 'wp_ajax_nopriv_email', array( 'WP_Email_Form', 'ajax_process' ) ), 'The send endpoint is registered for logged out callers too; the form is public.' );

		// The form is used by visitors who are not logged in, so the captcha
		// image has to be reachable by them too.
		$this->assertNotFalse( has_action( 'wp_ajax_wp_email_captcha', array( 'WP_Email_Captcha', 'ajax_serve' ) ), 'The captcha endpoint is registered for logged-in callers.' );
		$this->assertNotFalse( has_action( 'wp_ajax_nopriv_wp_email_captcha', array( 'WP_Email_Captcha', 'ajax_serve' ) ), 'The captcha endpoint is registered for logged out callers too.' );
	}

	public function test_query_vars_are_declared() {
		$vars = WP_Email::get_instance()->register_query_vars( array( 'existing' ) );

		$this->assertContains( 'existing', $vars, 'The query vars already registered survive.' );
		$this->assertContains( 'wp_email', $vars, 'The form var is appended.' );
		$this->assertContains( 'wp_email_popup', $vars, 'And the popup var.' );
	}

	public function test_both_endpoints_are_registered() {
		global $wp_rewrite;

		$wp_rewrite->init();
		WP_Email::get_instance()->register_endpoints();

		$found = array();

		foreach ( $wp_rewrite->endpoints as $endpoint ) {
			$found[ $endpoint[1] ] = $endpoint[0];
		}

		$this->assertArrayHasKey( 'email', $found, 'The email endpoint is registered as a query variable.' );
		$this->assertArrayHasKey( 'emailpopup', $found, 'The emailpopup endpoint is registered as a query variable.' );

		// EP_PERMALINK | EP_PAGES: one endpoint covers both, which is why the
		// separate 'emailpage/' path was never needed.
		$this->assertSame( EP_PERMALINK | EP_PAGES, $found['email'], 'The standalone endpoint is registered on posts and pages.' );
		$this->assertSame( EP_PERMALINK | EP_PAGES, $found['emailpopup'], 'And so is the popup endpoint.' );
	}

	public function test_the_takeover_can_be_filtered_off() {
		global $wp_query;

		add_filter( 'wp_email_template_redirect', '__return_false' );

		$wp_query->query_vars['wp_email'] = '';

		// Returns instead of require-ing the page and exiting, which is the
		// documented way for a theme to render the form itself.
		$this->assertNull( WP_Email::get_instance()->maybe_render_email_page(), 'Off an email request the page renderer does nothing at all.' );

		remove_filter( 'wp_email_template_redirect', '__return_false' );
	}


	public function test_the_shortcodes_are_registered() {
		$this->assertTrue( shortcode_exists( 'email_link' ), 'The email_link shortcode is registered.' );
		$this->assertTrue( shortcode_exists( 'donotemail' ), 'The donotemail shortcode is registered.' );
	}

	public function test_the_link_shortcode_renders_a_link() {
		$this->go_to( get_permalink( $this->post_id ) );
		the_post();

		$this->assertStringContainsString( '<a href=', do_shortcode( '[email_link]' ), 'The shortcode renders a link.' );
	}

	public function test_the_link_shortcode_explains_itself_in_a_feed() {
		$this->go_to( '/?feed=rss2' );

		$out = WP_Email::get_instance()->link_shortcode();

		// A feed reader cannot run the form, so a bare link would be a dead end.
		$this->assertStringNotContainsString( '<a href=', $out, 'In a feed there is no link to render.' );
		$this->assertStringContainsString( 'visit this post', $out, 'It says where to go instead.' );
	}

	public function test_the_donotemail_shortcode_passes_content_through() {
		$this->assertSame( 'keep me', do_shortcode( '[donotemail]keep me[/donotemail]' ), 'The shortcode passes its content through unchanged.' );
	}

	public function test_the_donotemail_shortcode_resolves_nested_shortcodes() {
		add_shortcode( 'harness_inner', static fn() => 'INNER' );

		$this->assertSame( 'INNER', do_shortcode( '[donotemail][harness_inner][/donotemail]' ), 'Resolving whatever is nested inside it.' );

		remove_shortcode( 'harness_inner' );
	}


	public function test_add_filters_installs_the_pair() {
		$this->go_to( get_permalink( $this->post_id ) );

		WP_Email::add_filters();

		$this->assertNotFalse( has_filter( 'the_title', array( 'WP_Email', 'filter_title' ) ), 'On an email request the title filter is attached.' );
		$this->assertNotFalse( has_filter( 'the_content', array( 'WP_Email_Form', 'render' ) ), 'On an email request the content filter is attached.' );

		WP_Email::remove_filters();
	}

	public function test_remove_filters_takes_them_off_again() {
		WP_Email::add_filters();
		WP_Email::remove_filters();

		$this->assertFalse( has_filter( 'the_title', array( 'WP_Email', 'filter_title' ) ), 'Off an email request the title filter stays off.' );
		$this->assertFalse( has_filter( 'the_content', array( 'WP_Email_Form', 'render' ) ), 'Off an email request the content filter stays off.' );
	}

	public function test_add_filters_ignores_a_secondary_query() {
		$secondary = new WP_Query( array( 'post__in' => array( $this->post_id ) ) );

		// loop_start hands over the query it is starting; judging the global
		// is_main_query() instead would install the filters for a widget or a
		// related-posts loop running on the e-mail page.
		WP_Email::add_filters( $secondary );

		$this->assertFalse( has_filter( 'the_title', array( 'WP_Email', 'filter_title' ) ), 'Outside the main query the title filter stays off.' );
		$this->assertFalse( has_filter( 'the_content', array( 'WP_Email_Form', 'render' ) ), 'Outside the main query the content filter stays off.' );

		wp_reset_postdata();
	}

	public function test_add_filters_accepts_the_main_query() {
		$this->go_to( get_permalink( $this->post_id ) );

		// Read through $GLOBALS after go_to(): it unsets wp_query, which breaks
		// any binding an earlier `global` statement made.
		WP_Email::add_filters( $GLOBALS['wp_query'] );

		$this->assertNotFalse( has_filter( 'the_title', array( 'WP_Email', 'filter_title' ) ), 'add_filters() accepts the main query object and attaches.' );

		WP_Email::remove_filters();
	}

	public function test_add_filters_still_works_without_an_argument() {
		$this->go_to( get_permalink( $this->post_id ) );

		// The pre-3.0.0 email_addfilters() was called bare in some themes.
		WP_Email::add_filters();

		$this->assertNotFalse( has_filter( 'the_title', array( 'WP_Email', 'filter_title' ) ), 'add_filters() called with no argument still attaches.' );

		WP_Email::remove_filters();
	}

	public function test_the_page_title_is_marked() {
		$this->assertStringContainsString( 'E-Mail', WP_Email::filter_page_title( 'Harness Post' ), 'The page title says what the page is for.' );
		$this->assertStringStartsWith( 'Harness Post', WP_Email::filter_page_title( 'Harness Post' ), 'After the post title rather than instead of it.' );
	}

	public function test_the_page_is_noindexed() {
		ob_start();
		WP_Email::noindex();
		$out = ob_get_clean();

		$this->assertStringContainsString( 'noindex', $out, 'The page asks not to be indexed.' );
		$this->assertStringContainsString( 'nofollow', $out, 'Nor its links followed.' );
	}


	public function test_a_logged_in_visitor_is_prefilled() {
		$user = self::factory()->user->create_and_get(
			array(
				'display_name' => 'Lester Chan',
				'user_email'   => 'lester@example.com',
			)
		);

		wp_set_current_user( $user->ID );

		$values = WP_Email::get_instance()->prefill_for_logged_in_user( array() );

		$this->assertSame( 'Lester Chan', $values['yourname'], 'A logged in visitor has their name filled in.' );
		$this->assertSame( 'lester@example.com', $values['youremail'], 'And their address.' );
	}

	public function test_a_logged_out_visitor_is_not_prefilled() {
		wp_set_current_user( 0 );

		$this->assertSame( array(), WP_Email::get_instance()->prefill_for_logged_in_user( array() ), 'While a logged out visitor is given nothing to fill in.' );
	}

	public function test_the_prefill_is_wired_to_the_public_filter() {
		$user = self::factory()->user->create_and_get( array( 'display_name' => 'Lester Chan' ) );
		wp_set_current_user( $user->ID );

		// wp_email_form_field_values is part of the plugin's public API; themes
		// hook it by that exact name.
		// Hyphenated on purpose: it is the plugin's documented filter name.
		$values = apply_filters( 'wp_email_form_field_values', array() );

		$this->assertSame( 'Lester Chan', $values['yourname'], 'The prefill runs off the public filter, so a theme could replace it.' );
	}


	public function test_the_assets_are_enqueued() {
		do_action( 'wp_enqueue_scripts' );

		$this->assertTrue( wp_style_is( 'wp-email', 'enqueued' ), 'The front-end stylesheet is enqueued.' );
		$this->assertTrue( wp_script_is( 'wp-email', 'enqueued' ), 'The front-end script is enqueued.' );
	}

	public function test_the_script_is_localised() {
		do_action( 'wp_enqueue_scripts' );

		$data = wp_scripts()->get_data( 'wp-email', 'data' );

		$this->assertStringContainsString( 'wpEmailL10n', $data, 'The localised object is attached under the name the script reads.' );
		$this->assertStringContainsString( 'ajax_url', $data, 'Carrying the endpoint.' );
		$this->assertStringContainsString( 'max_allowed', $data, 'The recipient cap.' );
		$this->assertStringContainsString( 'text_friend_email_invalid', $data, 'And the messages, so a bad field can be caught before a round trip.' );
	}

	public function test_the_localised_cap_tracks_the_setting() {
		$options                        = WP_Email_Options::all();
		$options['sending']['multiple'] = 3;
		WP_Email_Options::update( $options );

		do_action( 'wp_enqueue_scripts' );

		// wp_localize_script() stringifies every scalar, which is why the
		// script parseInt()s it back.
		$this->assertStringContainsString( '"max_allowed":"3"', wp_scripts()->get_data( 'wp-email', 'data' ), 'The localised cap follows the setting rather than a number written into the script.' );
	}

	public function test_the_script_loads_in_the_footer() {
		do_action( 'wp_enqueue_scripts' );

		// WP records a footer script as group 1; ->in_footer is only populated
		// once the scripts are actually printed.
		$this->assertSame( 1, wp_scripts()->get_data( 'wp-email', 'group' ), 'The script is printed in the footer.' );
	}


	public function test_install_grants_the_capability() {
		get_role( 'administrator' )->remove_cap( 'manage_email' );

		WP_Email::get_instance()->install();

		$this->assertTrue( get_role( 'administrator' )->has_cap( 'manage_email' ), 'Activation grants the capability to the administrator role.' );
	}

	public function test_install_does_not_grant_the_capability_to_subscribers() {
		WP_Email::get_instance()->install();

		$this->assertFalse( get_role( 'subscriber' )->has_cap( 'manage_email' ), 'Activation grants it to the administrator only, not to every role.' );
	}

	public function test_install_records_the_data_version() {
		delete_option( WP_Email_Options::VERSION );

		WP_Email::get_instance()->install();

		// The marker row holds 'plugin' and 'db' and nothing else, per
		// STANDARDS.md 2.1, so the schema counter is one key of it rather than
		// the whole value. Reading the row as a string casts the array and, with
		// deprecations converted to exceptions, takes the test down with it.
		$this->assertSame(
			(string) WP_EMAIL_DB_VERSION,
			(string) WP_Email_Options::markers()['db'],
			'install() did not record the schema version.'
		);
	}

	public function test_maybe_upgrade_is_a_no_op_when_current() {
		WP_Email::get_instance()->install();

		$options                 = WP_Email_Options::all();
		$options['link']['html'] = '<a href="%EMAIL_URL%">Untouched</a>';
		WP_Email_Options::update( $options );

		WP_Email::get_instance()->maybe_upgrade();

		$this->assertSame( '<a href="%EMAIL_URL%">Untouched</a>', WP_Email_Options::get( 'link', 'html' ), 'With the version current the upgrade leaves the settings alone.' );
	}

	public function test_the_upgrade_is_hooked_where_an_update_will_reach_it() {
		$source = file_get_contents( dirname( __DIR__ ) . '/includes/class-wp-email.php' );

		$this->assertMatchesRegularExpression(
			"/add_action\(\s*'init',\s*array\(\s*\\\$this,\s*'maybe_upgrade'\s*\),\s*5\s*\)/",
			$source,
			'The upgrade runs on init at priority 5, which every request an update goes through reaches.'
		);
	}

	public function test_the_plugin_constants_exist() {
		// All six of STANDARDS.md 2.3, not a sample of three: the two that were
		// not asserted are the ones every path and URL in the plugin is built
		// from, so losing one is how a stylesheet quietly 404s.
		foreach ( array( 'WP_EMAIL_VERSION', 'WP_EMAIL_DB_VERSION', 'WP_EMAIL_SLUG', 'WP_EMAIL_MAIN_FILE', 'WP_EMAIL_DIR', 'WP_EMAIL_URL' ) as $constant ) {
			$this->assertTrue( defined( $constant ), $constant . ' is not defined.' );
		}

		// Guarded so it can be overridden from wp-config.php and survive an
		// upgrade, which the pre-3.0.0 instructions could not. Renamed from the
		// unprefixed EMAIL_SHOW_REMARKS in 3.0.0, per 2.3; the old spelling must
		// not come back, or a site would have two switches for one behaviour.
		$this->assertTrue( defined( 'WP_EMAIL_SHOW_REMARKS' ), 'WP_EMAIL_SHOW_REMARKS is not defined.' );
		$this->assertFalse( defined( 'EMAIL_SHOW_REMARKS' ), 'The retired unprefixed constant is still defined.' );
	}

	public function test_the_version_constant_matches_the_header() {
		$header = get_file_data( WP_EMAIL_MAIN_FILE, array( 'Version' => 'Version' ) );

		$this->assertSame( $header['Version'], WP_EMAIL_VERSION, 'The version constant matches the plugin header.' );
	}

	public function test_the_plugin_stylesheet_is_enqueued_from_the_plugin_directory() {
		wp_deregister_style( 'wp-email' );

		do_action( 'wp_enqueue_scripts' );

		$this->assertStringContainsString( 'plugins/wp-email/css/wp-email.css', wp_styles()->registered['wp-email']->src, 'The stylesheet is served from the plugin.' );
	}

	public function test_no_second_stylesheet_is_enqueued_on_an_rtl_site() {
		// is_rtl() reads $wp_locale->text_direction directly; core has no
		// filter for it.
		$GLOBALS['wp_locale']->text_direction = 'rtl';

		do_action( 'wp_enqueue_scripts' );

		$GLOBALS['wp_locale']->text_direction = 'ltr';

		$this->assertFalse( wp_style_is( 'wp-email-rtl', 'enqueued' ), 'No separate RTL stylesheet is enqueued; the one file uses logical properties.' );
		$this->assertFalse( wp_style_is( 'wp-email-rtl', 'registered' ), 'No separate RTL stylesheet is even registered.' );
		$this->assertSame( array(), (array) glob( dirname( __DIR__ ) . '/css/*-rtl.css' ), 'There is no second stylesheet for RTL, because the one is written both ways.' );
	}

	public function test_activation_installs_on_a_single_site() {
		global $wpdb;

		delete_option( WP_Email_Options::VERSION );
		get_role( 'administrator' )->remove_cap( 'manage_email' );

		WP_Email::get_instance()->activate( false );

		$this->assertSame(
			(string) WP_EMAIL_DB_VERSION,
			(string) WP_Email_Options::markers()['db'],
			'Activation did not record the schema version.'
		);
		$this->assertTrue( get_role( 'administrator' )->has_cap( 'manage_email' ), 'Activation grants the capability.' );
		$this->assertNotNull( $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->email}" ), 'Activation created the log table, so it can be counted.' );
	}

	public function test_the_activation_hook_is_registered() {
		// register_activation_hook() has to run while the main file is loading,
		// which is why the constructor does it rather than a later hook.
		$this->assertNotFalse(
			has_action( 'activate_' . plugin_basename( WP_EMAIL_MAIN_FILE ), array( WP_Email::get_instance(), 'activate' ) ),
			'The activation hook is attached for this plugin basename.'
		);
	}

	public function test_the_form_can_be_rendered_bare() {
		$this->go_to( get_permalink( $this->post_id ) );
		the_post();

		$bare = WP_Email_Form::render( '', false, false, false );

		$this->assertStringNotContainsString( 'id="wp-email-content"', $bare, 'The bare form omits the content block.' );
		$this->assertStringContainsString( 'name="friendemail"', $bare, 'And still renders the form.' );
	}

	public function test_the_form_can_echo_itself() {
		$this->go_to( get_permalink( $this->post_id ) );
		the_post();

		ob_start();
		$returned = WP_Email_Form::render( '', true );
		$printed  = ob_get_clean();

		$this->assertSame( '', $returned, 'Asked to echo, the form returns nothing.' );
		$this->assertStringContainsString( 'name="friendemail"', $printed, 'It prints instead.' );
	}

	/**
	 * Network activation lifts the site-query row cap.
	 *
	 * Asserted against the source: a single-site suite cannot stand up a
	 * 101-site network, and the default cap only bites past the hundredth.
	 */
	public function test_network_activation_lifts_the_site_query_cap() {
		$source = file_get_contents( dirname( __DIR__ ) . '/includes/class-wp-email.php' );

		$this->assertMatchesRegularExpression( "/'number'\s*=>\s*0/", $source, 'The site loop lifts the query cap, or a network past the default is half-activated.' );
		$this->assertMatchesRegularExpression( "/'fields'\s*=>\s*'ids'/", $source, 'The site loop asks for ids only, which is what makes the unlimited query affordable.' );
	}

	public function test_network_activation_restores_inside_the_loop() {
		$source = file_get_contents( dirname( __DIR__ ) . '/includes/class-wp-email.php' );

		$start = strpos( $source, 'foreach ( $site_ids' );
		$body  = substr( $source, $start, strpos( $source, 'return;', $start ) - $start );

		// switch_to_blog() pushes onto a stack; restoring once after the loop
		// leaves it unwound by exactly one.
		$this->assertStringContainsString( 'restore_current_blog', $body, 'The network loop restores the site it switched away from, inside the loop.' );
	}

	public function test_every_php_file_refuses_to_run_directly() {
		$files   = glob( dirname( __DIR__ ) . '/includes/*.php' );
		$files[] = dirname( __DIR__ ) . '/wp-email.php';

		foreach ( $files as $file ) {
			if ( 'index.php' === basename( $file ) ) {
				continue;
			}

			// The one spelling the collection uses, from the shared templates.
			// The long if-block form this used to look for appears nowhere in the
			// plugin, so the assertion failed on the first file it was given
			// while every one of them was in fact guarded.
			$this->assertStringContainsString(
				"defined( 'ABSPATH' ) || exit;",
				file_get_contents( $file ),
				basename( $file ) . ' can be requested directly'
			);
		}
	}

	public function test_every_php_directory_has_a_silence_guard() {
		foreach ( array( '', '/includes', '/tests', '/bin' ) as $dir ) {
			$guard = dirname( __DIR__ ) . $dir . '/index.php';

			$this->assertFileExists( $guard, $dir . ' holds PHP but carries no silence guard.' );

			// The docblock form: phpcbf cannot fix the bare one-liner.
			$this->assertStringContainsString( '/**', file_get_contents( $guard ), $dir . ' has a silence guard with no docblock explaining what it is for.' );
		}
	}
}
