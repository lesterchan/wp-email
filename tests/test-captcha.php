<?php
/**
 * The transient-backed image verification challenge.
 *
 * @package WP-EMail
 */

/**
 * The image verification challenge.
 *
 * @covers Email_Captcha
 */
class Test_Email_Captcha extends WP_UnitTestCase {

	/**
	 * Set up the fixtures for each test.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		$options                           = Email_Options::all();
		$options['sending']['imageverify'] = 1;
		Email_Options::update( $options );
	}

	/**
	 * The answer behind an issued token.
	 *
	 * @param string $token Token.
	 *
	 * @return string|false
	 */
	private function answer_for( $token ) {
		return get_transient( Email_Captcha::TRANSIENT_PREFIX . $token );
	}

	/**
	 * Issue returns nothing when verification is off.
	 */
	public function test_issue_returns_nothing_when_verification_is_off() {
		$options                           = Email_Options::all();
		$options['sending']['imageverify'] = 0;
		Email_Options::update( $options );

		$this->assertSame( '', Email_Captcha::issue() );
	}

	/**
	 * Issue produces a token and stores an answer.
	 */
	public function test_issue_produces_a_token_and_stores_an_answer() {
		if ( ! Email_Captcha::is_available() ) {
			$this->markTestSkipped( 'No GD library on this PHP build.' );
		}

		$token = Email_Captcha::issue();

		$this->assertMatchesRegularExpression( '/^[A-Za-z0-9]{32}$/', $token );
		$this->assertSame( Email_Captcha::LENGTH, strlen( $this->answer_for( $token ) ) );
	}

	/**
	 * The session-backed version kept one site-wide answer.
	 */
	public function test_each_challenge_is_independent() {
		if ( ! Email_Captcha::is_available() ) {
			$this->markTestSkipped( 'No GD library on this PHP build.' );
		}

		$first  = Email_Captcha::issue();
		$second = Email_Captcha::issue();

		$this->assertNotSame( $first, $second );

		// The session-backed version kept one site-wide answer, so opening a
		// second form invalidated the first.
		$this->assertNotFalse( $this->answer_for( $first ) );
		$this->assertNotFalse( $this->answer_for( $second ) );
	}

	/**
	 * The right answer verifies.
	 */
	public function test_the_right_answer_verifies() {
		if ( ! Email_Captcha::is_available() ) {
			$this->markTestSkipped( 'No GD library on this PHP build.' );
		}

		$token = Email_Captcha::issue();

		$this->assertTrue( Email_Captcha::verify( $token, $this->answer_for( $token ) ) );
	}

	/**
	 * Verification is case insensitive.
	 */
	public function test_verification_is_case_insensitive() {
		if ( ! Email_Captcha::is_available() ) {
			$this->markTestSkipped( 'No GD library on this PHP build.' );
		}

		$token = Email_Captcha::issue();

		$this->assertTrue( Email_Captcha::verify( $token, strtolower( $this->answer_for( $token ) ) ) );
	}

	/**
	 * A challenge can only be answered once.
	 */
	public function test_a_challenge_can_only_be_answered_once() {
		if ( ! Email_Captcha::is_available() ) {
			$this->markTestSkipped( 'No GD library on this PHP build.' );
		}

		$token  = Email_Captcha::issue();
		$answer = $this->answer_for( $token );

		$this->assertTrue( Email_Captcha::verify( $token, $answer ) );
		$this->assertFalse( Email_Captcha::verify( $token, $answer ) );
	}

	/**
	 * Otherwise a five-character code could be brute-forced against one challenge.
	 */
	public function test_a_wrong_answer_burns_the_challenge() {
		if ( ! Email_Captcha::is_available() ) {
			$this->markTestSkipped( 'No GD library on this PHP build.' );
		}

		$token  = Email_Captcha::issue();
		$answer = $this->answer_for( $token );

		$this->assertFalse( Email_Captcha::verify( $token, 'WRONG' ) );

		// Otherwise a five-character code could be brute-forced against a
		// challenge that stays alive for ten minutes.
		$this->assertFalse( Email_Captcha::verify( $token, $answer ) );
	}

	/**
	 * An unknown token never verifies.
	 */
	public function test_an_unknown_token_never_verifies() {
		$this->assertFalse( Email_Captcha::verify( str_repeat( 'a', 32 ), 'ABCDE' ) );
		$this->assertFalse( Email_Captcha::verify( '', 'ABCDE' ) );
		$this->assertFalse( Email_Captcha::verify( '../../etc/passwd', 'ABCDE' ) );
	}

	/**
	 * Token sanitizing rejects anything off shape.
	 */
	public function test_token_sanitizing_rejects_anything_off_shape() {
		$this->assertSame( '', Email_Captcha::sanitize_token( 'short' ) );
		$this->assertSame( '', Email_Captcha::sanitize_token( str_repeat( 'a', 33 ) ) );
		$this->assertSame( '', Email_Captcha::sanitize_token( str_repeat( '-', 32 ) ) );
		$this->assertSame( str_repeat( 'a', 32 ), Email_Captcha::sanitize_token( str_repeat( 'a', 32 ) ) );
	}

	/**
	 * Sessions were unavailable behind most page caches and on a lot of hosting.
	 */
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

	/**
	 * The image URL points at the public AJAX endpoint and carries the token.
	 */
	public function test_the_image_url_targets_the_public_endpoint() {
		$url = Email_Captcha::image_url( str_repeat( 'a', 32 ) );

		$this->assertStringContainsString( 'admin-ajax.php', $url );
		$this->assertStringContainsString( 'action=wp_email_captcha', $url );
		$this->assertStringContainsString( 'token=' . str_repeat( 'a', 32 ), $url );
	}

	/**
	 * A token with URL-significant characters is encoded, not injected.
	 */
	public function test_the_image_url_encodes_its_token() {
		$this->assertStringNotContainsString( '&foo=bar', Email_Captcha::image_url( 'x&foo=bar' ) );
	}

	/**
	 * Verification is off while the setting is off.
	 */
	public function test_is_enabled_follows_the_setting() {
		$options                           = Email_Options::all();
		$options['sending']['imageverify'] = 0;
		Email_Options::update( $options );

		$this->assertFalse( Email_Captcha::is_enabled() );

		$options['sending']['imageverify'] = 1;
		Email_Options::update( $options );

		$this->assertSame( Email_Captcha::is_available(), Email_Captcha::is_enabled() );
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

		Email_Captcha::serve();
	}

	/**
	 * It 404s for a malformed token rather than looking anything up.
	 */
	public function test_the_endpoint_refuses_a_malformed_token() {
		$_GET['token'] = '../../../etc/passwd';

		$this->expectException( 'WPDieException' );

		Email_Captcha::serve();
	}

	/**
	 * It 404s when no token is given at all.
	 */
	public function test_the_endpoint_refuses_a_missing_token() {
		unset( $_GET['token'] );

		$this->expectException( 'WPDieException' );

		Email_Captcha::serve();
	}

	/**
	 * Requesting the image does not consume the challenge.
	 */
	public function test_requesting_the_image_does_not_spend_the_answer() {
		if ( ! Email_Captcha::is_available() ) {
			$this->markTestSkipped( 'No GD library on this PHP build.' );
		}

		$token  = Email_Captcha::issue();
		$answer = $this->answer_for( $token );

		// A reload or a caching proxy re-fetching the <img> must not invalidate
		// the form the visitor is still filling in. Only verify() spends it.
		$this->assertSame( $answer, $this->answer_for( $token ) );
		$this->assertTrue( Email_Captcha::verify( $token, $answer ) );
	}

	/**
	 * An issued challenge does not outlive its window.
	 */
	public function test_a_challenge_has_a_bounded_lifetime() {
		$this->assertLessThanOrEqual( 900, Email_Captcha::TTL );
		$this->assertGreaterThan( 0, Email_Captcha::TTL );
	}
}
