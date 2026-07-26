<?php
/**
 * Template variable expansion and the values fed into it.
 *
 * @package WP-EMail
 */

/**
 * Tests for the %VARIABLE% expander and the values it is fed.
 *
 * @covers Email_Template
 */
class Test_Email_Template extends WP_UnitTestCase {

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

		$this->post_id = self::factory()->post->create(
			array(
				'post_title'   => 'Harness Post',
				'post_content' => 'One two three four five six seven eight nine ten.',
				'post_excerpt' => 'Harness excerpt.',
			)
		);
	}

	/**
	 * Put the fixture into the loop the way a theme would.
	 *
	 * @param int|null $id Post to load.
	 *
	 * @return void
	 */
	private function loop( $id = null ) {
		$this->go_to( get_permalink( $id ? $id : $this->post_id ) );
		the_post();
	}

	// ------------------------------------------------------------- expand ---

	/**
	 * Every listed token is replaced.
	 */
	public function test_expand_replaces_tokens() {
		$this->assertSame(
			'Hi Bob, from Site',
			Email_Template::expand(
				'Hi %NAME%, from %SITE%',
				array(
					'NAME' => 'Bob',
					'SITE' => 'Site',
				)
			)
		);
	}

	/**
	 * A token appearing twice is replaced both times.
	 */
	public function test_expand_replaces_every_occurrence() {
		$this->assertSame(
			'Bob and Bob',
			Email_Template::expand( '%NAME% and %NAME%', array( 'NAME' => 'Bob' ) )
		);
	}

	/**
	 * An unknown token is left alone rather than blanked.
	 */
	public function test_expand_leaves_unknown_tokens_visible() {
		// A typo in a template should be obvious to whoever wrote it, not
		// silently swallowed.
		$this->assertSame(
			'%NOPE% Bob',
			Email_Template::expand( '%NOPE% %NAME%', array( 'NAME' => 'Bob' ) )
		);
	}

	/**
	 * Replacement values are never themselves scanned for tokens.
	 */
	public function test_expand_does_not_rescan_replacements() {
		$out = Email_Template::expand(
			'%A%',
			array(
				'A' => '%B%',
				'B' => 'should not appear',
			)
		);

		$this->assertSame( '%B%', $out );
	}

	/**
	 * Non-string values are cast rather than fatalling.
	 */
	public function test_expand_casts_non_strings() {
		$this->assertSame( '42', Email_Template::expand( '%N%', array( 'N' => 42 ) ) );
	}

	/**
	 * An empty template stays empty.
	 */
	public function test_expand_handles_an_empty_template() {
		$this->assertSame( '', Email_Template::expand( '', array( 'A' => 'b' ) ) );
	}

	// ---------------------------------------------------------- post_vars ---

	/**
	 * The shared variables resolve against the post in the loop.
	 */
	public function test_post_vars_describes_the_current_post() {
		$this->loop();

		$vars = Email_Template::post_vars();

		$this->assertSame( 'Harness Post', $vars['EMAIL_POST_TITLE'] );
		$this->assertSame( get_bloginfo( 'name' ), $vars['EMAIL_BLOG_NAME'] );
		$this->assertSame( get_bloginfo( 'url' ), $vars['EMAIL_BLOG_URL'] );
		$this->assertSame( get_permalink( $this->post_id ), $vars['EMAIL_PERMALINK'] );
		$this->assertNotEmpty( $vars['EMAIL_POST_DATE'] );
	}

	/**
	 * Every variable the settings screen documents is actually supplied.
	 */
	public function test_post_vars_supplies_everything_the_screen_advertises() {
		$this->loop();

		$supplied = array_keys( Email_Template::post_vars() );

		// The sender/friend variables come from the submission, not from here.
		$from_submission = array(
			'EMAIL_YOUR_NAME',
			'EMAIL_YOUR_EMAIL',
			'EMAIL_YOUR_REMARKS',
			'EMAIL_FRIEND_NAME',
			'EMAIL_FRIEND_EMAIL',
			'EMAIL_POST_EXCERPT',
			'EMAIL_POST_CONTENT',
			'EMAIL_ERROR_MSG',
		);

		foreach ( Email_Settings::template_meta() as $key => $meta ) {
			foreach ( $meta['vars'] as $var ) {
				$this->assertTrue(
					in_array( $var, $supplied, true ) || in_array( $var, $from_submission, true ),
					"Template '{$key}' advertises %{$var}% but nothing supplies it"
				);
			}
		}
	}

	// -------------------------------------------------------------- title ---

	/**
	 * The post title is used by default.
	 */
	public function test_title_uses_the_post_title() {
		$this->loop();

		$this->assertSame( 'Harness Post', Email_Template::title() );
	}

	/**
	 * The wp-email-title meta overrides the post title.
	 */
	public function test_title_honours_the_meta_override() {
		update_post_meta( $this->post_id, 'wp-email-title', 'Override Title' );

		$this->loop();

		$this->assertSame( 'Override Title', Email_Template::title() );
	}

	/**
	 * A password-protected post is marked as such.
	 */
	public function test_title_marks_a_protected_post() {
		$protected = self::factory()->post->create(
			array(
				'post_title'    => 'Secret',
				'post_password' => 'hunter2',
			)
		);

		$this->loop( $protected );

		$this->assertStringContainsString( 'Protected:', Email_Template::title() );
		$this->assertStringContainsString( 'Secret', Email_Template::title() );
	}

	/**
	 * A private post is marked as such.
	 */
	public function test_title_marks_a_private_post() {
		$private = self::factory()->post->create(
			array(
				'post_title'  => 'Hidden',
				'post_status' => 'private',
			)
		);

		$GLOBALS['post'] = get_post( $private );

		$this->assertStringContainsString( 'Private:', Email_Template::title() );
	}

	/**
	 * With no post in context the title is empty rather than a warning.
	 */
	public function test_title_without_a_post_is_empty() {
		$GLOBALS['post'] = null;

		$this->assertSame( '', Email_Template::title() );
	}

	// ------------------------------------------------------------- remark ---

	/**
	 * The author's suggested remark is read from post meta.
	 */
	public function test_remark_reads_the_post_meta() {
		update_post_meta( $this->post_id, 'wp-email-remark', 'Worth a read' );

		$this->loop();

		$this->assertSame( 'Worth a read', Email_Template::remark() );
	}

	/**
	 * No meta means no remark, not a warning.
	 */
	public function test_remark_is_empty_when_unset() {
		$this->loop();

		$this->assertSame( '', Email_Template::remark() );
	}

	// ----------------------------------------------------------- category ---

	/**
	 * Categories render as a linked list.
	 */
	public function test_category_lists_the_terms() {
		$term = self::factory()->category->create( array( 'name' => 'Reviews' ) );
		wp_set_post_categories( $this->post_id, array( $term ) );

		$this->loop();

		$this->assertStringContainsString( 'Reviews', Email_Template::category() );
		$this->assertStringContainsString( '<a href=', Email_Template::category() );
	}

	/**
	 * A custom separator is honoured.
	 */
	public function test_category_accepts_a_separator() {
		$one = self::factory()->category->create( array( 'name' => 'Alpha' ) );
		$two = self::factory()->category->create( array( 'name' => 'Beta' ) );
		wp_set_post_categories( $this->post_id, array( $one, $two ) );

		$this->loop();

		$this->assertStringContainsString( ' | ', Email_Template::category( ' | ' ) );
	}

	// ------------------------------------------------------------ content ---

	/**
	 * The whole post body is returned when no snippet is configured.
	 */
	public function test_content_returns_the_whole_body() {
		$this->loop();

		$this->assertStringContainsString( 'One two three', Email_Template::content() );
		$this->assertStringContainsString( 'ten', Email_Template::content() );
	}

	/**
	 * A snippet setting truncates to that many words.
	 */
	public function test_content_respects_the_snippet_setting() {
		$options                       = Email_Options::all();
		$options['sending']['snippet'] = 3;
		Email_Options::update( $options );

		$this->loop();

		$content = Email_Template::content();

		$this->assertStringContainsString( 'One two three ...', $content );
		$this->assertStringNotContainsString( 'ten', $content );
	}

	/**
	 * The alternate body carries no markup.
	 */
	public function test_content_alt_strips_markup() {
		$this->assertStringNotContainsString( '<p>', Email_Template::content_alt() );
	}

	/**
	 * A password-protected post yields a placeholder, never the body.
	 */
	public function test_raw_content_refuses_a_protected_post() {
		$protected = self::factory()->post->create(
			array(
				'post_content'  => 'Secret body text',
				'post_password' => 'hunter2',
			)
		);

		$this->loop( $protected );

		$content = Email_Template::raw_content();

		$this->assertStringNotContainsString( 'Secret body text', $content );
		$this->assertStringContainsString( 'Password Protected Post', $content );
	}

	/**
	 * A multi-page post is flattened into one body.
	 */
	public function test_raw_content_flattens_a_multipage_post() {
		$paged = self::factory()->post->create(
			array( 'post_content' => 'Page one body.<!--nextpage-->Page two body.' )
		);

		$this->loop( $paged );

		$content = Email_Template::raw_content();

		// The e-mail carries the whole article, never a single <!--nextpage-->
		// slice.
		$this->assertStringContainsString( 'Page one body.', $content );
		$this->assertStringContainsString( 'Page two body.', $content );
	}

	/**
	 * The plugin's own shortcodes are neutered while the body renders.
	 */
	public function test_raw_content_neuters_its_own_shortcodes() {
		$with_link = self::factory()->post->create(
			array( 'post_content' => 'Before [email_link] after [donotemail]hidden[/donotemail]' )
		);

		$this->loop( $with_link );

		$content = Email_Template::raw_content();

		// An e-mail must not carry the "email this" link that produced it, nor
		// content the author marked as not-for-email.
		$this->assertStringNotContainsString( 'hidden', $content );
		$this->assertStringContainsString( 'Before', $content );
		$this->assertStringContainsString( 'after', $content );
	}

	/**
	 * They are restored afterwards for the rest of the request.
	 */
	public function test_raw_content_restores_the_shortcodes() {
		$this->loop();

		Email_Template::raw_content();

		$this->assertSame( 'kept', do_shortcode( '[donotemail]kept[/donotemail]' ) );
		$this->assertStringContainsString( '<a href=', do_shortcode( '[email_link]' ) );
	}

	/**
	 * A third-party shortcode is left completely alone.
	 */
	public function test_raw_content_does_not_disturb_other_shortcodes() {
		add_shortcode( 'harness_other', static fn() => 'OTHER' );

		$this->loop();
		Email_Template::raw_content();

		$this->assertSame( 'OTHER', do_shortcode( '[harness_other]' ) );

		remove_shortcode( 'harness_other' );
	}

	// -------------------------------------------------------------- words ---

	/**
	 * Words trims to the requested count.
	 */
	public function test_words_trims_to_the_word_count() {
		$this->assertSame( 'one two three ...', Email_Template::words( 'one two three four five', 3 ) );
	}

	/**
	 * Asking for more words than exist returns them all.
	 */
	public function test_words_handles_a_length_beyond_the_text() {
		$this->assertSame( 'one two ...', Email_Template::words( 'one two', 10 ) );
	}

	// --------------------------------------------------------- characters ---

	/**
	 * Characters trims and marks the truncation.
	 */
	public function test_characters_trims_to_the_character_count() {
		$this->assertSame( 'abcde...', Email_Template::characters( 'abcdefghij', 5 ) );
	}

	/**
	 * Text shorter than the limit comes back whole and unmarked.
	 */
	public function test_characters_leaves_short_text_alone() {
		$this->assertSame( 'abc', Email_Template::characters( 'abc', 10 ) );
	}

	/**
	 * Markup in a title is entity-encoded, not passed through.
	 */
	public function test_characters_encodes_markup() {
		$out = Email_Template::characters( 'Bad <script>alert(1)</script>', 100 );

		$this->assertStringNotContainsString( '<script>', $out );
		$this->assertStringContainsString( '&lt;script&gt;', $out );
	}
}
