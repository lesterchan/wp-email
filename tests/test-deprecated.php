<?php
/**
 * The backwards compatibility shims.
 *
 * @package WP-EMail
 */

/**
 * Tests that every kept shim still reaches the class behind it.
 *
 * These are the whole reason the unprefixed helpers were kept at global scope
 * rather than deleted, so if they do not reach the classes the decision bought
 * nothing.
 *
 * @coversNothing
 */
class WP_Email_Deprecated_Test extends WP_Email_TestCase {

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
			)
		);

		$_SERVER['REMOTE_ADDR'] = '198.51.100.5';
	}

	/**
	 * Clear the fixtures after each test.
	 *
	 * @return void
	 */
	public function tear_down() {
		unset( $_SERVER['REMOTE_ADDR'] );

		parent::tear_down();
	}

	/**
	 * Put the fixture into the loop.
	 *
	 * @return void
	 */
	private function loop() {
		$this->go_to( get_permalink( $this->post_id ) );
		the_post();
	}


	public function test_the_shimmed_names_all_exist() {
		$names = array(
			'get_ipaddress',
			'is_valid_name',
			'is_valid_email',
			'is_valid_remarks',
			'snippet_words',
			'snippet_text',
			'email_addfilters',
			'email_removefilters',
			'email_meta_nofollow',
			'process_email_form',
		);

		foreach ( $names as $name ) {
			$this->assertTrue( function_exists( $name ), "{$name}() went missing" );
		}
	}

	public function test_the_shared_helpers_are_still_guarded() {
		$source = file_get_contents( dirname( __DIR__ ) . '/includes/deprecated.php' );

		// wp-print and wp-postratings define some of these too, and whichever
		// plugin loads first has always won.
		foreach ( array( 'get_ipaddress', 'is_valid_name', 'is_valid_email', 'is_valid_remarks', 'snippet_words', 'snippet_text' ) as $name ) {
			$this->assertStringContainsString(
				"if ( ! function_exists( '{$name}' ) ) {",
				$source,
				"{$name}() is no longer guarded"
			);
		}
	}

	public function test_the_dead_polyfills_were_deleted() {
		$source = file_get_contents( dirname( __DIR__ ) . '/includes/deprecated.php' );

		// htmlspecialchars_decode() landed in PHP 5.1 and get_the_id() only
		// ever shadowed core's get_the_ID(); the 8.2 floor makes both dead.
		$this->assertStringNotContainsString( 'function htmlspecialchars_decode', $source, 'The polyfill for htmlspecialchars_decode is gone.' );
		$this->assertStringNotContainsString( 'function get_the_id', $source, 'And so is the one for get_the_id.' );
	}


	public function test_get_ipaddress_delegates() {
		$this->assertSame( WP_Email_Form::ip_address(), get_ipaddress(), 'The shim answers with what the class answers.' );
		$this->assertSame( '198.51.100.5', get_ipaddress(), 'Which is the remote address.' );
	}

	public function test_the_validators_delegate() {
		$this->assertTrue( is_valid_name( 'Mary Jane' ), 'The deprecated name check still accepts an ordinary name.' );
		$this->assertFalse( is_valid_name( 'Mary <b>' ), 'The deprecated name check still rejects markup.' );

		$this->assertTrue( (bool) is_valid_email( 'a@example.com' ), 'The deprecated address check still accepts a real address.' );
		$this->assertFalse( (bool) is_valid_email( 'nope' ), 'The deprecated address check still rejects a non-address.' );

		$this->assertTrue( is_valid_remarks( 'Hello there' ), 'The deprecated remarks check still accepts ordinary text.' );
		$this->assertFalse( is_valid_remarks( "hi\ncontent-type: text/html" ), 'The deprecated remarks check still rejects a header injection.' );
	}

	public function test_the_snippet_helpers_delegate() {
		$this->assertSame( 'one two ...', snippet_words( 'one two three', 2 ), 'The word snippet shim delegates.' );
		$this->assertSame( 'abcde...', snippet_text( 'abcdefghij', 5 ), 'And the character snippet shim.' );
	}

	public function test_the_filter_helpers_delegate() {
		$this->go_to( get_permalink( $this->post_id ) );

		email_addfilters( $GLOBALS['wp_query'] );
		$this->assertNotFalse( has_filter( 'the_title', array( 'WP_Email', 'filter_title' ) ), 'The deprecated wrapper attaches the filter as the real one does.' );

		email_removefilters();
		$this->assertFalse( has_filter( 'the_title', array( 'WP_Email', 'filter_title' ) ), 'The deprecated wrapper leaves it off when the real one would.' );
	}

	public function test_the_robots_helper_delegates() {
		ob_start();
		email_meta_nofollow();
		$out = ob_get_clean();

		$this->assertStringContainsString( 'noindex', $out, 'The robots shim delegates.' );
	}

	public function test_the_wpstats_wrappers_are_gone() {
		// WP-Stats 3.0.0 retired wp_stats_page_* entirely, so a wrapper round a
		// method that answered one of them had nothing left to wrap.
		foreach ( array( 'email_wpstats_instance', 'email_page_admin_general_stats', 'email_page_admin_most_stats', 'email_page_general_stats', 'email_page_most_stats' ) as $name ) {
			$this->assertFalse( function_exists( $name ), "{$name}() should have been removed" );
		}
	}


	public function test_the_counter_tags_delegate() {
		$this->assertSame( '0', get_emails( false ), 'The total counter shim delegates.' );
		$this->assertSame( '0', get_emails_success( false ), 'The success counter.' );
		$this->assertSame( '0', get_emails_failed( false ), 'And the failure counter.' );
	}

	public function test_get_email_count_defaults_to_the_current_post() {
		WP_Email_Logs::insert(
			array(
				'yourname'    => 'Alice',
				'youremail'   => 'a@example.com',
				'yourremarks' => '',
				'friendname'  => 'F',
				'friendemail' => 'f@example.com',
				'postid'      => $this->post_id,
				'posttitle'   => 'Harness Post',
				'timestamp'   => time(),
				'ip'          => '198.51.100.5',
				'host'        => '',
				'status'      => WP_Email_Logs::STATUS_SUCCESS,
			)
		);

		$this->loop();

		$this->assertSame( '1', get_email_count( 0, false ), 'A post id of zero means the post in scope.' );
	}

	public function test_the_title_and_remark_tags_delegate() {
		update_post_meta( $this->post_id, 'wp-email-remark', 'Suggested' );

		$this->loop();

		$this->assertSame( 'Harness Post', email_get_title(), 'The title shim delegates.' );
		$this->assertSame( 'Suggested', email_get_remark(), 'And the remark shim.' );
	}

	public function test_the_category_tag_delegates() {
		$term = self::factory()->category->create( array( 'name' => 'Reviews' ) );
		wp_set_post_categories( $this->post_id, array( $term ) );

		$this->loop();

		$this->assertStringContainsString( 'Reviews', email_category(), 'The category shim delegates.' );
	}

	public function test_the_content_tags_delegate() {
		$this->loop();

		$this->assertStringContainsString( 'One two three', email_content(), 'The content shim delegates.' );
		$this->assertStringNotContainsString( '<p>', email_content_alt(), 'The plain text shim gives plain text.' );
		$this->assertStringContainsString( 'One two three', get_email_content(), 'And the returning form delegates too.' );
	}

	public function test_the_page_title_tag_delegates() {
		$this->assertStringContainsString( 'E-Mail', email_pagetitle( 'Harness Post' ), 'The page title shim delegates.' );
	}

	public function test_the_interval_tag_delegates() {
		$options                        = WP_Email_Options::all();
		$options['sending']['interval'] = 7;
		WP_Email_Options::update( $options );

		$this->assertSame( 7, email_flood_interval( false ), 'The interval shim delegates.' );
	}

	public function test_not_spamming_delegates() {
		$this->assertTrue( not_spamming(), 'The deprecated throttle wrapper still answers for a first send.' );
	}

	public function test_the_multiple_hint_delegates() {
		$options                        = WP_Email_Options::all();
		$options['sending']['multiple'] = 5;
		WP_Email_Options::update( $options );

		$this->assertStringContainsString( 'Maximum 5 entries', email_multiple( false ), 'The multiple hint shim delegates.' );

		// A cap of one means there is nothing to explain.
		$options['sending']['multiple'] = 1;
		WP_Email_Options::update( $options );

		$this->assertSame( '', email_multiple( false ), 'And says nothing when there is nothing worth saying.' );
	}

	public function test_the_form_header_tags_delegate() {
		$this->set_permalink_structure( '/%postname%/' );
		$this->loop();

		$standalone = email_form_header( $this->post_id, false );
		$popup      = email_popup_form_header( false, $this->post_id );

		$this->assertStringContainsString( 'email/', $standalone, 'The header shim points at the standalone endpoint.' );
		$this->assertStringNotContainsString( 'emailpopup/', $standalone, 'And not at the popup one.' );
		$this->assertStringContainsString( 'emailpopup/', $popup, 'While the popup form points at the popup endpoint.' );

		foreach ( array( $standalone, $popup ) as $header ) {
			$this->assertStringContainsString( 'wp-email_nonce', $header, 'The deprecated header helper still prints the nonce the form needs.' );
		}
	}

	public function test_the_echoing_tags_print() {
		$this->loop();

		ob_start();
		email_link();
		$printed = ob_get_clean();

		$this->assertStringContainsString( '<a href=', $printed, 'The echoing shim prints the link.' );

		ob_start();
		get_emails();
		$this->assertSame( '0', trim( ob_get_clean() ), 'And the echoing counter prints the count.' );
	}

	public function test_the_form_tag_keeps_its_old_signature() {
		$this->loop();

		// Registered as a the_content filter and called directly by themes, so
		// the argument order cannot move.
		$form = email_form( '', false, true, true, array() );

		$this->assertStringContainsString( 'id="wp-email-content"', $form, 'The form shim keeps its old signature, content block and all.' );
		$this->assertStringContainsString( 'name="friendemail"', $form, 'And renders the form.' );
	}
}
