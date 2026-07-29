<?php
/**
 * The public template tags: counters, most-emailed and the e-mail link.
 *
 * @package WP-EMail
 */

/**
 * The public template tags.
 *
 * @covers ::email_link
 * @covers ::get_emails
 * @covers ::get_mostemailed
 */
class Test_Email_Template_Tags extends WP_UnitTestCase {

	/**
	 * Post fixture.
	 *
	 * @var int
	 */
	private $post_id;

	/**
	 * Page fixture.
	 *
	 * @var int
	 */
	private $page_id;

	/**
	 * Set up the fixtures for each test.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		// Backdated: get_mostemailed() filters on post_date < NOW with a strict
		// comparison, so a post created in the same second is invisible.
		$past = gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS );

		$this->post_id = self::factory()->post->create(
			array(
				'post_title'   => 'Harness Post',
				'post_content' => 'One two three four five six seven eight nine ten.',
				'post_excerpt' => 'Harness excerpt.',
				'post_date'    => $past,
			)
		);

		$this->page_id = self::factory()->post->create(
			array(
				'post_title' => 'Harness Page',
				'post_type'  => 'page',
				'post_date'  => $past,
			)
		);

		$this->seed_logs();
	}

	/**
	 * Four rows for the post (one failed), one for the page.
	 *
	 * @return void
	 */
	private function seed_logs() {
		$rows = array(
			array( 'Alice', $this->post_id, 'Harness Post', WP_Email_Logs::STATUS_SUCCESS ),
			array( 'Bob', $this->post_id, 'Harness Post', WP_Email_Logs::STATUS_SUCCESS ),
			array( 'Cara', $this->post_id, 'Harness Post', WP_Email_Logs::STATUS_SUCCESS ),
			array( 'Carol', $this->post_id, 'Harness Post', WP_Email_Logs::STATUS_FAILED ),
			array( 'Dave', $this->page_id, 'Harness Page', WP_Email_Logs::STATUS_SUCCESS ),
		);

		foreach ( $rows as $i => $row ) {
			WP_Email_Logs::insert(
				array(
					'yourname'    => $row[0],
					'youremail'   => strtolower( $row[0] ) . '@example.com',
					'yourremarks' => 'remark ' . $i,
					'friendname'  => 'Friend ' . $i,
					'friendemail' => 'friend' . $i . '@example.com',
					'postid'      => $row[1],
					'posttitle'   => $row[2],
					'timestamp'   => time() - DAY_IN_SECONDS,
					'ip'          => '198.51.100.' . ( $i + 1 ),
					'host'        => 'host' . $i . '.example.com',
					'status'      => $row[3],
				)
			);
		}
	}

	/**
	 * Counters.
	 */
	public function test_counters() {
		$this->assertSame( '5', get_emails( false ) );
		$this->assertSame( '4', get_emails_success( false ) );
		$this->assertSame( '1', get_emails_failed( false ) );
		$this->assertSame( '4', get_email_count( $this->post_id, false ) );
		$this->assertSame( '1', get_email_count( $this->page_id, false ) );
	}

	/**
	 * Most emailed filters by post type.
	 */
	public function test_most_emailed_filters_by_post_type() {
		$posts = get_mostemailed( 'post', 10, 0, false );

		$this->assertStringContainsString( 'Harness Post', $posts );
		$this->assertStringNotContainsString( 'Harness Page', $posts );

		$both = get_mostemailed( 'both', 10, 0, false );

		$this->assertStringContainsString( 'Harness Post', $both );
		$this->assertStringContainsString( 'Harness Page', $both );
	}

	/**
	 * Most emailed shows the count.
	 */
	public function test_most_emailed_shows_the_count() {
		$this->assertStringContainsString( '4 emails', get_mostemailed( 'post', 10, 0, false ) );
	}

	/**
	 * Most emailed says not available when there is nothing.
	 */
	public function test_most_emailed_says_not_available_when_there_is_nothing() {
		$this->assertStringContainsString( 'N/A', get_mostemailed( 'attachment', 10, 0, false ) );
	}

	/**
	 * Most emailed escapes a hostile post title.
	 */
	public function test_most_emailed_escapes_a_hostile_post_title() {
		$hostile = self::factory()->post->create(
			array(
				'post_title' => 'Bad <script>alert(1)</script> Title',
				'post_date'  => gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ),
			)
		);

		WP_Email_Logs::insert(
			array(
				'yourname'    => 'X',
				'youremail'   => 'x@example.com',
				'yourremarks' => '',
				'friendname'  => 'Y',
				'friendemail' => 'y@example.com',
				'postid'      => $hostile,
				'posttitle'   => 'Bad',
				'timestamp'   => time() - DAY_IN_SECONDS,
				'ip'          => '198.51.100.99',
				'host'        => '',
				'status'      => WP_Email_Logs::STATUS_SUCCESS,
			)
		);

		$output = get_mostemailed( 'post', 10, 0, false );

		$this->assertStringNotContainsString( '<script>alert(1)</script>', $output );
	}

	/**
	 * The old loop assigned each row to the $post global.
	 */
	public function test_most_emailed_does_not_clobber_the_global_post() {
		$this->go_to( get_permalink( $this->post_id ) );
		the_post();

		$before = $GLOBALS['post']->ID;

		get_mostemailed( 'both', 10, 0, false );

		$this->assertSame( $before, $GLOBALS['post']->ID );
	}

	/**
	 * Email link styles.
	 */
	public function test_email_link_styles() {
		$this->go_to( get_permalink( $this->post_id ) );
		the_post();

		$options = WP_Email_Options::all();

		$options['link']['style'] = 1;
		$options['link']['type']  = 1;
		WP_Email_Options::update( $options );

		$link = email_link( '', '', false );
		$this->assertStringContainsString( 'WP-EmailIcon', $link );
		$this->assertStringContainsString( 'Email This Post', $link );
		$this->assertStringContainsString( 'rel="nofollow"', $link );

		$options['link']['style'] = 2;
		WP_Email_Options::update( $options );
		$this->assertStringContainsString( 'WP-EmailIcon', email_link( '', '', false ) );

		$options['link']['style'] = 3;
		WP_Email_Options::update( $options );
		$this->assertStringNotContainsString( 'WP-EmailIcon', email_link( '', '', false ) );
	}

	/**
	 * Email link custom style expands every token.
	 */
	public function test_email_link_custom_style_expands_every_token() {
		$this->go_to( get_permalink( $this->post_id ) );
		the_post();

		$options                  = WP_Email_Options::all();
		$options['link']['style'] = 4;
		$options['link']['html']  = '<a href="%EMAIL_URL%" title="%EMAIL_TEXT%">%EMAIL_TEXT% @ %EMAIL_ICON_URL%</a>';
		WP_Email_Options::update( $options );

		$link = email_link( '', '', false );

		$this->assertStringContainsString( 'Email This Post', $link );
		$this->assertStringContainsString( 'email_famfamfam.png', $link );
		$this->assertStringNotContainsString( '%EMAIL_', $link );
	}

	/**
	 * Email link popup uses a data attribute not an onclick.
	 */
	public function test_email_link_popup_uses_a_data_attribute_not_an_onclick() {
		$this->go_to( get_permalink( $this->post_id ) );
		the_post();

		$options                 = WP_Email_Options::all();
		$options['link']['type'] = 2;
		WP_Email_Options::update( $options );

		$link = email_link( '', '', false );

		$this->assertStringContainsString( 'data-wp-email-popup', $link );
		$this->assertStringNotContainsString( 'onclick', $link );
	}

	/**
	 * Email link text can be overridden.
	 */
	public function test_email_link_text_can_be_overridden() {
		$this->go_to( get_permalink( $this->post_id ) );
		the_post();

		$this->assertStringContainsString( 'Send this along', email_link( 'Send this along', '', false ) );
	}

	/**
	 * Title template expands.
	 */
	public function test_title_template_expands() {
		$this->go_to( get_permalink( $this->post_id ) );
		the_post();

		$options                       = WP_Email_Options::all();
		$options['templates']['title'] = 'T: %EMAIL_POST_TITLE% / %EMAIL_BLOG_NAME%';
		WP_Email_Options::update( $options );

		$title = email_title( 'ignored' );

		$this->assertStringContainsString( 'T: Harness Post', $title );
		$this->assertStringNotContainsString( '%EMAIL_', $title );
	}

	/**
	 * Title filter is a passthrough outside the loop.
	 */
	public function test_title_filter_is_a_passthrough_outside_the_loop() {
		$this->assertSame( 'untouched', email_title( 'untouched' ) );
	}

	/**
	 * Snippet cuts the content at the word limit.
	 */
	public function test_snippet_cuts_the_content_at_the_word_limit() {
		$this->go_to( get_permalink( $this->post_id ) );
		the_post();

		$options                       = WP_Email_Options::all();
		$options['sending']['snippet'] = 3;
		WP_Email_Options::update( $options );

		$content = email_content();

		$this->assertStringContainsString( 'One two three ...', $content );
		$this->assertStringNotContainsString( 'ten', $content );
	}

	/**
	 * They used to stay neutered for the rest of the request.
	 */
	public function test_email_content_restores_the_shortcodes_it_neuters() {
		$this->go_to( get_permalink( $this->post_id ) );
		the_post();

		email_content();

		// Before 3.0.0 both were left swapped for no-op callbacks, so any
		// [email_link] later in the request silently rendered nothing.
		$this->assertStringContainsString( '<a href=', do_shortcode( '[email_link]' ) );
		$this->assertSame( 'keep me', do_shortcode( '[donotemail]keep me[/donotemail]' ) );
	}

	/**
	 * Alternate content is plain text.
	 */
	public function test_alternate_content_is_plain_text() {
		$this->go_to( get_permalink( $this->post_id ) );
		the_post();

		$this->assertStringNotContainsString( '<p>', email_content_alt() );
	}

	/**
	 * Endpoints are registered.
	 */
	public function test_endpoints_are_registered() {
		global $wp_rewrite;

		$this->assertNotFalse(
			has_action( 'init', array( WP_Email::get_instance(), 'register_endpoints' ) )
		);

		// Any earlier set_permalink_structure() call in the run has already
		// re-init'd $wp_rewrite, which empties the endpoint list, so register
		// from a known-clean slate rather than depending on test order.
		$wp_rewrite->init();
		WP_Email::get_instance()->register_endpoints();

		$names = wp_list_pluck( $wp_rewrite->endpoints, 1 );

		$this->assertContains( 'email', $names );
		$this->assertContains( 'emailpopup', $names );
	}

	/**
	 * Query vars are public.
	 */
	public function test_query_vars_are_public() {
		$vars = apply_filters( 'query_vars', array() );

		$this->assertContains( 'wp_email', $vars );
		$this->assertContains( 'wp_email_popup', $vars );
	}

	/**
	 * It was printed on every page view whether or not anything used it.
	 */
	public function test_jquery_is_not_forced_into_wp_head() {
		$this->assertFalse( has_action( 'wp_head', 'email_javascripts_header' ) );
	}

	/**
	 * The script does not depend on jquery.
	 */
	public function test_the_script_does_not_depend_on_jquery() {
		do_action( 'wp_enqueue_scripts' );

		$script = wp_scripts()->query( 'wp-email' );

		$this->assertNotFalse( $script );
		$this->assertSame( array(), $script->deps );
	}
}
