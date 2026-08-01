<?php
/**
 * WP-EMail class-wp-email-settings.php
 *
 * @package WP-EMail
 */

defined( 'ABSPATH' ) || exit;

/**
 * The settings screen, on the Settings API.
 *
 * Replaces the hand-rolled <form> and its per-field $_POST handling. The
 * Settings API owns the nonce, the capability check and the save;
 * WP_Email_Options::sanitize() is registered as the sanitize_callback so it
 * runs on every write; and do_settings_sections() emits the form table, so
 * there is no hand-written <table class="form-table"> anywhere in this file.
 *
 * WP_Email_Admin owns the menu and the screens. This class owns
 * register_setting(), the sections, the field_<name>() callbacks and the
 * sanitiser, per STANDARDS.md 4.2.
 *
 * @since 3.0.0
 */
class WP_Email_Settings {

	/**
	 * Settings group, which is the settings row's name.
	 */
	const GROUP = 'wp_email_options';

	/**
	 * Page slug for the settings screen.
	 */
	const PAGE = 'wp-email-settings';

	/**
	 * Capability required for the settings screen.
	 *
	 * A settings screen, so manage_options rather than the plugin's own
	 * manage_email, per STANDARDS.md 2.7. Administrators hold both.
	 */
	const CAPABILITY = 'manage_options';

	/**
	 * The link-appearance section.
	 */
	const SECTION_STYLES = 'wp_email_styles';

	/**
	 * The form and delivery section.
	 */
	const SECTION_SENDING = 'wp_email_sending';

	/**
	 * The WP-Stats section.
	 */
	const SECTION_STATS = 'wp_email_stats';

	/**
	 * The templates section.
	 */
	const SECTION_TEMPLATES = 'wp_email_templates';

	/**
	 * The capability the settings screen is gated on.
	 *
	 * @return string
	 */
	public static function capability() {
		/** This filter is documented in includes/class-wp-email-admin.php */
		return (string) apply_filters( 'wp_email_capability', self::CAPABILITY, 'settings' );
	}

	/**
	 * Hook the settings up.
	 */
	public function __construct() {
		add_action( 'admin_init', array( $this, 'register' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Load the admin script on this plugin's screens only.
	 *
	 * @param string $hook Current admin page.
	 *
	 * @return void
	 */
	public function enqueue( $hook ) {
		if ( false === strpos( $hook, WP_EMAIL_SLUG ) ) {
			return;
		}

		wp_enqueue_script(
			'wp-email-admin',
			WP_EMAIL_URL . 'js/wp-email-admin.js',
			array(),
			WP_EMAIL_VERSION,
			true
		);
	}

	/**
	 * Register the setting, its sections and its fields.
	 *
	 * @return void
	 */
	public function register() {
		register_setting(
			self::GROUP,
			WP_Email_Options::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( 'WP_Email_Options', 'sanitize' ),
				'default'           => WP_Email_Options::defaults(),
			)
		);

		add_settings_section( self::SECTION_STYLES, __( 'E-Mail Link', 'wp-email' ), '__return_false', self::GROUP );
		add_settings_section( self::SECTION_SENDING, __( 'E-Mail Settings', 'wp-email' ), '__return_false', self::GROUP );
		add_settings_section( self::SECTION_STATS, __( 'WP-Stats', 'wp-email' ), array( $this, 'describe_stats_section' ), self::GROUP );
		add_settings_section( self::SECTION_TEMPLATES, __( 'E-Mail Templates', 'wp-email' ), array( $this, 'describe_templates_section' ), self::GROUP );

		$this->add_fields();
	}

	/**
	 * Every field, in the order it appears on the screen.
	 *
	 * One add_settings_field() per callback, so the list of fields and the list
	 * of methods that render them cannot drift apart.
	 *
	 * @return void
	 */
	protected function add_fields() {
		$fields = array(
			self::SECTION_STYLES    => array(
				'post_text'  => array( __( 'E-Mail Text Link For Post', 'wp-email' ), 'wp_email_link_post_text' ),
				'page_text'  => array( __( 'E-Mail Text Link For Page', 'wp-email' ), 'wp_email_link_page_text' ),
				'link_type'  => array( __( 'E-Mail Link Type', 'wp-email' ), 'wp_email_link_type' ),
				'link_style' => array( __( 'E-Mail Text Link Style', 'wp-email' ), 'wp_email_link_style' ),
			),
			self::SECTION_SENDING   => array(
				'form_fields' => array( __( 'E-Mail Fields', 'wp-email' ), '' ),
				'contenttype' => array( __( 'E-Mail Content Type', 'wp-email' ), 'wp_email_sending_contenttype' ),
				'snippet'     => array( __( 'No. Of Words Before Cutting Off', 'wp-email' ), 'wp_email_sending_snippet' ),
				'interval'    => array( __( 'Interval Between E-Mails', 'wp-email' ), 'wp_email_sending_interval' ),
				'ip_header'   => array( __( 'Header That Contains The IP', 'wp-email' ), 'wp_email_sending_ip_header' ),
				'multiple'    => array( __( 'Max Number Of Multiple E-Mails', 'wp-email' ), 'wp_email_sending_multiple' ),
				'imageverify' => array( __( 'Enable Image Verification', 'wp-email' ), 'wp_email_sending_imageverify' ),
			),
			self::SECTION_STATS     => array(
				'stats_display'    => array( __( 'Show WP-EMail On The WP-Stats Page', 'wp-email' ), '' ),
				'stats_most_limit' => array( __( 'No. Of Most Emailed Entries', 'wp-email' ), 'wp_email_stats_most_limit' ),
			),
			self::SECTION_TEMPLATES => array(),
		);

		foreach ( self::template_meta() as $key => $meta ) {
			$fields[ self::SECTION_TEMPLATES ][ 'template_' . $key ] = array( $meta['label'], 'wp_email_template_' . $key );
		}

		foreach ( $fields as $section => $section_fields ) {
			foreach ( $section_fields as $key => $field ) {
				list( $label, $label_for ) = $field;

				add_settings_field(
					'wp_email_' . $key,
					$label,
					array( $this, 'field_' . $key ),
					self::GROUP,
					$section,
					'' === $label_for ? array() : array( 'label_for' => $label_for )
				);
			}
		}
	}

	/**
	 * Label, input size and permitted variables for each template.
	 *
	 * @return array
	 */
	public static function template_meta() {
		$post   = array( 'EMAIL_POST_TITLE', 'EMAIL_POST_AUTHOR', 'EMAIL_POST_DATE', 'EMAIL_POST_CATEGORY' );
		$site   = array( 'EMAIL_BLOG_NAME', 'EMAIL_BLOG_URL', 'EMAIL_PERMALINK' );
		$sender = array( 'EMAIL_YOUR_NAME', 'EMAIL_YOUR_EMAIL', 'EMAIL_YOUR_REMARKS' );
		$friend = array( 'EMAIL_FRIEND_NAME', 'EMAIL_FRIEND_EMAIL' );
		$body   = array( 'EMAIL_POST_EXCERPT', 'EMAIL_POST_CONTENT' );

		return array(
			'title'       => array(
				'label' => __( 'E-Mail Page Title', 'wp-email' ),
				'rows'  => 0,
				'vars'  => array_merge( $post, $site ),
			),
			'subtitle'    => array(
				'label' => __( 'E-Mail Page Subtitle', 'wp-email' ),
				'rows'  => 0,
				'vars'  => array_merge( $post, $site ),
			),
			'subject'     => array(
				'label' => __( 'E-Mail Subject', 'wp-email' ),
				'rows'  => 0,
				'vars'  => array_merge( $sender, $post, $site ),
			),
			'body'        => array(
				'label' => __( 'E-Mail Body', 'wp-email' ),
				'rows'  => 15,
				'vars'  => array_merge( $sender, $friend, $post, $body, $site ),
			),
			'bodyalt'     => array(
				'label' => __( 'E-Mail Alternate Body', 'wp-email' ),
				'rows'  => 15,
				'vars'  => array_merge( $sender, $friend, $post, $body, $site ),
			),
			'sentsuccess' => array(
				'label' => __( 'Sent Successfully', 'wp-email' ),
				'rows'  => 10,
				'vars'  => array_merge( $friend, array( 'EMAIL_POST_TITLE' ), $site ),
			),
			'sentfailed'  => array(
				'label' => __( 'Sent Failed', 'wp-email' ),
				'rows'  => 10,
				'vars'  => array_merge( $friend, array( 'EMAIL_POST_TITLE' ), $site ),
			),
			'error'       => array(
				'label' => __( 'E-Mail Error', 'wp-email' ),
				'rows'  => 10,
				'vars'  => array_merge( array( 'EMAIL_ERROR_MSG' ), $site ),
			),
		);
	}

	// ------------------------------------------------------ the link fields --

	/**
	 * The link text shown on a post.
	 *
	 * @return void
	 */
	public function field_post_text() {
		$this->text( 'wp_email_link_post_text', $this->name( 'link', 'post_text' ), WP_Email_Options::get( 'link', 'post_text' ) );
	}

	/**
	 * The link text shown on a page.
	 *
	 * @return void
	 */
	public function field_page_text() {
		$this->text( 'wp_email_link_page_text', $this->name( 'link', 'page_text' ), WP_Email_Options::get( 'link', 'page_text' ) );
	}

	/**
	 * Whether the link opens the standalone page or the popup.
	 *
	 * @return void
	 */
	public function field_link_type() {
		$this->select(
			'wp_email_link_type',
			$this->name( 'link', 'type' ),
			(int) WP_Email_Options::get( 'link', 'type' ),
			array(
				1 => __( 'E-Mail Standalone Page', 'wp-email' ),
				2 => __( 'E-Mail Popup', 'wp-email' ),
			)
		);
	}

	/**
	 * Which of the four link layouts is used.
	 *
	 * @return void
	 */
	public function field_link_style() {
		$this->select(
			'wp_email_link_style',
			$this->name( 'link', 'style' ),
			(int) WP_Email_Options::get( 'link', 'style' ),
			array(
				1 => __( 'E-Mail Icon With Text Link', 'wp-email' ),
				2 => __( 'E-Mail Icon Only', 'wp-email' ),
				3 => __( 'E-Mail Text Link Only', 'wp-email' ),
				4 => __( 'Custom', 'wp-email' ),
			),
			'data-wp-email-toggle="wp_email_link_custom" data-wp-email-toggle-value="4"'
		);

		$this->render_custom_link_markup();
	}

	/**
	 * The custom-HTML box shown when the link style is "Custom".
	 *
	 * Hidden with the hidden attribute rather than an inline style, so the
	 * screen carries no style attributes at all (STANDARDS.md 4.4).
	 *
	 * @return void
	 */
	protected function render_custom_link_markup() {
		$defaults = WP_Email_Options::defaults();
		$hidden   = 4 === (int) WP_Email_Options::get( 'link', 'style' ) ? '' : ' hidden';
		?>
		<div id="wp_email_link_custom"<?php echo esc_attr( $hidden ); ?>>
			<p>
				<textarea rows="3" cols="80" class="large-text code" id="wp_email_link_html" name="<?php echo esc_attr( $this->name( 'link', 'html' ) ); ?>"><?php echo esc_textarea( WP_Email_Options::get( 'link', 'html' ) ); ?></textarea>
			</p>
			<p class="description"><?php esc_html_e( 'HTML is allowed. Available variables:', 'wp-email' ); ?></p>
			<ul>
				<li><code>%EMAIL_URL%</code> &mdash; <?php esc_html_e( 'URL to the email post/page.', 'wp-email' ); ?></li>
				<li><code>%EMAIL_POPUP%</code> &mdash; <?php esc_html_e( 'Marks the link as opening the popup.', 'wp-email' ); ?></li>
				<li><code>%EMAIL_TEXT%</code> &mdash; <?php esc_html_e( 'The link text you typed above.', 'wp-email' ); ?></li>
				<li><code>%EMAIL_ICON%</code> &mdash; <?php esc_html_e( 'The envelope glyph, drawn inline.', 'wp-email' ); ?></li>
			</ul>
			<p class="description">
				<?php esc_html_e( 'Example popup template:', 'wp-email' ); ?>
				<br />
				<code dir="ltr">&lt;a href="%EMAIL_URL%" %EMAIL_POPUP% rel="nofollow" title="%EMAIL_TEXT%"&gt;%EMAIL_TEXT%&lt;/a&gt;</code>
			</p>
			<?php $this->restore_button( 'wp_email_link_html', $defaults['link']['html'] ); ?>
		</div>
		<?php
	}

	// --------------------------------------------------- the sending fields --

	/**
	 * Which of the sender's fields the form asks for.
	 *
	 * @return void
	 */
	public function field_form_fields() {
		$labels = array(
			'yourname'    => __( 'Your Name', 'wp-email' ),
			'youremail'   => __( 'Your E-Mail', 'wp-email' ),
			'yourremarks' => __( 'Your Remarks', 'wp-email' ),
			'friendname'  => __( "Friend's Name", 'wp-email' ),
		);

		foreach ( $labels as $key => $label ) {
			printf(
				'<p><label><input type="checkbox" name="%1$s" value="1" %2$s /> %3$s</label></p>',
				esc_attr( $this->name( 'fields', $key ) ),
				checked( 1, (int) WP_Email_Options::get( 'fields', $key ), false ),
				esc_html( $label )
			);
		}

		printf(
			'<p><label><input type="checkbox" checked="checked" disabled="disabled" /> %s</label></p>',
			esc_html__( "Friend's E-Mail", 'wp-email' )
		);
	}

	/**
	 * Whether the message goes out as HTML or plain text.
	 *
	 * @return void
	 */
	public function field_contenttype() {
		$this->select(
			'wp_email_sending_contenttype',
			$this->name( 'sending', 'contenttype' ),
			WP_Email_Options::get( 'sending', 'contenttype' ),
			array(
				'text/plain' => __( 'Plain Text', 'wp-email' ),
				'text/html'  => __( 'HTML', 'wp-email' ),
			)
		);
	}

	/**
	 * How much of the article travels with the message.
	 *
	 * @return void
	 */
	public function field_snippet() {
		$this->number( 'wp_email_sending_snippet', $this->name( 'sending', 'snippet' ), WP_Email_Options::get( 'sending', 'snippet' ), 0 );

		echo '<p class="description">' . esc_html__( 'Setting this above 0 sends only that many words of the article instead of the whole thing.', 'wp-email' ) . '</p>';
	}

	/**
	 * How long a visitor must wait between sends.
	 *
	 * @return void
	 */
	public function field_interval() {
		$this->number( 'wp_email_sending_interval', $this->name( 'sending', 'interval' ), WP_Email_Options::get( 'sending', 'interval' ), 0 );

		echo ' ' . esc_html__( 'Mins', 'wp-email' );
		echo '<p class="description">' . esc_html__( 'Minutes each visitor, by IP, must wait between sends. Set to 0 to disable.', 'wp-email' ) . '</p>';
	}

	/**
	 * Which header the visitor's address is read from.
	 *
	 * @return void
	 */
	public function field_ip_header() {
		printf(
			'<input type="text" id="%1$s" name="%2$s" value="%3$s" size="30" class="regular-text" placeholder="REMOTE_ADDR" />',
			'wp_email_sending_ip_header',
			esc_attr( $this->name( 'sending', 'ip_header' ) ),
			esc_attr( WP_Email_Options::get( 'sending', 'ip_header' ) )
		);

		// Worded exactly as wp-polls and wp-postratings word it. A visitor
		// meeting the same setting in three of these plugins should not have to
		// work out three times whether it means the same thing.
		echo '<p class="description">';
		esc_html_e( 'Leave this blank unless the site is behind a reverse proxy or CDN. Blank means the address the web server saw is used.', 'wp-email' );
		echo '<br />';
		printf(
			/* translators: 1: an example header name, 2: the WP_EMAIL_TRUST_PROXY constant, 3: the wp_email_trust_proxy filter, all in code spans. */
			esc_html__( 'Example: %1$s. You can also opt in with the %2$s constant or the %3$s filter, which trust the usual proxy headers instead of one you name.', 'wp-email' ),
			'<code>HTTP_X_FORWARDED_FOR</code>',
			'<code>WP_EMAIL_TRUST_PROXY</code>',
			'<code>wp_email_trust_proxy</code>'
		);
		echo '<br />';
		printf(
			'<strong>%s</strong>',
			esc_html__( 'Only name a header your proxy sets and overwrites. A visitor can send any header they like, so trusting one your stack does not control lets anyone send as often as they wish.', 'wp-email' )
		);
		echo '</p>';
	}

	/**
	 * How many recipients one send may carry.
	 *
	 * @return void
	 */
	public function field_multiple() {
		$this->number( 'wp_email_sending_multiple', $this->name( 'sending', 'multiple' ), WP_Email_Options::get( 'sending', 'multiple' ), 1 );

		echo '<p class="description">' . esc_html__( 'Maximum number of recipients allowed in one send.', 'wp-email' ) . '</p>';
	}

	/**
	 * Whether senders have to read a verification image.
	 *
	 * @return void
	 */
	public function field_imageverify() {
		$available = WP_Email_Captcha::is_available();

		printf(
			'<label><input type="checkbox" id="%1$s" name="%2$s" value="1" %3$s %4$s /> %5$s</label>',
			'wp_email_sending_imageverify',
			esc_attr( $this->name( 'sending', 'imageverify' ) ),
			checked( 1, (int) WP_Email_Options::get( 'sending', 'imageverify' ), false ),
			disabled( $available, false, false ),
			esc_html__( 'Ask senders to read a verification image', 'wp-email' )
		);

		if ( ! $available ) {
			echo '<p class="description">' . esc_html__( 'Unavailable: this server has no PHP GD library, so no image can be drawn.', 'wp-email' ) . '</p>';
		}
	}

	// ----------------------------------------------------- the WP-Stats fields --

	/**
	 * The note above the WP-Stats fields.
	 *
	 * @return void
	 */
	public function describe_stats_section() {
		echo '<p class="description">' . esc_html__( 'These settings only do anything while WP-Stats is installed and active.', 'wp-email' ) . '</p>';
	}

	/**
	 * Whether the plugin contributes a block to the WP-Stats page.
	 *
	 * @return void
	 */
	public function field_stats_display() {
		printf(
			'<label><input type="checkbox" id="%1$s" name="%2$s" value="1" %3$s /> %4$s</label>',
			'wp_email_stats_display',
			esc_attr( WP_Email_Options::OPTION . '[stats_display]' ),
			checked( true, WP_Email_Options::stats_display(), false ),
			esc_html__( 'Show the e-mail totals and the most emailed posts and pages', 'wp-email' )
		);
	}

	/**
	 * How many entries the WP-Stats lists show.
	 *
	 * @return void
	 */
	public function field_stats_most_limit() {
		$this->number( 'wp_email_stats_most_limit', WP_Email_Options::OPTION . '[stats_most_limit]', WP_Email_Options::stats_most_limit(), 1 );
	}

	// --------------------------------------------------- the template fields --

	/**
	 * The note and the variable reference above the templates.
	 *
	 * @return void
	 */
	public function describe_templates_section() {
		$vars = array(
			'EMAIL_YOUR_NAME'     => __( "Display the sender's name", 'wp-email' ),
			'EMAIL_YOUR_EMAIL'    => __( "Display the sender's email", 'wp-email' ),
			'EMAIL_YOUR_REMARKS'  => __( "Display the sender's remarks", 'wp-email' ),
			'EMAIL_FRIEND_NAME'   => __( "Display the friend's name", 'wp-email' ),
			'EMAIL_FRIEND_EMAIL'  => __( "Display the friend's email", 'wp-email' ),
			'EMAIL_ERROR_MSG'     => __( 'Display the error message', 'wp-email' ),
			'EMAIL_BLOG_NAME'     => __( "Display the blog's name", 'wp-email' ),
			'EMAIL_BLOG_URL'      => __( "Display the blog's url", 'wp-email' ),
			'EMAIL_POST_TITLE'    => __( "Display the post's title", 'wp-email' ),
			'EMAIL_POST_AUTHOR'   => __( "Display the post's author", 'wp-email' ),
			'EMAIL_POST_DATE'     => __( "Display the post's date", 'wp-email' ),
			'EMAIL_POST_CATEGORY' => __( "Display the post's category", 'wp-email' ),
			'EMAIL_POST_EXCERPT'  => __( "Display the post's excerpt", 'wp-email' ),
			'EMAIL_POST_CONTENT'  => __( "Display the post's content", 'wp-email' ),
			'EMAIL_PERMALINK'     => __( 'Display the permalink of the post', 'wp-email' ),
		);
		?>
		<p><?php esc_html_e( 'Template Variables', 'wp-email' ); ?></p>
		<table class="widefat striped">
			<tbody>
				<?php foreach ( $vars as $var => $description ) : ?>
					<tr>
						<td><code><?php echo esc_html( '%' . $var . '%' ); ?></code></td>
						<td><?php echo esc_html( $description ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * The title of the standalone e-mail page.
	 *
	 * @return void
	 */
	public function field_template_title() {
		$this->template_field( 'title' );
	}

	/**
	 * The subtitle above the form.
	 *
	 * @return void
	 */
	public function field_template_subtitle() {
		$this->template_field( 'subtitle' );
	}

	/**
	 * The subject line of the message.
	 *
	 * @return void
	 */
	public function field_template_subject() {
		$this->template_field( 'subject' );
	}

	/**
	 * The HTML body of the message.
	 *
	 * @return void
	 */
	public function field_template_body() {
		$this->template_field( 'body' );
	}

	/**
	 * The plain text body of the message.
	 *
	 * @return void
	 */
	public function field_template_bodyalt() {
		$this->template_field( 'bodyalt' );
	}

	/**
	 * What the visitor sees after a successful send.
	 *
	 * @return void
	 */
	public function field_template_sentsuccess() {
		$this->template_field( 'sentsuccess' );
	}

	/**
	 * What the visitor sees after a failed send.
	 *
	 * @return void
	 */
	public function field_template_sentfailed() {
		$this->template_field( 'sentfailed' );
	}

	/**
	 * What the visitor sees when the form is rejected.
	 *
	 * @return void
	 */
	public function field_template_error() {
		$this->template_field( 'error' );
	}

	// ------------------------------------------------------- shared renderers --

	/**
	 * Render one template field, its variable list and its restore button.
	 *
	 * @param string $key Template key.
	 *
	 * @return void
	 */
	protected function template_field( $key ) {
		$meta     = self::template_meta();
		$meta     = $meta[ $key ];
		$name     = $this->name( 'templates', $key );
		$id       = 'wp_email_template_' . $key;
		$defaults = WP_Email_Options::defaults();

		if ( $meta['rows'] > 0 ) {
			printf(
				'<textarea rows="%1$d" cols="80" class="large-text code" id="%2$s" name="%3$s">%4$s</textarea>',
				(int) $meta['rows'],
				esc_attr( $id ),
				esc_attr( $name ),
				esc_textarea( WP_Email_Options::template( $key ) )
			);
		} else {
			printf(
				'<input type="text" class="large-text code" id="%1$s" name="%2$s" value="%3$s" />',
				esc_attr( $id ),
				esc_attr( $name ),
				esc_attr( WP_Email_Options::template( $key ) )
			);
		}

		$codes = array();

		foreach ( $meta['vars'] as $var ) {
			$codes[] = '<code>' . esc_html( '%' . $var . '%' ) . '</code>';
		}

		echo '<p class="description">' . esc_html__( 'Allowed variables:', 'wp-email' ) . ' ' . wp_kses_post( implode( ' ', $codes ) ) . '</p>';

		$this->restore_button( $id, $defaults['templates'][ $key ] );
	}

	/**
	 * A field name in the nested option array.
	 *
	 * @param string $group Group key.
	 * @param string $key   Setting key.
	 *
	 * @return string
	 */
	protected function name( $group, $key ) {
		return WP_Email_Options::OPTION . '[' . $group . '][' . $key . ']';
	}

	/**
	 * A "restore default" button.
	 *
	 * The default travels in a data attribute rather than in an inline onclick
	 * handler, so the template text is escaped once as an attribute instead of
	 * being run through esc_js( esc_attr() ) into executable markup.
	 *
	 * @param string $target Element ID to reset.
	 * @param string $value  Default value.
	 *
	 * @return void
	 */
	protected function restore_button( $target, $value ) {
		printf(
			'<p><button type="button" class="button button-secondary" data-wp-email-restore="%1$s" data-wp-email-default="%2$s">%3$s</button></p>',
			esc_attr( $target ),
			esc_attr( $value ),
			esc_html__( 'Restore Default Template', 'wp-email' )
		);
	}

	/**
	 * Render a text input.
	 *
	 * @param string $id    Element ID.
	 * @param string $name  Field name.
	 * @param string $value Current value.
	 *
	 * @return void
	 */
	protected function text( $id, $name, $value ) {
		printf(
			'<input type="text" id="%1$s" name="%2$s" value="%3$s" size="30" class="regular-text" />',
			esc_attr( $id ),
			esc_attr( $name ),
			esc_attr( $value )
		);
	}

	/**
	 * Render a select.
	 *
	 * @param string $id      Element ID.
	 * @param string $name    Field name.
	 * @param mixed  $current Selected value.
	 * @param array  $choices Value => label.
	 * @param string $extra   Extra attributes.
	 *
	 * @return void
	 */
	protected function select( $id, $name, $current, array $choices, $extra = '' ) {
		printf(
			'<select id="%1$s" name="%2$s" %3$s>',
			esc_attr( $id ),
			esc_attr( $name ),
			$extra // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Literal data attributes written in this class; there is no caller-supplied value to escape.
		);

		foreach ( $choices as $value => $label ) {
			printf(
				'<option value="%1$s" %2$s>%3$s</option>',
				esc_attr( $value ),
				selected( (string) $value, (string) $current, false ),
				esc_html( $label )
			);
		}

		echo '</select>';
	}

	/**
	 * Render a number input.
	 *
	 * @param string $id    Element ID.
	 * @param string $name  Field name.
	 * @param mixed  $value Current value.
	 * @param int    $min   Minimum.
	 *
	 * @return void
	 */
	protected function number( $id, $name, $value, $min ) {
		printf(
			'<input type="number" min="%1$d" id="%2$s" name="%3$s" value="%4$s" class="small-text" />',
			(int) $min,
			esc_attr( $id ),
			esc_attr( $name ),
			esc_attr( $value )
		);
	}

	/**
	 * Render the settings screen.
	 *
	 * @return void
	 */
	public static function render() {
		if ( ! current_user_can( self::capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to change these settings.', 'wp-email' ) );
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'E-Mail Settings', 'wp-email' ); ?></h1>

			<?php settings_errors(); ?>

			<form method="post" action="options.php">
				<?php
				settings_fields( self::GROUP );
				do_settings_sections( self::GROUP );
				submit_button();
				?>
			</form>
		</div>
		<?php
	}
}
