<?php
/**
 * WP-EMail class-wp-email-settings.php
 *
 * @package WP-EMail
 */

defined( 'ABSPATH' ) || exit;

/**
 * The options screen, on the Settings API.
 *
 * Replaces the hand-rolled <form> and its per-field $_POST handling. The
 * Settings API owns the nonce, the capability check and the save, and
 * WP_Email_Options::sanitize() is registered as the sanitize_callback so it runs
 * on every write.
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
		if ( false === strpos( $hook, 'wp-email' ) ) {
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

		add_settings_section( 'wp-email-styles', __( 'E-Mail Styles', 'wp-email' ), '__return_false', self::GROUP );
		add_settings_section( 'wp-email-settings', __( 'E-Mail Settings', 'wp-email' ), '__return_false', self::GROUP );
		add_settings_section(
			'wp-email-templates',
			__( 'E-Mail Templates', 'wp-email' ),
			array( $this, 'render_variable_reference' ),
			self::GROUP
		);

		$this->add_style_fields();
		$this->add_setting_fields();
		$this->add_template_fields();
	}

	/**
	 * Fields for the "E-Mail Styles" section.
	 *
	 * @return void
	 */
	private function add_style_fields() {
		$fields = array(
			'post_text' => __( 'E-Mail Text Link For Post', 'wp-email' ),
			'page_text' => __( 'E-Mail Text Link For Page', 'wp-email' ),
			'icon'      => __( 'E-Mail Icon', 'wp-email' ),
			'type'      => __( 'E-Mail Link Type', 'wp-email' ),
			'style'     => __( 'E-Mail Text Link Style', 'wp-email' ),
		);

		foreach ( $fields as $key => $label ) {
			add_settings_field(
				'wp-email-link-' . $key,
				$label,
				array( $this, 'render_link_field' ),
				self::GROUP,
				'wp-email-styles',
				array(
					'key'       => $key,
					'label_for' => 'email_link_' . $key,
				)
			);
		}
	}

	/**
	 * Fields for the "E-Mail Settings" section.
	 *
	 * @return void
	 */
	private function add_setting_fields() {
		add_settings_field(
			'wp-email-fields',
			__( 'E-Mail Fields:', 'wp-email' ),
			array( $this, 'render_fields_field' ),
			self::GROUP,
			'wp-email-settings'
		);

		$fields = array(
			'contenttype' => __( 'E-Mail Content Type:', 'wp-email' ),
			'snippet'     => __( 'No. Of Words Before Cutting Off:', 'wp-email' ),
			'interval'    => __( 'Interval Between E-Mails:', 'wp-email' ),
			'ip_header'   => __( 'Header That Contains The IP:', 'wp-email' ),
			'multiple'    => __( 'Max Number Of Multiple E-Mails:', 'wp-email' ),
			'imageverify' => __( 'Enable Image Verification:', 'wp-email' ),
		);

		foreach ( $fields as $key => $label ) {
			add_settings_field(
				'wp-email-sending-' . $key,
				$label,
				array( $this, 'render_sending_field' ),
				self::GROUP,
				'wp-email-settings',
				array(
					'key'       => $key,
					'label_for' => 'email_sending_' . $key,
				)
			);
		}
	}

	/**
	 * Fields for the templates section.
	 *
	 * @return void
	 */
	private function add_template_fields() {
		foreach ( self::template_meta() as $key => $meta ) {
			add_settings_field(
				'wp-email-template-' . $key,
				$meta['label'],
				array( $this, 'render_template_field' ),
				self::GROUP,
				'wp-email-templates',
				array(
					'key'       => $key,
					'label_for' => 'email_template_' . $key,
				)
			);
		}
	}

	/**
	 * Label, input type and permitted variables for each template.
	 *
	 * The variable names are deliberately data rather than parts of a
	 * translatable sentence: phpcbf reads a literal %TOKEN% inside a
	 * translatable string as a printf placeholder and renumbers it, which
	 * would rewrite the token users are told to type.
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
				'label' => __( 'E-Mail Page Title:', 'wp-email' ),
				'rows'  => 0,
				'vars'  => array_merge( $post, $site ),
			),
			'subtitle'    => array(
				'label' => __( 'E-Mail Page Subtitle:', 'wp-email' ),
				'rows'  => 0,
				'vars'  => array_merge( $post, $site ),
			),
			'subject'     => array(
				'label' => __( 'E-Mail Subject:', 'wp-email' ),
				'rows'  => 0,
				'vars'  => array_merge( $sender, $post, $site ),
			),
			'body'        => array(
				'label' => __( 'E-Mail Body:', 'wp-email' ),
				'rows'  => 15,
				'vars'  => array_merge( $sender, $friend, $post, $body, $site ),
			),
			'bodyalt'     => array(
				'label' => __( 'E-Mail Alternate Body:', 'wp-email' ),
				'rows'  => 15,
				'vars'  => array_merge( $sender, $friend, $post, $body, $site ),
			),
			'sentsuccess' => array(
				'label' => __( 'Sent Successfully:', 'wp-email' ),
				'rows'  => 10,
				'vars'  => array_merge( $friend, array( 'EMAIL_POST_TITLE' ), $site ),
			),
			'sentfailed'  => array(
				'label' => __( 'Sent Failed:', 'wp-email' ),
				'rows'  => 10,
				'vars'  => array_merge( $friend, array( 'EMAIL_POST_TITLE' ), $site ),
			),
			'error'       => array(
				'label' => __( 'E-Mail Error:', 'wp-email' ),
				'rows'  => 10,
				'vars'  => array_merge( array( 'EMAIL_ERROR_MSG' ), $site ),
			),
		);
	}

	/**
	 * A field name in the nested option array.
	 *
	 * @param string $group Group key.
	 * @param string $key   Setting key.
	 *
	 * @return string
	 */
	private static function name( $group, $key ) {
		return WP_Email_Options::OPTION . '[' . $group . '][' . $key . ']';
	}

	/**
	 * Render one of the link-style fields.
	 *
	 * @param array $args Field arguments.
	 *
	 * @return void
	 */
	public function render_link_field( $args ) {
		$key   = $args['key'];
		$link  = WP_Email_Options::all()['link'];
		$name  = self::name( 'link', $key );
		$id    = 'email_link_' . $key;
		$value = $link[ $key ];

		switch ( $key ) {
			case 'post_text':
			case 'page_text':
				printf(
					'<input type="text" id="%1$s" name="%2$s" value="%3$s" size="30" class="regular-text" />',
					esc_attr( $id ),
					esc_attr( $name ),
					esc_attr( $value )
				);
				break;

			case 'icon':
				foreach ( WP_Email_Options::available_icons() as $icon ) {
					printf(
						'<p><label><input type="radio" name="%1$s" value="%2$s" %3$s /> <img src="%4$s" alt="%2$s" /> <code>%2$s</code></label></p>',
						esc_attr( $name ),
						esc_attr( $icon ),
						checked( $icon, $value, false ),
						esc_url( plugins_url( 'images/' . $icon, WP_EMAIL_MAIN_FILE ) )
					);
				}
				break;

			case 'type':
				$this->render_select(
					$id,
					$name,
					(int) $value,
					array(
						1 => __( 'E-Mail Standalone Page', 'wp-email' ),
						2 => __( 'E-Mail Popup', 'wp-email' ),
					)
				);
				break;

			case 'style':
				$this->render_select(
					$id,
					$name,
					(int) $value,
					array(
						1 => __( 'E-Mail Icon With Text Link', 'wp-email' ),
						2 => __( 'E-Mail Icon Only', 'wp-email' ),
						3 => __( 'E-Mail Text Link Only', 'wp-email' ),
						4 => __( 'Custom', 'wp-email' ),
					),
					'data-wp-email-toggle="email_link_custom" data-wp-email-toggle-value="4"'
				);

				$this->render_custom_link_markup( $link );
				break;
		}
	}

	/**
	 * The custom-HTML box shown when the link style is "Custom".
	 *
	 * @param array $link The link settings.
	 *
	 * @return void
	 */
	private function render_custom_link_markup( array $link ) {
		$hidden = 4 === (int) $link['style'] ? '' : ' style="display: none;"';
		?>
		<div id="email_link_custom"<?php echo $hidden; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Literal attribute. ?>>
			<p>
				<textarea rows="3" cols="80" class="large-text code" id="email_link_html" name="<?php echo esc_attr( self::name( 'link', 'html' ) ); ?>"><?php echo esc_textarea( $link['html'] ); ?></textarea>
			</p>
			<p class="description"><?php esc_html_e( 'HTML is allowed. Available variables:', 'wp-email' ); ?></p>
			<ul>
				<li><code>%EMAIL_URL%</code> &mdash; <?php esc_html_e( 'URL to the email post/page.', 'wp-email' ); ?></li>
				<li><code>%EMAIL_POPUP%</code> &mdash; <?php esc_html_e( 'Marks the link as opening the popup.', 'wp-email' ); ?></li>
				<li><code>%EMAIL_TEXT%</code> &mdash; <?php esc_html_e( 'The link text you typed above.', 'wp-email' ); ?></li>
				<li><code>%EMAIL_ICON_URL%</code> &mdash; <?php esc_html_e( 'URL to the icon you chose above.', 'wp-email' ); ?></li>
			</ul>
			<p class="description">
				<?php esc_html_e( 'Example popup template:', 'wp-email' ); ?>
				<br />
				<code dir="ltr">&lt;a href="%EMAIL_URL%" %EMAIL_POPUP% rel="nofollow" title="%EMAIL_TEXT%"&gt;%EMAIL_TEXT%&lt;/a&gt;</code>
			</p>
			<?php $this->render_restore_button( 'email_link_html', WP_Email_Options::defaults()['link']['html'] ); ?>
		</div>
		<?php
	}

	/**
	 * Render the e-mail field checkboxes.
	 *
	 * @return void
	 */
	public function render_fields_field() {
		$fields = WP_Email_Options::all()['fields'];

		$labels = array(
			'yourname'    => __( 'Your Name', 'wp-email' ),
			'youremail'   => __( 'Your E-Mail', 'wp-email' ),
			'yourremarks' => __( 'Your Remarks', 'wp-email' ),
			'friendname'  => __( "Friend's Name", 'wp-email' ),
		);

		foreach ( $labels as $key => $label ) {
			printf(
				'<p><label><input type="checkbox" name="%1$s" value="1" %2$s /> %3$s</label></p>',
				esc_attr( self::name( 'fields', $key ) ),
				checked( 1, (int) $fields[ $key ], false ),
				esc_html( $label )
			);
		}

		printf(
			'<p><label><input type="checkbox" checked="checked" disabled="disabled" /> %s</label></p>',
			esc_html__( "Friend's E-Mail", 'wp-email' )
		);
	}

	/**
	 * Render one of the sending fields.
	 *
	 * @param array $args Field arguments.
	 *
	 * @return void
	 */
	public function render_sending_field( $args ) {
		$key     = $args['key'];
		$sending = WP_Email_Options::all()['sending'];
		$name    = self::name( 'sending', $key );
		$id      = 'email_sending_' . $key;
		$value   = $sending[ $key ];

		switch ( $key ) {
			case 'contenttype':
				$this->render_select(
					$id,
					$name,
					$value,
					array(
						'text/plain' => __( 'Plain Text', 'wp-email' ),
						'text/html'  => __( 'HTML', 'wp-email' ),
					)
				);
				break;

			case 'snippet':
				$this->render_number( $id, $name, $value, 0 );
				echo '<p class="description">' . esc_html__( 'Setting this above 0 sends only that many words of the article instead of the whole thing.', 'wp-email' ) . '</p>';
				break;

			case 'interval':
				$this->render_number( $id, $name, $value, 0 );
				echo ' ' . esc_html__( 'Mins', 'wp-email' );
				echo '<p class="description">' . esc_html__( 'Minutes each visitor, by IP, must wait between sends. Set to 0 to disable.', 'wp-email' ) . '</p>';
				break;

			case 'multiple':
				$this->render_number( $id, $name, $value, 1 );
				echo '<p class="description">' . esc_html__( 'Maximum number of recipients allowed in one send.', 'wp-email' ) . '</p>';
				break;

			case 'ip_header':
				printf(
					'<input type="text" id="%1$s" name="%2$s" value="%3$s" size="30" class="regular-text" placeholder="REMOTE_ADDR" />',
					esc_attr( $id ),
					esc_attr( $name ),
					esc_attr( $value )
				);
				echo '<p class="description">';
				esc_html_e( 'Leave blank to use REMOTE_ADDR. Only set this if a proxy in front of WordPress always overwrites the header, otherwise a visitor can forge it and bypass the interval above.', 'wp-email' );
				echo '<br />';
				printf(
					/* translators: 1: The WP_EMAIL_TRUST_PROXY constant, 2: the wp_email_trust_proxy filter, both in code spans. */
					esc_html__( 'Alternatively define %1$s, or return true from the %2$s filter, to trust the usual proxy headers.', 'wp-email' ),
					'<code>WP_EMAIL_TRUST_PROXY</code>',
					'<code>wp_email_trust_proxy</code>'
				);
				echo '</p>';
				break;

			case 'imageverify':
				$available = WP_Email_Captcha::is_available();

				printf(
					'<label><input type="checkbox" id="%1$s" name="%2$s" value="1" %3$s %4$s /> %5$s</label>',
					esc_attr( $id ),
					esc_attr( $name ),
					checked( 1, (int) $value, false ),
					disabled( $available, false, false ),
					esc_html__( 'Ask senders to read a verification image', 'wp-email' )
				);

				if ( ! $available ) {
					echo '<p class="description">' . esc_html__( 'Unavailable: this server has no PHP GD library, so no image can be drawn.', 'wp-email' ) . '</p>';
				}
				break;
		}
	}

	/**
	 * Render one template field, its variable list and its restore button.
	 *
	 * @param array $args Field arguments.
	 *
	 * @return void
	 */
	public function render_template_field( $args ) {
		$key      = $args['key'];
		$meta     = self::template_meta();
		$meta     = $meta[ $key ];
		$name     = self::name( 'templates', $key );
		$id       = 'email_template_' . $key;
		$value    = WP_Email_Options::template( $key );
		$defaults = WP_Email_Options::defaults();

		if ( $meta['rows'] > 0 ) {
			printf(
				'<textarea rows="%1$d" cols="80" class="large-text code" id="%2$s" name="%3$s">%4$s</textarea>',
				(int) $meta['rows'],
				esc_attr( $id ),
				esc_attr( $name ),
				esc_textarea( $value )
			);
		} else {
			printf(
				'<input type="text" class="large-text code" id="%1$s" name="%2$s" value="%3$s" />',
				esc_attr( $id ),
				esc_attr( $name ),
				esc_attr( $value )
			);
		}

		echo '<p class="description">' . esc_html__( 'Allowed variables:', 'wp-email' ) . ' ';

		$codes = array();

		foreach ( $meta['vars'] as $var ) {
			$codes[] = '<code>%' . esc_html( $var ) . '%</code>';
		}

		echo wp_kses_post( implode( ' ', $codes ) );
		echo '</p>';

		$this->render_restore_button( $id, $defaults['templates'][ $key ] );
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
	private function render_restore_button( $target, $value ) {
		printf(
			'<p><button type="button" class="button button-secondary" data-wp-email-restore="%1$s" data-wp-email-default="%2$s">%3$s</button></p>',
			esc_attr( $target ),
			esc_attr( $value ),
			esc_html__( 'Restore Default Template', 'wp-email' )
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
	private function render_select( $id, $name, $current, array $choices, $extra = '' ) {
		printf(
			'<select id="%1$s" name="%2$s" %3$s>',
			esc_attr( $id ),
			esc_attr( $name ),
			$extra // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Literal attributes from this class only.
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
	private function render_number( $id, $name, $value, $min ) {
		printf(
			'<input type="number" min="%1$d" id="%2$s" name="%3$s" value="%4$s" class="small-text" />',
			(int) $min,
			esc_attr( $id ),
			esc_attr( $name ),
			esc_attr( $value )
		);
	}

	/**
	 * The shared variable reference, shown above the templates.
	 *
	 * @return void
	 */
	public function render_variable_reference() {
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
						<td style="width: 15em;"><code><?php echo esc_html( '%' . $var . '%' ); ?></code></td>
						<td><?php echo esc_html( $description ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Render the options screen.
	 *
	 * @return void
	 */
	public static function render() {
		if ( ! current_user_can( self::capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to manage e-mail.', 'wp-email' ) );
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'E-Mail Options', 'wp-email' ); ?></h1>

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
