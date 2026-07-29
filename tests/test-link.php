<?php
/**
 * The "Email This Post" link in each of its four styles.
 *
 * @package WP-EMail
 */

/**
 * Tests for the link renderer and its URL construction.
 *
 * @covers WP_Email_Link
 */
class Test_Email_Link extends WP_UnitTestCase {

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

	// ----------------------------------------------------------------- url --

	/**
	 * With pretty permalinks the URL hangs the endpoint off the permalink.
	 */
	public function test_url_uses_the_endpoint_with_pretty_permalinks() {
		$this->set_permalink_structure( '/%postname%/' );
		$this->loop( $this->post_id );

		$this->assertStringEndsWith( '/email/', WP_Email_Link::url( 1 ) );
		$this->assertStringEndsWith( '/emailpopup/', WP_Email_Link::url( 2 ) );
	}

	/**
	 * Without them it falls back to the registered query var.
	 */
	public function test_url_falls_back_to_a_query_var_without_permalinks() {
		$this->set_permalink_structure( '' );
		$this->loop( $this->post_id );

		// 'wp_email_popup' is the registered query var; an earlier version
		// emitted 'emailpopup', which nothing ever answered to.
		$this->assertStringContainsString( 'wp_email=1', WP_Email_Link::url( 1 ) );
		$this->assertStringContainsString( 'wp_email_popup=1', WP_Email_Link::url( 2 ) );
	}

	/**
	 * The query-var URL keeps the post's own query string intact.
	 */
	public function test_url_without_permalinks_keeps_the_post_id() {
		$this->set_permalink_structure( '' );
		$this->loop( $this->post_id );

		$this->assertStringContainsString( 'p=' . $this->post_id, WP_Email_Link::url( 1 ) );
	}

	// -------------------------------------------------------------- styles --

	/**
	 * Style 1 renders the icon and the text, both linked.
	 */
	public function test_style_one_renders_icon_and_text() {
		$this->set_permalink_structure( '/%postname%/' );
		$this->set_link(
			array(
				'style' => 1,
				'type'  => 1,
			)
		);
		$this->loop( $this->post_id );

		$link = WP_Email_Link::render();

		$this->assertSame( 2, substr_count( $link, '<a href=' ) );
		$this->assertStringContainsString( 'wp-email-icon', $link );
		$this->assertStringContainsString( 'Email This Post', $link );
		$this->assertStringContainsString( 'rel="nofollow"', $link );
	}

	/**
	 * Style 2 is the icon only.
	 */
	public function test_style_two_is_icon_only() {
		$this->set_link( array( 'style' => 2 ) );
		$this->loop( $this->post_id );

		$link = WP_Email_Link::render();

		$this->assertSame( 1, substr_count( $link, '<a href=' ) );
		$this->assertStringContainsString( 'wp-email-icon', $link );
	}

	/**
	 * Style 3 is the text only.
	 */
	public function test_style_three_is_text_only() {
		$this->set_link( array( 'style' => 3 ) );
		$this->loop( $this->post_id );

		$link = WP_Email_Link::render();

		$this->assertStringNotContainsString( 'wp-email-icon', $link );
		$this->assertStringContainsString( 'Email This Post', $link );
	}

	/**
	 * Style 4 expands the custom template.
	 */
	public function test_style_four_expands_every_token() {
		$this->set_permalink_structure( '/%postname%/' );
		$this->set_link(
			array(
				'style' => 4,
				'html'  => '<a href="%EMAIL_URL%" title="%EMAIL_TEXT%">%EMAIL_ICON% %EMAIL_TEXT%</a>',
			)
		);
		$this->loop( $this->post_id );

		$link = WP_Email_Link::render();

		$this->assertStringContainsString( '/email/', $link );
		$this->assertStringContainsString( 'Email This Post', $link );
		$this->assertStringContainsString( '<svg class="wp-email-icon"', $link );
		$this->assertStringNotContainsString( '%EMAIL_', $link );
	}

	/**
	 * An unknown style falls back to style 1 rather than rendering nothing.
	 */
	public function test_an_unknown_style_falls_back() {
		$this->set_link( array( 'style' => 99 ) );
		$this->loop( $this->post_id );

		$this->assertStringContainsString( 'wp-email-icon', WP_Email_Link::render() );
	}

	// --------------------------------------------------------------- popup --

	/**
	 * The popup link carries a data attribute, never an inline handler.
	 */
	public function test_popup_uses_a_data_attribute() {
		$this->set_link(
			array(
				'style' => 3,
				'type'  => 2,
			)
		);
		$this->loop( $this->post_id );

		$link = WP_Email_Link::render();

		// The pre-3.0.0 markup was onclick="email_popup(this.href); return false;".
		$this->assertStringContainsString( 'data-wp-email-popup', $link );
		$this->assertStringNotContainsString( 'onclick', $link );
	}

	/**
	 * The standalone link carries no popup marker.
	 */
	public function test_standalone_has_no_popup_marker() {
		$this->set_link(
			array(
				'style' => 3,
				'type'  => 1,
			)
		);
		$this->loop( $this->post_id );

		$this->assertStringNotContainsString( 'data-wp-email-popup', WP_Email_Link::render() );
	}

	/**
	 * A custom template gets the popup marker through %EMAIL_POPUP%.
	 */
	public function test_custom_template_receives_the_popup_marker() {
		$this->set_link(
			array(
				'style' => 4,
				'type'  => 2,
				'html'  => '<a href="%EMAIL_URL%" %EMAIL_POPUP%>go</a>',
			)
		);
		$this->loop( $this->post_id );

		$this->assertStringContainsString( 'data-wp-email-popup', WP_Email_Link::render() );
	}

	// ---------------------------------------------------------------- text --

	/**
	 * A page uses the page text, a post the post text.
	 */
	public function test_page_and_post_use_their_own_text() {
		$this->set_link( array( 'style' => 3 ) );

		$this->loop( $this->post_id );
		$this->assertStringContainsString( 'Email This Post', WP_Email_Link::render() );

		$this->loop( $this->page_id );
		$this->assertStringContainsString( 'Email This Page', WP_Email_Link::render() );
	}

	/**
	 * The caller's override wins over the stored option.
	 */
	public function test_an_override_wins() {
		$this->set_link( array( 'style' => 3 ) );

		$this->loop( $this->post_id );
		$this->assertStringContainsString( 'Send this along', WP_Email_Link::render( 'Send this along' ) );

		$this->loop( $this->page_id );
		$this->assertStringContainsString( 'Share this page', WP_Email_Link::render( '', 'Share this page' ) );
	}

	/**
	 * The post override does not leak onto a page.
	 */
	public function test_the_post_override_does_not_apply_to_a_page() {
		$this->set_link( array( 'style' => 3 ) );
		$this->loop( $this->page_id );

		$this->assertStringNotContainsString( 'Post Only Text', WP_Email_Link::render( 'Post Only Text' ) );
	}

	// ------------------------------------------------------------ escaping --

	/**
	 * Markup stored in the link text is escaped, not rendered.
	 */
	public function test_hostile_link_text_is_escaped() {
		// wp_kses_post() on save would normally have caught this; the option
		// could still have been written directly, so the sink escapes too.
		$options                      = WP_Email_Options::all();
		$options['link']['post_text'] = 'Bad <script>alert(1)</script>';
		WP_Email_Options::update( $options );

		$this->set_link( array( 'style' => 1 ) );
		$this->loop( $this->post_id );

		$link = WP_Email_Link::render();

		$this->assertStringNotContainsString( '<script>alert(1)</script>', $link );
	}

	/**
	 * A quote in the link text cannot break out of the title attribute.
	 */
	public function test_a_quote_in_the_text_cannot_break_the_attribute() {
		$this->set_link( array( 'style' => 1 ) );
		$this->loop( $this->post_id );

		$link = WP_Email_Link::render( 'Say "hi" now' );

		$this->assertStringNotContainsString( 'title="Say "hi" now"', $link );
		$this->assertStringContainsString( '&quot;', $link );
	}
}
