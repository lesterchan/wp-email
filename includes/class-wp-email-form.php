<?php
/**
 * WP-EMail class-wp-email-form.php
 *
 * @package WP-EMail
 */

defined( 'ABSPATH' ) || exit;

/**
 * The front-end form and the endpoint that processes it.
 *
 * @since 3.0.0
 */
class WP_Email_Form {

	/**
	 * Nonce action for the form.
	 */
	const NONCE_ACTION = 'wp-email-nonce';

	/**
	 * Nonce field name for the form.
	 */
	const NONCE_NAME = 'wp-email_nonce';

	/**
	 * Separator prefixed to each validation error before they are joined.
	 */
	const ERROR_SEPARATOR = '<br /><strong>&raquo;</strong> ';

	/**
	 * Prefix for the transient and cache key holding a visitor's flood claim.
	 */
	const FLOOD_PREFIX = 'wp_email_flood_';

	/**
	 * Object cache group for the flood claim.
	 */
	const CACHE_GROUP = 'wp-email';

	/**
	 * The visitor's IP address.
	 *
	 * Only REMOTE_ADDR is trusted by default. Proxy headers are attacker
	 * controlled unless something in front of WordPress is guaranteed to
	 * overwrite them, and the flood interval is keyed on this value, so
	 * honouring them unconditionally -- as the plugin did before 3.0.0 -- let
	 * anyone bypass it by sending a different X-Forwarded-For each time.
	 *
	 * Sites genuinely behind a proxy opt in three ways: by naming the header on
	 * the settings screen, by defining WP_EMAIL_TRUST_PROXY, or by returning
	 * true from the wp_email_trust_proxy filter.
	 *
	 * @return string
	 */
	public static function ip_address() {
		// sanitize_text_field() is inline on each read so the sniff can see it;
		// filter_var( ..., FILTER_VALIDATE_IP ) in valid_ip() is what actually
		// decides whether the value is usable.
		$ip     = self::valid_ip( isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '' );
		$header = (string) WP_Email_Options::get( 'sending', 'ip_header' );

		/**
		 * Filters whether the usual proxy headers may be trusted.
		 *
		 * Lets the decision be made per request -- trusting the header only when
		 * the request actually arrives from a known load balancer, say -- rather
		 * than once in wp-config.php.
		 *
		 * @param bool $trust Defaults to the WP_EMAIL_TRUST_PROXY constant.
		 */
		$trust_proxy = (bool) apply_filters(
			'wp_email_trust_proxy',
			defined( 'WP_EMAIL_TRUST_PROXY' ) && WP_EMAIL_TRUST_PROXY
		);

		if ( '' !== $header && ! empty( $_SERVER[ $header ] ) ) {
			$candidate = self::first_valid_ip( sanitize_text_field( wp_unslash( $_SERVER[ $header ] ) ) );

			if ( '' !== $candidate ) {
				$ip = $candidate;
			}
		} elseif ( $trust_proxy ) {
			$headers = array(
				'HTTP_CF_CONNECTING_IP',
				'HTTP_CLIENT_IP',
				'HTTP_X_FORWARDED_FOR',
				'HTTP_X_FORWARDED',
				'HTTP_X_CLUSTER_CLIENT_IP',
				'HTTP_FORWARDED_FOR',
				'HTTP_FORWARDED',
			);

			foreach ( $headers as $name ) {
				if ( empty( $_SERVER[ $name ] ) ) {
					continue;
				}

				$candidate = self::first_valid_ip( sanitize_text_field( wp_unslash( $_SERVER[ $name ] ) ) );

				if ( '' !== $candidate ) {
					$ip = $candidate;
					break;
				}
			}
		}

		/**
		 * Filters the IP address the plugin attributes a send to.
		 *
		 * @param string $ip IP address.
		 */
		return (string) apply_filters( 'wp_email_ipaddress', $ip );
	}

	/**
	 * The first syntactically valid IP in a comma separated list.
	 *
	 * @param string $value Header value.
	 *
	 * @return string
	 */
	private static function first_valid_ip( $value ) {
		foreach ( explode( ',', (string) $value ) as $candidate ) {
			$candidate = self::valid_ip( $candidate );

			if ( '' !== $candidate ) {
				return $candidate;
			}
		}

		return '';
	}

	/**
	 * An IP address, or an empty string.
	 *
	 * @param string $ip Candidate.
	 *
	 * @return string
	 */
	private static function valid_ip( $ip ) {
		$ip = trim( (string) $ip );

		return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
	}

	/**
	 * The flood interval, in minutes.
	 *
	 * @return int
	 */
	public static function flood_interval() {
		return (int) WP_Email_Options::get( 'sending', 'interval' );
	}

	/**
	 * The key this visitor's flood claim is held under.
	 *
	 * Hashed, because the address is the input and an option name has a length
	 * limit that an IPv6 address plus a prefix can approach.
	 *
	 * @param string $ip Visitor address.
	 *
	 * @return string
	 */
	private static function flood_key( $ip ) {
		return self::FLOOD_PREFIX . md5( (string) $ip );
	}

	/**
	 * Whether the visitor is clear to send again.
	 *
	 * A read, and only a read -- it answers the question the form asks before it
	 * draws itself. Deciding whether a *send* may proceed is claim_send_slot()'s
	 * job, because that one has to be a claim rather than a question.
	 *
	 * @return bool
	 */
	public static function not_spamming() {
		$interval = self::flood_interval() * MINUTE_IN_SECONDS;

		if ( $interval <= 0 ) {
			return true;
		}

		return false === get_transient( self::flood_key( self::ip_address() ) );
	}

	/**
	 * Take this visitor's slot in the flood interval, if it is free.
	 *
	 * Two things were wrong with reading the interval out of the log, and both
	 * mattered.
	 *
	 * It was a read followed, much later, by a write: `not_spamming()` consulted
	 * the last logged send and `send()` wrote the row afterwards, with template
	 * expansion, a DNS lookup and wp_mail() in between. Requests arriving
	 * together all read the same value, all concluded they were clear, and all
	 * sent -- so the interval bounded a polite sender and did nothing at all to
	 * a parallel one. The claim is taken here, before anything is sent.
	 *
	 * And it keyed on `email_status = 'Success'`, so a delivery the mailer
	 * refused cost the sender no cooldown whatever. An attempt is what is
	 * counted now.
	 *
	 * wp_cache_add() is the atomic half: add-if-absent, and genuinely atomic on
	 * a persistent object cache, which is what a site big enough to be flooded
	 * in parallel is running. Without one it is a per-request array and proves
	 * nothing between requests, so the transient is what holds there -- and the
	 * window it leaves is the few microseconds between the two calls below
	 * rather than the seconds wp_mail() used to sit in.
	 *
	 * @return bool True when the caller may send.
	 */
	public static function claim_send_slot() {
		$interval = self::flood_interval() * MINUTE_IN_SECONDS;

		if ( $interval <= 0 ) {
			return true;
		}

		$key = self::flood_key( self::ip_address() );

		if ( ! wp_cache_add( $key, time(), self::CACHE_GROUP, $interval ) ) {
			return false;
		}

		if ( false !== get_transient( $key ) ) {
			return false;
		}

		// A transient rather than an option, so the record expires and is
		// reaped on its own. An option would need pruning, and one row per
		// address that never comes back is how a wp_options table grows without
		// anybody noticing.
		set_transient( $key, time(), $interval );

		return true;
	}

	/**
	 * Name check: no characters that have no business in a person's name.
	 *
	 * @param string $name Candidate.
	 *
	 * @return bool
	 */
	public static function is_valid_name( $name ) {
		return ! preg_match( '/[(\*\(\)\[\]\+\,\/\?\:\;\'\"\`\~\\#\$\%\^\&\<\>)+]/', (string) $name );
	}

	/**
	 * E-mail address check.
	 *
	 * @param string $email Candidate.
	 *
	 * @return bool
	 */
	public static function is_valid_email( $email ) {
		return (bool) is_email( (string) $email );
	}

	/**
	 * Remarks check: reject anything that looks like header injection.
	 *
	 * @param string $content Candidate.
	 *
	 * @return bool
	 */
	public static function is_valid_remarks( $content ) {
		$injection_strings = array(
			'apparently-to',
			'content-disposition',
			'content-type',
			'content-transfer-encoding',
			'errors-to',
			'in-reply-to',
			'message-id',
			'mime-version',
			'multipart/mixed',
			'multipart/alternative',
			'multipart/related',
			'reply-to',
			'x-mailer',
			'x-sender',
			'x-uidl',
		);

		$content = strtolower( (string) $content );

		foreach ( $injection_strings as $needle ) {
			if ( false !== strpos( $content, $needle ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * The maximum number of recipients per send.
	 *
	 * @return int
	 */
	public static function max_recipients() {
		return max( 1, (int) WP_Email_Options::get( 'sending', 'multiple' ) );
	}

	/**
	 * The "separate multiple entries with a comma" hint.
	 *
	 * @return string
	 */
	public static function multiple_hint() {
		$max = self::max_recipients();

		if ( $max <= 1 ) {
			return '';
		}

		return '<br /><em>' . sprintf(
			/* translators: %s: Maximum number of entries. */
			esc_html( _n( 'Separate multiple entries with a comma. Maximum %s entry.', 'Separate multiple entries with a comma. Maximum %s entries.', $max, 'wp-email' ) ),
			esc_html( number_format_i18n( $max ) )
		) . '</em>';
	}

	/**
	 * The opening form tag and its hidden fields.
	 *
	 * @param int  $post_id Post being e-mailed.
	 * @param bool $popup   Whether this is the popup variant.
	 *
	 * @return string
	 */
	public static function header( $post_id = 0, $popup = false ) {
		$post_id = (int) $post_id;

		if ( $post_id <= 0 ) {
			$post_id = (int) get_the_ID();
		}

		$permalink = get_permalink( $post_id );

		// Static front page: get_permalink() returns the site root, which is
		// not somewhere an endpoint can hang off.
		if ( 'page' === get_option( 'show_on_front' ) && is_page() && (int) get_option( 'page_on_front' ) > 0 ) {
			$permalink = _get_page_link( $post_id );
		}

		$endpoint = $popup ? 'emailpopup' : 'email';

		if ( get_option( 'permalink_structure' ) ) {
			$action = trailingslashit( $permalink ) . $endpoint . '/';
		} else {
			$action = add_query_arg( $popup ? 'wp_email_popup' : 'wp_email', 1, $permalink );
		}

		// 'page_id' vs 'p' is what tells the handler which query to run.
		$id_field = is_page() ? 'page_id' : 'p';

		// hidden rather than style="display: none;": the plugin sets no inline
		// styles, so a theme has one place to look.
		$output  = '<form action="' . esc_url( $action ) . '" method="post">' . "\n";
		$output .= '<p hidden>';
		$output .= '<input type="hidden" id="' . esc_attr( $id_field ) . '" name="' . esc_attr( $id_field ) . '" value="' . esc_attr( $post_id ) . '" />';
		$output .= '</p>' . "\n";
		$output .= '<p hidden>';
		$output .= '<input type="hidden" id="' . esc_attr( self::NONCE_NAME ) . '" name="' . esc_attr( self::NONCE_NAME ) . '" value="' . esc_attr( wp_create_nonce( self::NONCE_ACTION ) ) . '" />';
		$output .= '</p>' . "\n";

		return $output;
	}

	/**
	 * Render the form.
	 *
	 * Signature kept from the pre-3.0.0 email_form() because it is registered
	 * as a the_content filter and is also called directly by themes.
	 *
	 * @param string $content     Unused; present because this runs as a filter.
	 * @param bool   $echo_output Whether to echo rather than return.
	 * @param bool   $subtitle    Whether to render the subtitle template.
	 * @param bool   $div         Whether to wrap the output in the container div.
	 * @param array  $submitted   Values the visitor already typed.
	 *
	 * @return string
	 */
	public static function render( $content = '', $echo_output = true, $subtitle = true, $div = true, $submitted = array() ) {
		global $multipage;

		// Force the whole post into one page: the e-mail carries the entire
		// article, never a <!--nextpage--> slice.
		$multipage = false; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- The e-mail carries the whole article, so the <!--nextpage--> split has to be off for this render.

		$fields = WP_Email_Options::all()['fields'];
		$output = '';

		if ( $subtitle ) {
			$output .= WP_Email_Template::expand( WP_Email_Options::template( 'subtitle' ), WP_Email_Template::post_vars() );
		}

		if ( $div ) {
			$output .= '<div id="wp-email-content" class="wp-email">' . "\n";
		}

		if ( ! self::not_spamming() ) {
			$minutes = self::flood_interval();

			$output .= '<p>' . sprintf(
				/* translators: %s: Number of minutes. */
				wp_kses_post( _n( 'Please wait for <strong>%s Minute</strong> before sending the next article.', 'Please wait for <strong>%s Minutes</strong> before sending the next article.', $minutes, 'wp-email' ) ),
				esc_html( number_format_i18n( $minutes ) )
			) . '</p>' . "\n";
		} elseif ( post_password_required() ) {
			$output .= get_the_password_form();
		} else {
			$output .= self::render_fields( $fields, $submitted );
		}

		// A CSS-only spinner rather than the animated GIF the plugin shipped
		// until 3.0.0: it takes the theme's colour through currentColor and
		// stands still for a visitor who asked for reduced motion.
		$output .= '<div id="wp-email-loading" class="wp-email-loading">';
		$output .= '<span class="wp-email-spinner" aria-hidden="true"></span>&nbsp;';
		$output .= esc_html__( 'Loading', 'wp-email' ) . ' ...';
		$output .= '</div>' . "\n";

		if ( $div ) {
			$output .= '</div>' . "\n";
		}

		WP_Email::remove_filters();

		if ( $echo_output ) {
			echo $output; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped at each sink above.
			return '';
		}

		return $output;
	}

	/**
	 * The fields themselves.
	 *
	 * @param array $fields    Which fields are switched on.
	 * @param array $submitted Values the visitor already typed.
	 *
	 * @return string
	 */
	private static function render_fields( array $fields, array $submitted ) {
		/**
		 * Filters the values the form is pre-filled with.
		 *
		 * Named email_form-fieldvalues before 3.0.0, which was neither prefixed
		 * with the plugin's slug nor spelled with underscores.
		 *
		 * @since 3.0.0
		 *
		 * @param array $values Field name => value.
		 */
		$values = apply_filters( 'wp_email_form_field_values', array() );

		// Anything the visitor actually typed wins over the pre-fill, so a
		// failed submission comes back with their input intact.
		foreach ( $submitted as $key => $value ) {
			if ( null !== $value && '' !== $value ) {
				$values[ $key ] = $value;
			}
		}

		$value_of = static function ( $key ) use ( $values ) {
			return isset( $values[ $key ] ) ? (string) $values[ $key ] : '';
		};

		$post_id = isset( $values['id'] ) ? (int) $values['id'] : 0;
		$popup   = 2 === (int) WP_Email_Options::get( 'link', 'type' );

		$output  = self::header( $post_id, $popup );
		$output .= '<p id="wp-email-required" class="wp-email-required">' . esc_html__( '* Required Field', 'wp-email' ) . '</p>' . "\n";

		if ( 1 === (int) $fields['yourname'] ) {
			$output .= '<p>' . "\n";
			$output .= '<label for="yourname">' . esc_html__( 'Your Name: *', 'wp-email' ) . '</label><br />' . "\n";
			$output .= '<input type="text" size="50" id="yourname" name="yourname" class="TextField" value="' . esc_attr( $value_of( 'yourname' ) ) . '" />' . "\n";
			$output .= '</p>' . "\n";
		}

		if ( 1 === (int) $fields['youremail'] ) {
			$output .= '<p>' . "\n";
			$output .= '<label for="youremail">' . esc_html__( 'Your E-Mail: *', 'wp-email' ) . '</label><br />' . "\n";
			$output .= '<input type="email" size="50" id="youremail" name="youremail" class="TextField" value="' . esc_attr( $value_of( 'youremail' ) ) . '" dir="ltr" />' . "\n";
			$output .= '</p>' . "\n";
		}

		if ( 1 === (int) $fields['yourremarks'] ) {
			$remarks = $value_of( 'yourremarks' );

			if ( '' === $remarks ) {
				$remarks = WP_Email_Template::remark();
			}

			$output .= '<p>' . "\n";
			$output .= '<label for="yourremarks">' . esc_html__( 'Your Remark:', 'wp-email' ) . '</label><br />' . "\n";
			$output .= '<textarea cols="49" rows="8" id="yourremarks" name="yourremarks" class="Forms">' . esc_textarea( $remarks ) . '</textarea>' . "\n";
			$output .= '</p>' . "\n";
		}

		if ( 1 === (int) $fields['friendname'] ) {
			$output .= '<p>' . "\n";
			$output .= '<label for="friendname">' . esc_html__( "Friend's Name: *", 'wp-email' ) . '</label><br />' . "\n";
			$output .= '<input type="text" size="50" id="friendname" name="friendname" class="TextField" value="' . esc_attr( $value_of( 'friendname' ) ) . '" />';
			$output .= self::multiple_hint() . "\n";
			$output .= '</p>' . "\n";
		}

		$output .= '<p>' . "\n";
		$output .= '<label for="friendemail">' . esc_html__( "Friend's E-Mail: *", 'wp-email' ) . '</label><br />' . "\n";
		$output .= '<input type="text" size="50" id="friendemail" name="friendemail" class="TextField" value="' . esc_attr( $value_of( 'friendemail' ) ) . '" dir="ltr" />';
		$output .= self::multiple_hint() . "\n";
		$output .= '</p>' . "\n";

		if ( WP_Email_Captcha::is_enabled() ) {
			$token = WP_Email_Captcha::issue();

			$output .= '<p>' . "\n";
			$output .= '<label for="imageverify">' . esc_html__( 'Image Verification: *', 'wp-email' ) . '</label><br />' . "\n";
			$output .= '<img src="' . esc_url( WP_Email_Captcha::image_url( $token ) ) . '" width="55" height="15" alt="' . esc_attr__( 'E-Mail Image Verification', 'wp-email' ) . '" />';
			$output .= '<input type="hidden" id="imageverify_token" name="imageverify_token" value="' . esc_attr( $token ) . '" />';
			$output .= '<input type="text" size="5" maxlength="5" id="imageverify" name="imageverify" class="TextField" autocomplete="off" />' . "\n";
			$output .= '</p>' . "\n";
		}

		$output .= '<p id="wp-email-button" class="wp-email-button">';
		$output .= '<input type="button" value="' . esc_attr__( '     Mail It!     ', 'wp-email' ) . '" id="wp-email-submit" class="Button" />';
		$output .= '</p>' . "\n";
		$output .= '</form>' . "\n";

		return $output;
	}

	/**
	 * Handle a submitted form.
	 *
	 * Registered on wp_ajax_email and wp_ajax_nopriv_email.
	 *
	 * @return void
	 */
	public static function process() {
		if ( ! check_ajax_referer( self::NONCE_ACTION, self::NONCE_NAME, false ) ) {
			esc_html_e( 'Failed To Verify Referrer', 'wp-email' );
			wp_die( '', '', array( 'response' => 200 ) );
		}

		header( 'Content-Type: text/html; charset=' . get_option( 'blog_charset' ) );

		$input = self::read_input();

		$post_id = $input['p'] > 0 ? $input['p'] : $input['page_id'];

		$post = $post_id > 0 ? get_post( $post_id ) : null;

		/*
		 * is_post_publicly_viewable() as well as the status, because the status
		 * alone is not the question. Plenty of post types are published and not
		 * public -- core's own wp_block, wp_template and wp_navigation among
		 * them, and any plugin that stores records as a published private type --
		 * and this endpoint never runs the front-end query, so nothing else on
		 * the path consults the type at all. Without this an anonymous caller
		 * naming any such id got its full rendered content delivered to an
		 * address of their choosing, and its title back in the response.
		 */
		if ( ! $post || 'publish' !== $post->post_status || ! is_post_publicly_viewable( $post ) ) {
			esc_html_e( 'Invalid post.', 'wp-email' );
			wp_die( '', '', array( 'response' => 200 ) );
		}

		$result = self::submit( $input, $post );

		// Three outcomes, not two. A submission that failed validation goes
		// back as the form carrying its errors; a delivery the mailer refused
		// goes back as the sentfailed template, which is a different thing and
		// says so to the person who filled the form in.
		if ( 'invalid' === $result['status'] ) {
			self::render_errors( $result['errors'], $input );
			wp_die( '', '', array( 'response' => 200 ) );
		}

		echo $result['html']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Template is wp_kses_post()'d on save.

		wp_die( '', '', array( 'response' => 200 ) );
	}

	/**
	 * Validate a submission and send it, reporting what happened.
	 *
	 * Split out of process() so the outcome is a value rather than something a
	 * caller has to infer from printed markup. process() used to validate,
	 * send and render in one run and then wp_die(), so "did this send?" was
	 * answerable only by buffering the output and reading it -- which is why
	 * the errors this raises could not reach any client that is not a browser.
	 *
	 * Prints nothing. What to do with the result belongs to the caller.
	 *
	 * @param array   $input Sanitized submission, as read_input() returns it.
	 * @param WP_Post $post  Post being sent.
	 * @return array {
	 *     @type string $status One of 'sent', 'failed' or 'invalid'.
	 *     @type bool   $sent   Whether the message went.
	 *     @type array  $errors Validation messages, empty unless 'invalid'.
	 *     @type string $html   Result markup, empty when 'invalid'.
	 * }
	 */
	public static function submit( array $input, $post ) {
		// Set up the loop so the template variables resolve against the post
		// being sent rather than whatever admin-ajax.php happens to have.
		// admin-ajax.php has no loop of its own, so the post being sent has to
		// be installed as the current one for the template tags to resolve.
		$GLOBALS['post'] = $post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- admin-ajax.php has no loop, so the post being sent has to be installed as the current one for the template tags to resolve.
		setup_postdata( $post );
		$GLOBALS['id'] = $post->ID; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Set with $GLOBALS['post'] above; setup_postdata() reads it and admin-ajax.php never set it.

		$errors = self::validate( $input );

		/*
		 * Validation first, so a submission that was going to be refused anyway
		 * does not burn the sender's slot -- and the claim only after it passes,
		 * because claiming is a write and the interval should measure attempts
		 * to send rather than attempts to fill the form in.
		 *
		 * The throttle is deliberately not an error message. It answers like a
		 * validation failure so a flooder learns nothing from the difference.
		 */
		if ( ! empty( $errors ) || ! self::claim_send_slot() ) {
			return array(
				'status' => 'invalid',
				'sent'   => false,
				'errors' => $errors,
				'html'   => '',
			);
		}

		$outcome = self::send( $input, $post );

		// 'failed' is not 'invalid': the submission was fine and the mailer
		// refused it, so the caller shows the sentfailed template rather than
		// handing the form back as though the person had mistyped something.
		return array(
			'status' => $outcome['sent'] ? 'sent' : 'failed',
			'sent'   => $outcome['sent'],
			'errors' => array(),
			'html'   => $outcome['html'],
		);
	}

	/**
	 * Read and sanitize the submission.
	 *
	 * @return array
	 */
	private static function read_input() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Verified by process() before this runs.
		return array(
			'yourname'          => isset( $_POST['yourname'] ) ? sanitize_text_field( wp_unslash( $_POST['yourname'] ) ) : '',
			'youremail'         => isset( $_POST['youremail'] ) ? sanitize_text_field( wp_unslash( $_POST['youremail'] ) ) : '',
			'yourremarks'       => isset( $_POST['yourremarks'] ) ? sanitize_textarea_field( wp_unslash( $_POST['yourremarks'] ) ) : '',
			'friendname'        => isset( $_POST['friendname'] ) ? sanitize_text_field( wp_unslash( $_POST['friendname'] ) ) : '',
			'friendemail'       => isset( $_POST['friendemail'] ) ? sanitize_text_field( wp_unslash( $_POST['friendemail'] ) ) : '',
			'imageverify'       => isset( $_POST['imageverify'] ) ? sanitize_text_field( wp_unslash( $_POST['imageverify'] ) ) : '',
			// sanitize_text_field() inline keeps the sniff happy -- it cannot see
			// through a Class::method() call -- and sanitize_token() then
			// narrows it to the exact shape WP_Email_Captcha::issue() produces.
			'imageverify_token' => isset( $_POST['imageverify_token'] )
				? WP_Email_Captcha::sanitize_token( sanitize_text_field( wp_unslash( $_POST['imageverify_token'] ) ) )
				: '',
			'p'                 => isset( $_POST['p'] ) ? absint( $_POST['p'] ) : 0,
			'page_id'           => isset( $_POST['page_id'] ) ? absint( $_POST['page_id'] ) : 0,
		);
		// phpcs:enable WordPress.Security.NonceVerification.Missing
	}

	/**
	 * Validate a submission.
	 *
	 * @param array $input Sanitized submission.
	 *
	 * @return array Error messages.
	 */
	private static function validate( array $input ) {
		$fields = WP_Email_Options::all()['fields'];
		$max    = self::max_recipients();
		$errors = array();

		/*
		 * "Required" is a question about the form; "valid" is a question about
		 * the value. Only the first of the two belongs behind the `fields`
		 * toggles, and running both behind them was the bug: turning a field off
		 * turned its validation off with it, while read_input() went on reading
		 * the value and send() went on putting it in the From header. An admin
		 * tidying a field away was quietly widening what a crafted POST could
		 * do, because a submission does not have to come from the form.
		 *
		 * sanitize_text_field() has already flattened CR and LF by the time any
		 * of this runs, so header injection is not reachable either way. These
		 * are the checks that stop a value being a plausible-looking wrong
		 * thing.
		 */
		if ( 1 === (int) $fields['yourname'] && '' === $input['yourname'] ) {
			$errors[] = __( 'Your Name is empty', 'wp-email' );
		} elseif ( '' !== $input['yourname'] && ! self::is_valid_name( $input['yourname'] ) ) {
			$errors[] = __( 'Your Name is invalid', 'wp-email' );
		}

		if ( 1 === (int) $fields['youremail'] && '' === $input['youremail'] ) {
			$errors[] = __( 'Your Email is empty', 'wp-email' );
		} elseif ( '' !== $input['youremail'] && ! self::is_valid_email( $input['youremail'] ) ) {
			$errors[] = __( 'Your Email is invalid', 'wp-email' );
		}

		// Unconditional for the same reason, and this is the header blocklist:
		// %EMAIL_YOUR_REMARKS% is a documented variable for the *subject*
		// template, so on a site that uses it there the value reaches a
		// header-bound token.
		if ( ! self::is_valid_remarks( $input['yourremarks'] ) ) {
			$errors[] = __( 'Your Remarks is invalid', 'wp-email' );
		}

		$names  = self::split_list( $input['friendname'] );
		$emails = self::split_list( $input['friendemail'] );

		if ( 1 === (int) $fields['friendname'] ) {
			if ( empty( $names ) ) {
				$errors[] = __( 'Friend Name(s) is empty', 'wp-email' );
			}

			foreach ( $names as $name ) {
				if ( ! self::is_valid_name( $name ) ) {
					/* translators: %s: The invalid name. */
					$errors[] = sprintf( __( 'Friend Name is invalid: %s', 'wp-email' ), $name );
				}
			}
		}

		if ( empty( $emails ) ) {
			$errors[] = __( 'Friend Email(s) is empty', 'wp-email' );
		}

		foreach ( $emails as $email ) {
			if ( ! self::is_valid_email( $email ) ) {
				/* translators: %s: The invalid e-mail address. */
				$errors[] = sprintf( __( 'Friend Email is invalid: %s', 'wp-email' ), $email );
			}
		}

		if ( count( $emails ) > $max ) {
			$errors[] = sprintf(
				/* translators: %s: Maximum number of recipients. */
				_n( 'Maximum %s Friend allowed', 'Maximum %s Friend(s) allowed', $max, 'wp-email' ),
				number_format_i18n( $max )
			);
		}

		if ( 1 === (int) $fields['friendname'] && count( $names ) !== count( $emails ) ) {
			$errors[] = __( 'Friend Name(s) count does not tally with Friend Email(s) count', 'wp-email' );
		}

		/*
		 * is_required() rather than is_enabled(), so this fails closed. The two
		 * differ only when the site has asked for verification and the server
		 * cannot draw it -- GD lost across a PHP upgrade, most likely -- and the
		 * old check treated that as "no verification needed", which silently
		 * removed the form's only anti-automation control on exactly the install
		 * whose owner believed it was on.
		 */
		if ( WP_Email_Captcha::is_required() ) {
			if ( ! WP_Email_Captcha::is_available() ) {
				$errors[] = __( 'Image Verification is unavailable on this server, so the form cannot be submitted. An administrator needs to turn it off or install the GD extension.', 'wp-email' );
			} elseif ( '' === $input['imageverify'] ) {
				$errors[] = __( 'Image Verification is empty', 'wp-email' );
			} elseif ( ! WP_Email_Captcha::verify( $input['imageverify_token'], $input['imageverify'] ) ) {
				$errors[] = __( 'Image Verification failed', 'wp-email' );
			}
		}

		return $errors;
	}

	/**
	 * Split a comma or semicolon separated list into trimmed, non-empty parts.
	 *
	 * @param string $value Raw list.
	 *
	 * @return array
	 */
	private static function split_list( $value ) {
		if ( '' === trim( (string) $value ) ) {
			return array();
		}

		$parts = preg_split( '/[,;]+/', (string) $value );
		$parts = array_map( 'trim', (array) $parts );

		return array_values( array_filter( $parts, 'strlen' ) );
	}

	/**
	 * Render the error template followed by the re-populated form.
	 *
	 * @param array $errors Error messages.
	 * @param array $input  The submission, so the form comes back filled in.
	 *
	 * @return void
	 */
	private static function render_errors( array $errors, array $input ) {
		if ( empty( $errors ) ) {
			// Blocked by the flood interval rather than by a bad field; the
			// form itself explains the wait.
			echo self::render( '', false, false, false, self::submitted_values( $input ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped at each sink.
			return;
		}

		/*
		 * Escaped here rather than trusted upstream. Two of these messages carry
		 * the value that failed -- "Friend Email is invalid: %s" -- so they hold
		 * visitor input, and they are substituted into a stored template that is
		 * echoed raw. sanitize_text_field() has already stripped tags, so this is
		 * not currently the difference between safe and not; it is the
		 * difference between safe and safe-by-coincidence, and quotes do survive
		 * sanitisation, so a template that moves %EMAIL_ERROR_MSG% inside an
		 * attribute would have had no backstop at all.
		 *
		 * Each message individually, then joined: the separator is the plugin's
		 * own markup and must stay markup.
		 */
		$message = implode( self::ERROR_SEPARATOR, array_map( 'esc_html', $errors ) );

		$output  = WP_Email_Template::expand(
			WP_Email_Options::template( 'error' ),
			array_merge(
				WP_Email_Template::post_vars(),
				array( 'EMAIL_ERROR_MSG' => $message )
			)
		);
		$output .= self::render( '', false, false, false, self::submitted_values( $input ) );

		echo $output; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Template is sanitized on save; form is escaped at each sink.
	}

	/**
	 * The subset of a submission the form should come back holding.
	 *
	 * @param array $input Sanitized submission.
	 *
	 * @return array
	 */
	private static function submitted_values( array $input ) {
		return array(
			'yourname'    => $input['yourname'],
			'youremail'   => $input['youremail'],
			'yourremarks' => $input['yourremarks'],
			'friendname'  => $input['friendname'],
			'friendemail' => $input['friendemail'],
			'id'          => $input['p'] > 0 ? $input['p'] : $input['page_id'],
		);
	}

	/**
	 * Build and send the message, then log it.
	 *
	 * Returns rather than prints, so the caller can tell a send from a failure
	 * -- the template chosen here already knows, and used to discard it.
	 *
	 * @param array   $input Sanitized submission.
	 * @param WP_Post $post  Post being sent.
	 *
	 * @return array {
	 *     @type bool   $sent Whether wp_mail() accepted the message.
	 *     @type string $html Markup for the result template.
	 * }
	 */
	private static function send( array $input, $post ) {
		$names  = self::split_list( $input['friendname'] );
		$emails = self::split_list( $input['friendemail'] );

		$remarks = '' === $input['yourremarks'] ? __( 'N/A', 'wp-email' ) : $input['yourremarks'];

		$post_vars     = WP_Email_Template::post_vars();
		$category_alt  = wp_strip_all_tags( $post_vars['EMAIL_POST_CATEGORY'] );
		$content_type  = (string) WP_Email_Options::get( 'sending', 'contenttype' );
		$is_plain_text = 'text/plain' === $content_type;

		$sender_vars = array(
			'EMAIL_YOUR_NAME'    => $input['yourname'],
			'EMAIL_YOUR_EMAIL'   => $input['youremail'],
			'EMAIL_YOUR_REMARKS' => $remarks,
			'EMAIL_FRIEND_NAME'  => $input['friendname'],
			'EMAIL_FRIEND_EMAIL' => $input['friendemail'],
			'EMAIL_POST_EXCERPT' => get_the_excerpt(),
		);

		$subject = WP_Email_Template::expand(
			WP_Email_Options::template( 'subject' ),
			array_merge( $post_vars, $sender_vars, array( 'EMAIL_POST_CATEGORY' => $category_alt ) )
		);

		$body_vars = array_merge(
			$post_vars,
			$sender_vars,
			array(
				'EMAIL_POST_CONTENT'  => $is_plain_text ? WP_Email_Template::content_alt() : WP_Email_Template::content(),
				'EMAIL_POST_CATEGORY' => $is_plain_text ? $category_alt : $post_vars['EMAIL_POST_CATEGORY'],
			)
		);

		$message = WP_Email_Template::expand(
			WP_Email_Options::template( $is_plain_text ? 'bodyalt' : 'body' ),
			$body_vars
		);

		if ( ! $is_plain_text && is_rtl() ) {
			$message = '<div style="direction: rtl;">' . $message . '</div>';
		}

		$to = array();

		foreach ( $emails as $index => $email ) {
			$name = isset( $names[ $index ] ) ? $names[ $index ] : '';

			$to[] = '' === $name ? $email : $name . ' <' . $email . '>';
		}

		$headers = array(
			'From: ' . $input['yourname'] . ' <' . $input['youremail'] . '>',
			'Content-Type: ' . $content_type . '; charset=' . get_bloginfo( 'charset' ),
		);

		$sent = wp_mail( implode( ', ', $to ), wp_specialchars_decode( $subject, ENT_QUOTES ), $message, $headers );

		$status = $sent ? WP_Email_Logs::STATUS_SUCCESS : WP_Email_Logs::STATUS_FAILED;
		$ip     = self::ip_address();

		foreach ( $emails as $index => $email ) {
			WP_Email_Logs::insert(
				array(
					'yourname'    => $input['yourname'],
					'youremail'   => $input['youremail'],
					'yourremarks' => $sent ? $remarks : $input['yourremarks'],
					'friendname'  => isset( $names[ $index ] ) ? $names[ $index ] : '',
					'friendemail' => $email,
					'postid'      => $post->ID,
					'posttitle'   => $post_vars['EMAIL_POST_TITLE'],
					// Site-local, not UTC, and deliberately so: the column has
					// held local timestamps since 2.x and the logs screen reads
					// them back as local. Switching to time() would shift every
					// historical row by the site's offset.
					'timestamp'   => current_time( 'timestamp' ), // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested -- The column has held site-local time since 2.x; switching to UTC would shift every historical row by the site's offset.
					'ip'          => $ip,
					'host'        => '' === $ip ? '' : gethostbyaddr( $ip ),
					'status'      => $status,
				)
			);
		}

		$result_vars = array_merge(
			$post_vars,
			array(
				'EMAIL_FRIEND_NAME'  => $input['friendname'],
				'EMAIL_FRIEND_EMAIL' => $input['friendemail'],
				'EMAIL_ERROR_MSG'    => '',
			)
		);

		$result = WP_Email_Template::expand(
			WP_Email_Options::template( $sent ? 'sentsuccess' : 'sentfailed' ),
			$result_vars
		);

		return array(
			'sent' => $sent,
			'html' => $result,
		);
	}
}
