<?php
/**
 * Template variable expansion and the values fed into it.
 *
 * @package WP-EMail
 */

/**
 * Tests for the %VARIABLE% expander and the values it is fed.
 *
 * @covers WP_Email_Template
 */
class WP_Email_Template_Test extends WP_Email_TestCase {

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


	public function test_expand_replaces_tokens() {
		$this->assertSame(
			'Hi Bob, from Site',
			WP_Email_Template::expand(
				'Hi %NAME%, from %SITE%',
				array(
					'NAME' => 'Bob',
					'SITE' => 'Site',
				)
			)
		);
	}

	public function test_expand_replaces_every_occurrence() {
		$this->assertSame(
			'Bob and Bob',
			WP_Email_Template::expand( '%NAME% and %NAME%', array( 'NAME' => 'Bob' ) )
		);
	}

	public function test_expand_leaves_unknown_tokens_visible() {
		// A typo in a template should be obvious to whoever wrote it, not
		// silently swallowed.
		$this->assertSame(
			'%NOPE% Bob',
			WP_Email_Template::expand( '%NOPE% %NAME%', array( 'NAME' => 'Bob' ) )
		);
	}

	public function test_expand_does_not_rescan_replacements() {
		$out = WP_Email_Template::expand(
			'%A%',
			array(
				'A' => '%B%',
				'B' => 'should not appear',
			)
		);

		$this->assertSame( '%B%', $out );
	}

	public function test_expand_casts_non_strings() {
		$this->assertSame( '42', WP_Email_Template::expand( '%N%', array( 'N' => 42 ) ) );
	}

	public function test_expand_handles_an_empty_template() {
		$this->assertSame( '', WP_Email_Template::expand( '', array( 'A' => 'b' ) ) );
	}


	public function test_post_vars_describes_the_current_post() {
		$this->loop();

		$vars = WP_Email_Template::post_vars();

		$this->assertSame( 'Harness Post', $vars['EMAIL_POST_TITLE'] );
		$this->assertSame( get_bloginfo( 'name' ), $vars['EMAIL_BLOG_NAME'] );
		$this->assertSame( get_bloginfo( 'url' ), $vars['EMAIL_BLOG_URL'] );
		$this->assertSame( get_permalink( $this->post_id ), $vars['EMAIL_PERMALINK'] );
		$this->assertNotEmpty( $vars['EMAIL_POST_DATE'] );
	}

	public function test_post_vars_supplies_everything_the_screen_advertises() {
		$this->loop();

		$supplied = array_keys( WP_Email_Template::post_vars() );

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

		foreach ( WP_Email_Settings::template_meta() as $key => $meta ) {
			foreach ( $meta['vars'] as $var ) {
				$this->assertTrue(
					in_array( $var, $supplied, true ) || in_array( $var, $from_submission, true ),
					"Template '{$key}' advertises %{$var}% but nothing supplies it"
				);
			}
		}
	}


	public function test_title_uses_the_post_title() {
		$this->loop();

		$this->assertSame( 'Harness Post', WP_Email_Template::title() );
	}

	public function test_title_honours_the_meta_override() {
		update_post_meta( $this->post_id, 'wp-email-title', 'Override Title' );

		$this->loop();

		$this->assertSame( 'Override Title', WP_Email_Template::title() );
	}

	public function test_title_marks_a_protected_post() {
		$protected = self::factory()->post->create(
			array(
				'post_title'    => 'Secret',
				'post_password' => 'hunter2',
			)
		);

		$this->loop( $protected );

		$this->assertStringContainsString( 'Protected:', WP_Email_Template::title() );
		$this->assertStringContainsString( 'Secret', WP_Email_Template::title() );
	}

	public function test_title_marks_a_private_post() {
		$private = self::factory()->post->create(
			array(
				'post_title'  => 'Hidden',
				'post_status' => 'private',
			)
		);

		$GLOBALS['post'] = get_post( $private );

		$this->assertStringContainsString( 'Private:', WP_Email_Template::title() );
	}

	public function test_title_without_a_post_is_empty() {
		$GLOBALS['post'] = null;

		$this->assertSame( '', WP_Email_Template::title() );
	}


	public function test_remark_reads_the_post_meta() {
		update_post_meta( $this->post_id, 'wp-email-remark', 'Worth a read' );

		$this->loop();

		$this->assertSame( 'Worth a read', WP_Email_Template::remark() );
	}

	public function test_remark_is_empty_when_unset() {
		$this->loop();

		$this->assertSame( '', WP_Email_Template::remark() );
	}


	public function test_category_lists_the_terms() {
		$term = self::factory()->category->create( array( 'name' => 'Reviews' ) );
		wp_set_post_categories( $this->post_id, array( $term ) );

		$this->loop();

		$this->assertStringContainsString( 'Reviews', WP_Email_Template::category() );
		$this->assertStringContainsString( '<a href=', WP_Email_Template::category() );
	}

	public function test_category_accepts_a_separator() {
		$one = self::factory()->category->create( array( 'name' => 'Alpha' ) );
		$two = self::factory()->category->create( array( 'name' => 'Beta' ) );
		wp_set_post_categories( $this->post_id, array( $one, $two ) );

		$this->loop();

		$this->assertStringContainsString( ' | ', WP_Email_Template::category( ' | ' ) );
	}


	public function test_content_returns_the_whole_body() {
		$this->loop();

		$this->assertStringContainsString( 'One two three', WP_Email_Template::content() );
		$this->assertStringContainsString( 'ten', WP_Email_Template::content() );
	}

	public function test_content_respects_the_snippet_setting() {
		$options                       = WP_Email_Options::all();
		$options['sending']['snippet'] = 3;
		WP_Email_Options::update( $options );

		$this->loop();

		$content = WP_Email_Template::content();

		$this->assertStringContainsString( 'One two three ...', $content );
		$this->assertStringNotContainsString( 'ten', $content );
	}

	public function test_content_alt_strips_markup() {
		$this->assertStringNotContainsString( '<p>', WP_Email_Template::content_alt() );
	}

	public function test_raw_content_refuses_a_protected_post() {
		$protected = self::factory()->post->create(
			array(
				'post_content'  => 'Secret body text',
				'post_password' => 'hunter2',
			)
		);

		$this->loop( $protected );

		$content = WP_Email_Template::raw_content();

		$this->assertStringNotContainsString( 'Secret body text', $content );
		$this->assertStringContainsString( 'Password Protected Post', $content );
	}

	public function test_raw_content_flattens_a_multipage_post() {
		$paged = self::factory()->post->create(
			array( 'post_content' => 'Page one body.<!--nextpage-->Page two body.' )
		);

		$this->loop( $paged );

		$content = WP_Email_Template::raw_content();

		// The e-mail carries the whole article, never a single <!--nextpage-->
		// slice.
		$this->assertStringContainsString( 'Page one body.', $content );
		$this->assertStringContainsString( 'Page two body.', $content );
	}

	public function test_raw_content_neuters_its_own_shortcodes() {
		$with_link = self::factory()->post->create(
			array( 'post_content' => 'Before [email_link] after [donotemail]hidden[/donotemail]' )
		);

		$this->loop( $with_link );

		$content = WP_Email_Template::raw_content();

		// An e-mail must not carry the "email this" link that produced it, nor
		// content the author marked as not-for-email.
		$this->assertStringNotContainsString( 'hidden', $content );
		$this->assertStringContainsString( 'Before', $content );
		$this->assertStringContainsString( 'after', $content );
	}

	public function test_raw_content_restores_the_shortcodes() {
		$this->loop();

		WP_Email_Template::raw_content();

		$this->assertSame( 'kept', do_shortcode( '[donotemail]kept[/donotemail]' ) );
		$this->assertStringContainsString( '<a href=', do_shortcode( '[email_link]' ) );
	}

	public function test_raw_content_does_not_disturb_other_shortcodes() {
		add_shortcode( 'harness_other', static fn() => 'OTHER' );

		$this->loop();
		WP_Email_Template::raw_content();

		$this->assertSame( 'OTHER', do_shortcode( '[harness_other]' ) );

		remove_shortcode( 'harness_other' );
	}


	public function test_words_trims_to_the_word_count() {
		$this->assertSame( 'one two three ...', WP_Email_Template::words( 'one two three four five', 3 ) );
	}

	public function test_words_handles_a_length_beyond_the_text() {
		$this->assertSame( 'one two ...', WP_Email_Template::words( 'one two', 10 ) );
	}


	public function test_characters_trims_to_the_character_count() {
		$this->assertSame( 'abcde...', WP_Email_Template::characters( 'abcdefghij', 5 ) );
	}

	public function test_characters_leaves_short_text_alone() {
		$this->assertSame( 'abc', WP_Email_Template::characters( 'abc', 10 ) );
	}

	public function test_characters_encodes_markup() {
		$out = WP_Email_Template::characters( 'Bad <script>alert(1)</script>', 100 );

		$this->assertStringNotContainsString( '<script>', $out );
		$this->assertStringContainsString( '&lt;script&gt;', $out );
	}
}
