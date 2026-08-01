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
	 * The three link settings one template replaced.
	 *
	 * 'style' chose between four layouts, three of which were the template with
	 * a piece left out; 'post_text' and 'page_text' fed the %EMAIL_TEXT% token
	 * that went with them. Named once because both halves of the migration -- the
	 * synthesis that reads them and the sanitiser that must stop storing them --
	 * have to agree on the list.
	 */
	const RETIRED_LINK_KEYS = array( 'post_text', 'page_text', 'style' );

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
				'type' => 1,
				'html' => self::default_link_html(),
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
	 * The link template a fresh install starts with.
	 *
	 * Reproduces what the "E-Mail Icon With Text Link" style drew, which is what
	 * the plugin has shipped as its default appearance: the envelope glyph, a
	 * space, and the link text.
	 *
	 * The %POST_TYPE% token is passed in as a sprintf argument rather than
	 * written into the translatable string, for the reason given on
	 * template_defaults() -- the i18n tooling reads a literal %POST_TYPE% as an
	 * unnumbered placeholder and phpcbf will renumber it.
	 *
	 * @return string
	 */
	public static function default_link_html() {
		return self::compose_link_html( self::default_link_text(), true, true );
	}

	/**
	 * The link text a fresh install starts with.
	 *
	 * @return string
	 */
	public static function default_link_text() {
		return sprintf(
			/* translators: %s: The %POST_TYPE% template token, which becomes the post type's singular label. */
			__( 'Email This %s', 'wp-email' ),
			'%POST_TYPE%'
		);
	}

	/**
	 * Build a link template out of a piece of text, an icon, or both.
	 *
	 * The anchor is written exactly as WP_Email_Link built it before 3.0.0's
	 * link settings collapsed into this one template: the popup marker is a
	 * data attribute whose value is a token, and the text is escaped once here
	 * rather than at the sink, because a template is echoed as written.
	 *
	 * @param string $text      Link text, already in its final form.
	 * @param bool   $with_icon Whether the envelope glyph appears.
	 * @param bool   $with_text Whether the text appears beside it.
	 *
	 * @return string
	 */
	public static function compose_link_html( $text, $with_icon, $with_text ) {
		$inside = array();

		if ( $with_icon ) {
			$inside[] = '%EMAIL_ICON%';
		}

		if ( $with_text ) {
			$inside[] = esc_html( $text );
		}

		return '<a href="%EMAIL_URL%" data-wp-email-popup="%EMAIL_POPUP%" title="' . esc_attr( $text ) . '" rel="nofollow">'
			. implode( ' ', $inside )
			. '</a>';
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
	 * runs it on every save.
	 *
	 * The settings screen is two tabs posting disjoint sets of fields against
	 * one option row, and the Settings API hands this only what the submitting
	 * form posted. So the stored value is the starting point and a key is
	 * overwritten only where the submission mentioned it -- rebuilding the whole
	 * shape from the defaults would blank the other tab on every save, which for
	 * this plugin means eight templates a site had written. Every checkbox on
	 * the screen carries a hidden 0 in front of it, so "off" is something the
	 * form says rather than something it leaves out.
	 *
	 * A function from what the form posted, plus what is already stored, to what
	 * gets stored. The upgrade markers live in their own row, so there is still
	 * nothing here to rescue out of the settings.
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
		$clean    = self::all();

		$link = isset( $input['link'] ) && is_array( $input['link'] ) ? $input['link'] : array();

		if ( isset( $link['type'] ) ) {
			$type                  = (int) $link['type'];
			$clean['link']['type'] = in_array( $type, array( 1, 2 ), true ) ? $type : $defaults['link']['type'];
		}

		if ( isset( $link['html'] ) ) {
			$clean['link']['html'] = trim( wp_kses_post( (string) $link['html'] ) );
		}

		// Unconditionally, whatever the submission or the stored row holds: the
		// three keys in RETIRED_LINK_KEYS are what the template replaced, and a
		// row that carries them again -- a restored backup, or a plugin written
		// against the old shape -- must not be able to put them back.
		foreach ( self::RETIRED_LINK_KEYS as $key ) {
			unset( $clean['link'][ $key ] );
		}

		$fields = isset( $input['fields'] ) && is_array( $input['fields'] ) ? $input['fields'] : array();

		foreach ( array( 'yourname', 'youremail', 'yourremarks', 'friendname' ) as $key ) {
			if ( isset( $fields[ $key ] ) ) {
				$clean['fields'][ $key ] = empty( $fields[ $key ] ) ? 0 : 1;
			}
		}

		// The one field the form cannot work without, so it is always on.
		$clean['fields']['friendemail'] = 1;

		$sending = isset( $input['sending'] ) && is_array( $input['sending'] ) ? $input['sending'] : array();

		if ( isset( $sending['contenttype'] ) ) {
			$contenttype                     = (string) $sending['contenttype'];
			$clean['sending']['contenttype'] = in_array( $contenttype, array( 'text/plain', 'text/html' ), true )
				? $contenttype
				: $defaults['sending']['contenttype'];
		}

		if ( isset( $sending['snippet'] ) ) {
			$clean['sending']['snippet'] = absint( $sending['snippet'] );
		}

		if ( isset( $sending['interval'] ) ) {
			$clean['sending']['interval'] = absint( $sending['interval'] );
		}

		if ( isset( $sending['multiple'] ) ) {
			$clean['sending']['multiple'] = max( 1, absint( $sending['multiple'] ) );
		}

		if ( isset( $sending['imageverify'] ) ) {
			$clean['sending']['imageverify'] = empty( $sending['imageverify'] ) ? 0 : 1;
		}

		if ( isset( $sending['ip_header'] ) ) {
			$clean['sending']['ip_header'] = self::sanitize_ip_header( $sending['ip_header'] );
		}

		// Templates are echoed verbatim by the renderer, so they are sanitized
		// on the way in rather than on the way out. The subject lands in a mail
		// header, so it may not carry markup at all.
		$templates = isset( $input['templates'] ) && is_array( $input['templates'] ) ? $input['templates'] : array();

		foreach ( array_keys( $defaults['templates'] ) as $key ) {
			if ( ! isset( $templates[ $key ] ) || is_array( $templates[ $key ] ) ) {
				continue;
			}

			$value = trim( (string) $templates[ $key ] );

			$clean['templates'][ $key ] = ( 'subject' === $key )
				? wp_strip_all_tags( $value )
				: wp_kses_post( $value );
		}

		if ( isset( $input['stats_display'] ) ) {
			$clean['stats_display'] = ! empty( $input['stats_display'] );
		}

		if ( isset( $input['stats_most_limit'] ) ) {
			$clean['stats_most_limit'] = max( 1, absint( $input['stats_most_limit'] ) );
		}

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

		if ( version_compare( $db, '2', '<' ) ) {
			self::migrate_link_template();
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
			// envelope, so there is no longer a file to choose between. The three
			// keys in RETIRED_LINK_KEYS are read into the array anyway, because
			// synthesise_link_html() below is what turns them into the template
			// the plugin now keeps -- and sanitize() drops them afterwards.
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

		$new['link']['html'] = self::synthesise_link_html( $new['link'] );

		self::update( self::sanitize( $new ) );

		foreach ( self::LEGACY_ROWS as $option_name ) {
			delete_option( $option_name );
		}
	}

	/**
	 * Collapse a stored install's link settings into the one template.
	 *
	 * The other half of migrate_legacy_rows(): that one meets a 2.x site, this
	 * one meets a site whose wp_email_options row already exists but still holds
	 * the style picker and the two text settings. Gated on the schema counter
	 * rather than on "are the keys there", so a row that gains them again -- a
	 * restored backup, or a plugin written against the old shape -- is not sent
	 * round a second time.
	 *
	 * Idempotent: a row with none of the retired keys is left exactly as it is,
	 * which is also what makes running it after migrate_legacy_rows() free.
	 *
	 * @return void
	 */
	protected static function migrate_link_template() {
		$stored = get_option( self::OPTION, null );

		if ( ! is_array( $stored ) || ! isset( $stored['link'] ) || ! is_array( $stored['link'] ) ) {
			return;
		}

		if ( ! array_intersect( self::RETIRED_LINK_KEYS, array_keys( $stored['link'] ) ) ) {
			return;
		}

		$stored['link']['html'] = self::synthesise_link_html( $stored['link'] );

		foreach ( self::RETIRED_LINK_KEYS as $key ) {
			unset( $stored['link'][ $key ] );
		}

		self::update( $stored );
	}

	/**
	 * Build one link template out of the old style picker and link texts.
	 *
	 * Reads the old style so a site that showed an icon and no text keeps
	 * showing an icon and no text, rather than being handed the default template
	 * and a wording it never chose.
	 *
	 * The two texts collapse into one because one template cannot carry two
	 * arbitrary strings. Where they are the pair the plugin shipped, %POST_TYPE%
	 * expresses both exactly. Where they were customised and differ, the post
	 * wording wins verbatim and the page wording is lost -- inventing %POST_TYPE%
	 * there would put the plugin's own words on a site that had replaced them.
	 *
	 * @param array $link The link group as it is stored today.
	 *
	 * @return string
	 */
	protected static function synthesise_link_html( array $link ) {
		$style = isset( $link['style'] ) ? (int) $link['style'] : 1;

		// Already writing its own template: nothing here can improve on it, and
		// rewriting it would throw away markup somebody typed.
		if ( 4 === $style && isset( $link['html'] ) ) {
			return (string) $link['html'];
		}

		$post_text = isset( $link['post_text'] ) ? (string) $link['post_text'] : '';
		$page_text = isset( $link['page_text'] ) ? (string) $link['page_text'] : '';

		$stock = ( '' === $post_text || __( 'Email This Post', 'wp-email' ) === $post_text )
			&& ( '' === $page_text || __( 'Email This Page', 'wp-email' ) === $page_text );

		$text = $stock ? self::default_link_text() : $post_text;

		return self::compose_link_html( $text, 3 !== $style, 2 !== $style );
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
