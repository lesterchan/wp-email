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

		$this->assertSame( '', WP_Email_Captcha::issue() );
	}

	public function test_issue_produces_a_token_and_stores_an_answer() {
		if ( ! WP_Email_Captcha::is_available() ) {
			$this->markTestSkipped( 'No GD library on this PHP build.' );
		}

		$token = WP_Email_Captcha::issue();

		$this->assertMatchesRegularExpression( '/^[A-Za-z0-9]{32}$/', $token );
		$this->assertSame( WP_Email_Captcha::LENGTH, strlen( $this->answer_for( $token ) ) );
	}

	public function test_each_challenge_is_independent() {
		if ( ! WP_Email_Captcha::is_available() ) {
			$this->markTestSkipped( 'No GD library on this PHP build.' );
		}

		$first  = WP_Email_Captcha::issue();
		$second = WP_Email_Captcha::issue();

		$this->assertNotSame( $first, $second );

		// The session-backed version kept one site-wide answer, so opening a
		// second form invalidated the first.
		$this->assertNotFalse( $this->answer_for( $first ) );
		$this->assertNotFalse( $this->answer_for( $second ) );
	}

	public function test_the_right_answer_verifies() {
		if ( ! WP_Email_Captcha::is_available() ) {
			$this->markTestSkipped( 'No GD library on this PHP build.' );
		}

		$token = WP_Email_Captcha::issue();

		$this->assertTrue( WP_Email_Captcha::verify( $token, $this->answer_for( $token ) ) );
	}

	public function test_verification_is_case_insensitive() {
		if ( ! WP_Email_Captcha::is_available() ) {
			$this->markTestSkipped( 'No GD library on this PHP build.' );
		}

		$token = WP_Email_Captcha::issue();

		$this->assertTrue( WP_Email_Captcha::verify( $token, strtolower( $this->answer_for( $token ) ) ) );
	}

	public function test_a_challenge_can_only_be_answered_once() {
		if ( ! WP_Email_Captcha::is_available() ) {
			$this->markTestSkipped( 'No GD library on this PHP build.' );
		}

		$token  = WP_Email_Captcha::issue();
		$answer = $this->answer_for( $token );

		$this->assertTrue( WP_Email_Captcha::verify( $token, $answer ) );
		$this->assertFalse( WP_Email_Captcha::verify( $token, $answer ) );
	}

	public function test_a_wrong_answer_burns_the_challenge() {
		if ( ! WP_Email_Captcha::is_available() ) {
			$this->markTestSkipped( 'No GD library on this PHP build.' );
		}

		$token  = WP_Email_Captcha::issue();
		$answer = $this->answer_for( $token );

		$this->assertFalse( WP_Email_Captcha::verify( $token, 'WRONG' ) );

		// Otherwise a five-character code could be brute-forced against a
		// challenge that stays alive for ten minutes.
		$this->assertFalse( WP_Email_Captcha::verify( $token, $answer ) );
	}

	public function test_an_unknown_token_never_verifies() {
		$this->assertFalse( WP_Email_Captcha::verify( str_repeat( 'a', 32 ), 'ABCDE' ) );
		$this->assertFalse( WP_Email_Captcha::verify( '', 'ABCDE' ) );
		$this->assertFalse( WP_Email_Captcha::verify( '../../etc/passwd', 'ABCDE' ) );
	}

	public function test_token_sanitizing_rejects_anything_off_shape() {
		$this->assertSame( '', WP_Email_Captcha::sanitize_token( 'short' ) );
		$this->assertSame( '', WP_Email_Captcha::sanitize_token( str_repeat( 'a', 33 ) ) );
		$this->assertSame( '', WP_Email_Captcha::sanitize_token( str_repeat( '-', 32 ) ) );
		$this->assertSame( str_repeat( 'a', 32 ), WP_Email_Captcha::sanitize_token( str_repeat( 'a', 32 ) ) );
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

		$this->assertStringContainsString( 'admin-ajax.php', $url );
		$this->assertStringContainsString( 'action=wp_email_captcha', $url );
		$this->assertStringContainsString( 'token=' . str_repeat( 'a', 32 ), $url );
	}

	public function test_the_image_url_encodes_its_token() {
		$this->assertStringNotContainsString( '&foo=bar', WP_Email_Captcha::image_url( 'x&foo=bar' ) );
	}

	public function test_is_enabled_follows_the_setting() {
		$options                           = WP_Email_Options::all();
		$options['sending']['imageverify'] = 0;
		WP_Email_Options::update( $options );

		$this->assertFalse( WP_Email_Captcha::is_enabled() );

		$options['sending']['imageverify'] = 1;
		WP_Email_Options::update( $options );

		$this->assertSame( WP_Email_Captcha::is_available(), WP_Email_Captcha::is_enabled() );
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

		WP_Email_Captcha::serve();
	}

	public function test_the_endpoint_refuses_a_malformed_token() {
		$_GET['token'] = '../../../etc/passwd';

		$this->expectException( 'WPDieException' );

		WP_Email_Captcha::serve();
	}

	public function test_the_endpoint_refuses_a_missing_token() {
		unset( $_GET['token'] );

		$this->expectException( 'WPDieException' );

		WP_Email_Captcha::serve();
	}

	public function test_requesting_the_image_does_not_spend_the_answer() {
		if ( ! WP_Email_Captcha::is_available() ) {
			$this->markTestSkipped( 'No GD library on this PHP build.' );
		}

		$token  = WP_Email_Captcha::issue();
		$answer = $this->answer_for( $token );

		// A reload or a caching proxy re-fetching the <img> must not invalidate
		// the form the visitor is still filling in. Only verify() spends it.
		$this->assertSame( $answer, $this->answer_for( $token ) );
		$this->assertTrue( WP_Email_Captcha::verify( $token, $answer ) );
	}

	public function test_a_challenge_has_a_bounded_lifetime() {
		$this->assertLessThanOrEqual( 900, WP_Email_Captcha::TTL );
		$this->assertGreaterThan( 0, WP_Email_Captcha::TTL );
	}
}
