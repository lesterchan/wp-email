<?php
### Session Start
@session_start();

### Filters
add_action('wp_head', 'email_meta_nofollow');
add_filter('wp_title', 'email_pagetitle');
add_action('loop_start', 'email_addfilters');

### We Use Page Template
if(file_exists(TEMPLATEPATH.'/email.php')) {
	include(TEMPLATEPATH.'/email.php');
} elseif(file_exists(TEMPLATEPATH.'/page.php')) {
	include(get_page_template());
} elseif(file_exists(TEMPLATEPATH.'/single.php')) {
	include(get_single_template());
} else {
	include(TEMPLATEPATH.'/index.php');
}
