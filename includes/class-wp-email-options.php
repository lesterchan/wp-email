<?php
/**
 * WP-EMail class-wp-email-options.php
 *
 * @package WP-EMail
 */

defined( 'ABSPATH' ) || exit;

/**
 * Reads, writes and sanitizes the plugin's settings.
 *
 * Before 3.0.0 the plugin owned fifteen separate wp_options rows. They are
 * consolidated here into the one row it already had, 'email_options', holding
 * a nested array. Reusing the existing name means no new row is minted and the
 * value an install already has merges over the defaults for free.
 *
 * @since 3.0.0
 */
class WP_Email_Options {

	/**
	 * Option holding every plugin setting.
	 */
	const OPTION = 'email_options';

	/**
	 * Option holding the data version.
	 *
	 * Deliberately its own row rather than a key inside self::OPTION: it is
	 * read to decide whether that option still needs migrating, so it cannot
	 * live inside the thing being migrated. Keeping it out also keeps it clear
	 * of the registered sanitize_callback, which would otherwise have to carry
	 * it across every save by hand.
	 */
	const VERSION = 'email_db_version';

	/**
	 * Settings rows the plugin used before 3.0.0, folded into self::OPTION.
	 *
	 * 'email_options' is deliberately absent: it is the row being consolidated
	 * into, and deleting it here would throw away everything just written.
	 *
	 * @return array
	 */
	public static function legacy_option_names() {
		return array(
			'email_contenttype',
			'email_snippet',
			'email_interval',
			'email_multiple',
			'email_imageverify',
			'email_fields',
			'email_template_title',
			'email_template_subtitle',
			'email_template_subject',
			'email_template_body',
			'email_template_bodyalt',
			'email_template_sentsuccess',
			'email_template_sentfailed',
			'email_template_error',
			// Dropped in 2.68.0 when the plugin moved to wp_mail().
			'email_smtp',
			'email_mailer',
		);
	}

	/**
	 * Default settings.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'link'      => array(
				'post_text' => __( 'Email This Post', 'wp-email' ),
				'page_text' => __( 'Email This Page', 'wp-email' ),
				'icon'      => 'email_famfamfam.png',
				'type'      => 1,
				'style'     => 1,
				'html'      => '<a href="%EMAIL_URL%" rel="nofollow" title="%EMAIL_TEXT%">%EMAIL_TEXT%</a>',
			),
			'fields'    => array(
				'yourname'    => 1,
				'youremail'   => 1,
				'yourremarks' => 1,
				'friendname'  => 1,
				'friendemail' => 1,
			),
			'sending'   => array(
				'contenttype' => 'text/html',
				'snippet'     => 0,
				'interval'    => 10,
				'multiple'    => 5,
				'imageverify' => 1,
				'ip_header'   => '',
			),
			'templates' => array(
				'title'       => __( "E-Mail '%EMAIL_POST_TITLE%' To A Friend", 'wp-email' ),
				'subtitle'    => '<p style="text-align: center;">' . __( "Email a copy of <strong>'%EMAIL_POST_TITLE%'</strong> to a friend", 'wp-email' ) . '</p>',
				'subject'     => __( 'Recommended Article By %EMAIL_YOUR_NAME%: %EMAIL_POST_TITLE%', 'wp-email' ),
				'body'        => __( "<p>Hi <strong>%EMAIL_FRIEND_NAME%</strong>,<br />Your friend, <strong>%EMAIL_YOUR_NAME%</strong>, has recommended this article entitled '<strong>%EMAIL_POST_TITLE%</strong>' to you.</p><p><strong>Here is his/her remark:</strong><br />%EMAIL_YOUR_REMARKS%</p><p><strong>%EMAIL_POST_TITLE%</strong><br />Posted By %EMAIL_POST_AUTHOR% On %EMAIL_POST_DATE% In %EMAIL_POST_CATEGORY%</p>%EMAIL_POST_CONTENT%<p>Article taken from %EMAIL_BLOG_NAME% - <a href=\"%EMAIL_BLOG_URL%\">%EMAIL_BLOG_URL%</a><br />URL to article: <a href=\"%EMAIL_PERMALINK%\">%EMAIL_PERMALINK%</a></p>", 'wp-email' ),
				'bodyalt'     => __( "Hi %EMAIL_FRIEND_NAME%,\nYour friend, %EMAIL_YOUR_NAME%, has recommended this article entitled '%EMAIL_POST_TITLE%' to you.\n\nHere is his/her remarks:\n%EMAIL_YOUR_REMARKS%\n\n%EMAIL_POST_TITLE%\nPosted By %EMAIL_POST_AUTHOR% On %EMAIL_POST_DATE% In %EMAIL_POST_CATEGORY%\n%EMAIL_POST_CONTENT%\nArticle taken from %EMAIL_BLOG_NAME% - %EMAIL_BLOG_URL%\nURL to article: %EMAIL_PERMALINK%", 'wp-email' ),
				'sentsuccess' => '<p>' . __( 'Article: <strong>%EMAIL_POST_TITLE%</strong> has been sent to <strong>%EMAIL_FRIEND_NAME% (%EMAIL_FRIEND_EMAIL%)</strong>', 'wp-email' ) . '</p><p>&laquo; <a href="%EMAIL_PERMALINK%">' . __( 'Back to %EMAIL_POST_TITLE%', 'wp-email' ) . '</a></p>',
				'sentfailed'  => '<p>' . __( 'An error has occurred when trying to send this email.', 'wp-email' ) . '</p>',
				'error'       => '<p>' . __( 'An error has occurred: ', 'wp-email' ) . '<br /><strong>&raquo;</strong> %EMAIL_ERROR_MSG%</p>',
			),
		);
	}

	/**
	 * Get every setting, merged over the defaults.
	 *
	 * Merged one level deep, so a group the stored value does not carry (or
	 * carries only part of) never has to be guarded at the call site.
	 *
	 * @return array
	 */
	public static function all() {
		$stored = get_option( self::OPTION, array() );

		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		$data = self::defaults();

		foreach ( $data as $group => $values ) {
			if ( isset( $stored[ $group ] ) && is_array( $stored[ $group ] ) ) {
				$data[ $group ] = array_merge( $values, $stored[ $group ] );
			}
		}

		return $data;
	}

	/**
	 * Get a single setting.
	 *
	 * @param string $group Group name, e.g. 'link' or 'sending'.
	 * @param string $key   Setting within that group.
	 *
	 * @return mixed Null when the setting does not exist.
	 */
	public static function get( $group, $key ) {
		$data = self::all();

		return isset( $data[ $group ][ $key ] ) ? $data[ $group ][ $key ] : null;
	}

	/**
	 * Get one template by name.
	 *
	 * @param string $name Template key.
	 *
	 * @return string
	 */
	public static function template( $name ) {
		return (string) self::get( 'templates', $name );
	}

	/**
	 * Store the settings.
	 *
	 * @param array $options Settings to store.
	 *
	 * @return void
	 */
	public static function update( array $options ) {
		update_option( self::OPTION, $options );
	}

	/**
	 * Sanitize a submitted settings array.
	 *
	 * Registered as the sanitize_callback for the setting, so the Settings API
	 * runs it on every save. The shape is rebuilt from the defaults rather than
	 * trusted from the submission, which means a partial post can never leave a
	 * group half-populated for the renderer.
	 *
	 * @param mixed $input Submitted settings.
	 *
	 * @return array
	 */
	public static function sanitize( $input ) {
		if ( ! is_array( $input ) ) {
			$input = array();
		}

		$defaults = self::defaults();
		$current  = self::all();
		$clean    = array();

		$link  = isset( $input['link'] ) && is_array( $input['link'] ) ? $input['link'] : array();
		$style = isset( $link['style'] ) ? (int) $link['style'] : (int) $current['link']['style'];
		$type  = isset( $link['type'] ) ? (int) $link['type'] : (int) $current['link']['type'];

		$clean['link'] = array(
			'post_text' => isset( $link['post_text'] ) ? trim( wp_kses_post( (string) $link['post_text'] ) ) : $defaults['link']['post_text'],
			'page_text' => isset( $link['page_text'] ) ? trim( wp_kses_post( (string) $link['page_text'] ) ) : $defaults['link']['page_text'],
			'icon'      => self::sanitize_icon( isset( $link['icon'] ) ? $link['icon'] : '' ),
			'type'      => in_array( $type, array( 1, 2 ), true ) ? $type : $defaults['link']['type'],
			'style'     => in_array( $style, array( 1, 2, 3, 4 ), true ) ? $style : $defaults['link']['style'],
			'html'      => isset( $link['html'] ) ? trim( wp_kses_post( (string) $link['html'] ) ) : $defaults['link']['html'],
		);

		// Checkboxes: absent means off. Friend's e-mail is the one field the
		// form cannot work without, so it is always on.
		$fields          = isset( $input['fields'] ) && is_array( $input['fields'] ) ? $input['fields'] : array();
		$clean['fields'] = array(
			'yourname'    => empty( $fields['yourname'] ) ? 0 : 1,
			'youremail'   => empty( $fields['youremail'] ) ? 0 : 1,
			'yourremarks' => empty( $fields['yourremarks'] ) ? 0 : 1,
			'friendname'  => empty( $fields['friendname'] ) ? 0 : 1,
			'friendemail' => 1,
		);

		$sending     = isset( $input['sending'] ) && is_array( $input['sending'] ) ? $input['sending'] : array();
		$contenttype = isset( $sending['contenttype'] ) ? (string) $sending['contenttype'] : '';

		$clean['sending'] = array(
			'contenttype' => in_array( $contenttype, array( 'text/plain', 'text/html' ), true ) ? $contenttype : $defaults['sending']['contenttype'],
			'snippet'     => isset( $sending['snippet'] ) ? absint( $sending['snippet'] ) : $defaults['sending']['snippet'],
			'interval'    => isset( $sending['interval'] ) ? absint( $sending['interval'] ) : $defaults['sending']['interval'],
			'multiple'    => isset( $sending['multiple'] ) ? max( 1, absint( $sending['multiple'] ) ) : $defaults['sending']['multiple'],
			'imageverify' => empty( $sending['imageverify'] ) ? 0 : 1,
			'ip_header'   => self::sanitize_ip_header( isset( $sending['ip_header'] ) ? $sending['ip_header'] : '' ),
		);

		// Templates are echoed verbatim by the renderer, so they are sanitized
		// on the way in rather than on the way out. The subject lands in a mail
		// header, so it may not carry markup at all.
		$templates          = isset( $input['templates'] ) && is_array( $input['templates'] ) ? $input['templates'] : array();
		$clean['templates'] = array();

		foreach ( $defaults['templates'] as $key => $default ) {
			if ( ! isset( $templates[ $key ] ) || is_array( $templates[ $key ] ) ) {
				$clean['templates'][ $key ] = $current['templates'][ $key ];
				continue;
			}

			$value = trim( (string) $templates[ $key ] );

			$clean['templates'][ $key ] = ( 'subject' === $key )
				? wp_strip_all_tags( $value )
				: wp_kses_post( $value );
		}

		return $clean;
	}

	/**
	 * Restrict the icon to a file that actually ships in the images directory.
	 *
	 * @param mixed $icon Submitted file name.
	 *
	 * @return string
	 */
	public static function sanitize_icon( $icon ) {
		$icon      = basename( sanitize_file_name( (string) $icon ) );
		$available = self::available_icons();

		if ( in_array( $icon, $available, true ) ) {
			return $icon;
		}

		$defaults = self::defaults();

		return in_array( $defaults['link']['icon'], $available, true ) || empty( $available )
			? $defaults['link']['icon']
			: $available[0];
	}

	/**
	 * The icon files shipped with the plugin.
	 *
	 * @return array
	 */
	public static function available_icons() {
		$icons = array();
		$dir   = plugin_dir_path( WP_EMAIL_MAIN_FILE ) . 'images';

		if ( ! is_dir( $dir ) ) {
			return $icons;
		}

		foreach ( (array) scandir( $dir ) as $file ) {
			if ( '.' === $file[0] || 'loading.gif' === $file || ! is_file( $dir . '/' . $file ) ) {
				continue;
			}

			$icons[] = $file;
		}

		sort( $icons );

		return $icons;
	}

	/**
	 * Restrict the trusted-IP header to a plausible header name.
	 *
	 * @param mixed $header Submitted header name.
	 *
	 * @return string
	 */
	public static function sanitize_ip_header( $header ) {
		$header = strtoupper( trim( (string) $header ) );

		return preg_match( '/^[A-Z0-9_]{1,60}$/', $header ) ? $header : '';
	}

	/**
	 * Fold the pre-3.0.0 option rows into the single consolidated row.
	 *
	 * Idempotent twice over: the caller gates it on the stored data version,
	 * and it also returns early when the option is already in the nested shape.
	 * Without that second check, running against an install that had already
	 * migrated would find no legacy rows and write defaults over real settings.
	 *
	 * @return void
	 */
	public static function migrate() {
		$stored = get_option( self::OPTION, array() );

		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		// Already nested: nothing to fold in.
		if ( isset( $stored['templates'] ) && is_array( $stored['templates'] ) ) {
			return;
		}

		$defaults = self::defaults();
		$new      = $defaults;

		// The flat 'email_options' row the plugin already owned.
		$map = array(
			'post_text'  => 'post_text',
			'page_text'  => 'page_text',
			'email_icon' => 'icon',
			'email_type' => 'type',
			'email_html' => 'html',
		);

		foreach ( $map as $old => $new_key ) {
			if ( isset( $stored[ $old ] ) ) {
				$new['link'][ $new_key ] = $stored[ $old ];
			}
		}

		if ( isset( $stored['email_style'] ) ) {
			$new['link']['style'] = (int) $stored['email_style'];
		}

		if ( isset( $stored['ip_header'] ) ) {
			$new['sending']['ip_header'] = $stored['ip_header'];
		}

		// Standalone rows.
		$legacy_fields = get_option( 'email_fields' );

		if ( is_array( $legacy_fields ) ) {
			$new['fields'] = array_merge( $new['fields'], $legacy_fields );
		}

		$scalars = array(
			'email_contenttype' => 'contenttype',
			'email_snippet'     => 'snippet',
			'email_interval'    => 'interval',
			'email_multiple'    => 'multiple',
			'email_imageverify' => 'imageverify',
		);

		foreach ( $scalars as $option_name => $key ) {
			$value = get_option( $option_name, null );

			if ( null !== $value && false !== $value ) {
				$new['sending'][ $key ] = $value;
			}
		}

		foreach ( array_keys( $defaults['templates'] ) as $key ) {
			$value = get_option( 'email_template_' . $key, null );

			if ( null !== $value && false !== $value ) {
				// Stored slashed by the old options screen, which ran the value
				// through addslashes() before update_option().
				$new['templates'][ $key ] = wp_unslash( $value );
			}
		}

		self::update( self::sanitize( $new ) );

		foreach ( self::legacy_option_names() as $option_name ) {
			delete_option( $option_name );
		}
	}
}
