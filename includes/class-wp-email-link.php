<?php
/**
 * WP-EMail class-wp-email-link.php
 *
 * @package WP-EMail
 */

defined( 'ABSPATH' ) || exit;

/**
 * The "Email This Post" link, built from the site's link template.
 *
 * @since 3.0.0
 */
class WP_Email_Link {

	/**
	 * Attribute that marks a link as opening the popup.
	 *
	 * Replaces the inline onclick="email_popup(this.href); return false;" the
	 * plugin emitted before 3.0.0. One delegated listener in js/wp-email.js picks
	 * it up, which keeps the markup free of executable attributes.
	 *
	 * %EMAIL_POPUP% expands to this attribute's VALUE -- 1 or 0 -- rather than to
	 * the whole attribute. It has to: wp_kses_post() runs over every saved
	 * template, and a bare %EMAIL_POPUP% sitting where an attribute name goes is
	 * not an attribute kses allows, so it was stripped out of any template saved
	 * through the settings screen and the popup silently stopped opening. A
	 * data-* attribute with a token for its value survives kses intact.
	 */
	const POPUP_ATTRIBUTE = 'data-wp-email-popup';

	/**
	 * Build the link.
	 *
	 * One template rather than the four styles the plugin offered until 3.0.0:
	 * three of those were this template with a piece left out, and the fourth
	 * was this template.
	 *
	 * @return string
	 */
	public static function render() {
		$link = WP_Email_Options::all()['link'];
		$type = (int) $link['type'];

		return WP_Email_Template::expand(
			$link['html'],
			array(
				'EMAIL_URL'   => esc_url( self::url( $type ) ),
				'EMAIL_POPUP' => 2 === $type ? '1' : '0',
				'EMAIL_ICON'  => self::icon(),
				// Escaped as an attribute rather than as text: the token is
				// meant to be usable in the title as well as in the link text,
				// and esc_attr() is safe in both.
				'POST_TYPE'   => esc_attr( self::post_type_label() ),
			)
		);
	}

	/**
	 * The singular label of the post type being viewed.
	 *
	 * "Post", "Page", or whatever a custom type calls one of itself, which is
	 * what %POST_TYPE% resolves to. Falls back to the built-in post label when
	 * there is no post in the loop to ask.
	 *
	 * @return string
	 */
	public static function post_type_label() {
		$object = get_post_type_object( get_post_type() );

		if ( $object && isset( $object->labels->singular_name ) && '' !== $object->labels->singular_name ) {
			return (string) $object->labels->singular_name;
		}

		return __( 'Post', 'wp-email' );
	}

	/**
	 * The envelope glyph, drawn inline.
	 *
	 * An inline SVG rather than one of the two raster files the plugin shipped
	 * until 3.0.0: it inherits the theme's colour through currentColor, stays
	 * sharp at any density, and costs no request. Two paths, an outline and the
	 * flap, so it reads as an envelope at 16px.
	 *
	 * %EMAIL_ICON% expands to the unlabelled form, which is hidden from
	 * assistive technology. A template that shows the glyph on its own therefore
	 * takes its accessible name from the anchor's title attribute, which is what
	 * the shipped templates carry -- and a labelled glyph inside a titled anchor
	 * would announce the same words twice. The parameter remains for callers
	 * placing the glyph somewhere the surrounding markup names nothing.
	 *
	 * @param string $label Accessible name, or an empty string for a decorative
	 *                      glyph.
	 *
	 * @return string
	 */
	public static function icon( $label = '' ) {
		$title = '' === $label
			? ''
			: '<title>' . esc_html( $label ) . '</title>';

		return '<svg class="wp-email-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16" width="16" height="16" role="img"'
			. ( '' === $label ? ' aria-hidden="true" focusable="false"' : '' ) . '>'
			. $title
			. '<path d="M1.5 3.5h13v9h-13z" fill="none" stroke="currentColor" stroke-width="1.2" stroke-linejoin="round" />'
			. '<path d="M1.5 4 8 9l6.5-5" fill="none" stroke="currentColor" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round" />'
			. '</svg>';
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
