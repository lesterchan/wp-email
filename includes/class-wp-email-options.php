<?php
/**
 * The plugin's two stored rows.
 *
 * The settings row, wp_email_options, holds every setting a site owner can
 * change and nothing else. The markers row, wp_email_version, holds the pair of
 * upgrade markers on its own: the settings form never posts them, so a marker
 * kept inside the settings array would have to be rescued from the stored value
 * on every single save, and the one save that forgot would make the upgrade run
 * again on every request.
 *
 * Before 3.0.0 the plugin owned sixteen unprefixed rows -- email_options,
 * email_fields, eight email_template_* rows and the rest -- plus two it merely
 * borrowed from WP-Stats. They are all folded in here and deleted.
 *
 * @package WP-EMail
 */

defined( 'ABSPATH' ) || exit;

/**
 * Reads, writes, sanitizes and upgrades the settings and the version markers.
 *
 * @since 3.0.0
 */
class WP_Email_Options {

	/**
	 * Settings row. Autoloaded.
	 */
	const OPTION = 'wp_email_options';

	/**
	 * Upgrade markers row, holding 'plugin' and 'db'. Autoloaded.
	 *
	 * Deliberately its own row rather than a key inside self::OPTION: it is read
	 * to decide whether that option still needs migrating, so it cannot live
	 * inside the thing being migrated. Keeping it out also keeps it clear of the
	 * registered sanitize_callback, which would otherwise have to carry it
	 * across every save by hand.
	 */
	const VERSION = 'wp_email_version';

	/**
	 * Every row the plugin owned before 3.0.0, in one list.
	 *
	 * The migration reads these and then deletes them, and naming them once
	 * means the two halves cannot fall out of step - a row read but not deleted
	 * would be migrated again on the next schema bump, and one deleted but not
	 * read would take its setting with it.
	 *
	 * 'stats_display' and 'stats_mostlimit' are deliberately absent: they were
	 * shared with six other plugins rather than owned, so they are handled by
	 * migrate_stats_rows() and left alone by uninstall.php.
	 */
	const LEGACY_ROWS = array(
		'email_options',
		'email_db_version',
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

	/**
	 * Cached copy of the merged settings for this request.
	 *
	 * @var array|null
	 */
	protected static $cache = null;

	/**
	 * Default settings.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'link'             => array(
				'post_text' => __( 'Email This Post', 'wp-email' ),
				'page_text' => __( 'Email This Page', 'wp-email' ),
				'type'      => 1,
				'style'     => 1,
				'html'      => '<a href="%EMAIL_URL%" rel="nofollow" title="%EMAIL_TEXT%">%EMAIL_TEXT%</a>',
			),
			'fields'           => array(
				'yourname'    => 1,
				'youremail'   => 1,
				'yourremarks' => 1,
				'friendname'  => 1,
				'friendemail' => 1,
			),
			'sending'          => array(
				'contenttype' => 'text/html',
				'snippet'     => 0,
				'interval'    => 10,
				'multiple'    => 5,
				'imageverify' => 1,
				'ip_header'   => '',
			),
			'templates'        => self::template_defaults(),
			'stats_display'    => true,
			'stats_most_limit' => 10,
		);
	}

	/**
	 * The text each template starts life with.
	 *
	 * The defaults carry the plugin's own %TOKEN% names, which are not printf
	 * placeholders -- WP_Email_Template::expand() substitutes them. They are
	 * passed in as sprintf arguments rather than written into the translatable
	 * string, because the i18n tooling reads a literal %EMAIL_POST_TITLE% as an
	 * unnumbered placeholder and phpcbf will happily renumber it, silently
	 * rewriting the token every user is told to type.
	 *
	 * @return array
	 */
	public static function template_defaults() {
		return array(
			'title'       => sprintf(
				/* translators: %s: The %EMAIL_POST_TITLE% template token. */
				__( "E-Mail '%s' To A Friend", 'wp-email' ),
				'%EMAIL_POST_TITLE%'
			),
			'subtitle'    => '<p class="wp-email-subtitle">' . sprintf(
				/* translators: %s: The %EMAIL_POST_TITLE% template token, in quotes. */
				__( 'Email a copy of <strong>%s</strong> to a friend', 'wp-email' ),
				"'%EMAIL_POST_TITLE%'"
			) . '</p>',
			'subject'     => sprintf(
				/* translators: 1: The %EMAIL_YOUR_NAME% token, 2: the %EMAIL_POST_TITLE% token. */
				__( 'Recommended Article By %1$s: %2$s', 'wp-email' ),
				'%EMAIL_YOUR_NAME%',
				'%EMAIL_POST_TITLE%'
			),
			'body'        => sprintf(
				/* translators: 1: %EMAIL_FRIEND_NAME%, 2: %EMAIL_YOUR_NAME%, 3: %EMAIL_POST_TITLE%, 4: %EMAIL_YOUR_REMARKS%, 5: %EMAIL_POST_AUTHOR%, 6: %EMAIL_POST_DATE%, 7: %EMAIL_POST_CATEGORY%, 8: %EMAIL_POST_CONTENT%, 9: %EMAIL_BLOG_NAME%, 10: %EMAIL_BLOG_URL%, 11: %EMAIL_PERMALINK%. Every one is a template token the plugin substitutes later, not a value. */
				__( '<p>Hi <strong>%1$s</strong>,<br />Your friend, <strong>%2$s</strong>, has recommended this article entitled \'<strong>%3$s</strong>\' to you.</p><p><strong>Here is his/her remark:</strong><br />%4$s</p><p><strong>%3$s</strong><br />Posted By %5$s On %6$s In %7$s</p>%8$s<p>Article taken from %9$s - <a href="%10$s">%10$s</a><br />URL to article: <a href="%11$s">%11$s</a></p>', 'wp-email' ),
				'%EMAIL_FRIEND_NAME%',
				'%EMAIL_YOUR_NAME%',
				'%EMAIL_POST_TITLE%',
				'%EMAIL_YOUR_REMARKS%',
				'%EMAIL_POST_AUTHOR%',
				'%EMAIL_POST_DATE%',
				'%EMAIL_POST_CATEGORY%',
				'%EMAIL_POST_CONTENT%',
				'%EMAIL_BLOG_NAME%',
				'%EMAIL_BLOG_URL%',
				'%EMAIL_PERMALINK%'
			),
			'bodyalt'     => sprintf(
				/* translators: 1: %EMAIL_FRIEND_NAME%, 2: %EMAIL_YOUR_NAME%, 3: %EMAIL_POST_TITLE%, 4: %EMAIL_YOUR_REMARKS%, 5: %EMAIL_POST_AUTHOR%, 6: %EMAIL_POST_DATE%, 7: %EMAIL_POST_CATEGORY%, 8: %EMAIL_POST_CONTENT%, 9: %EMAIL_BLOG_NAME%, 10: %EMAIL_BLOG_URL%, 11: %EMAIL_PERMALINK%. Every one is a template token the plugin substitutes later, not a value. */
				__( "Hi %1\$s,\nYour friend, %2\$s, has recommended this article entitled '%3\$s' to you.\n\nHere is his/her remarks:\n%4\$s\n\n%3\$s\nPosted By %5\$s On %6\$s In %7\$s\n%8\$s\nArticle taken from %9\$s - %10\$s\nURL to article: %11\$s", 'wp-email' ),
				'%EMAIL_FRIEND_NAME%',
				'%EMAIL_YOUR_NAME%',
				'%EMAIL_POST_TITLE%',
				'%EMAIL_YOUR_REMARKS%',
				'%EMAIL_POST_AUTHOR%',
				'%EMAIL_POST_DATE%',
				'%EMAIL_POST_CATEGORY%',
				'%EMAIL_POST_CONTENT%',
				'%EMAIL_BLOG_NAME%',
				'%EMAIL_BLOG_URL%',
				'%EMAIL_PERMALINK%'
			),
			'sentsuccess' => '<p>' . sprintf(
				/* translators: 1: The %EMAIL_POST_TITLE% token, 2: the %EMAIL_FRIEND_NAME% token, 3: the %EMAIL_FRIEND_EMAIL% token. */
				__( 'Article: <strong>%1$s</strong> has been sent to <strong>%2$s (%3$s)</strong>', 'wp-email' ),
				'%EMAIL_POST_TITLE%',
				'%EMAIL_FRIEND_NAME%',
				'%EMAIL_FRIEND_EMAIL%'
			) . '</p><p>&laquo; <a href="%EMAIL_PERMALINK%">' . sprintf(
				/* translators: %s: The %EMAIL_POST_TITLE% template token. */
				__( 'Back to %s', 'wp-email' ),
				'%EMAIL_POST_TITLE%'
			) . '</a></p>',
			'sentfailed'  => '<p>' . __( 'An error has occurred when trying to send this email.', 'wp-email' ) . '</p>',
			'error'       => '<p>' . __( 'An error has occurred: ', 'wp-email' ) . '<br /><strong>&raquo;</strong> %EMAIL_ERROR_MSG%</p>',
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
		if ( null !== self::$cache ) {
			return self::$cache;
		}

		$stored = get_option( self::OPTION, array() );

		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		$data = self::defaults();

		foreach ( $data as $group => $values ) {
			if ( ! isset( $stored[ $group ] ) ) {
				continue;
			}

			// The two WP-Stats settings are scalars sitting beside four nested
			// groups, so the merge has to know which of the two it is looking at.
			$data[ $group ] = ( is_array( $values ) && is_array( $stored[ $group ] ) )
				? array_merge( $values, $stored[ $group ] )
				: $stored[ $group ];
		}

		$data['stats_display']    = (bool) $data['stats_display'];
		$data['stats_most_limit'] = max( 1, (int) $data['stats_most_limit'] );

		self::$cache = $data;

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
	 * Whether the plugin contributes its section to the WP-Stats page.
	 *
	 * @return bool
	 */
	public static function stats_display() {
		$data = self::all();

		return (bool) $data['stats_display'];
	}

	/**
	 * How many entries the WP-Stats "most emailed" lists show.
	 *
	 * @return int
	 */
	public static function stats_most_limit() {
		$data = self::all();

		return (int) $data['stats_most_limit'];
	}

	/**
	 * Store the settings.
	 *
	 * @param array $options Settings to store.
	 *
	 * @return void
	 */
	public static function update( array $options ) {
		self::$cache = null;

		update_option( self::OPTION, $options );
	}

	/**
	 * Forget the per-request cache.
	 *
	 * @return void
	 */
	public static function flush() {
		self::$cache = null;
	}

	/**
	 * The stored upgrade markers, normalised.
	 *
	 * @return array
	 */
	public static function markers() {
		$markers = get_option( self::VERSION, array() );

		return is_array( $markers ) ? $markers : array();
	}

	/**
	 * Sanitize a submitted settings array.
	 *
	 * Registered as the sanitize_callback for the setting, so the Settings API
	 * runs it on every save. The shape is rebuilt from the defaults rather than
	 * trusted from the submission, which means a partial post can never leave a
	 * group half-populated for the renderer.
	 *
	 * A function from what the form posted to what gets stored, and nothing
	 * else: the upgrade markers live in their own row, so there is nothing here
	 * to rescue back out of the stored value.
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

		$clean['stats_display']    = ! empty( $input['stats_display'] );
		$clean['stats_most_limit'] = isset( $input['stats_most_limit'] )
			? max( 1, absint( $input['stats_most_limit'] ) )
			: $defaults['stats_most_limit'];

		return $clean;
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
	 * Create the settings row on activation.
	 *
	 * @return void
	 */
	public static function activate() {
		self::maybe_upgrade();

		add_option( self::OPTION, self::defaults() );
	}

	/**
	 * Bring the stored rows up to the running version.
	 *
	 * Gated on the markers rather than on "do the old rows exist". An install
	 * that has already migrated has no old rows, so a second pass would find
	 * nothing and write the defaults straight over the settings; and a row that
	 * reappears afterwards - a restored backup, or a plugin built against 2.x
	 * calling update_option() - must not send the migration round again.
	 *
	 * Both markers are written in one call at the end, so an upgrade that dies
	 * half way never records itself as finished.
	 *
	 * @return void
	 */
	public static function maybe_upgrade() {
		$markers = self::markers();

		$plugin = isset( $markers['plugin'] ) ? (string) $markers['plugin'] : '';
		$db     = isset( $markers['db'] ) ? (string) $markers['db'] : '';

		if ( WP_EMAIL_VERSION === $plugin && WP_EMAIL_DB_VERSION === $db ) {
			return;
		}

		if ( '' === $db ) {
			self::migrate_legacy_rows();
		}

		update_option(
			self::VERSION,
			array(
				'plugin' => WP_EMAIL_VERSION,
				'db'     => WP_EMAIL_DB_VERSION,
			),
			true
		);
	}

	/**
	 * Fold every pre-3.0.0 row into wp_email_options, then delete it.
	 *
	 * Two generations are recognised: the flat email_options row of 2.x with its
	 * fifteen companions, and the nested shape an unreleased 3.0.0 build wrote
	 * into that same row before the name gained its prefix. An install with
	 * neither is a fresh one and keeps its defaults.
	 *
	 * @return void
	 */
	protected static function migrate_legacy_rows() {
		// Read through LEGACY_ROWS rather than by name, so the list of rows the
		// migration reads and the list it deletes are the same list.
		$legacy = array();

		foreach ( self::LEGACY_ROWS as $row ) {
			$legacy[ $row ] = get_option( $row, null );
		}

		$stored = is_array( $legacy['email_options'] ) ? $legacy['email_options'] : array();

		$defaults = self::defaults();
		$new      = $defaults;

		if ( isset( $stored['templates'] ) && is_array( $stored['templates'] ) ) {
			foreach ( $defaults as $group => $values ) {
				if ( is_array( $values ) && isset( $stored[ $group ] ) && is_array( $stored[ $group ] ) ) {
					$new[ $group ] = array_merge( $values, $stored[ $group ] );
				}
			}
		} else {
			// email_icon is deliberately absent: 3.0.0 draws one inline SVG
			// envelope, so there is no longer a file to choose between.
			$map = array(
				'post_text'  => 'post_text',
				'page_text'  => 'page_text',
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

			if ( is_array( $legacy['email_fields'] ) ) {
				$new['fields'] = array_merge( $new['fields'], $legacy['email_fields'] );
			}

			$scalars = array(
				'email_contenttype' => 'contenttype',
				'email_snippet'     => 'snippet',
				'email_interval'    => 'interval',
				'email_multiple'    => 'multiple',
				'email_imageverify' => 'imageverify',
			);

			foreach ( $scalars as $option_name => $key ) {
				$value = $legacy[ $option_name ];

				if ( null !== $value && false !== $value ) {
					$new['sending'][ $key ] = $value;
				}
			}

			foreach ( array_keys( $defaults['templates'] ) as $key ) {
				$value = $legacy[ 'email_template_' . $key ];

				if ( null !== $value && false !== $value ) {
					// Stored slashed by the old options screen, which ran the
					// value through addslashes() before update_option().
					$new['templates'][ $key ] = wp_unslash( $value );
				}
			}
		}

		$new = array_merge( $new, self::migrate_stats_rows() );

		self::update( self::sanitize( $new ) );

		foreach ( self::LEGACY_ROWS as $option_name ) {
			delete_option( $option_name );
		}
	}

	/**
	 * Carry the two WP-Stats rows into this plugin's own settings.
	 *
	 * Both stats_display and stats_mostlimit were unprefixed rows that seven
	 * plugins read and none owned. Each of the seven keeps its own copy now, and each
	 * deletes the shared rows once it has folded them in -- so whichever plugin
	 * a site upgrades FIRST is the only one that finds anything there.
	 *
	 * That is why an absent row reads as "a sibling has already migrated" rather
	 * than "switched off". Reading a deleted row as a deliberate opt-out would
	 * turn six blocks off with no error anywhere; the worst this way round can
	 * do is leave a block on that its owner then switches off again. See
	 * STANDARDS.md 13.2.
	 *
	 * @return array The two settings, ready to merge.
	 */
	protected static function migrate_stats_rows() {
		$legacy_display = get_option( 'stats_display', null );
		$legacy_limit   = get_option( 'stats_mostlimit', null );
		$defaults       = self::defaults();

		if ( null === $legacy_display ) {
			$display = true;
		} elseif ( is_array( $legacy_display ) ) {
			// WP-Stats 2.x kept one toggle per block and WP-EMail owned three of
			// them. It contributes a single section now, so that section is on
			// if any of the three was.
			$display = ! empty( $legacy_display['email'] )
				|| ! empty( $legacy_display['emailed_most_post'] )
				|| ! empty( $legacy_display['emailed_most_page'] );
		} else {
			$display = (bool) $legacy_display;
		}

		$limit = ( null === $legacy_limit )
			? $defaults['stats_most_limit']
			: max( 1, (int) $legacy_limit );

		delete_option( 'stats_display' );
		delete_option( 'stats_mostlimit' );

		return array(
			'stats_display'    => $display,
			'stats_most_limit' => $limit,
		);
	}
}
