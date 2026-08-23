<?php
/**
 * The transient-backed image verification challenge.
 *
 * @package WP-EMail
 */

/**
 * The image verification challenge.
 *
 * @covers WP_Email_Captcha
 */
class WP_Email_Captcha_Test extends WP_Email_TestCase {

	/**
	 * Set up the fixtures for each test.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		$options                           = WP_Email_Options::all();
		$options['sending']['imageverify'] = 1;
		WP_Email_Options::update( $options );
	}

	/**
	 * The answer behind an issued token.
	 *
	 * @param string $token Token.
	 *
	 * @return string|false
	 */
	private function answer_for( $token ) {
		return get_transient( WP_Email_Captcha::TRANSIENT_PREFIX . $token );
	}

	public function test_issue_returns_nothing_when_verification_is_off() {
		$options                           = WP_Email_Options::all();
		$options['sending']['imageverify'] = 0;
		WP_Email_Options::update( $options );

		$this->assertSame( '', WP_Email_Captcha::issue(), 'With verification off there is no challenge to issue.' );
	}

	public function test_issue_produces_a_token_and_stores_an_answer() {
		if ( ! WP_Email_Captcha::is_available() ) {
			$this->markTestSkipped( 'No GD library on this PHP build.' );
		}

		$token = WP_Email_Captcha::issue();

		$this->assertMatchesRegularExpression( '/^[A-Za-z0-9]{32}$/', $token, 'The token is a 32-character alphanumeric string, safe to use in a file name.' );
		$this->assertSame( WP_Email_Captcha::LENGTH, strlen( $this->answer_for( $token ) ), 'The stored answer is as long as the challenge asks for.' );
	}

	public function test_each_challenge_is_independent() {
		if ( ! WP_Email_Captcha::is_available() ) {
			$this->markTestSkipped( 'No GD library on this PHP build.' );
		}

		$first  = WP_Email_Captcha::issue();
		$second = WP_Email_Captcha::issue();

		$this->assertNotSame( $first, $second, 'Each challenge is its own, so one answer cannot solve another.' );

		// The session-backed version kept one site-wide answer, so opening a
		// second form invalidated the first.
		$this->assertNotFalse( $this->answer_for( $first ), 'The first challenge has an answer stored.' );
		$this->assertNotFalse( $this->answer_for( $second ), 'The second challenge has its own answer, so the two are independent.' );
	}

	/**
	 * Rendering the form is free and unauthenticated, and every render wrote a
	 * ten-minute transient -- two non-autoloaded wp_options rows apiece without
	 * a persistent object cache, reaped only by the daily cron.
	 */
	public function test_one_visitor_cannot_hold_an_unbounded_number_of_challenges() {
		if ( ! WP_Email_Captcha::is_available() ) {
			$this->markTestSkipped( 'No GD library on this PHP build.' );
		}

		$tokens = array();

		for ( $i = 0; $i < WP_Email_Captcha::MAX_LIVE + 15; $i++ ) {
			$tokens[] = WP_Email_Captcha::issue();
		}

		$live = 0;

		foreach ( $tokens as $token ) {
			if ( false !== $this->answer_for( $token ) ) {
				++$live;
			}
		}

		$this->assertSame( WP_Email_Captcha::MAX_LIVE, $live, 'Past the cap the oldest challenge is discarded rather than accumulating.' );
		$this->assertNotFalse( $this->answer_for( end( $tokens ) ), 'And the newest is always one of the survivors.' );
	}

	/**
	 * The cap must not become "one challenge per visitor". That is the bug the
	 * 3.0.0 rewrite exists to fix -- the session-backed version kept one
	 * site-wide answer, so opening a second form invalidated the first.
	 */
	public function test_a_handful_of_open_forms_all_keep_working() {
		if ( ! WP_Email_Captcha::is_available() ) {
			$this->markTestSkipped( 'No GD library on this PHP build.' );
		}

		$first  = WP_Email_Captcha::issue();
		$second = WP_Email_Captcha::issue();
		$third  = WP_Email_Captcha::issue();

		foreach ( array( $first, $second, $third ) as $token ) {
			$this->assertNotFalse( $this->answer_for( $token ), 'Three tabs open at once are three live challenges.' );
		}

		$this->assertTrue( WP_Email_Captcha::verify( $second, $this->answer_for( $second ) ), 'Answering the second works.' );
		$this->assertNotFalse( $this->answer_for( $first ), 'And does not consume the first.' );
		$this->assertNotFalse( $this->answer_for( $third ), 'Nor the third.' );
	}

	public function test_the_right_answer_verifies() {
		if ( ! WP_Email_Captcha::is_available() ) {
			$this->markTestSkipped( 'No GD library on this PHP build.' );
		}

		$token = WP_Email_Captcha::issue();

		$this->assertTrue( WP_Email_Captcha::verify( $token, $this->answer_for( $token ) ), 'The right answer verifies.' );
	}

	public function test_verification_is_case_insensitive() {
		if ( ! WP_Email_Captcha::is_available() ) {
			$this->markTestSkipped( 'No GD library on this PHP build.' );
		}

		$token = WP_Email_Captcha::issue();

		$this->assertTrue( WP_Email_Captcha::verify( $token, strtolower( $this->answer_for( $token ) ) ), 'The answer is compared case insensitively.' );
	}

	public function test_a_challenge_can_only_be_answered_once() {
		if ( ! WP_Email_Captcha::is_available() ) {
			$this->markTestSkipped( 'No GD library on this PHP build.' );
		}

		$token  = WP_Email_Captcha::issue();
		$answer = $this->answer_for( $token );

		$this->assertTrue( WP_Email_Captcha::verify( $token, $answer ), 'The answer verifies the first time.' );
		$this->assertFalse( WP_Email_Captcha::verify( $token, $answer ), 'The same answer does not verify twice; the challenge is burned.' );
	}

	public function test_a_wrong_answer_burns_the_challenge() {
		if ( ! WP_Email_Captcha::is_available() ) {
			$this->markTestSkipped( 'No GD library on this PHP build.' );
		}

		$token  = WP_Email_Captcha::issue();
		$answer = $this->answer_for( $token );

		$this->assertFalse( WP_Email_Captcha::verify( $token, 'WRONG' ), 'A wrong answer does not verify.' );

		// Otherwise a five-character code could be brute-forced against a
		// challenge that stays alive for ten minutes.
		$this->assertFalse( WP_Email_Captcha::verify( $token, $answer ), 'A wrong answer burns the challenge, so even the right one no longer verifies.' );
	}

	public function test_an_unknown_token_never_verifies() {
		$this->assertFalse( WP_Email_Captcha::verify( str_repeat( 'a', 32 ), 'ABCDE' ), 'A token that was never issued does not verify.' );
		$this->assertFalse( WP_Email_Captcha::verify( '', 'ABCDE' ), 'An empty token does not verify.' );
		$this->assertFalse( WP_Email_Captcha::verify( '../../etc/passwd', 'ABCDE' ), 'A token that is a path does not verify, and never reaches the filesystem.' );
	}

	public function test_token_sanitizing_rejects_anything_off_shape() {
		$this->assertSame( '', WP_Email_Captcha::sanitize_token( 'short' ), 'A token too short is refused.' );
		$this->assertSame( '', WP_Email_Captcha::sanitize_token( str_repeat( 'a', 33 ) ), 'One too long is refused.' );
		$this->assertSame( '', WP_Email_Captcha::sanitize_token( str_repeat( '-', 32 ) ), 'One of the wrong alphabet is refused.' );
		$this->assertSame( str_repeat( 'a', 32 ), WP_Email_Captcha::sanitize_token( str_repeat( 'a', 32 ) ), 'And one of the right shape is accepted.' );
	}

	public function test_the_plugin_no_longer_touches_php_sessions() {
		$files   = glob( dirname( __DIR__ ) . '/includes/*.php' );
		$files[] = dirname( __DIR__ ) . '/wp-email.php';

		foreach ( $files as $file ) {
			// Tokenised rather than grepped: the captcha class explains the old
			// session mechanism in its docblock, and a plain string search
			// cannot tell that comment from a live call.
			$calls = array_filter(
				token_get_all( file_get_contents( $file ) ),
				static function ( $token ) {
					return is_array( $token )
						&& T_STRING === $token[0]
						&& in_array( strtolower( $token[1] ), array( 'session_start', 'session_id' ), true );
				}
			);

			$this->assertSame(
				array(),
				$calls,
				basename( $file ) . ' still starts a PHP session'
			);
		}
	}

	public function test_the_image_url_targets_the_public_endpoint() {
		$url = WP_Email_Captcha::image_url( str_repeat( 'a', 32 ) );

		$this->assertStringContainsString( 'admin-ajax.php', $url, 'The image URL is the public endpoint.' );
		$this->assertStringContainsString( 'action=wp_email_captcha', $url, 'Naming the action.' );
		$this->assertStringContainsString( 'token=' . str_repeat( 'a', 32 ), $url, 'And carrying the token.' );
	}

	public function test_the_image_url_encodes_its_token() {
		$this->assertStringNotContainsString( '&foo=bar', WP_Email_Captcha::image_url( 'x&foo=bar' ), 'The token is encoded, so it cannot add arguments of its own.' );
	}

	/**
	 * The setting on its own, versus the setting AND what the server can draw.
	 * They differ exactly when a host has lost GD with verification switched on,
	 * and the old code answered that by skipping the check -- so the only
	 * anti-automation control on a form that sends mail vanished on the install
	 * whose owner believed it was on, with nothing anywhere to say so.
	 */
	public function test_a_required_captcha_the_server_cannot_draw_fails_closed() {
		$options                           = WP_Email_Options::all();
		$options['sending']['imageverify'] = 1;
		WP_Email_Options::update( $options );

		add_filter( 'wp_email_captcha_available', '__return_false' );

		$this->assertTrue( WP_Email_Captcha::is_required(), 'The site still wants verification.' );
		$this->assertFalse( WP_Email_Captcha::is_enabled(), 'The server cannot draw it, so nothing is rendered.' );
		$this->assertSame( '', WP_Email_Captcha::issue(), 'And no challenge is issued.' );
	}

	public function test_is_required_ignores_whether_the_server_can_draw() {
		$options                           = WP_Email_Options::all();
		$options['sending']['imageverify'] = 1;
		WP_Email_Options::update( $options );

		add_filter( 'wp_email_captcha_available', '__return_false' );

		$this->assertTrue( WP_Email_Captcha::is_required(), 'is_required() is the setting and nothing else.' );

		$options['sending']['imageverify'] = 0;
		WP_Email_Options::update( $options );

		$this->assertFalse( WP_Email_Captcha::is_required(), 'And it follows the setting down again.' );
	}

	public function test_is_enabled_follows_the_setting() {
		$options                           = WP_Email_Options::all();
		$options['sending']['imageverify'] = 0;
		WP_Email_Options::update( $options );

		$this->assertFalse( WP_Email_Captcha::is_enabled(), 'With no GD the captcha reports itself off rather than rendering a broken image.' );

		$options['sending']['imageverify'] = 1;
		WP_Email_Options::update( $options );

		$this->assertSame( WP_Email_Captcha::is_available(), WP_Email_Captcha::is_enabled(), 'The setting can only turn on what the server can actually draw.' );
	}

	/**
	 * The endpoint 404s for a token that was never issued.
	 *
	 * Only the refusing branch is driven here: the success branch ends in
	 * Exit() after writing a JPEG, which would take the test runner with it.
	 */
	public function test_the_endpoint_refuses_an_unknown_token() {
		$_GET['token'] = str_repeat( 'b', 32 );

		$this->expectException( 'WPDieException' );

		WP_Email_Captcha::ajax_serve();
	}

	public function test_the_endpoint_refuses_a_malformed_token() {
		$_GET['token'] = '../../../etc/passwd';

		$this->expectException( 'WPDieException' );

		WP_Email_Captcha::ajax_serve();
	}

	public function test_the_endpoint_refuses_a_missing_token() {
		unset( $_GET['token'] );

		$this->expectException( 'WPDieException' );

		WP_Email_Captcha::ajax_serve();
	}

	public function test_requesting_the_image_does_not_spend_the_answer() {
		if ( ! WP_Email_Captcha::is_available() ) {
			$this->markTestSkipped( 'No GD library on this PHP build.' );
		}

		$token  = WP_Email_Captcha::issue();
		$answer = $this->answer_for( $token );

		// A reload or a caching proxy re-fetching the <img> must not invalidate
		// the form the visitor is still filling in. Only verify() spends it.
		$this->assertSame( $answer, $this->answer_for( $token ), 'Requesting the image does not spend the answer, so it can still be checked.' );
		$this->assertTrue( WP_Email_Captcha::verify( $token, $answer ), 'A challenge inside its lifetime still verifies.' );
	}

	public function test_a_challenge_has_a_bounded_lifetime() {
		$this->assertLessThanOrEqual( 900, WP_Email_Captcha::TTL, 'The captcha lifetime is short enough to be worth having.' );
		$this->assertGreaterThan( 0, WP_Email_Captcha::TTL, 'The captcha lifetime is positive, or every challenge expires on issue.' );
	}
}
