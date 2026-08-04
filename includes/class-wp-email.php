<?php
/**
 * WP-EMail class-wp-email.php
 *
 * @package WP-EMail
 */

defined( 'ABSPATH' ) || exit;

/**
 * Plugin bootstrap: registers the table, the endpoints and every hook.
 *
 * @since 3.0.0
 */
class WP_Email {

	/**
	 * Static instance.
	 *
	 * @var WP_Email|null
	 */
	private static $instance;

	/**
	 * Constructor.
	 *
	 * The activation hook is registered here rather than on a later hook: this
	 * runs while the main plugin file is loading, which is where WordPress
	 * requires it to be registered.
	 */
	public function __construct() {
		$this->register_table();

		register_activation_hook( WP_EMAIL_MAIN_FILE, array( $this, 'activate' ) );

		add_action( 'plugins_loaded', array( $this, 'add_hooks' ) );
	}

	/**
	 * Initialize the plugin object and return its instance.
	 *
	 * @return WP_Email
	 */
	public static function get_instance() {
		if ( ! isset( self::$instance ) ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Make $wpdb->email available.
	 *
	 * Adding the name to $wpdb->tables is what keeps it correct across
	 * switch_to_blog() on multisite, since WordPress re-prefixes those.
	 *
	 * @return void
	 */
	private function register_table() {
		global $wpdb;

		$wpdb->tables[] = 'email';
		$wpdb->email    = $wpdb->prefix . 'email';
	}

	/**
	 * Register the plugin's hooks.
	 *
	 * @return void
	 */
	public function add_hooks() {
		add_action( 'init', array( $this, 'register_endpoints' ) );
		add_filter( 'query_vars', array( $this, 'register_query_vars' ) );

		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
		add_action( 'template_redirect', array( $this, 'maybe_render_email_page' ), 5 );

		add_shortcode( 'email_link', array( $this, 'link_shortcode' ) );
		add_shortcode( 'donotemail', array( $this, 'donotemail_shortcode' ) );

		add_action( 'wp_ajax_email', array( 'WP_Email_Form', 'process' ) );
		add_action( 'wp_ajax_nopriv_email', array( 'WP_Email_Form', 'process' ) );

		add_action( 'wp_ajax_wp_email_captcha', array( 'WP_Email_Captcha', 'serve' ) );
		add_action( 'wp_ajax_nopriv_wp_email_captcha', array( 'WP_Email_Captcha', 'serve' ) );

		add_action( 'widgets_init', array( $this, 'register_widget' ) );

		add_filter( 'wp_email_form_field_values', array( $this, 'prefill_for_logged_in_user' ) );

		// Loaded unconditionally and inert without WP-Stats: the class hooks one
		// filter WP-Stats fires and nothing else, so there is nothing to probe
		// for. See STANDARDS.md 13.
		new WP_Email_WPStats();

		if ( is_admin() ) {
			add_action( 'admin_init', array( $this, 'maybe_upgrade' ) );

			new WP_Email_Admin();
			new WP_Email_Settings();
		}
	}

	/**
	 * Register the /email/ and /emailpopup/ endpoints.
	 *
	 * @return void
	 */
	public function register_endpoints() {
		add_rewrite_endpoint( 'email', EP_PERMALINK | EP_PAGES, 'wp_email' );
		add_rewrite_endpoint( 'emailpopup', EP_PERMALINK | EP_PAGES, 'wp_email_popup' );
	}

	/**
	 * Declare the plugin's public query vars.
	 *
	 * @param array $vars Registered query vars.
	 *
	 * @return array
	 */
	public function register_query_vars( $vars ) {
		$vars[] = 'wp_email';
		$vars[] = 'wp_email_popup';

		return $vars;
	}

	/**
	 * Enqueue the stylesheet and the form script.
	 *
	 * One stylesheet, in both text directions: the rules are written with
	 * logical properties, so the mirrored email-css-rtl.css the plugin shipped
	 * until 3.0.0 has nothing left to say and is gone.
	 *
	 * A theme that wants its own rules enqueues them after this handle, or
	 * dequeues it outright; the plugin no longer goes looking for a file of its
	 * own name in the theme directory, which meant a theme could only replace
	 * the sheet wholesale and never add to it.
	 *
	 * @return void
	 */
	public function enqueue_scripts() {
		wp_enqueue_style( 'wp-email', WP_EMAIL_URL . 'css/wp-email.css', array(), WP_EMAIL_VERSION );

		// Shipped unminified and served as-is: with no build step in the repo a
		// separate minified copy only drifts out of sync with this one.
		wp_enqueue_script(
			'wp-email',
			WP_EMAIL_URL . 'js/wp-email.js',
			array(),
			WP_EMAIL_VERSION,
			true
		);

		$max = WP_Email_Form::max_recipients();

		wp_localize_script(
			'wp-email',
			'wpEmailL10n',
			array(
				'ajax_url'                       => admin_url( 'admin-ajax.php' ),
				'max_allowed'                    => $max,
				'text_error'                     => __( 'The Following Error Occurs:', 'wp-email' ),
				'text_name_invalid'              => __( '- Your Name is empty/invalid', 'wp-email' ),
				'text_email_invalid'             => __( '- Your Email is empty/invalid', 'wp-email' ),
				'text_remarks_invalid'           => __( '- Your Remarks is invalid', 'wp-email' ),
				'text_friend_names_empty'        => __( '- Friend Name(s) is empty', 'wp-email' ),
				'text_friend_name_invalid'       => __( '- Friend Name is empty/invalid: ', 'wp-email' ),
				'text_max_friend_names_allowed'  => sprintf(
					/* translators: %s: Maximum number of names. */
					_n( '- Maximum %s Friend Name allowed', '- Maximum %s Friend Names allowed', $max, 'wp-email' ),
					number_format_i18n( $max )
				),
				'text_friend_emails_empty'       => __( '- Friend Email(s) is empty', 'wp-email' ),
				'text_friend_email_invalid'      => __( '- Friend Email is invalid: ', 'wp-email' ),
				'text_max_friend_emails_allowed' => sprintf(
					/* translators: %s: Maximum number of e-mail addresses. */
					_n( '- Maximum %s Friend Email allowed', '- Maximum %s Friend Emails allowed', $max, 'wp-email' ),
					number_format_i18n( $max )
				),
				'text_friends_tally'             => __( '- Friend Name(s) count does not tally with Friend Email(s) count', 'wp-email' ),
				'text_image_verify_empty'        => __( '- Image Verification is empty', 'wp-email' ),
			)
		);
	}

	/**
	 * Serve the standalone or popup e-mail page when its endpoint is hit.
	 *
	 * @return void
	 */
	public function maybe_render_email_page() {
		global $wp_query;

		/**
		 * Filters whether the plugin takes over the request for its endpoints.
		 *
		 * @param bool $handle Whether to render the e-mail page.
		 */
		if ( ! apply_filters( 'wp_email_template_redirect', true ) ) {
			return;
		}

		if ( array_key_exists( 'wp_email', $wp_query->query_vars ) ) {
			require WP_EMAIL_DIR . 'includes/screen-standalone.php';
			exit;
		}

		if ( array_key_exists( 'wp_email_popup', $wp_query->query_vars ) ) {
			require WP_EMAIL_DIR . 'includes/screen-popup.php';
			exit;
		}
	}

	/**
	 * Add the filters that turn a post into the e-mail form.
	 *
	 * Runs on loop_start, which passes the query being started. That argument
	 * is what has to be judged: the global is_main_query() describes
	 * $GLOBALS['wp_query'], so a secondary loop on the e-mail page -- a widget,
	 * a related-posts block -- would otherwise get the title rewritten and the
	 * form injected into its content.
	 *
	 * @param WP_Query|null $query Query being started.
	 *
	 * @return void
	 */
	public static function add_filters( $query = null ) {
		$is_main = $query instanceof WP_Query ? $query->is_main_query() : is_main_query();

		if ( ! $is_main ) {
			return;
		}

		add_filter( 'the_title', array( 'WP_Email', 'filter_title' ) );
		add_filter( 'the_content', array( 'WP_Email_Form', 'render' ) );
	}

	/**
	 * Remove them again.
	 *
	 * @return void
	 */
	public static function remove_filters() {
		remove_action( 'loop_start', array( 'WP_Email', 'add_filters' ) );
		remove_filter( 'the_title', array( 'WP_Email', 'filter_title' ) );
		remove_filter( 'the_content', array( 'WP_Email_Form', 'render' ) );
	}

	/**
	 * Replace the post title with the configured page-title template.
	 *
	 * @param string $title Post title.
	 *
	 * @return string
	 */
	public static function filter_title( $title ) {
		if ( ! in_the_loop() ) {
			return $title;
		}

		return WP_Email_Template::expand( WP_Email_Options::template( 'title' ), WP_Email_Template::post_vars() );
	}

	/**
	 * Append " » E-Mail" to the document title.
	 *
	 * @param string $title Document title.
	 *
	 * @return string
	 */
	public static function filter_page_title( $title ) {
		return $title . ' &raquo; ' . __( 'E-Mail', 'wp-email' );
	}

	/**
	 * Append " » E-Mail" to the document title parts.
	 *
	 * The same job as filter_page_title() for the other half of the title API:
	 * a theme declaring add_theme_support( 'title-tag' ) goes through
	 * document_title_parts and never calls wp_title(), which core deprecated in
	 * 4.4. Both are hooked, because a site may be running either kind of theme.
	 *
	 * A method rather than the global function this used to be. It is hooked
	 * from screen-standalone.php, which is required at request time when the
	 * /email/ endpoint is hit -- so a global there is both a §2.5 violation and
	 * a redeclare fatal waiting for the day something includes that file twice.
	 *
	 * @param array $parts Title parts.
	 *
	 * @return array
	 */
	public static function filter_document_title_parts( $parts ) {
		if ( isset( $parts['title'] ) ) {
			$parts['title'] .= ' &raquo; ' . __( 'E-Mail', 'wp-email' );
		}

		return $parts;
	}

	/**
	 * Tell robots not to index the e-mail page.
	 *
	 * @return void
	 */
	public static function noindex() {
		echo '<meta name="robots" content="noindex, nofollow" />' . "\n";
	}

	/**
	 * The [email_link] shortcode.
	 *
	 * @return string
	 */
	public function link_shortcode() {
		if ( is_feed() ) {
			return __( 'Note: There is an email link embedded within this post, please visit this post to email it.', 'wp-email' );
		}

		return WP_Email_Link::render();
	}

	/**
	 * The [donotemail] shortcode: renders normally everywhere but in an e-mail.
	 *
	 * @param array  $atts    Shortcode attributes.
	 * @param string $content Enclosed content.
	 *
	 * @return string
	 */
	public function donotemail_shortcode( $atts, $content = null ) {
		return do_shortcode( (string) $content );
	}

	/**
	 * Pre-fill the sender fields for a logged-in visitor.
	 *
	 * @param array $values Field name => value.
	 *
	 * @return array
	 */
	public function prefill_for_logged_in_user( $values ) {
		if ( ! is_user_logged_in() ) {
			return $values;
		}

		$user = wp_get_current_user();

		$values['yourname']  = $user->display_name;
		$values['youremail'] = $user->user_email;

		return $values;
	}

	/**
	 * Register the most-emailed widget.
	 *
	 * @return void
	 */
	public function register_widget() {
		require_once WP_EMAIL_DIR . 'includes/class-wp-email-widget.php';

		register_widget( 'WP_Email_Widget' );
	}

	/**
	 * Install on activation.
	 *
	 * @param bool $network_wide Whether the plugin is being activated network wide.
	 *
	 * @return void
	 */
	public function activate( $network_wide = false ) {
		if ( is_multisite() && $network_wide ) {
			// 'number' => 0 lifts WP_Site_Query's default cap of 100.
			$site_ids = get_sites(
				array(
					'fields' => 'ids',
					'number' => 0,
				)
			);

			foreach ( $site_ids as $site_id ) {
				switch_to_blog( (int) $site_id );
				$this->install();
				restore_current_blog();
			}

			return;
		}

		$this->install();
	}

	/**
	 * Run the install when the stored markers are behind.
	 *
	 * Activation alone is not enough: the hook does not fire on a plugin
	 * update, which is the single most common reason a migration never runs.
	 *
	 * @return void
	 */
	public function maybe_upgrade() {
		$markers = WP_Email_Options::markers();

		$plugin = isset( $markers['plugin'] ) ? (string) $markers['plugin'] : '';
		$db     = isset( $markers['db'] ) ? (string) $markers['db'] : '';

		if ( WP_EMAIL_VERSION === $plugin && WP_EMAIL_DB_VERSION === $db ) {
			return;
		}

		$this->install();
	}

	/**
	 * Create the table, fold the old rows in, and grant the capability.
	 *
	 * The markers are written last, by WP_Email_Options::maybe_upgrade(), so an
	 * upgrade that dies half way through never records itself as finished.
	 *
	 * @return void
	 */
	public function install() {
		WP_Email_Logs::install();
		WP_Email_Logs::normalize_statuses();

		$role = get_role( 'administrator' );

		if ( $role && ! $role->has_cap( WP_Email_Admin::CAPABILITY ) ) {
			$role->add_cap( WP_Email_Admin::CAPABILITY );
		}

		WP_Email_Options::maybe_upgrade();

		// The endpoints are registered on init, which has already run by the
		// time activation fires, so the rules are there to be written out.
		flush_rewrite_rules();
	}
}
