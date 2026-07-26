<?php
/**
 * Plugin bootstrap: hooks, endpoints, shortcodes, assets and install.
 *
 * @package WP-EMail
 */

/**
 * Tests for the hooks, endpoints, shortcodes, assets and install routine.
 *
 * @covers Email
 */
class Test_Email_Boot extends WP_UnitTestCase {

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

	// -------------------------------------------------------------- wiring --

	/**
	 * Get_instance() is a singleton.
	 */
	public function test_get_instance_returns_one_object() {
		$this->assertSame( Email::get_instance(), Email::get_instance() );
	}

	/**
	 * The AJAX endpoints answer logged-out visitors as well as logged-in ones.
	 */
	public function test_the_ajax_endpoints_are_registered_for_both_audiences() {
		$this->assertNotFalse( has_action( 'wp_ajax_email', array( 'Email_Form', 'process' ) ) );
		$this->assertNotFalse( has_action( 'wp_ajax_nopriv_email', array( 'Email_Form', 'process' ) ) );

		// The form is used by visitors who are not logged in, so the captcha
		// image has to be reachable by them too.
		$this->assertNotFalse( has_action( 'wp_ajax_wp_email_captcha', array( 'Email_Captcha', 'serve' ) ) );
		$this->assertNotFalse( has_action( 'wp_ajax_nopriv_wp_email_captcha', array( 'Email_Captcha', 'serve' ) ) );
	}

	/**
	 * The query vars the endpoints resolve to are declared.
	 */
	public function test_query_vars_are_declared() {
		$vars = Email::get_instance()->register_query_vars( array( 'existing' ) );

		$this->assertContains( 'existing', $vars );
		$this->assertContains( 'wp_email', $vars );
		$this->assertContains( 'wp_email_popup', $vars );
	}

	/**
	 * Both endpoints are registered against posts and pages.
	 */
	public function test_both_endpoints_are_registered() {
		global $wp_rewrite;

		$wp_rewrite->init();
		Email::get_instance()->register_endpoints();

		$found = array();

		foreach ( $wp_rewrite->endpoints as $endpoint ) {
			$found[ $endpoint[1] ] = $endpoint[0];
		}

		$this->assertArrayHasKey( 'email', $found );
		$this->assertArrayHasKey( 'emailpopup', $found );

		// EP_PERMALINK | EP_PAGES: one endpoint covers both, which is why the
		// separate 'emailpage/' path was never needed.
		$this->assertSame( EP_PERMALINK | EP_PAGES, $found['email'] );
		$this->assertSame( EP_PERMALINK | EP_PAGES, $found['emailpopup'] );
	}

	/**
	 * The template_redirect takeover can be switched off by a filter.
	 */
	public function test_the_takeover_can_be_filtered_off() {
		global $wp_query;

		add_filter( 'wp_email_template_redirect', '__return_false' );

		$wp_query->query_vars['wp_email'] = '';

		// Returns instead of require-ing the page and exiting, which is the
		// documented way for a theme to render the form itself.
		$this->assertNull( Email::get_instance()->maybe_render_email_page() );

		remove_filter( 'wp_email_template_redirect', '__return_false' );
	}

	// ---------------------------------------------------------- shortcodes --

	/**
	 * Both shortcodes are registered.
	 */
	public function test_the_shortcodes_are_registered() {
		$this->assertTrue( shortcode_exists( 'email_link' ) );
		$this->assertTrue( shortcode_exists( 'donotemail' ) );
	}

	/**
	 * The link shortcode renders a link on the site.
	 */
	public function test_the_link_shortcode_renders_a_link() {
		$this->go_to( get_permalink( $this->post_id ) );
		the_post();

		$this->assertStringContainsString( '<a href=', do_shortcode( '[email_link]' ) );
	}

	/**
	 * In a feed it renders a note instead of a link.
	 */
	public function test_the_link_shortcode_explains_itself_in_a_feed() {
		$this->go_to( '/?feed=rss2' );

		$out = Email::get_instance()->link_shortcode();

		// A feed reader cannot run the form, so a bare link would be a dead end.
		$this->assertStringNotContainsString( '<a href=', $out );
		$this->assertStringContainsString( 'visit this post', $out );
	}

	/**
	 * The donotemail shortcode is a passthrough outside an e-mail.
	 */
	public function test_the_donotemail_shortcode_passes_content_through() {
		$this->assertSame( 'keep me', do_shortcode( '[donotemail]keep me[/donotemail]' ) );
	}

	/**
	 * It resolves nested shortcodes too.
	 */
	public function test_the_donotemail_shortcode_resolves_nested_shortcodes() {
		add_shortcode( 'harness_inner', static fn() => 'INNER' );

		$this->assertSame( 'INNER', do_shortcode( '[donotemail][harness_inner][/donotemail]' ) );

		remove_shortcode( 'harness_inner' );
	}

	// ------------------------------------------------------------- filters --

	/**
	 * Add_filters() installs the title and content filters.
	 */
	public function test_add_filters_installs_the_pair() {
		$this->go_to( get_permalink( $this->post_id ) );

		Email::add_filters();

		$this->assertNotFalse( has_filter( 'the_title', array( 'Email', 'filter_title' ) ) );
		$this->assertNotFalse( has_filter( 'the_content', array( 'Email_Form', 'render' ) ) );

		Email::remove_filters();
	}

	/**
	 * Remove_filters() takes them back off.
	 */
	public function test_remove_filters_takes_them_off_again() {
		Email::add_filters();
		Email::remove_filters();

		$this->assertFalse( has_filter( 'the_title', array( 'Email', 'filter_title' ) ) );
		$this->assertFalse( has_filter( 'the_content', array( 'Email_Form', 'render' ) ) );
	}

	/**
	 * A secondary query never gets the filters, so widgets stay unaffected.
	 */
	public function test_add_filters_ignores_a_secondary_query() {
		$secondary = new WP_Query( array( 'post__in' => array( $this->post_id ) ) );

		// loop_start hands over the query it is starting; judging the global
		// is_main_query() instead would install the filters for a widget or a
		// related-posts loop running on the e-mail page.
		Email::add_filters( $secondary );

		$this->assertFalse( has_filter( 'the_title', array( 'Email', 'filter_title' ) ) );
		$this->assertFalse( has_filter( 'the_content', array( 'Email_Form', 'render' ) ) );

		wp_reset_postdata();
	}

	/**
	 * The main query, passed the same way, does get them.
	 */
	public function test_add_filters_accepts_the_main_query() {
		$this->go_to( get_permalink( $this->post_id ) );

		// Read through $GLOBALS after go_to(): it unsets wp_query, which breaks
		// any binding an earlier `global` statement made.
		Email::add_filters( $GLOBALS['wp_query'] );

		$this->assertNotFalse( has_filter( 'the_title', array( 'Email', 'filter_title' ) ) );

		Email::remove_filters();
	}

	/**
	 * Called with nothing it still falls back to the global query.
	 */
	public function test_add_filters_still_works_without_an_argument() {
		$this->go_to( get_permalink( $this->post_id ) );

		// The pre-3.0.0 email_addfilters() was called bare in some themes.
		Email::add_filters();

		$this->assertNotFalse( has_filter( 'the_title', array( 'Email', 'filter_title' ) ) );

		Email::remove_filters();
	}

	/**
	 * The document title is marked as the e-mail page.
	 */
	public function test_the_page_title_is_marked() {
		$this->assertStringContainsString( 'E-Mail', Email::filter_page_title( 'Harness Post' ) );
		$this->assertStringStartsWith( 'Harness Post', Email::filter_page_title( 'Harness Post' ) );
	}

	/**
	 * The e-mail page asks robots to stay away.
	 */
	public function test_the_page_is_noindexed() {
		ob_start();
		Email::noindex();
		$out = ob_get_clean();

		$this->assertStringContainsString( 'noindex', $out );
		$this->assertStringContainsString( 'nofollow', $out );
	}

	// ------------------------------------------------------------- prefill --

	/**
	 * A logged-in visitor gets their name and address filled in.
	 */
	public function test_a_logged_in_visitor_is_prefilled() {
		$user = self::factory()->user->create_and_get(
			array(
				'display_name' => 'Lester Chan',
				'user_email'   => 'lester@example.com',
			)
		);

		wp_set_current_user( $user->ID );

		$values = Email::get_instance()->prefill_for_logged_in_user( array() );

		$this->assertSame( 'Lester Chan', $values['yourname'] );
		$this->assertSame( 'lester@example.com', $values['youremail'] );
	}

	/**
	 * A logged-out visitor is left alone.
	 */
	public function test_a_logged_out_visitor_is_not_prefilled() {
		wp_set_current_user( 0 );

		$this->assertSame( array(), Email::get_instance()->prefill_for_logged_in_user( array() ) );
	}

	/**
	 * The prefill reaches the form through the documented filter.
	 */
	public function test_the_prefill_is_wired_to_the_public_filter() {
		$user = self::factory()->user->create_and_get( array( 'display_name' => 'Lester Chan' ) );
		wp_set_current_user( $user->ID );

		// email_form-fieldvalues is part of the plugin's public API; themes
		// hook it by that exact name.
		// Hyphenated on purpose: it is the plugin's documented filter name.
		$values = apply_filters( 'email_form-fieldvalues', array() ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores

		$this->assertSame( 'Lester Chan', $values['yourname'] );
	}

	// -------------------------------------------------------------- assets --

	/**
	 * The stylesheet and script are enqueued on the front end.
	 */
	public function test_the_assets_are_enqueued() {
		do_action( 'wp_enqueue_scripts' );

		$this->assertTrue( wp_style_is( 'wp-email', 'enqueued' ) );
		$this->assertTrue( wp_script_is( 'wp-email', 'enqueued' ) );
	}

	/**
	 * The script carries the strings and limits the form needs.
	 */
	public function test_the_script_is_localised() {
		do_action( 'wp_enqueue_scripts' );

		$data = wp_scripts()->get_data( 'wp-email', 'data' );

		$this->assertStringContainsString( 'emailL10n', $data );
		$this->assertStringContainsString( 'ajax_url', $data );
		$this->assertStringContainsString( 'max_allowed', $data );
		$this->assertStringContainsString( 'text_friend_email_invalid', $data );
	}

	/**
	 * The localised recipient cap tracks the setting.
	 */
	public function test_the_localised_cap_tracks_the_setting() {
		$options                        = Email_Options::all();
		$options['sending']['multiple'] = 3;
		Email_Options::update( $options );

		do_action( 'wp_enqueue_scripts' );

		// wp_localize_script() stringifies every scalar, which is why the
		// script parseInt()s it back.
		$this->assertStringContainsString( '"max_allowed":"3"', wp_scripts()->get_data( 'wp-email', 'data' ) );
	}

	/**
	 * The front-end script is loaded in the footer.
	 */
	public function test_the_script_loads_in_the_footer() {
		do_action( 'wp_enqueue_scripts' );

		// WP records a footer script as group 1; ->in_footer is only populated
		// once the scripts are actually printed.
		$this->assertSame( 1, wp_scripts()->get_data( 'wp-email', 'group' ) );
	}

	// ------------------------------------------------------------- install --

	/**
	 * Installing grants the capability to administrators.
	 */
	public function test_install_grants_the_capability() {
		get_role( 'administrator' )->remove_cap( 'manage_email' );

		Email::get_instance()->install();

		$this->assertTrue( get_role( 'administrator' )->has_cap( 'manage_email' ) );
	}

	/**
	 * Installing does not hand the capability to everyone.
	 */
	public function test_install_does_not_grant_the_capability_to_subscribers() {
		Email::get_instance()->install();

		$this->assertFalse( get_role( 'subscriber' )->has_cap( 'manage_email' ) );
	}

	/**
	 * Installing records the data version.
	 */
	public function test_install_records_the_data_version() {
		delete_option( Email_Options::VERSION_OPTION );

		Email::get_instance()->install();

		$this->assertSame( (string) WP_EMAIL_DB_VERSION, (string) get_option( Email_Options::VERSION_OPTION ) );
	}

	/**
	 * The upgrade check does nothing once the version matches.
	 */
	public function test_maybe_upgrade_is_a_no_op_when_current() {
		Email::get_instance()->install();

		$options                      = Email_Options::all();
		$options['link']['post_text'] = 'Untouched';
		Email_Options::update( $options );

		Email::get_instance()->maybe_upgrade();

		$this->assertSame( 'Untouched', Email_Options::get( 'link', 'post_text' ) );
	}

	/**
	 * The upgrade runs on admin_init, because activation misses plugin updates.
	 */
	public function test_the_upgrade_is_hooked_where_an_update_will_reach_it() {
		$source = file_get_contents( dirname( __DIR__ ) . '/includes/class-email.php' );

		$this->assertMatchesRegularExpression(
			"/add_action\(\s*'admin_init',\s*array\(\s*\\\$this,\s*'maybe_upgrade'\s*\)/",
			$source
		);
	}

	/**
	 * The plugin constants are defined.
	 */
	public function test_the_plugin_constants_exist() {
		$this->assertTrue( defined( 'WP_EMAIL_VERSION' ) );
		$this->assertTrue( defined( 'WP_EMAIL_DB_VERSION' ) );
		$this->assertTrue( defined( 'WP_EMAIL_MAIN_FILE' ) );

		// Guarded so it can be overridden from wp-config.php and survive an
		// upgrade, which the pre-3.0.0 instructions could not.
		$this->assertTrue( defined( 'EMAIL_SHOW_REMARKS' ) );
	}

	/**
	 * The version constant matches the plugin header.
	 */
	public function test_the_version_constant_matches_the_header() {
		$header = get_file_data( WP_EMAIL_MAIN_FILE, array( 'Version' => 'Version' ) );

		$this->assertSame( $header['Version'], WP_EMAIL_VERSION );
	}

	/**
	 * A theme copy of the stylesheet wins over the plugin's own.
	 */
	public function test_a_theme_stylesheet_overrides_the_plugin_one() {
		// The plugin directory already holds an email-css.css, so pointing the
		// stylesheet directory at it is enough to exercise the branch.
		$dir = dirname( __DIR__ );

		add_filter( 'stylesheet_directory', static fn() => $dir );
		add_filter( 'stylesheet_directory_uri', static fn() => 'http://example.org/theme' );

		// wp_enqueue_style() ignores a new src for an already-registered
		// handle, and an earlier test in this process will have registered it.
		wp_deregister_style( 'wp-email' );

		do_action( 'wp_enqueue_scripts' );

		$src = wp_styles()->registered['wp-email']->src;

		remove_all_filters( 'stylesheet_directory' );
		remove_all_filters( 'stylesheet_directory_uri' );

		$this->assertStringContainsString( 'http://example.org/theme/email-css.css', $src );
	}

	/**
	 * Without a theme copy the plugin's own stylesheet is used.
	 */
	public function test_the_plugin_stylesheet_is_the_default() {
		wp_deregister_style( 'wp-email' );

		do_action( 'wp_enqueue_scripts' );

		$this->assertStringContainsString( 'plugins/wp-email/email-css.css', wp_styles()->registered['wp-email']->src );
	}

	/**
	 * The RTL stylesheet is only enqueued on an RTL site.
	 */
	public function test_the_rtl_stylesheet_is_conditional() {
		wp_dequeue_style( 'wp-email-rtl' );

		do_action( 'wp_enqueue_scripts' );
		$this->assertFalse( wp_style_is( 'wp-email-rtl', 'enqueued' ) );

		// is_rtl() reads $wp_locale->text_direction directly; core has no
		// filter for it.
		$GLOBALS['wp_locale']->text_direction = 'rtl';

		do_action( 'wp_enqueue_scripts' );

		$GLOBALS['wp_locale']->text_direction = 'ltr';

		$this->assertTrue( wp_style_is( 'wp-email-rtl', 'enqueued' ) );
	}

	/**
	 * Activation installs on a single site.
	 */
	public function test_activation_installs_on_a_single_site() {
		global $wpdb;

		delete_option( Email_Options::VERSION_OPTION );
		get_role( 'administrator' )->remove_cap( 'manage_email' );

		Email::get_instance()->activate( false );

		$this->assertSame( (string) WP_EMAIL_DB_VERSION, (string) get_option( Email_Options::VERSION_OPTION ) );
		$this->assertTrue( get_role( 'administrator' )->has_cap( 'manage_email' ) );
		$this->assertNotNull( $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->email}" ) );
	}

	/**
	 * The activation hook is registered against the main plugin file.
	 */
	public function test_the_activation_hook_is_registered() {
		// register_activation_hook() has to run while the main file is loading,
		// which is why the constructor does it rather than a later hook.
		$this->assertNotFalse(
			has_action( 'activate_' . plugin_basename( WP_EMAIL_MAIN_FILE ), array( Email::get_instance(), 'activate' ) )
		);
	}

	/**
	 * The form can be rendered bare, without the wrapper or the subtitle.
	 */
	public function test_the_form_can_be_rendered_bare() {
		$this->go_to( get_permalink( $this->post_id ) );
		the_post();

		$bare = Email_Form::render( '', false, false, false );

		$this->assertStringNotContainsString( 'id="wp-email-content"', $bare );
		$this->assertStringContainsString( 'name="friendemail"', $bare );
	}

	/**
	 * Rendering with echo on prints instead of returning.
	 */
	public function test_the_form_can_echo_itself() {
		$this->go_to( get_permalink( $this->post_id ) );
		the_post();

		ob_start();
		$returned = Email_Form::render( '', true );
		$printed  = ob_get_clean();

		$this->assertSame( '', $returned );
		$this->assertStringContainsString( 'name="friendemail"', $printed );
	}

	/**
	 * Network activation lifts the site-query row cap.
	 *
	 * Asserted against the source: a single-site suite cannot stand up a
	 * 101-site network, and the default cap only bites past the hundredth.
	 */
	public function test_network_activation_lifts_the_site_query_cap() {
		$source = file_get_contents( dirname( __DIR__ ) . '/includes/class-email.php' );

		$this->assertMatchesRegularExpression( "/'number'\s*=>\s*0/", $source );
		$this->assertMatchesRegularExpression( "/'fields'\s*=>\s*'ids'/", $source );
	}

	/**
	 * It restores the blog inside the loop, not once at the end.
	 */
	public function test_network_activation_restores_inside_the_loop() {
		$source = file_get_contents( dirname( __DIR__ ) . '/includes/class-email.php' );

		$start = strpos( $source, 'foreach ( $site_ids' );
		$body  = substr( $source, $start, strpos( $source, 'return;', $start ) - $start );

		// switch_to_blog() pushes onto a stack; restoring once after the loop
		// leaves it unwound by exactly one.
		$this->assertStringContainsString( 'restore_current_blog', $body );
	}

	/**
	 * The plugin never loads outside WordPress.
	 */
	public function test_every_php_file_refuses_to_run_directly() {
		$files   = glob( dirname( __DIR__ ) . '/includes/*.php' );
		$files[] = dirname( __DIR__ ) . '/wp-email.php';

		foreach ( $files as $file ) {
			if ( 'index.php' === basename( $file ) ) {
				continue;
			}

			$this->assertStringContainsString(
				"if ( ! defined( 'ABSPATH' ) ) {",
				file_get_contents( $file ),
				basename( $file ) . ' can be requested directly'
			);
		}
	}

	/**
	 * Every directory holding PHP carries a silence guard.
	 */
	public function test_every_php_directory_has_a_silence_guard() {
		foreach ( array( '', '/includes', '/tests', '/bin' ) as $dir ) {
			$guard = dirname( __DIR__ ) . $dir . '/index.php';

			$this->assertFileExists( $guard );

			// The docblock form: phpcbf cannot fix the bare one-liner.
			$this->assertStringContainsString( '/**', file_get_contents( $guard ) );
		}
	}
}
