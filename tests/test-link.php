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

		$this->assertStringEndsWith( '/email/', WP_Email_Link::url( 1 ), 'The standalone link is the standalone endpoint.' );
		$this->assertStringEndsWith( '/emailpopup/', WP_Email_Link::url( 2 ), 'And the popup link is the popup endpoint.' );
	}

	public function test_url_falls_back_to_a_query_var_without_permalinks() {
		$this->set_permalink_structure( '' );
		$this->loop( $this->post_id );

		// 'wp_email_popup' is the registered query var; an earlier version
		// emitted 'emailpopup', which nothing ever answered to.
		$this->assertStringContainsString( 'wp_email=1', WP_Email_Link::url( 1 ), 'Without permalinks the standalone link falls back to the query var.' );
		$this->assertStringContainsString( 'wp_email_popup=1', WP_Email_Link::url( 2 ), 'And so does the popup link.' );
	}

	public function test_url_without_permalinks_keeps_the_post_id() {
		$this->set_permalink_structure( '' );
		$this->loop( $this->post_id );

		$this->assertStringContainsString( 'p=' . $this->post_id, WP_Email_Link::url( 1 ), 'Keeping the post id, which is all the query form has to go on.' );
	}


	public function test_the_default_template_draws_the_icon_beside_the_text() {
		$this->set_permalink_structure( '/%postname%/' );
		$this->loop( $this->post_id );

		$link = WP_Email_Link::render();

		$this->assertSame( 1, substr_count( $link, '<a href=' ), 'The default template draws one link, not one per token.' );
		$this->assertStringContainsString( 'wp-email-icon', $link, 'Carrying the icon.' );
		$this->assertStringContainsString( 'Email This Post', $link, 'The text.' );
		$this->assertStringContainsString( 'rel="nofollow"', $link, 'A nofollow, because there is nothing here to crawl.' );
		$this->assertStringContainsString( '/email/', $link, 'And the endpoint it points at.' );
	}

	public function test_a_template_without_the_icon_token_is_a_text_link() {
		$this->set_link( array( 'html' => '<a href="%EMAIL_URL%">Email This %POST_TYPE%</a>' ) );
		$this->loop( $this->post_id );

		$link = WP_Email_Link::render();

		$this->assertStringNotContainsString( 'wp-email-icon', $link, 'A template with no icon token draws no icon.' );
		$this->assertStringContainsString( 'Email This Post', $link, 'And is a text link.' );
	}

	public function test_a_template_holding_only_the_icon_token_is_an_icon_link() {
		$this->set_link( array( 'html' => '<a href="%EMAIL_URL%" title="Email This %POST_TYPE%">%EMAIL_ICON%</a>' ) );
		$this->loop( $this->post_id );

		$link = WP_Email_Link::render();

		$this->assertSame( 1, substr_count( $link, '<a href=' ), 'A template with only the icon token still draws one link.' );
		$this->assertStringContainsString( 'wp-email-icon', $link, 'Which is the icon.' );
		// Icon only: the wording is the link's accessible name rather than
		// anything printed beside it.
		$this->assertStringContainsString( 'title="Email This Post"', $link, 'With the text as its title, because there is no text to read.' );
	}

	public function test_the_template_expands_every_token() {
		$this->set_permalink_structure( '/%postname%/' );
		$this->set_link(
			array( 'html' => '<a href="%EMAIL_URL%" data-wp-email-popup="%EMAIL_POPUP%" title="%POST_TYPE%">%EMAIL_ICON% %POST_TYPE%</a>' )
		);
		$this->loop( $this->post_id );

		$link = WP_Email_Link::render();

		$this->assertStringContainsString( '/email/', $link, 'The URL token becomes the link.' );
		$this->assertStringContainsString( '<svg class="wp-email-icon"', $link, 'The icon token becomes the icon.' );
		$this->assertStringNotContainsString( '%EMAIL_', $link, 'And nothing of the plugin is left unexpanded.' );
		$this->assertStringNotContainsString( '%POST_TYPE%', $link, 'Nor the post type token.' );
	}

	/**
	 * The property the retired %EMAIL_TEXT% relies on for its own migration
	 * notice: a template nobody updated has to look wrong rather than empty.
	 */
	public function test_an_unrecognised_token_is_left_in_the_markup_as_written() {
		$this->set_link( array( 'html' => '<a href="%EMAIL_URL%">%EMAIL_TEXT%</a>' ) );
		$this->loop( $this->post_id );

		$this->assertStringContainsString( '%EMAIL_TEXT%', WP_Email_Link::render(), 'A token the plugin does not know is left in the markup as written.' );
	}


	public function test_popup_uses_a_data_attribute() {
		$this->set_link( array( 'type' => 2 ) );
		$this->loop( $this->post_id );

		$link = WP_Email_Link::render();

		// The pre-3.0.0 markup was onclick="email_popup(this.href); return false;".
		$this->assertStringContainsString( 'data-wp-email-popup="1"', $link, 'The popup is asked for with a data attribute.' );
		$this->assertStringNotContainsString( 'onclick', $link, 'Rather than with an inline handler.' );
	}

	public function test_standalone_says_so_rather_than_dropping_the_attribute() {
		$this->set_link( array( 'type' => 1 ) );
		$this->loop( $this->post_id );

		$link = WP_Email_Link::render();

		// The attribute is always written and carries 1 or 0. A bare token in
		// attribute position is stripped by wp_kses_post() on save, so the marker
		// has to be a value if it is to survive being saved on the settings screen.
		$this->assertStringContainsString( 'data-wp-email-popup="0"', $link, 'A standalone link says so.' );
		$this->assertStringNotContainsString( 'data-wp-email-popup="1"', $link, 'Rather than dropping the attribute and leaving the script to guess.' );
	}

	public function test_the_shipped_template_keeps_its_popup_marker_through_the_sanitizer() {
		$clean = WP_Email_Options::sanitize(
			array( 'link' => array( 'html' => WP_Email_Options::default_link_html() ) )
		);

		$this->assertStringContainsString( '%EMAIL_POPUP%', $clean['link']['html'], 'The shipped template keeps its popup marker through the sanitizer.' );
	}

	public function test_a_template_that_omits_the_popup_token_gets_no_marker() {
		$this->set_link(
			array(
				'type' => 2,
				'html' => '<a href="%EMAIL_URL%">go</a>',
			)
		);
		$this->loop( $this->post_id );

		$this->assertStringNotContainsString( 'data-wp-email-popup', WP_Email_Link::render(), 'A template that omits the token gets no marker at all.' );
	}


	public function test_the_post_type_token_becomes_the_singular_label() {
		$this->loop( $this->post_id );
		$this->assertStringContainsString( 'Email This Post', WP_Email_Link::render(), 'On a post the token becomes the post label.' );

		$this->loop( $this->page_id );
		$this->assertStringContainsString( 'Email This Page', WP_Email_Link::render(), 'And on a page the page label.' );
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

		$this->assertStringContainsString( 'Email This Book', $link, 'A custom type gets its own label.' );
		$this->assertStringNotContainsString( 'Email This Post', $link, 'Rather than the post one.' );

		unregister_post_type( 'email_book' );
	}

	public function test_the_post_type_label_falls_back_when_there_is_no_post() {
		unset( $GLOBALS['post'] );

		$this->assertSame( 'Post', WP_Email_Link::post_type_label(), 'With no post in scope the label falls back rather than being empty.' );
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

		$this->assertStringNotContainsString( '<script>alert(1)</script>', $link, 'A hostile label cannot carry a script into the link.' );
		$this->assertStringNotContainsString( 'title="Email This Say "hi"', $link, 'Nor close the title attribute early.' );
		$this->assertStringContainsString( '&quot;', $link, 'Its quotes are escaped for the attribute instead.' );

		unset( $GLOBALS['post'] );
		unregister_post_type( 'email_hostile' );
	}

	public function test_hostile_stored_markup_in_the_template_is_dropped_on_save() {
		// The template is echoed as written, so it is cleaned on the way in.
		$clean = WP_Email_Options::sanitize(
			array( 'link' => array( 'html' => '<a href="%EMAIL_URL%">go</a><script>alert(1)</script>' ) )
		);

		$this->assertStringNotContainsString( '<script>', $clean['link']['html'], 'A hostile template loses its script on save.' );
		$this->assertStringContainsString( '%EMAIL_URL%', $clean['link']['html'], 'And keeps the tokens that make it a link.' );
	}

	public function test_the_icon_is_an_inline_svg_taking_the_theme_colour() {
		$icon = WP_Email_Link::icon();

		$this->assertStringStartsWith( '<svg class="wp-email-icon"', $icon, 'The icon is an inline SVG.' );
		$this->assertStringContainsString( 'stroke="currentColor"', $icon, 'Taking the colour of the text around it.' );
		$this->assertStringNotContainsString( '<img', $icon, 'Not an image element.' );
		$this->assertStringNotContainsString( 'images/', $icon, 'And nothing under the images directory the plugin no longer ships.' );
	}

	public function test_a_decorative_icon_is_hidden_from_assistive_technology() {
		$icon = WP_Email_Link::icon();

		$this->assertStringContainsString( 'aria-hidden="true"', $icon, 'Beside text the icon is decorative, so it is hidden.' );
		$this->assertStringNotContainsString( '<title>', $icon, 'And carries no name of its own to be read out twice.' );
	}

	public function test_an_icon_standing_alone_carries_the_link_text_as_its_name() {
		$icon = WP_Email_Link::icon( 'Email This Post' );

		$this->assertStringContainsString( '<title>Email This Post</title>', $icon, 'Standing alone it carries the link text as its name.' );
		$this->assertStringNotContainsString( 'aria-hidden', $icon, 'And is not hidden, because it is the only thing to read.' );
	}

	public function test_the_icon_escapes_its_accessible_name() {
		$this->assertStringContainsString(
			'<title>Email &lt;b&gt;This&lt;/b&gt;</title>',
			WP_Email_Link::icon( 'Email <b>This</b>' ),
			'The accessible name is escaped, so markup in it reads as text.'
		);
	}
}
