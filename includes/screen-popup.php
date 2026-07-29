<?php
/**
 * WP-EMail popup e-mail page.
 *
 * Loaded by WP_Email::maybe_render_email_page() when the /emailpopup/ endpoint is
 * hit. Deliberately standalone rather than themed: it renders inside a small
 * window.open() popup.
 *
 * @package WP-EMail
 */

defined( 'ABSPATH' ) || exit;

add_filter( 'the_title', array( 'WP_Email', 'filter_title' ) );

// The popup is not themed, but it does borrow the theme's stylesheet so the
// form does not look foreign. Enqueued rather than hand-written into <head> so
// dependencies and versioning behave normally.
wp_enqueue_style( 'wp-email-popup-theme', get_stylesheet_uri(), array(), WP_EMAIL_VERSION );

?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>" />
	<meta name="robots" content="noindex, nofollow" />
	<meta name="viewport" content="width=device-width, initial-scale=1" />
	<title><?php echo esc_html( get_bloginfo( 'name' ) . ' &raquo; ' . __( 'E-Mail', 'wp-email' ) ); ?></title>
	<?php wp_head(); ?>
</head>
<body class="wp-email-popup-body" data-wp-email-reposition="1">
	<div id="wp-email-popup" class="wp-email wp-email-popup">
		<?php WP_Email_Form::render(); ?>
		<p class="wp-email-popup-close">
			<a href="#" data-wp-email-close="1"><?php esc_html_e( 'Close This Window', 'wp-email' ); ?></a>
		</p>
	</div>
	<?php wp_footer(); ?>
</body>
</html>
<?php
