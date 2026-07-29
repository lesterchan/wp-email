<?php
/**
 * WP-EMail class-wp-email-template.php
 *
 * @package WP-EMail
 */

defined( 'ABSPATH' ) || exit;

/**
 * Expands the %VARIABLE% templates and builds the values they are fed.
 *
 * @since 3.0.0
 */
class WP_Email_Template {

	/**
	 * Replace %VARIABLE% tokens in a template.
	 *
	 * Unknown tokens are left alone rather than blanked, so a typo in a
	 * template is visible to whoever wrote it instead of silently vanishing.
	 *
	 * @param string $template Template text.
	 * @param array  $vars     Token name (without the % signs) => replacement.
	 *
	 * @return string
	 */
	public static function expand( $template, array $vars ) {
		$pairs = array();

		foreach ( $vars as $name => $value ) {
			$pairs[ '%' . $name . '%' ] = (string) $value;
		}

		// strtr() rather than str_replace(): str_replace() walks the array and
		// re-scans its own output, so a value that happens to contain another
		// token would itself be expanded. strtr() makes a single pass.
		return strtr( (string) $template, $pairs );
	}

	/**
	 * The values shared by every template: the current post and the site.
	 *
	 * @return array
	 */
	public static function post_vars() {
		$category = self::category();

		return array(
			'EMAIL_POST_TITLE'    => self::title(),
			'EMAIL_POST_AUTHOR'   => get_the_author(),
			'EMAIL_POST_DATE'     => get_the_time( get_option( 'date_format' ) . ' (' . get_option( 'time_format' ) . ')', '', '', false ),
			'EMAIL_POST_CATEGORY' => $category,
			'EMAIL_BLOG_NAME'     => get_bloginfo( 'name' ),
			'EMAIL_BLOG_URL'      => get_bloginfo( 'url' ),
			'EMAIL_PERMALINK'     => get_permalink(),
		);
	}

	/**
	 * The post's categories as a linked list.
	 *
	 * @param string $separator Separator between categories.
	 * @param string $parents   How to display parents. See get_the_category_list().
	 *
	 * @return string
	 */
	public static function category( $separator = null, $parents = '' ) {
		if ( null === $separator ) {
			$separator = __( ',', 'wp-email' ) . ' ';
		}

		return get_the_category_list( $separator, $parents );
	}

	/**
	 * The title to use for the current post.
	 *
	 * Honours the wp-email-title post meta override, and mirrors core's
	 * "Protected:" / "Private:" prefixes.
	 *
	 * @return string
	 */
	public static function title() {
		$post = get_post();

		if ( ! $post ) {
			return '';
		}

		$post_title = get_post_meta( $post->ID, 'wp-email-title', true );

		if ( empty( $post_title ) ) {
			$post_title = $post->post_title;
		}

		if ( ! empty( $post->post_password ) ) {
			/* translators: %s: Post title. */
			$post_title = sprintf( __( 'Protected: %s', 'wp-email' ), $post_title );
		} elseif ( 'private' === $post->post_status ) {
			/* translators: %s: Post title. */
			$post_title = sprintf( __( 'Private: %s', 'wp-email' ), $post_title );
		}

		return $post_title;
	}

	/**
	 * The pre-filled remark for the current post, if the author set one.
	 *
	 * @return string
	 */
	public static function remark() {
		$post = get_post();

		return $post ? (string) get_post_meta( $post->ID, 'wp-email-remark', true ) : '';
	}

	/**
	 * The post content as it should appear in an HTML e-mail.
	 *
	 * @return string
	 */
	public static function content() {
		return self::maybe_snippet( self::raw_content() );
	}

	/**
	 * The post content as it should appear in a plain text e-mail.
	 *
	 * @return string
	 */
	public static function content_alt() {
		remove_filter( 'the_content', 'wptexturize' );

		return self::maybe_snippet( wp_strip_all_tags( self::raw_content() ) );
	}

	/**
	 * Cut the content down to the configured word count, if one is set.
	 *
	 * @param string $content Content to trim.
	 *
	 * @return string
	 */
	private static function maybe_snippet( $content ) {
		$snippet = (int) WP_Email_Options::get( 'sending', 'snippet' );

		return $snippet > 0 ? self::words( $content, $snippet ) : $content;
	}

	/**
	 * The current post's content, with the plugin's own shortcodes neutered.
	 *
	 * @return string
	 */
	public static function raw_content() {
		global $pages, $multipage, $numpages;

		if ( post_password_required() ) {
			return __( 'Password Protected Post', 'wp-email' );
		}

		$content = '';

		if ( $multipage && is_array( $pages ) ) {
			for ( $page = 0; $page < $numpages; $page++ ) {
				$content .= isset( $pages[ $page ] ) ? $pages[ $page ] : '';
			}
		} elseif ( is_array( $pages ) && isset( $pages[0] ) ) {
			$content = $pages[0];
		}

		$content = html_entity_decode( $content );
		$content = htmlspecialchars_decode( $content );

		// The e-mail body must not carry the "email this" link that produced
		// it, nor any content the author marked as not-for-email. Both are
		// restored afterwards so the rest of the request is unaffected -- the
		// pre-3.0.0 code swapped them out and left them swapped.
		$restore = array();

		foreach ( array( 'email_link', 'donotemail', 'donotprint' ) as $tag ) {
			if ( shortcode_exists( $tag ) ) {
				$restore[ $tag ] = $GLOBALS['shortcode_tags'][ $tag ];
				remove_shortcode( $tag );
				add_shortcode( $tag, '__return_empty_string' );
			}
		}

		$content = apply_filters( 'the_content', $content );

		foreach ( $restore as $tag => $callback ) {
			remove_shortcode( $tag );
			add_shortcode( $tag, $callback );
		}

		return $content;
	}

	/**
	 * Trim text to a number of words.
	 *
	 * @param string $text   Text to trim.
	 * @param int    $length Word count.
	 *
	 * @return string
	 */
	public static function words( $text, $length = 0 ) {
		$words = explode( ' ', (string) $text );

		return implode( ' ', array_slice( $words, 0, (int) $length ) ) . ' ...';
	}

	/**
	 * Trim text to a number of characters, entity-encoding the result.
	 *
	 * @param string $text   Text to trim.
	 * @param int    $length Character count.
	 *
	 * @return string
	 */
	public static function characters( $text, $length = 0 ) {
		$charset = get_option( 'blog_charset' );
		$text    = html_entity_decode( (string) $text, ENT_QUOTES, $charset );

		if ( mb_strlen( $text ) > $length ) {
			return htmlentities( mb_substr( $text, 0, (int) $length ), ENT_COMPAT, $charset ) . '...';
		}

		return htmlentities( $text, ENT_COMPAT, $charset );
	}
}
