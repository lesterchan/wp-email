<?php
/**
 * The "Email This Post" link, built from the site's one link template.
 *
 * @package WP-EMail
 */

/**
 * Tests for the link renderer and its URL construction.
 *
 * @covers WP_Email_Link
 */
class WP_Email_Link_Test extends WP_Email_TestCase {

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

		$this->post_id = self::factory()->post->create( array( 'post_title' => 'Harness Post' ) );
		$this->page_id = self::factory()->post->create(
			array(
				'post_title' => 'Harness Page',
				'post_type'  => 'page',
			)
		);
	}

	/**
	 * Apply link settings.
	 *
	 * @param array $link Settings to merge into the link group.
	 *
	 * @return void
	 */
	private function set_link( array $link ) {
		$options         = WP_Email_Options::all();
		$options['link'] = array_merge( $options['link'], $link );
		WP_Email_Options::update( $options );
	}

	/**
	 * Put a post into the loop.
	 *
	 * @param int $id Post to load.
	 *
	 * @return void
	 */
	private function loop( $id ) {
		$this->go_to( get_permalink( $id ) );
		the_post();
	}


	public function test_url_uses_the_endpoint_with_pretty_permalinks() {
		$this->set_permalink_structure( '/%postname%/' );
		$this->loop( $this->post_id );

		$this->assertStringEndsWith( '/email/', WP_Email_Link::url( 1 ) );
		$this->assertStringEndsWith( '/emailpopup/', WP_Email_Link::url( 2 ) );
	}

	public function test_url_falls_back_to_a_query_var_without_permalinks() {
		$this->set_permalink_structure( '' );
		$this->loop( $this->post_id );

		// 'wp_email_popup' is the registered query var; an earlier version
		// emitted 'emailpopup', which nothing ever answered to.
		$this->assertStringContainsString( 'wp_email=1', WP_Email_Link::url( 1 ) );
		$this->assertStringContainsString( 'wp_email_popup=1', WP_Email_Link::url( 2 ) );
	}

	public function test_url_without_permalinks_keeps_the_post_id() {
		$this->set_permalink_structure( '' );
		$this->loop( $this->post_id );

		$this->assertStringContainsString( 'p=' . $this->post_id, WP_Email_Link::url( 1 ) );
	}


	public function test_the_default_template_draws_the_icon_beside_the_text() {
		$this->set_permalink_structure( '/%postname%/' );
		$this->loop( $this->post_id );

		$link = WP_Email_Link::render();

		$this->assertSame( 1, substr_count( $link, '<a href=' ) );
		$this->assertStringContainsString( 'wp-email-icon', $link );
		$this->assertStringContainsString( 'Email This Post', $link );
		$this->assertStringContainsString( 'rel="nofollow"', $link );
		$this->assertStringContainsString( '/email/', $link );
	}

	public function test_a_template_without_the_icon_token_is_a_text_link() {
		$this->set_link( array( 'html' => '<a href="%EMAIL_URL%">Email This %POST_TYPE%</a>' ) );
		$this->loop( $this->post_id );

		$link = WP_Email_Link::render();

		$this->assertStringNotContainsString( 'wp-email-icon', $link );
		$this->assertStringContainsString( 'Email This Post', $link );
	}

	public function test_a_template_holding_only_the_icon_token_is_an_icon_link() {
		$this->set_link( array( 'html' => '<a href="%EMAIL_URL%" title="Email This %POST_TYPE%">%EMAIL_ICON%</a>' ) );
		$this->loop( $this->post_id );

		$link = WP_Email_Link::render();

		$this->assertSame( 1, substr_count( $link, '<a href=' ) );
		$this->assertStringContainsString( 'wp-email-icon', $link );
		// Icon only: the wording is the link's accessible name rather than
		// anything printed beside it.
		$this->assertStringContainsString( 'title="Email This Post"', $link );
	}

	public function test_the_template_expands_every_token() {
		$this->set_permalink_structure( '/%postname%/' );
		$this->set_link(
			array( 'html' => '<a href="%EMAIL_URL%" data-wp-email-popup="%EMAIL_POPUP%" title="%POST_TYPE%">%EMAIL_ICON% %POST_TYPE%</a>' )
		);
		$this->loop( $this->post_id );

		$link = WP_Email_Link::render();

		$this->assertStringContainsString( '/email/', $link );
		$this->assertStringContainsString( '<svg class="wp-email-icon"', $link );
		$this->assertStringNotContainsString( '%EMAIL_', $link );
		$this->assertStringNotContainsString( '%POST_TYPE%', $link );
	}

	/**
	 * The property the retired %EMAIL_TEXT% relies on for its own migration
	 * notice: a template nobody updated has to look wrong rather than empty.
	 */
	public function test_an_unrecognised_token_is_left_in_the_markup_as_written() {
		$this->set_link( array( 'html' => '<a href="%EMAIL_URL%">%EMAIL_TEXT%</a>' ) );
		$this->loop( $this->post_id );

		$this->assertStringContainsString( '%EMAIL_TEXT%', WP_Email_Link::render() );
	}


	public function test_popup_uses_a_data_attribute() {
		$this->set_link( array( 'type' => 2 ) );
		$this->loop( $this->post_id );

		$link = WP_Email_Link::render();

		// The pre-3.0.0 markup was onclick="email_popup(this.href); return false;".
		$this->assertStringContainsString( 'data-wp-email-popup="1"', $link );
		$this->assertStringNotContainsString( 'onclick', $link );
	}

	public function test_standalone_says_so_rather_than_dropping_the_attribute() {
		$this->set_link( array( 'type' => 1 ) );
		$this->loop( $this->post_id );

		$link = WP_Email_Link::render();

		// The attribute is always written and carries 1 or 0. A bare token in
		// attribute position is stripped by wp_kses_post() on save, so the marker
		// has to be a value if it is to survive being saved on the settings screen.
		$this->assertStringContainsString( 'data-wp-email-popup="0"', $link );
		$this->assertStringNotContainsString( 'data-wp-email-popup="1"', $link );
	}

	public function test_the_shipped_template_keeps_its_popup_marker_through_the_sanitizer() {
		$clean = WP_Email_Options::sanitize(
			array( 'link' => array( 'html' => WP_Email_Options::default_link_html() ) )
		);

		$this->assertStringContainsString( '%EMAIL_POPUP%', $clean['link']['html'] );
	}

	public function test_a_template_that_omits_the_popup_token_gets_no_marker() {
		$this->set_link(
			array(
				'type' => 2,
				'html' => '<a href="%EMAIL_URL%">go</a>',
			)
		);
		$this->loop( $this->post_id );

		$this->assertStringNotContainsString( 'data-wp-email-popup', WP_Email_Link::render() );
	}


	public function test_the_post_type_token_becomes_the_singular_label() {
		$this->loop( $this->post_id );
		$this->assertStringContainsString( 'Email This Post', WP_Email_Link::render() );

		$this->loop( $this->page_id );
		$this->assertStringContainsString( 'Email This Page', WP_Email_Link::render() );
	}

	public function test_the_post_type_token_becomes_a_custom_type_own_label() {
		register_post_type(
			'email_book',
			array(
				'public'  => true,
				'labels'  => array(
					'name'          => 'Books',
					'singular_name' => 'Book',
				),
				'rewrite' => array( 'slug' => 'wp-email-book' ),
			)
		);

		$this->set_permalink_structure( '/%postname%/' );

		$book_id = self::factory()->post->create(
			array(
				'post_title' => 'Harness Book',
				'post_type'  => 'email_book',
			)
		);

		$this->loop( $book_id );

		$link = WP_Email_Link::render();

		$this->assertStringContainsString( 'Email This Book', $link );
		$this->assertStringNotContainsString( 'Email This Post', $link );

		unregister_post_type( 'email_book' );
	}

	public function test_the_post_type_label_falls_back_when_there_is_no_post() {
		unset( $GLOBALS['post'] );

		$this->assertSame( 'Post', WP_Email_Link::post_type_label() );
	}

	public function test_the_post_type_label_cannot_break_out_of_the_title_attribute() {
		register_post_type(
			'email_hostile',
			array(
				'public' => true,
				'labels' => array(
					'name'          => 'Hostiles',
					'singular_name' => 'Say "hi" <script>alert(1)</script>',
				),
			)
		);

		$hostile_id = self::factory()->post->create( array( 'post_type' => 'email_hostile' ) );

		// Straight into the loop's global rather than through a request: the type
		// is registered inside the test, so no rewrite rule matches a permalink
		// for it and the request would be a 404 with nothing in the loop.
		$GLOBALS['post'] = get_post( $hostile_id );

		$link = WP_Email_Link::render();

		$this->assertStringNotContainsString( '<script>alert(1)</script>', $link );
		$this->assertStringNotContainsString( 'title="Email This Say "hi"', $link );
		$this->assertStringContainsString( '&quot;', $link );

		unset( $GLOBALS['post'] );
		unregister_post_type( 'email_hostile' );
	}

	public function test_hostile_stored_markup_in_the_template_is_dropped_on_save() {
		// The template is echoed as written, so it is cleaned on the way in.
		$clean = WP_Email_Options::sanitize(
			array( 'link' => array( 'html' => '<a href="%EMAIL_URL%">go</a><script>alert(1)</script>' ) )
		);

		$this->assertStringNotContainsString( '<script>', $clean['link']['html'] );
		$this->assertStringContainsString( '%EMAIL_URL%', $clean['link']['html'] );
	}

	public function test_the_icon_is_an_inline_svg_taking_the_theme_colour() {
		$icon = WP_Email_Link::icon();

		$this->assertStringStartsWith( '<svg class="wp-email-icon"', $icon );
		$this->assertStringContainsString( 'stroke="currentColor"', $icon );
		$this->assertStringNotContainsString( '<img', $icon );
		$this->assertStringNotContainsString( 'images/', $icon );
	}

	public function test_a_decorative_icon_is_hidden_from_assistive_technology() {
		$icon = WP_Email_Link::icon();

		$this->assertStringContainsString( 'aria-hidden="true"', $icon );
		$this->assertStringNotContainsString( '<title>', $icon );
	}

	public function test_an_icon_standing_alone_carries_the_link_text_as_its_name() {
		$icon = WP_Email_Link::icon( 'Email This Post' );

		$this->assertStringContainsString( '<title>Email This Post</title>', $icon );
		$this->assertStringNotContainsString( 'aria-hidden', $icon );
	}

	public function test_the_icon_escapes_its_accessible_name() {
		$this->assertStringContainsString(
			'<title>Email &lt;b&gt;This&lt;/b&gt;</title>',
			WP_Email_Link::icon( 'Email <b>This</b>' )
		);
	}
}
