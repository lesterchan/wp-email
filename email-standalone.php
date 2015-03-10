<?php
### Session Start
@session_start();

### Filters
add_action('wp_head', 'email_meta_nofollow');
add_filter('wp_title', 'email_pagetitle');
add_action('loop_start', 'email_addfilters');
add_filter( 'comments_open', '__return_false' );

### We Use Page Template
if ( $template = locate_template( 'email.php' ) ) {
	include $template;
} elseif ( $template = get_page_template() ) {
	include $template;
} elseif ( $template = get_single_template() ) {
	include $template;
} elseif ( $template = get_index_template() ) {
	include $template;
}