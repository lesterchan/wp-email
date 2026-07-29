<?php
/**
 * WP-EMail class-email-link.php
 *
 * @package WP-EMail
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The "Email This Post" link, in each of its four styles.
 *
 * @since 3.0.0
 */
class Email_Link {

	/**
	 * Attribute that marks a link as opening the popup.
	 *
	 * Replaces the inline onclick="email_popup(this.href); return false;" the
	 * plugin emitted before 3.0.0. One delegated listener in js/wp-email.js picks
	 * up, which keeps the markup free of executable attributes.
	 */
	const POPUP_ATTRIBUTE = 'data-wp-email-popup="1"';

	/**
	 * Build the link.
	 *
	 * @param string $post_text Override for the post link text.
	 * @param string $page_text Override for the page link text.
	 *
	 * @return string
	 */
	public static function render( $post_text = '', $page_text = '' ) {
		$link  = Email_Options::all()['link'];
		$style = (int) $link['style'];
		$type  = (int) $link['type'];

		$text = is_page()
			? ( '' !== $page_text ? $page_text : $link['page_text'] )
			: ( '' !== $post_text ? $post_text : $link['post_text'] );

		$url   = self::url( $type );
		$icon  = plugins_url( 'images/' . $link['icon'], WP_EMAIL_MAIN_FILE );
		$popup = 2 === $type ? ' ' . self::POPUP_ATTRIBUTE . ' ' : '';

		switch ( $style ) {
			case 2:
				return self::open_tag( $url, $text, $popup )
					. self::icon_tag( $icon, $text )
					. '</a>';

			case 3:
				return self::open_tag( $url, $text, $popup ) . esc_html( $text ) . '</a>';

			case 4:
				return Email_Template::expand(
					$link['html'],
					array(
						'EMAIL_URL'      => esc_url( $url ),
						'EMAIL_POPUP'    => $popup,
						'EMAIL_TEXT'     => esc_attr( $text ),
						'EMAIL_ICON_URL' => esc_url( $icon ),
					)
				);

			case 1:
			default:
				return self::open_tag( $url, $text, $popup )
					. self::icon_tag( $icon, $text )
					. '</a>&nbsp;'
					. self::open_tag( $url, $text, $popup )
					. esc_html( $text )
					. '</a>';
		}
	}

	/**
	 * The opening anchor tag.
	 *
	 * @param string $url   Destination.
	 * @param string $text  Link title.
	 * @param string $popup Popup attribute, or an empty string.
	 *
	 * @return string
	 */
	private static function open_tag( $url, $text, $popup ) {
		return '<a href="' . esc_url( $url ) . '"' . $popup . ' title="' . esc_attr( $text ) . '" rel="nofollow">';
	}

	/**
	 * The icon image tag.
	 *
	 * @param string $icon Icon URL.
	 * @param string $text Alt text.
	 *
	 * @return string
	 */
	private static function icon_tag( $icon, $text ) {
		return '<img class="WP-EmailIcon" src="' . esc_url( $icon ) . '" alt="' . esc_attr( $text ) . '" title="' . esc_attr( $text ) . '" style="border: 0px;" />';
	}

	/**
	 * Where the link points.
	 *
	 * @param int $type 1 for the standalone page, 2 for the popup.
	 *
	 * @return string
	 */
	public static function url( $type ) {
		$permalink = get_permalink();

		// Static front page: get_permalink() gives the site root, which is not
		// something an endpoint can hang off.
		if ( 'page' === get_option( 'show_on_front' ) && is_page() && (int) get_option( 'page_on_front' ) > 0 ) {
			$permalink = _get_page_link();
		}

		$endpoint = 2 === (int) $type ? 'emailpopup' : 'email';

		if ( get_option( 'permalink_structure' ) ) {
			return trailingslashit( $permalink ) . $endpoint . '/';
		}

		return add_query_arg( 2 === (int) $type ? 'wp_email_popup' : 'wp_email', 1, $permalink );
	}
}
