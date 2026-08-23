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
	 * Prefix for the per-visitor pointer at their live challenge.
	 *
	 * @since 3.0.0
	 */
	const POINTER_PREFIX = 'wp_email_captcha_for_';

	/**
	 * How many challenges one visitor may hold at once.
	 *
	 * Generous on purpose: a person with several tabs open is the case this
	 * protects, and nobody legitimately holds ten.
	 *
	 * @since 3.0.0
	 */
	const MAX_LIVE = 10;

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
		$available = function_exists( 'imagecreatetruecolor' )
			&& function_exists( 'imagejpeg' )
			&& function_exists( 'imagerotate' );

		/**
		 * Filters whether the server can draw a verification image.
		 *
		 * Answering false makes the form refuse every submission while the
		 * setting is on, which is the point: it is the state a host that has
		 * lost GD is already in, and the only way to exercise it on a host that
		 * has not.
		 *
		 * @since 3.0.0
		 *
		 * @param bool $available Whether the required GD functions are present.
		 */
		return (bool) apply_filters( 'wp_email_captcha_available', $available );
	}

	/**
	 * Whether the site has asked for image verification.
	 *
	 * Deliberately separate from is_enabled(): this one is the *setting*, and
	 * the difference between the two is what the form has to fail closed on.
	 *
	 * @since 3.0.0
	 *
	 * @return bool
	 */
	public static function is_required() {
		return 1 === (int) WP_Email_Options::get( 'sending', 'imageverify' );
	}

	/**
	 * Whether image verification is switched on and usable.
	 *
	 * What the *renderer* asks, because a challenge that cannot be drawn must
	 * not be drawn. What the validator asks is is_required(), and the gap
	 * between the two is deliberate: a host that loses GD across a PHP upgrade
	 * leaves this false while the stored setting still says the site wants
	 * verification, and answering that by quietly accepting every submission is
	 * how the only anti-automation control on the form disappears with nothing
	 * in any log to say so.
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		return self::is_required() && self::is_available();
	}

	/**
	 * Issue a challenge and return its token.
	 *
	 * A fresh challenge every time, but no more than MAX_LIVE of them alive per
	 * visitor at once.
	 *
	 * Rendering the form is free and unauthenticated, and every GET of
	 * `/post/email/` or `/post/emailpopup/` -- and every failed submission --
	 * wrote a transient that lived ten minutes. With no persistent object cache
	 * that is two non-autoloaded wp_options rows apiece, reaped only by the
	 * daily cron, so a request loop grew the table by millions of rows inside
	 * the hour.
	 *
	 * Handing back the *same* challenge instead would bound it in one line, and
	 * would reintroduce the bug the 3.0.0 rewrite exists to fix: the
	 * session-backed version kept one site-wide answer, so opening a second form
	 * invalidated the first. Two tabs have to keep working. So the count is
	 * capped rather than collapsed -- the oldest challenge is discarded when a
	 * visitor is holding too many, which no real person ever is.
	 *
	 * The design note that the image endpoint must not be able to mint a
	 * challenge is untouched; this is about the form endpoint not minting an
	 * unbounded number.
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

		self::remember( $token );

		return $token;
	}

	/**
	 * Track this visitor's live challenges, discarding the oldest past the cap.
	 *
	 * @since 3.0.0
	 *
	 * @param string $token The challenge just issued.
	 * @return void
	 */
	protected static function remember( $token ) {
		$pointer = self::POINTER_PREFIX . md5( WP_Email_Form::ip_address() );
		$live    = get_transient( $pointer );
		$live    = is_array( $live ) ? $live : array();

		// Anything verify() has already consumed, or that has simply expired, is
		// not this visitor's to be charged for.
		$live = array_values(
			array_filter(
				$live,
				static function ( $held ) {
					return false !== get_transient( self::TRANSIENT_PREFIX . $held );
				}
			)
		);

		$live[] = $token;

		for ( $over = count( $live ) - self::MAX_LIVE; $over > 0; $over-- ) {
			delete_transient( self::TRANSIENT_PREFIX . array_shift( $live ) );
		}

		set_transient( $pointer, $live, self::TTL );
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
	public static function ajax_serve() {
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

		imagejpeg( self::draw( $code ) );

		exit;
	}

	/**
	 * Draw the challenge.
	 *
	 * The 3.0.0 image was 55x15, pure black on pure white, one imagestring()
	 * call: the built-in GD font at a fixed pitch from a fixed origin, no noise,
	 * no distortion, no rotation, and an alphabet of thirty-two glyphs. Thirty-two
	 * reference bitmaps and a per-cell crop read it perfectly, which made the
	 * only anti-automation control on a form that sends mail worth nothing
	 * against anything but a person.
	 *
	 * GD's bitmap fonts cannot be rotated in place, so each character is drawn
	 * into its own small canvas, turned, and copied down at an offset of its
	 * own. That is what breaks the fixed grid a template matcher needs: there is
	 * no longer a cell to crop. The speckle and the two lines are secondary --
	 * they cost an attacker a denoising pass, where the jitter and rotation cost
	 * them the segmentation step entirely.
	 *
	 * Still not a hard captcha, and it is not trying to be. It is trying not to
	 * be free.
	 *
	 * @since 3.0.0
	 *
	 * @param string $code The answer to draw.
	 *
	 * @return resource|GdImage
	 */
	protected static function draw( $code ) {
		$width  = 150;
		$height = 44;

		$image = imagecreatetruecolor( $width, $height );

		$background = imagecolorallocate( $image, 255, 255, 255 );
		imagefilledrectangle( $image, 0, 0, $width, $height, $background );

		// Speckle first, so the glyphs sit on top of it and a "delete every
		// isolated pixel" pass cannot also lift parts of the answer.
		for ( $i = 0; $i < 320; $i++ ) {
			$speckle = imagecolorallocate( $image, wp_rand( 140, 215 ), wp_rand( 140, 215 ), wp_rand( 140, 215 ) );
			imagesetpixel( $image, wp_rand( 0, $width - 1 ), wp_rand( 0, $height - 1 ), $speckle );
		}

		$length = strlen( $code );
		$step   = (int) floor( ( $width - 20 ) / max( 1, $length ) );

		for ( $i = 0; $i < $length; $i++ ) {
			// One character on its own transparent canvas, so imagerotate() has
			// something to turn. 5 is the largest built-in font.
			$glyph = imagecreatetruecolor( 14, 22 );
			$clear = imagecolorallocate( $glyph, 255, 255, 255 );
			imagefilledrectangle( $glyph, 0, 0, 14, 22, $clear );
			imagecolortransparent( $glyph, $clear );

			$ink = imagecolorallocate( $glyph, wp_rand( 0, 90 ), wp_rand( 0, 90 ), wp_rand( 0, 90 ) );
			imagestring( $glyph, 5, 2, 1, $code[ $i ], $ink );

			$rotated = imagerotate( $glyph, wp_rand( -28, 28 ), $clear );
			imagecolortransparent( $rotated, $clear );

			imagecopy(
				$image,
				$rotated,
				10 + ( $i * $step ) + wp_rand( -3, 3 ),
				wp_rand( 2, $height - 30 ),
				0,
				0,
				imagesx( $rotated ),
				imagesy( $rotated )
			);
		}

		// Drawn last and in the same ink range as the glyphs, so they cannot be
		// separated out by colour.
		for ( $i = 0; $i < 2; $i++ ) {
			$line = imagecolorallocate( $image, wp_rand( 0, 90 ), wp_rand( 0, 90 ), wp_rand( 0, 90 ) );
			imageline( $image, 0, wp_rand( 0, $height ), $width, wp_rand( 0, $height ), $line );
		}

		// No imagedestroy(): deprecated in PHP 8.0, where GdImage instances are
		// freed by the garbage collector like any other object.
		return $image;
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
