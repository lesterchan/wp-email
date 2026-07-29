<?php
/**
 * WP-EMail class-wp-email-captcha.php
 *
 * @package WP-EMail
 */

defined( 'ABSPATH' ) || exit;

/**
 * The image verification challenge.
 *
 * Before 3.0.0 this used a raw PHP session: email-image-verify.php called
 * session_start() outside WordPress entirely and wrote the answer to
 * $_SESSION['email_verify']. That had three problems. Sessions are unavailable
 * or non-persistent on a lot of hosting and behind most page caches, so
 * verification simply failed. The answer was a single session-wide value, so
 * two open forms invalidated each other. And any request to the image script
 * rotated that value for the whole session.
 *
 * The challenge is now issued when the form renders: the answer goes into a
 * short-lived transient under a random token, the token travels with the form,
 * and the image endpoint only renders a challenge that was already issued.
 * Creating a challenge therefore costs a form render rather than a bare hit on
 * the image URL, and each one is consumed exactly once.
 *
 * @since 3.0.0
 */
class WP_Email_Captcha {

	/**
	 * Prefix for the transient holding an issued answer.
	 */
	const TRANSIENT_PREFIX = 'wp_email_captcha_';

	/**
	 * How long an issued challenge stays valid.
	 */
	const TTL = 600;

	/**
	 * Characters used in a challenge. No O/0/I/1 -- they are unreadable at
	 * this size.
	 */
	const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

	/**
	 * Number of characters in a challenge.
	 */
	const LENGTH = 5;

	/**
	 * Whether image verification can run at all.
	 *
	 * @return bool
	 */
	public static function is_available() {
		return function_exists( 'imagecreate' ) && function_exists( 'imagejpeg' );
	}

	/**
	 * Whether image verification is switched on and usable.
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		return (int) WP_Email_Options::get( 'sending', 'imageverify' ) === 1 && self::is_available();
	}

	/**
	 * Issue a challenge and return its token.
	 *
	 * @return string Empty string when verification is not in use.
	 */
	public static function issue() {
		if ( ! self::is_enabled() ) {
			return '';
		}

		$alphabet = self::ALPHABET;
		$code     = '';

		for ( $i = 0; $i < self::LENGTH; $i++ ) {
			$code .= $alphabet[ wp_rand( 0, strlen( $alphabet ) - 1 ) ];
		}

		$token = wp_generate_password( 32, false, false );

		set_transient( self::TRANSIENT_PREFIX . $token, $code, self::TTL );

		return $token;
	}

	/**
	 * URL of the image for an issued challenge.
	 *
	 * @param string $token Token from issue().
	 *
	 * @return string
	 */
	public static function image_url( $token ) {
		return add_query_arg(
			array(
				'action' => 'wp_email_captcha',
				'token'  => rawurlencode( $token ),
			),
			admin_url( 'admin-ajax.php' )
		);
	}

	/**
	 * Check a submitted answer, consuming the challenge either way.
	 *
	 * One-shot on purpose: a correct answer cannot be replayed, and a wrong one
	 * costs the caller a fresh form load rather than letting them brute-force a
	 * five-character code against a challenge that stays alive.
	 *
	 * @param string $token  Token submitted with the form.
	 * @param string $answer Answer submitted with the form.
	 *
	 * @return bool
	 */
	public static function verify( $token, $answer ) {
		$token = self::sanitize_token( $token );

		if ( '' === $token ) {
			return false;
		}

		$expected = get_transient( self::TRANSIENT_PREFIX . $token );

		delete_transient( self::TRANSIENT_PREFIX . $token );

		if ( false === $expected ) {
			return false;
		}

		return hash_equals( (string) $expected, strtoupper( trim( (string) $answer ) ) );
	}

	/**
	 * Render the challenge image for a token.
	 *
	 * Registered on both the logged-in and logged-out AJAX actions.
	 *
	 * @return void
	 */
	public static function serve() {
		// No nonce: this is an <img src> fetched by the browser for a visitor who
		// may be logged out, so a nonce would prove nothing and would break
		// caching of the tag. The token is the only credential, and it is
		// worthless without the challenge behind it.
		$token = isset( $_GET['token'] ) ? self::sanitize_token( sanitize_text_field( wp_unslash( $_GET['token'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- An <img src> fetched by the browser for a logged-out visitor; the one-shot token is the credential and a nonce would only break caching of the tag.
		$code  = '' === $token ? false : get_transient( self::TRANSIENT_PREFIX . $token );

		// Deliberately not consumed here: the image can be re-requested (a
		// reload, a proxy) and the answer is only spent by verify().
		if ( false === $code || ! self::is_available() ) {
			status_header( 404 );
			wp_die( '', '', array( 'response' => 404 ) );
		}

		nocache_headers();
		header( 'Content-Type: image/jpeg' );
		header( 'X-Content-Type-Options: nosniff' );

		$image      = imagecreate( 55, 15 );
		$background = imagecolorallocate( $image, 255, 255, 255 );
		$foreground = imagecolorallocate( $image, 0, 0, 0 );

		imagestring( $image, 5, 5, 1, $code, $foreground );
		imagejpeg( $image );

		// No imagedestroy(): deprecated in PHP 8.0, where GdImage instances are
		// freed by the garbage collector like any other object.
		unset( $image, $background );

		exit;
	}

	/**
	 * Reduce a submitted token to the shape issue() produces, or nothing.
	 *
	 * @param mixed $token Submitted token.
	 *
	 * @return string
	 */
	public static function sanitize_token( $token ) {
		$token = (string) $token;

		return preg_match( '/^[A-Za-z0-9]{32}$/', $token ) ? $token : '';
	}
}
