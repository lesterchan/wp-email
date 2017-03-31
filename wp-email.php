<?php
/*
 Plugin Name: WP-EMail
 Plugin URI: https://lesterchan.net/portfolio/programming/php/
 Description: Allows people to recommand/send your WordPress blog's post/page to a friend.
 Version: 2.67.5
 Author: Lester 'GaMerZ' Chan
 Author URI: https://lesterchan.net
 Text Domain: wp-email
 */

/*
    Copyright 2017  Lester Chan  (email : lesterchan@gmail.com)

    This program is free software; you can redistribute it and/or modify
    it under the terms of the GNU General Public License as published by
    the Free Software Foundation; either version 2 of the License, or
    (at your option) any later version.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with this program; if not, write to the Free Software
    Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
*/

define( 'WP_EMAIL_VERSION', '2.67.5' );

### Define: Show Email Remarks In Logs?
define('EMAIL_SHOW_REMARKS', true);


### Create Text Domain For Translations
add_action( 'plugins_loaded', 'email_textdomain' );
function email_textdomain() {
	load_plugin_textdomain( 'wp-email', false, dirname( plugin_basename( __FILE__ ) ) );
}


### E-Mail Table Name
global $wpdb;
$wpdb->email = $wpdb->prefix.'email';


### Function: E-Mail Administration Menu
add_action('admin_menu', 'email_menu');
function email_menu() {
	add_menu_page(__('E-Mail', 'wp-email'), __('E-Mail', 'wp-email'), 'manage_email', 'wp-email/email-manager.php', '', 'dashicons-email-alt');
	add_submenu_page('wp-email/email-manager.php', __('Manage E-Mail', 'wp-email'), __('Manage E-Mail', 'wp-email'), 'manage_email', 'wp-email/email-manager.php');
	add_submenu_page('wp-email/email-manager.php', __('E-Mail Options', 'wp-email'), __('E-Mail Options', 'wp-email'),  'manage_email', 'wp-email/email-options.php');
}


### Function: Add htaccess Rewrite Endpoint - this handles all the rules
add_action( 'init', 'wp_email_endpoint' );
function wp_email_endpoint() {
	add_rewrite_endpoint( 'email', EP_PERMALINK | EP_PAGES, 'wp_email' );
	add_rewrite_endpoint( 'emailpopup', EP_PERMALINK | EP_PAGES, 'wp_email_popup' );
}


### Function: E-Mail Public Variables
add_filter('query_vars', 'email_variables');
function email_variables($public_query_vars) {
	$public_query_vars[] = 'wp_email';
	$public_query_vars[] = 'wp_email_popup';
	return $public_query_vars;
}


### Function: Print Out jQuery Script At The Top
add_action('wp_head', 'email_javascripts_header');
function email_javascripts_header() {
	wp_print_scripts('jquery');
}


### Function: Enqueue E-Mail Javascripts/CSS
add_action('wp_enqueue_scripts', 'email_scripts');
function email_scripts() {
	if(@file_exists(get_stylesheet_directory().'/email-css.css')) {
		wp_enqueue_style('wp-email', get_stylesheet_directory_uri().'/email-css.css', false, WP_EMAIL_VERSION, 'all');
	} else {
		wp_enqueue_style('wp-email', plugins_url('wp-email/email-css.css'), false, WP_EMAIL_VERSION, 'all');
	}
	if( is_rtl() ) {
		if(@file_exists(get_stylesheet_directory().'/email-css-rtl.css')) {
			wp_enqueue_style('wp-email-rtl', get_stylesheet_directory_uri().'/email-css-rtl.css', false, WP_EMAIL_VERSION, 'all');
		} else {
			wp_enqueue_style('wp-email-rtl', plugins_url('wp-email/email-css-rtl.css'), false, WP_EMAIL_VERSION, 'all');
		}
	}
	$email_max = intval(get_option('email_multiple'));
	wp_enqueue_script('wp-email', plugins_url('wp-email/email-js.js'), array('jquery'), WP_EMAIL_VERSION, true);
	wp_localize_script('wp-email', 'emailL10n', array(
		'ajax_url' => admin_url('admin-ajax.php'),
		'max_allowed' => $email_max,
		'text_error' => __('The Following Error Occurs:', 'wp-email'),
		'text_name_invalid' => __('- Your Name is empty/invalid', 'wp-email'),
		'text_email_invalid' => __('- Your Email is empty/invalid', 'wp-email'),
		'text_remarks_invalid' => __('- Your Remarks is invalid', 'wp-email'),
		'text_friend_names_empty' => __('- Friend Name(s) is empty', 'wp-email'),
		'text_friend_name_invalid' => __('- Friend Name is empty/invalid: ', 'wp-email'),
		'text_max_friend_names_allowed' => sprintf(_n('- Maximum %s Friend Name allowed', '- Maximum %s Friend Names allowed', $email_max, 'wp-email'), number_format_i18n($email_max)),
		'text_friend_emails_empty' => __('- Friend Email(s) is empty', 'wp-email'),
		'text_friend_email_invalid' => __('- Friend Email is invalid: ', 'wp-email'),
		'text_max_friend_emails_allowed' => sprintf(_n('- Maximum %s Friend Email allowed', '- Maximum %s Friend Emails allowed', $email_max, 'wp-email'), number_format_i18n($email_max)),
		'text_friends_tally' => __('- Friend Name(s) count does not tally with Friend Email(s) count', 'wp-email'),
		'text_image_verify_empty' => __('- Image Verification is empty', 'wp-email')
	));
}


### Function: Display E-Mail Link
function email_link($email_post_text = '', $email_page_text = '', $echo = true) {
	global $id;
	$output = '';
	$using_permalink = get_option('permalink_structure');
	$email_options = get_option('email_options');
	$email_style = intval($email_options['email_style']);
	$email_type = intval($email_options['email_type']);
	if(empty($email_post_text)) {
		$email_text = stripslashes($email_options['post_text']);
	} else {
		$email_text = $email_post_text;
	}
	$email_icon = plugins_url('wp-email/images/'.$email_options['email_icon']);
	$email_link = get_permalink();
	$email_html = stripslashes($email_options['email_html']);
	$onclick = '';
	// Fix For Static Page
	if(get_option('show_on_front') == 'page' && is_page()) {
		if(intval(get_option('page_on_front')) > 0) {
			$email_link = _get_page_link();
		}
	}
	switch($email_type) {
		// E-Mail Standalone Page
		case 1:
			if(!empty($using_permalink)) {
				if(substr($email_link, -1, 1) != '/') {
					$email_link= $email_link.'/';
				}
				if(is_page()) {
					if(empty($email_page_text)) {
						$email_text = stripslashes($email_options['page_text']);
					} else {
						$email_text = $email_page_text;
					}
				}
				$email_link .= 'email/';
			} else {
				if(is_page()) {
					if(empty($email_page_text)) {
						$email_text = stripslashes($email_options['page_text']);
					} else {
						$email_text = $email_page_text;
					}
				}
				$email_link .= '&amp;wp_email=1';
			}
			break;
		// E-Mail Popup
		case 2:
			if(!empty($using_permalink)) {
				if(substr($email_link, -1, 1) != '/') {
					$email_link= $email_link.'/';
				}
				if(is_page()) {
					if(empty($email_page_text)) {
						$email_text = stripslashes($email_options['page_text']);
					} else {
						$email_text = $email_page_text;
					}
				}
				$email_link .= 'emailpopup/';
			} else {
				if(is_page()) {
					if(empty($email_page_text)) {
						$email_text = stripslashes($email_options['page_text']);
					} else {
						$email_text = $email_page_text;
					}
				}
				$email_link .= '&amp;wp_email_popup=1';
			}
			$onclick = ' onclick="email_popup(this.href); return false;" ';
			break;
	}
	unset($email_options);
	switch($email_style) {
		// Icon + Text Link
		case 1:
			$output = '<a href="'.$email_link.'"'.$onclick.' title="'.$email_text.'" rel="nofollow"><img class="WP-EmailIcon" src="' . esc_attr( $email_icon ) .'" alt="' . esc_attr( $email_text ) . '" title="' . esc_attr( $email_text ) . '" style="border: 0px;" /></a>&nbsp;<a href="' . esc_attr( $email_link ) .'"' . $onclick . ' title="' . esc_attr( $email_text ) . '" rel="nofollow">' . $email_text . '</a>';
			break;
		// Icon Only
		case 2:
			$output = '<a href="'.$email_link.'"'.$onclick.' title="'.$email_text.'" rel="nofollow"><img class="WP-EmailIcon" src="' . esc_attr( $email_icon ) .'" alt="' . esc_attr( $email_text ) . '" title="' . esc_attr( $email_text ) .'" style="border: 0px;" /></a>';
			break;
		// Text Link Only
		case 3:
			$output = '<a href="'.$email_link.'"'.$onclick.' title="'.$email_text.'" rel="nofollow">'.$email_text.'</a>';
			break;
		case 4:
			$email_html = str_replace("%EMAIL_URL%", $email_link, $email_html);
			$email_html = str_replace("%EMAIL_POPUP%", $onclick, $email_html);
			$email_html = str_replace("%EMAIL_TEXT%", $email_text, $email_html);
			$email_html = str_replace("%EMAIL_ICON_URL%", $email_icon, $email_html);
			$output = $email_html;
			break;
	}
	if($echo) {
		echo $output."\n";
	} else {
		return $output;
	}
}


### Function: Short Code For Inserting Email Links Into Posts/Pages
add_shortcode('email_link', 'email_link_shortcode');
function email_link_shortcode($atts) {
	if(!is_feed()) {
		return email_link('', '', false);
	} else {
		return __('Note: There is an email link embedded within this post, please visit this post to email it.', 'wp-email');
	}
}
function email_link_shortcode2($atts) {
	return;
}


### Function: Short Code For DO NOT EMAIL Content
add_shortcode('donotemail', 'email_donotemail_shortcode');
function email_donotemail_shortcode($atts, $content = null) {
	return do_shortcode($content);
}
function email_donotemail_shortcode2($atts, $content = null) {
	return;
}


### Function: Snippet Words
if(!function_exists( 'snippet_words' ) ) {
	function snippet_words( $text, $length = 0 ) {
		$words = explode(' ', $text);
		return implode(' ', array_slice( $words, 0, $length ) ) . ' ...';
	}
}


### Function: Snippet Text
if(!function_exists('snippet_text')) {
	function snippet_text($text, $length = 0) {
		if (defined('MB_OVERLOAD_STRING')) {
		  $text = @html_entity_decode($text, ENT_QUOTES, get_option('blog_charset'));
		 	if (mb_strlen($text) > $length) {
				return htmlentities(mb_substr($text,0,$length), ENT_COMPAT, get_option('blog_charset')).'...';
		 	} else {
				return htmlentities($text, ENT_COMPAT, get_option('blog_charset'));
		 	}
		} else {
			$text = @html_entity_decode($text, ENT_QUOTES, get_option('blog_charset'));
		 	if (strlen($text) > $length) {
				return htmlentities(substr($text,0,$length), ENT_COMPAT, get_option('blog_charset')).'...';
		 	} else {
				return htmlentities($text, ENT_COMPAT, get_option('blog_charset'));
		 	}
		}
	}
}


### Function: Add E-Mail Filters
function email_addfilters( $wp_query ) {
	if ( $wp_query->is_main_query() ) {
		add_filter( 'the_title', 'email_title' );
		add_filter( 'the_content', 'email_form', 10, 5 );
	}
}


### Function: Remove E-Mail Filters
function email_removefilters() {
	remove_action( 'loop_start', 'email_addfilters' );
	remove_filter( 'the_title', 'email_title' );
	remove_filter( 'the_content', 'email_form', 10, 5 );
}


### Function: E-Mail Page Title
function email_pagetitle($page_title) {
	$page_title .= ' &raquo; '.__('E-Mail', 'wp-email');
	return $page_title;
}


### Function: Add noindex & nofollow to meta
function email_meta_nofollow() {
	echo '<meta name="robots" content="noindex, nofollow" />'."\n";
}


### Function: E-Mail Post ID
if(!function_exists('get_the_id')) {
	function get_the_id() {
		global $id;
		return $id;
	}
}


### Function: Get E-Mail Remark
function email_get_remark() {
	global $post;
	return get_post_meta($post->ID, 'wp-email-remark', true);
}


### Function: Get E-Mail Title
function email_get_title() {
	global $post;
	$post_title = get_post_meta($post->ID, 'wp-email-title', true);
	if( empty($post_title) ) {
		$post_title = $post->post_title;
	}
	if(!empty($post->post_password)) {
		$post_title = sprintf(__('Protected: %s', 'wp-email'), $post_title);
	} elseif($post->post_status == 'private') {
		$post_title = sprintf(__('Private: %s', 'wp-email'), $post_title);
	}
	return $post_title;
}


### Function: E-Mail Title
function email_title($page_title) {
	if(in_the_loop()) {
		$post_title = email_get_title();
		$post_author = get_the_author();
		$post_date = get_the_time(get_option('date_format').' ('.get_option('time_format').')', '', '', false);
		$post_category = email_category(__(',', 'wp-email').' ');
		$post_category_alt = strip_tags($post_category);
		$template_title = stripslashes(get_option('email_template_title'));
		$template_title = str_replace("%EMAIL_POST_TITLE%", $post_title, $template_title);
		$template_title = str_replace("%EMAIL_POST_AUTHOR%", $post_author, $template_title);
		$template_title = str_replace("%EMAIL_POST_DATE%", $post_date, $template_title);
		$template_title = str_replace("%EMAIL_POST_CATEGORY%", $post_category, $template_title);
		$template_title = str_replace("%EMAIL_BLOG_NAME%", get_bloginfo('name'), $template_title);
		$template_title = str_replace("%EMAIL_BLOG_URL%", get_bloginfo('url'), $template_title);
		$template_title = str_replace("%EMAIL_PERMALINK%", get_permalink(), $template_title);
		return $template_title;
	} else {
		return $page_title;
	}
}


### Function: E-Mail Category
function email_category($separator = ', ', $parents='') {
	return get_the_category_list($separator, $parents);
}


### Function: E-Mail Content
function email_content() {
	$content = get_email_content();
	$email_snippet = intval(get_option('email_snippet'));
	if($email_snippet > 0) {
		return snippet_words($content , $email_snippet);
	} else {
		return $content;
	}
}


### Function: E-Mail Alternate Content
function email_content_alt() {
	remove_filter('the_content', 'wptexturize');
	$content = get_email_content();
	$content = strip_tags($content);
	$email_snippet = intval(get_option('email_snippet'));
	if($email_snippet > 0) {
		return snippet_words($content , $email_snippet);
	} else {
		return $content;
	}
}


### Function: E-Mail Get The Content
function get_email_content() {
	global $pages, $multipage, $numpages;
	$content = '';
	if(post_password_required()) {
		return __('Password Protected Post', 'wp-email');
	}
	if($multipage) {
		for($page = 0; $page < $numpages; $page++) {
			$content .= $pages[$page];
		}
	} else {
		$content = $pages[0];
	}
	$content = html_entity_decode($content);
	$content = htmlspecialchars_decode($content);
	if(function_exists('print_rewrite')) {
		remove_shortcode('donotprint');
		add_shortcode('donotprint', 'print_donotprint_shortcode2');
	}
	remove_shortcode('donotemail');
	add_shortcode('donotemail', 'email_donotemail_shortcode2');
	remove_shortcode('email_link');
	add_shortcode('email_link', 'email_link_shortcode2');
	$content = apply_filters('the_content', $content);
	return $content;
}


### Function: Get IP Address
if(!function_exists('get_ipaddress')) {
	function get_ipaddress() {
		foreach ( array( 'HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_FORWARDED', 'HTTP_X_CLUSTER_CLIENT_IP', 'HTTP_FORWARDED_FOR', 'HTTP_FORWARDED', 'REMOTE_ADDR' ) as $key ) {
			if ( array_key_exists( $key, $_SERVER ) === true ) {
				foreach ( explode( ',', $_SERVER[$key] ) as $ip ) {
					$ip = trim( $ip );
					if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false ) {
						return esc_attr( $ip );
					}
				}
			}
		}
	}
}

### Function: There Are Still Many PHP 4.x Users
if(!function_exists('htmlspecialchars_decode')) {
	function htmlspecialchars_decode($string, $style = ENT_COMPAT) {
		$translation = array_flip(get_html_translation_table(HTML_SPECIALCHARS,$style));
		if($style === ENT_QUOTES) {
			$translation['&#039;'] = '\'';
		}
		return strtr($string, $translation);
	}
}


### Function: Check Vaild Name (AlphaNumeric With Spaces Allowed Only)
if(!function_exists('is_valid_name')) {
	function is_valid_name($name) {
	   $regex = '/[(\*\(\)\[\]\+\,\/\?\:\;\'\"\`\~\\#\$\%\^\&\<\>)+]/';
	   return !(preg_match($regex, $name));
	}
}


### Function: Check Valid E-Mail Address
if(!function_exists('is_valid_email')) {
	function is_valid_email($email) {
	   $regex = '/^([a-zA-Z0-9_\.\-])+\@(([a-zA-Z0-9\-])+\.)+([a-zA-Z0-9]{2,4})+$/';
	   return (preg_match($regex, $email));
	}
}


### Function: Check Valid Remarks (Ensure No E-Mail Injections)
if(!function_exists('is_valid_remarks')) {
	function is_valid_remarks($content) {
		$injection_strings = array('apparently-to', 'content-disposition', 'content-type', 'content-transfer-encoding', 'errors-to', 'in-reply-to', 'message-id', 'mime-version', 'multipart/mixed', 'multipart/alternative', 'multipart/related', 'reply-to', 'x-mailer', 'x-sender', 'x-uidl');
		foreach ($injection_strings as $spam) {
			$check = strpos(strtolower($content), $spam);
			if ($check !== false) {
				return false;
			}
		}
		return true;
	}
}


### Function: Check For E-Mail Spamming
function not_spamming() {
	global $wpdb;

	$last_emailed = $wpdb->get_var(
		$wpdb->prepare( "SELECT email_timestamp FROM $wpdb->email WHERE email_ip = %s AND email_status = %s ORDER BY email_timestamp DESC LIMIT 1", get_ipaddress(), __( 'Success', 'wp-email' ) )
	);

	$email_allow_interval = intval( get_option( 'email_interval' ) ) * 60;
	if( ( current_time( 'timestamp' ) - $last_emailed) < $email_allow_interval ) {
		return false;
	}

	return true;
}


### Function: E-Mail Flood Interval
function email_flood_interval($echo = true) {
	$email_allow_interval_min = intval(get_option('email_interval'));
	if($echo) {
		echo $email_allow_interval_min;
	} else {
		return $email_allow_interval_min;
	}
}


### Function: Fill In Email Fills If Logged In (By Aaron Campbell)
add_filter('email_form-fieldvalues', 'email_fill_fields');
function email_fill_fields($email_fields) {
    global $current_user;
    if ($current_user->ID > 0) {
        $email_fields['yourname'] = esc_attr( $current_user->display_name );
        $email_fields['youremail'] = esc_attr( $current_user->user_email );
    }
    return $email_fields;
}


### Function: E-Mail Form Header
function email_form_header($echo = true, $temp_id) {
	global $id;
	if(intval($temp_id) > 0) {
		$id = $temp_id;
	}
	$using_permalink = get_option('permalink_structure');
	$permalink = get_permalink();
	// Fix For Static Page
	if(get_option('show_on_front') == 'page' && is_page()) {
		if(intval(get_option('page_on_front')) > 0) {
			$permalink = _get_page_link();
		}
	}
	$output = '';
	if(!empty($using_permalink)) {
		if(is_page()) {
			$output .= '<form action="'.$permalink.'emailpage/" method="post">'."\n";
			$output .= '<p style="display: none;"><input type="hidden" id="page_id" name="page_id" value="'.$id.'" /></p>'."\n";
		} else {
			$output = '<form action="'.$permalink.'email/" method="post">'."\n";
			$output .= '<p style="display: none;"><input type="hidden" id="p" name="p" value="'.$id.'" /></p>'."\n";
		}
	} else {
		if(is_page()) {
			$output .= '<form action="'.$permalink.'&amp;wp_email=1" method="post">'."\n";
			$output .= '<p style="display: none;"><input type="hidden" id="page_id" name="page_id" value="'.$id.'" /></p>'."\n";
		} else {
			$output .= '<form action="'.$permalink.'&amp;wp_email=1" method="post">'."\n";
			$output .= '<p style="display: none;"><input type="hidden" id="p" name="p" value="'.$id.'" /></p>'."\n";
		}
	}
	$output .= '<p style="display: none;"><input type="hidden" id="wp-email_nonce" name="wp-email_nonce" value="'.wp_create_nonce('wp-email-nonce').'" /></p>'."\n";
	if($echo) {
		echo $output;
	} else {
		return $output;
	}
}


### Function: E-Mail Form Header For Popup
function email_popup_form_header($echo = true, $temp_id) {
	global $post;
	$id = intval($post->ID);
	if(intval($temp_id) > 0) {
		$id = $temp_id;
	}
	$using_permalink = get_option('permalink_structure');
	$permalink = get_permalink();
	// Fix For Static Page
	if(get_option('show_on_front') == 'page' && is_page()) {
		if(intval(get_option('page_on_front')) > 0) {
			$permalink = _get_page_link();
		}
	}
	$output = '';
	if(!empty($using_permalink)) {
		if(is_page()) {
			$output .= '<form action="'.$permalink.'emailpopuppage/" method="post">'."\n";
			$output .= '<p style="display: none;"><input type="hidden" id="page_id" name="page_id" value="'.$id.'" /></p>'."\n";
		} else {
			$output = '<form action="'.$permalink.'emailpopup/" method="post">'."\n";
			$output .= '<p style="display: none;"><input type="hidden" id="p" name="p" value="'.$id.'" /></p>'."\n";
		}
	} else {
		if(is_page()) {
			$output .= '<form action="'.$permalink.'&amp;emailpopup=1" method="post">'."\n";
			$output .= '<p style="display: none;"><input type="hidden" id="page_id" name="page_id" value="'.$id.'" /></p>'."\n";
		} else {
			$output .= '<form action="'.$permalink.'&amp;emailpopup=1" method="post">'."\n";
			$output .= '<p style="display: none;"><input type="hidden" id="p" name="p" value="'.$id.'" /></p>'."\n";
		}
	}
	$output .= '<p style="display: none;"><input type="hidden" id="wp-email_nonce" name="wp-email_nonce" value="'.wp_create_nonce('wp-email-nonce').'" /></p>'."\n";
	if($echo) {
		echo $output;
	} else {
		return $output;
	}
}


### Function: Multiple E-Mails
function email_multiple($echo = true) {
	$email_multiple = intval(get_option('email_multiple'));
	if($email_multiple > 1) {
		$output = '<br /><em>'.sprintf(_n('Separate multiple entries with a comma. Maximum %s entry.', 'Separate multiple entries with a comma. Maximum %s entries.', $email_multiple, 'wp-email'), number_format_i18n($email_multiple)).'</em>';
		if($echo) {
			echo $outut;
		} else {
			return $output;
		}
	}
}


### Function: Get EMail Total Sent
if(!function_exists('get_emails')) {
	function get_emails($echo = true) {
		global $wpdb;
		$totalemails = $wpdb->get_var("SELECT COUNT(email_id) FROM $wpdb->email");
		if($echo) {
			echo number_format_i18n($totalemails);
		} else {
			return number_format_i18n($totalemails);
		}
	}
}


### Function: Get EMail Total Sent Success
if( ! function_exists( 'get_emails_success' ) ) {
	function get_emails_success( $echo = true ) {
		global $wpdb;
		$totalemails_success = $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(email_id) FROM $wpdb->email WHERE email_status = %s", __('Success', 'wp-email') )
		);

		if( $echo ) {
			echo number_format_i18n( $totalemails_success );
		} else {
			return number_format_i18n( $totalemails_success );
		}
	}
}


### Function: Get EMail Total Sent Failed
if( ! function_exists( 'get_emails_failed' ) ) {
	function get_emails_failed( $echo = true ) {
		global $wpdb;
		$totalemails_failed = $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(email_id) FROM $wpdb->email WHERE email_status = %s", __('Failed', 'wp-email') )
		);

		if( $echo ) {
			echo number_format_i18n( $totalemails_failed );
		} else {
			return number_format_i18n( $totalemails_failed );
		}
	}
}


### Function: Get EMail Sent For Post
if( ! function_exists( 'get_email_count' ) ) {
	function get_email_count( $post_id = 0, $echo = true ) {
		global $wpdb;

		if( $post_id === 0 ) {
			 global $post;
			$post_id = $post->ID;
		}

		$totalemails = $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(email_id) FROM $wpdb->email WHERE email_postid = %d", intval( $post_id ) )
		);
		if( $echo ) {
			echo number_format_i18n( $totalemails );
		} else {
			return number_format_i18n( $totalemails );
		}
	}
}


### Function: Get Most E-Mailed
if(!function_exists('get_mostemailed')) {
	function get_mostemailed($mode = '', $limit = 10, $chars = 0, $echo = true) {
		global $wpdb, $post;
		$temp_post = $post;
		$where = '';
		$temp = '';
		if(!empty($mode) && $mode != 'both') {
			$where = "post_type = '$mode'";
		} else {
			$where = '1=1';
		}
		$mostemailed= $wpdb->get_results("SELECT $wpdb->posts.*, COUNT($wpdb->email.email_postid) AS email_total FROM $wpdb->email LEFT JOIN $wpdb->posts ON $wpdb->email.email_postid = $wpdb->posts.ID WHERE post_date < '".current_time('mysql')."' AND $where AND post_password = '' AND post_status = 'publish' GROUP BY $wpdb->email.email_postid ORDER  BY email_total DESC LIMIT $limit");
		if($mostemailed) {
			if($chars > 0) {
				foreach ($mostemailed as $post) {
						$post_title = get_the_title();
						$email_total = intval($post->email_total);
						$temp .= "<li><a href=\"".get_permalink()."\">".snippet_text($post_title, $chars)."</a> - ".sprintf(_n('%s email', '%s emails', $email_total, 'wp-email'), number_format_i18n($email_total))."</li>\n";
				}
			} else {
				foreach ($mostemailed as $post) {
						$post_title = get_the_title();
						$email_total = intval($post->email_total);
						$temp .= "<li><a href=\"".get_permalink()."\">$post_title</a> - ".sprintf(_n('%s email', '%s emails', $email_total, 'wp-email'), number_format_i18n($email_total))."</li>\n";
				}
			}
		} else {
			$temp = '<li>'.__('N/A', 'wp-email').'</li>'."\n";
		}
		$post = $temp_post;
		if($echo) {
			echo $temp;
		} else {
			return $temp;
		}
	}
}


### Function: Load WP-EMail
add_action('template_redirect', 'wp_email', 5);
function wp_email() {
	global $wp_query;

	$template_redirect = apply_filters( 'wp_email_template_redirect', true );

	if( $template_redirect ) {
		if (array_key_exists('wp_email', $wp_query->query_vars)) {
			include(WP_PLUGIN_DIR . '/wp-email/email-standalone.php');
			exit();
		} elseif (array_key_exists('wp_email_popup', $wp_query->query_vars)) {
			include(WP_PLUGIN_DIR . '/wp-email/email-popup.php');
			exit();
		}
	}
}


### Function: Process E-Mail Form
add_action('wp_ajax_email', 'process_email_form');
add_action('wp_ajax_nopriv_email', 'process_email_form');
function process_email_form() {
	global $wpdb, $post;
	// If User Click On Mail
	if(isset($_POST['action']) && $_POST['action'] == 'email') {

		// Verify Referer
		if(!check_ajax_referer('wp-email-nonce', 'wp-email_nonce', false))
		{
			_e('Failed To Verify Referrer', 'wp-email');
			exit();
		}

		@session_start();
		email_textdomain();
		header('Content-Type: text/html; charset='.get_option('blog_charset').'');
		// POST Variables
		$yourname		= (!empty($_POST['yourname'])	? sanitize_text_field( $_POST['yourname'] ) : '');
		$youremail		= (!empty($_POST['youremail'])	? sanitize_text_field( $_POST['youremail'] ) : '');
		$yourremarks	= (!empty($_POST['yourremarks'])? sanitize_text_field( $_POST['yourremarks'] ) : '');
		$friendname		= (!empty($_POST['friendname'])	? sanitize_text_field( $_POST['friendname'] ) : '');
		$friendemail	= (!empty($_POST['friendemail'])? sanitize_text_field( $_POST['friendemail'] ) : '');
		$imageverify	= (!empty($_POST['imageverify'])? $_POST['imageverify'] : '');
		$p 				= (!empty($_POST['p'])			? intval( $_POST['p'] ) : 0);
		$page_id 		= (!empty($_POST['page_id'])	? intval( $_POST['page_id'] ) : 0);
		// Get Post Information
		if($p > 0) {
			$post_type = get_post_type($p);
	 		$query_post = 'p='. $p . '&post_type=' . $post_type;
			$id = $p;
		} else {
			$query_post = 'page_id='.$page_id;
			$id = $page_id;
		}
		query_posts($query_post);
		if(have_posts()) {
			while(have_posts()) {
				the_post();
				$post_title = email_get_title();
				$post_author = get_the_author();
				$post_date = get_the_time(get_option('date_format').' ('.get_option('time_format').')', '', '', false);
				$post_category = email_category(__(',', 'wp-email').' ');
				$post_category_alt = strip_tags($post_category);
				$post_excerpt = get_the_excerpt();
				$post_content = email_content();
				$post_content_alt = email_content_alt();
			}
		}
		// Error
		$error = '';
		$error_field = array('yourname' => $yourname, 'youremail' => $youremail, 'yourremarks' => $yourremarks, 'friendname' => $friendname, 'friendemail' => $friendemail, 'id' => $id);
		// Get Options
		$email_fields = get_option('email_fields');
		$email_image_verify = intval(get_option('email_imageverify'));
		$email_smtp = get_option('email_smtp');
		// Multiple Names/Emails
		$friends = array();
		$friendname_count = 0;
		$friendemail_count = 0;
		$multiple_names = preg_split('/,|;/', $friendname);
		$multiple_emails = preg_split('/,|;/', $friendemail);
		$multiple_max = intval(get_option('email_multiple'));
		if($multiple_max == 0) { $multiple_max = 1; }
		// Checking Your Name Field For Errors
		if(intval($email_fields['yourname']) == 1) {
			if(empty($yourname)) {
				$error .= '<br /><strong>&raquo;</strong> '.__('Your Name is empty', 'wp-email');
			}
			if(!is_valid_name($yourname)) {
				$error .= '<br /><strong>&raquo;</strong> '.__('Your Name is invalid', 'wp-email');
			}
		}
		// Checking Your E-Mail Field For Errors
		if(intval($email_fields['youremail']) == 1) {
			if(empty($youremail)) {
				$error .= '<br /><strong>&raquo;</strong> '.__('Your Email is empty', 'wp-email');
			}
			if(!is_valid_email($youremail)) {
				$error .= '<br /><strong>&raquo;</strong> '.__('Your Email is invalid', 'wp-email');
			}
		}
		// Checking Your Remarks Field For Errors
		if(intval($email_fields['yourremarks']) == 1) {
			if(!is_valid_remarks($yourremarks)) {
				$error .= '<br /><strong>&raquo;</strong> '.__('Your Remarks is invalid', 'wp-email');
			}
		}
		// Checking Friend's Name Field For Errors
		if(intval($email_fields['friendname']) == 1) {
			if(empty($friendname)) {
				$error .= '<br /><strong>&raquo;</strong> '.__('Friend Name(s) is empty', 'wp-email');
			} else {
				if($multiple_names) {
					foreach($multiple_names as $multiple_name) {
						$multiple_name = trim($multiple_name);
						if(empty($multiple_name)) {
							$error .= '<br /><strong>&raquo;</strong> '.sprintf(__('Friend Name is empty: %s', 'wp-email'), $multiple_name);
						} elseif(!is_valid_name($multiple_name)) {
							$error .= '<br /><strong>&raquo;</strong> '.sprintf(__('Friend Name is invalid: %s', 'wp-email'), $multiple_name);
						} else {
							$friends[$friendname_count]['name'] = $multiple_name;
							$friendname_count++;
						}
						if($friendname_count > $multiple_max) {
							break;
						}
					}
				}
			}
		}
		// Checking Friend's E-Mail Field For Errors
		if(empty($friendemail)) {
			$error .= '<br /><strong>&raquo;</strong> '.__('Friend Email(s) is empty', 'wp-email');
		} else {
			if($multiple_emails) {
				foreach($multiple_emails as $multiple_email) {
					$multiple_email = trim($multiple_email);
					if(empty($multiple_email)) {
						$error .= '<br /><strong>&raquo;</strong> '.sprintf(__('Friend Email is empty: %s', 'wp-email'), $multiple_email);
					} elseif(!is_valid_email($multiple_email)) {
						$error .= '<br /><strong>&raquo;</strong> '.sprintf(__('Friend Email is invalid: %s', 'wp-email'), $multiple_email);
					} else {
						$friends[$friendemail_count]['email'] = $multiple_email;
						$friendemail_count++;
					}
					if($friendemail_count > $multiple_max) {
						break;
					}
				}
			}
		}
		// Checking If The Fields Exceed The Size Of Maximum Entries Allowed
		if(sizeof($friends) > $multiple_max) {
			$error .= '<br /><strong>&raquo;</strong> '.sprintf(_n('Maximum %s Friend allowed', 'Maximum %s Friend(s) allowed', $multiple_max, 'wp-email'), number_format_i18n($multiple_max));
		}
		if(intval($email_fields['friendname']) == 1) {
			if($friendname_count != $friendemail_count) {
				$error .= '<br /><strong>&raquo;</strong> '.__('Friend Name(s) count does not tally with Friend Email(s) count', 'wp-email');
			}
		}
		// Check Whether We Enable Image Verification
		if($email_image_verify) {
			$imageverify = strtoupper($imageverify);
			if(empty($imageverify)) {
				$error .= '<br /><strong>&raquo;</strong> '.__('Image Verification is empty', 'wp-email');
			} else {
				if($_SESSION['email_verify'] != md5($imageverify)) {
					$error .= '<br /><strong>&raquo;</strong> '.__('Image Verification failed', 'wp-email');
				}
			}
		}
		// If There Is No Error, We Process The E-Mail
		if(empty($error) && not_spamming()) {
			// If Remarks Is Empty, Assign N/A
			if(empty($yourremarks)) { $yourremarks = __('N/A', 'wp-email'); }
			// Template For E-Mail Subject
			$template_email_subject = stripslashes(get_option('email_template_subject'));
			$template_email_subject = str_replace("%EMAIL_YOUR_NAME%", $yourname, $template_email_subject);
			$template_email_subject = str_replace("%EMAIL_YOUR_EMAIL%", $youremail, $template_email_subject);
			$template_email_subject = str_replace("%EMAIL_POST_TITLE%", $post_title, $template_email_subject);
			$template_email_subject = str_replace("%EMAIL_POST_AUTHOR%", $post_author, $template_email_subject);
			$template_email_subject = str_replace("%EMAIL_POST_DATE%", $post_date, $template_email_subject);
			$template_email_subject = str_replace("%EMAIL_POST_CATEGORY%", $post_category_alt, $template_email_subject);
			$template_email_subject = str_replace("%EMAIL_BLOG_NAME%", get_bloginfo('name'), $template_email_subject);
			$template_email_subject = str_replace("%EMAIL_BLOG_URL%", get_bloginfo('url'), $template_email_subject);
			$template_email_subject = str_replace("%EMAIL_PERMALINK%", get_permalink(), $template_email_subject);
			// Template For E-Mail Body
			$template_email_body = stripslashes(get_option('email_template_body'));
			$template_email_body = str_replace("%EMAIL_YOUR_NAME%", $yourname, $template_email_body);
			$template_email_body = str_replace("%EMAIL_YOUR_EMAIL%", $youremail, $template_email_body);
			$template_email_body = str_replace("%EMAIL_YOUR_REMARKS%", $yourremarks, $template_email_body);
			$template_email_body = str_replace("%EMAIL_FRIEND_NAME%", $friendname, $template_email_body);
			$template_email_body = str_replace("%EMAIL_FRIEND_EMAIL%", $friendemail, $template_email_body);
			$template_email_body = str_replace("%EMAIL_POST_TITLE%", $post_title, $template_email_body);
			$template_email_body = str_replace("%EMAIL_POST_AUTHOR%", $post_author, $template_email_body);
			$template_email_body = str_replace("%EMAIL_POST_DATE%", $post_date, $template_email_body);
			$template_email_body = str_replace("%EMAIL_POST_CATEGORY%", $post_category, $template_email_body);
			$template_email_body = str_replace("%EMAIL_POST_EXCERPT%", $post_excerpt, $template_email_body);
			$template_email_body = str_replace("%EMAIL_POST_CONTENT%", $post_content, $template_email_body);
			$template_email_body = str_replace("%EMAIL_BLOG_NAME%", get_bloginfo('name'), $template_email_body);
			$template_email_body = str_replace("%EMAIL_BLOG_URL%", get_bloginfo('url'), $template_email_body);
			$template_email_body = str_replace("%EMAIL_PERMALINK%", get_permalink(), $template_email_body);
			if( is_rtl() ) {
				$template_email_body = "<div style=\"direction: rtl;\">$template_email_body</div>";
			}
			// Template For E-Mail Alternate Body
			$template_email_bodyalt = stripslashes(get_option('email_template_bodyalt'));
			$template_email_bodyalt = str_replace("%EMAIL_YOUR_NAME%", $yourname, $template_email_bodyalt);
			$template_email_bodyalt = str_replace("%EMAIL_YOUR_EMAIL%", $youremail, $template_email_bodyalt);
			$template_email_bodyalt = str_replace("%EMAIL_YOUR_REMARKS%", $yourremarks, $template_email_bodyalt);
			$template_email_bodyalt = str_replace("%EMAIL_FRIEND_NAME%", $friendname, $template_email_bodyalt);
			$template_email_bodyalt = str_replace("%EMAIL_FRIEND_EMAIL%", $friendemail, $template_email_bodyalt);
			$template_email_bodyalt = str_replace("%EMAIL_POST_TITLE%", $post_title, $template_email_bodyalt);
			$template_email_bodyalt = str_replace("%EMAIL_POST_AUTHOR%", $post_author, $template_email_bodyalt);
			$template_email_bodyalt = str_replace("%EMAIL_POST_DATE%", $post_date, $template_email_bodyalt);
			$template_email_bodyalt = str_replace("%EMAIL_POST_CATEGORY%", $post_category_alt, $template_email_bodyalt);
			$template_email_bodyalt = str_replace("%EMAIL_POST_EXCERPT%", $post_excerpt, $template_email_bodyalt);
			$template_email_bodyalt = str_replace("%EMAIL_POST_CONTENT%", $post_content_alt, $template_email_bodyalt);
			$template_email_bodyalt = str_replace("%EMAIL_BLOG_NAME%", get_bloginfo('name'), $template_email_bodyalt);
			$template_email_bodyalt = str_replace("%EMAIL_BLOG_URL%", get_bloginfo('url'), $template_email_bodyalt);
			$template_email_bodyalt = str_replace("%EMAIL_PERMALINK%", get_permalink(), $template_email_bodyalt);
			// PHP Mailer Variables
			if (!class_exists("phpmailer")) {
				require_once(ABSPATH.WPINC.'/class-phpmailer.php');
			}
			$mail = new PHPMailer();
			$mail->From     = $youremail;
			$mail->FromName = $yourname;
			foreach($friends as $friend) {
				$mail->AddAddress($friend['email'], $friend['name']);
			}
			$mail->CharSet = strtolower(get_bloginfo('charset'));
			$mail->Username = $email_smtp['username'];
			$mail->Password = $email_smtp['password'];
			$mail->Host     = $email_smtp['server'];
			$mail->Mailer   = get_option('email_mailer');
			if($mail->Mailer == 'smtp') {
				$mail->SMTPAuth = true;
			}
			$mail->ContentType =  get_option('email_contenttype');
			$mail->Subject = $template_email_subject;
			if(get_option('email_contenttype') == 'text/plain') {
				$mail->Body    = $template_email_bodyalt;
			} else {
				$mail->Body    = $template_email_body;
				$mail->AltBody = $template_email_bodyalt;
			}
			// Send The Mail if($mail->Send()) {
			if($mail->Send()) {
				$email_status = __('Success', 'wp-email');
				// Template For Sent Successfully
				$template_email_sentsuccess = stripslashes(get_option('email_template_sentsuccess'));
				$template_email_sentsuccess = str_replace("%EMAIL_FRIEND_NAME%", $friendname, $template_email_sentsuccess);
				$template_email_sentsuccess = str_replace("%EMAIL_FRIEND_EMAIL%", $friendemail, $template_email_sentsuccess);
				$template_email_sentsuccess = str_replace("%EMAIL_POST_TITLE%", $post_title, $template_email_sentsuccess);
				$template_email_sentsuccess = str_replace("%EMAIL_BLOG_NAME%", get_bloginfo('name'), $template_email_sentsuccess);
				$template_email_sentsuccess = str_replace("%EMAIL_BLOG_URL%", get_bloginfo('url'), $template_email_sentsuccess);
				$template_email_sentsuccess = str_replace("%EMAIL_PERMALINK%", get_permalink(), $template_email_sentsuccess);
			// If There Is Error Sending
			} else {
				if($yourremarks == __('N/A', 'wp-email')) { $yourremarks = ''; }
				$email_status = __('Failed', 'wp-email');
				// Template For Sent Failed
				$template_email_sentfailed = stripslashes(get_option('email_template_sentfailed'));
				$template_email_sentfailed = str_replace("%EMAIL_FRIEND_NAME%", $friendname, $template_email_sentfailed);
				$template_email_sentfailed = str_replace("%EMAIL_FRIEND_EMAIL%", $friendemail, $template_email_sentfailed);
				$template_email_sentfailed = str_replace("%EMAIL_ERROR_MSG%", $mail->ErrorInfo, $template_email_sentfailed);
				$template_email_sentfailed = str_replace("%EMAIL_POST_TITLE%", $post_title, $template_email_sentfailed);
				$template_email_sentfailed = str_replace("%EMAIL_BLOG_NAME%", get_bloginfo('name'), $template_email_sentfailed);
				$template_email_sentfailed = str_replace("%EMAIL_BLOG_URL%", get_bloginfo('url'), $template_email_sentfailed);
				$template_email_sentfailed = str_replace("%EMAIL_PERMALINK%", get_permalink(), $template_email_sentfailed);
			}
			// Logging
			$email_yourname = addslashes($yourname);
			$email_youremail = addslashes($youremail);
			$email_yourremarks = addslashes($yourremarks);
			$email_postid = intval(get_the_id());
			$email_posttitle = addslashes($post_title);
			$email_timestamp = current_time('timestamp');
			$email_ip = get_ipaddress();
			$email_host = esc_attr(@gethostbyaddr($email_ip));
			foreach($friends as $friend) {
				$email_friendname = addslashes($friend['name']);
				$email_friendemail = addslashes($friend['email']);
				$wpdb->insert(
					$wpdb->email,
					array(
						'email_yourname'    => $email_yourname,
						'email_youremail'   => $email_youremail,
						'email_yourremarks' => $email_yourremarks,
						'email_friendname'  => $email_friendname,
						'email_friendemail' => $email_friendemail,
						'email_postid'      => $email_postid,
						'email_posttitle'   => $email_posttitle,
						'email_timestamp'   => $email_timestamp,
						'email_ip'          => $email_ip,
						'email_host'        => $email_host,
						'email_status'      => $email_status
					),
					array(
						'%s',
						'%s',
						'%s',
						'%s',
						'%s',
						'%d',
						'%s',
						'%s',
						'%s',
						'%s',
						'%s'
					)
				);
			}

			if($email_status == __('Success', 'wp-email')) {
				$output = $template_email_sentsuccess;
			} else {
				$output = $template_email_sentfailed;
			}
			echo $output;
			exit();
		// If There Are Errors
		} else {
			$error = substr($error, 21);
			$template_email_error = stripslashes(get_option('email_template_error'));
			$template_email_error = str_replace("%EMAIL_ERROR_MSG%", $error, $template_email_error);
			$template_email_error = str_replace("%EMAIL_BLOG_NAME%", get_bloginfo('name'), $template_email_error);
			$template_email_error = str_replace("%EMAIL_BLOG_URL%", get_bloginfo('url'), $template_email_error);
			$template_email_error = str_replace("%EMAIL_PERMALINK%", get_permalink(), $template_email_error);
			$output = $template_email_error;
			$output .= email_form('', false, false, false, $error_field);
			echo $output;
			exit();
		} // End if(empty($error))
	} // End if(!empty($_POST['wp-email']))
}


### Function: E-Mail Form
function email_form($content, $echo = true, $subtitle = true, $div = true, $error_field = '') {
	global $wpdb, $multipage;
	// Variables
	$multipage = false;
	$post_title = email_get_title();
	$post_author = get_the_author();
	$post_date = get_the_time(get_option('date_format').' ('.get_option('time_format').')', '', '', false);
	$post_category = email_category(__(',', 'wp-email').' ');
	$post_category_alt = strip_tags($post_category);
	$email_fields = get_option('email_fields');
	$email_image_verify = intval(get_option('email_imageverify'));
	$email_options = get_option('email_options');
	$email_type = intval($email_options['email_type']);
	$error_field = apply_filters('email_form-fieldvalues', array());
	$output = '';
	// Template - Subtitle
	if($subtitle) {
		$template_subtitle = stripslashes(get_option('email_template_subtitle'));
		$template_subtitle = str_replace("%EMAIL_POST_TITLE%", $post_title, $template_subtitle);
		$template_subtitle = str_replace("%EMAIL_POST_AUTHOR%", $post_author, $template_subtitle);
		$template_subtitle = str_replace("%EMAIL_POST_DATE%", $post_date, $template_subtitle);
		$template_subtitle = str_replace("%EMAIL_POST_CATEGORY%", $post_category, $template_subtitle);
		$template_subtitle = str_replace("%EMAIL_BLOG_NAME%", get_bloginfo('name'), $template_subtitle);
		$template_subtitle = str_replace("%EMAIL_BLOG_URL%", get_bloginfo('url'), $template_subtitle);
		$template_subtitle = str_replace("%EMAIL_PERMALINK%", get_permalink(), $template_subtitle);
		$output .= $template_subtitle;
	}
	// Display WP-EMail Form
	if($div) {
		$output .= '<div id="wp-email-content" class="wp-email">'."\n";
	}
	if (not_spamming()) {
		if(!post_password_required()) {
			if($email_type == 2){
				$output .= email_popup_form_header(false, (!empty($error_field['id']) ? $error_field['id'] : 0));
			} else {
				$output .= email_form_header(false, (!empty($error_field['id']) ? $error_field['id'] : 0));
			}
			$output .= '<p id="wp-email-required">'.__('* Required Field', 'wp-email').'</p>'."\n";
			if(intval($email_fields['yourname']) == 1) {
				$output .= '<p>'."\n";
				$output .= '<label for="yourname">'.__('Your Name: *', 'wp-email').'</label><br />'."\n";
				$output .= '<input type="text" size="50" id="yourname" name="yourname" class="TextField" value="' . ( ! empty( $error_field['yourname'] ) ? esc_attr( $error_field['yourname'] ) : '' ) . '" />'."\n";
				$output .= '</p>'."\n";
			}
			if(intval($email_fields['youremail']) == 1) {
				$output .= '<p>'."\n";
				$output .= '<label for="youremail">'.__('Your E-Mail: *', 'wp-email').'</label><br />'."\n";
				$output .= '<input type="text" size="50" id="youremail" name="youremail" class="TextField" value="' . ( ! empty( $error_field['youremail'] ) ? esc_attr( $error_field['youremail'] ) : '' ) . '" dir="ltr" />'."\n";
				$output .= '</p>'."\n";
			}
			if(intval($email_fields['yourremarks']) == 1) {
				$output .= '<p>'."\n";
				$output .= '	<label for="yourremarks">'.__('Your Remark:', 'wp-email').'</label><br />'."\n";
				$output .= '	<textarea cols="49" rows="8" id="yourremarks" name="yourremarks" class="Forms">';
				$val = email_get_remark();
				if ( !empty($error_field['yourremarks']) ) {
					$val = $error_field['yourremarks'];
				}
				if ( !empty($val) ) {
					$output .= esc_html($val);
				}
				$output .= '</textarea>'."\n";
				$output .= '</p>'."\n";
			}
			if(intval($email_fields['friendname']) == 1) {
				$output .= '<p>'."\n";
				$output .= '<label for="friendname">'.__('Friend\'s Name: *', 'wp-email').'</label><br />'."\n";
				$output .= '<input type="text" size="50" id="friendname" name="friendname" class="TextField" value="' . ( ! empty( $error_field['friendname'] ) ? esc_attr( $error_field['friendname'] ) : '' ) . '" />' . email_multiple( false ) . "\n";
				$output .= '</p>'."\n";
			}
			$output .= '<p>'."\n";
			$output .= '<label for="friendemail">'.__('Friend\'s E-Mail: *', 'wp-email').'</label><br />'."\n";
			$output .= '<input type="text" size="50" id="friendemail" name="friendemail" class="TextField" value="' . ( ! empty( $error_field['friendemail'] ) ? esc_attr( $error_field['friendemail'] ) : '' ) . '" dir="ltr" />' . email_multiple( false ) . "\n";
			$output .= '</p>'."\n";
			if($email_image_verify) {
				$output .= '<p>'."\n";
				$output .= '<label for="imageverify">'.__('Image Verification: *', 'wp-email').'</label><br />'."\n";
				$output .= '<img src="'.plugins_url('wp-email/email-image-verify.php').'" width="55" height="15" alt="'.__('E-Mail Image Verification', 'wp-email').'" /><input type="text" size="5" maxlength="5" id="imageverify" name="imageverify" class="TextField" />'."\n";
				$output .= '</p>'."\n";
			}
			$output .= '<p id="wp-email-button"><input type="button" value="'.__('     Mail It!     ', 'wp-email').'" id="wp-email-submit" class="Button" onclick="email_form();" onkeypress="email_form();" /></p>'."\n";
			$output .= '</form>'."\n";
		} else {
			$output .= get_the_password_form();
		} // End if(!post_password_required())
	} else {
		$output .= '<p>'.sprintf(_n('Please wait for <strong>%s Minute</strong> before sending the next article.', 'Please wait for <strong>%s Minutes</strong> before sending the next article.', email_flood_interval(false), 'wp-email'), email_flood_interval(false)).'</p>'."\n";
	} // End if (not_spamming())
	$output .= '<div id="wp-email-loading" class="wp-email-loading"><img src="'.plugins_url('wp-email/images/loading.gif').'" width="16" height="16" alt="'.__('Loading', 'wp-email').' ..." title="'.__('Loading', 'wp-email').' ..." class="wp-email-image" />&nbsp;'.__('Loading', 'wp-email').' ...</div>'."\n";
	if($div) {
		$output .= '</div>'."\n";
	}
	email_removefilters();
	if($echo) {
		echo $output;
	} else {
		return $output;
	}
}


### Function: Modify Default WordPress Listing To Make It Sorted By Most E-Mailed
function email_fields($content) {
	global $wpdb;
	$content .= ", COUNT($wpdb->email.email_postid) AS email_total";
	return $content;
}
function email_join($content) {
	global $wpdb;
	$content .= " LEFT JOIN $wpdb->email ON $wpdb->email.email_postid = $wpdb->posts.ID";
	return $content;
}
function email_groupby($content) {
	global $wpdb;
	$content .= " $wpdb->email.email_postid";
	return $content;
}
function email_orderby($content) {
	$orderby = trim(addslashes($_GET['orderby']));
	if(empty($orderby) || ($orderby != 'asc' && $orderby != 'desc')) {
		$orderby = 'desc';
	}
	$content = " email_total $orderby";
	return $content;
}


### Process The Sorting
/*
if($_GET['sortby'] == 'email') {
	add_filter('posts_fields', 'email_fields');
	add_filter('posts_join', 'email_join');
	add_filter('posts_groupby', 'email_groupby');
	add_filter('posts_orderby', 'email_orderby');
}
*/


### Function: Plug Into WP-Stats
add_action( 'plugins_loaded','email_wp_stats' );
function email_wp_stats() {
	add_filter( 'wp_stats_page_admin_plugins', 'email_page_admin_general_stats' );
	add_filter( 'wp_stats_page_admin_most', 'email_page_admin_most_stats' );
	add_filter( 'wp_stats_page_plugins', 'email_page_general_stats' );
	add_filter( 'wp_stats_page_most', 'email_page_most_stats' );
}


### Function: Add WP-EMail General Stats To WP-Stats Page Options
function email_page_admin_general_stats($content) {
	$stats_display = get_option('stats_display');
	if($stats_display['email'] == 1) {
		$content .= '<input type="checkbox" name="stats_display[]" id="wpstats_email" value="email" checked="checked" />&nbsp;&nbsp;<label for="wpstats_email">'.__('WP-EMail', 'wp-email').'</label><br />'."\n";
	} else {
		$content .= '<input type="checkbox" name="stats_display[]" id="wpstats_email" value="email" />&nbsp;&nbsp;<label for="wpstats_email">'.__('WP-EMail', 'wp-email').'</label><br />'."\n";
	}
	return $content;
}


### Function: Add WP-EMail Top Most/Highest Stats To WP-Stats Page Options
function email_page_admin_most_stats($content) {
	$stats_display = get_option('stats_display');
	$stats_mostlimit = intval(get_option('stats_mostlimit'));
	if($stats_display['emailed_most_post'] == 1) {
		$content .= '<input type="checkbox" name="stats_display[]" id="wpstats_emailed_most_post" value="emailed_most_post" checked="checked" />&nbsp;&nbsp;<label for="wpstats_emailed_most_post">'.sprintf(_n('%s Most Emailed Post', '%s Most Emailed Posts', $stats_mostlimit, 'wp-email'), number_format_i18n($stats_mostlimit)).'</label><br />'."\n";
	} else {
		$content .= '<input type="checkbox" name="stats_display[]" id="wpstats_emailed_most_post" value="emailed_most_post" />&nbsp;&nbsp;<label for="wpstats_emailed_most_post">'.sprintf(_n('%s Most Emailed Post', '%s Most Emailed Posts', $stats_mostlimit, 'wp-email'), number_format_i18n($stats_mostlimit)).'</label><br />'."\n";
	}
	if($stats_display['emailed_most_page'] == 1) {
		$content .= '<input type="checkbox" name="stats_display[]" id="wpstats_emailed_most_page" value="emailed_most_page" checked="checked" />&nbsp;&nbsp;<label for="wpstats_emailed_most_page">'.sprintf(_n('%s Most Emailed Page', '%s Most Emailed Pages', $stats_mostlimit, 'wp-email'), number_format_i18n($stats_mostlimit)).'</label><br />'."\n";
	} else {
		$content .= '<input type="checkbox" name="stats_display[]" id="wpstats_emailed_most_page" value="emailed_most_page" />&nbsp;&nbsp;<label for="wpstats_emailed_most_page">'.sprintf(_n('%s Most Emailed Page', '%s Most Emailed Pages', $stats_mostlimit, 'wp-email'), number_format_i18n($stats_mostlimit)).'</label><br />'."\n";
	}
	return $content;
}


### Function: Add WP-EMail General Stats To WP-Stats Page
function email_page_general_stats($content) {
	global $wpdb;
	$stats_display = get_option('stats_display');
	if($stats_display['email'] == 1) {
		$email_stats = $wpdb->get_results("SELECT email_status, COUNT(email_id) AS email_total FROM $wpdb->email GROUP BY email_status");
		if($email_stats) {
			$email_stats_array = array();
			$email_stats_array['total'] = 0;
			foreach($email_stats as $email_stat) {
				$email_stats_array[$email_stat->email_status] = intval($email_stat->email_total);
				$email_stats_array['total'] += intval($email_stat->email_total);
			}
		}
		$content .= '<p><strong>'.__('WP-EMail', 'wp-email').'</strong></p>'."\n";
		$content .= '<ul>'."\n";
		$content .= '<li>'.sprintf(_n('<strong>%s</strong> email was sent.', '<strong>%s</strong> emails were sent.', $email_stats_array['total'], 'wp-email'), number_format_i18n($email_stats_array['total'])).'</li>'."\n";
		$content .= '<li>'.sprintf(_n('<strong>%s</strong> email was sent successfully.', '<strong>%s</strong> emails were sent successfully.', $email_stats_array[__('Success', 'wp-email')], 'wp-email'), number_format_i18n($email_stats_array[__('Success', 'wp-email')])).'</li>'."\n";
		$content .= '<li>'.sprintf(_n('<strong>%s</strong> email failed to send.', '<strong>%s</strong> emails failed to send.', $email_stats_array[__('Failed', 'wp-email')], 'wp-email'), number_format_i18n($email_stats_array[__('Failed', 'wp-email')])).'</li>'."\n";
		$content .= '</ul>'."\n";
	}
	return $content;
}


### Function: Add WP-EMail Top Most/Highest Stats To WP-Stats Page
function email_page_most_stats($content) {
	$stats_display = get_option('stats_display');
	$stats_mostlimit = intval(get_option('stats_mostlimit'));
	if($stats_display['emailed_most_post'] == 1) {
		$content .= '<p><strong>'.sprintf(_n('%s Most Emailed Post', '%s Most Emailed Posts', $stats_mostlimit, 'wp-email'), number_format_i18n($stats_mostlimit)).'</strong></p>'."\n";
		$content .= '<ul>'."\n";
		$content .= get_mostemailed('post', $stats_mostlimit, 0, false);
		$content .= '</ul>'."\n";
	}
	if($stats_display['emailed_most_page'] == 1) {
		$content .= '<p><strong>'.sprintf(_n('%s Most Emailed Page', '%s Most Emailed Pages', $stats_mostlimit, 'wp-email'), number_format_i18n($stats_mostlimit)).'</strong></p>'."\n";
		$content .= '<ul>'."\n";
		$content .= get_mostemailed('page', $stats_mostlimit, 0, false);
		$content .= '</ul>'."\n";
	}
	return $content;
}


### Class: WP-EMail Widget
 class WP_Widget_Email extends WP_Widget {
	// Constructor
	function __construct() {
		$widget_ops = array('description' => __('WP-EMail emails statistics', 'wp-email'));
		parent::__construct('email', __('Email', 'wp-email'), $widget_ops);
	}

	// Display Widget
	function widget($args, $instance) {
		extract($args);
		$title = apply_filters('widget_title', esc_attr($instance['title']));
		$type = esc_attr($instance['type']);
		$mode = esc_attr($instance['mode']);
		$limit = intval($instance['limit']);
		$chars = intval($instance['chars']);
		//$cat_ids = explode(',', esc_attr($instance['cat_ids']));
		echo $before_widget.$before_title.$title.$after_title;
		echo '<ul>'."\n";
		switch($type) {
			case 'most_emailed':
				get_mostemailed($mode, $limit, $chars);
				break;
		}
		echo '</ul>'."\n";
		echo $after_widget;
	}

	// When Widget Control Form Is Posted
	function update($new_instance, $old_instance) {
		if (!isset($new_instance['submit'])) {
			return false;
		}
		$instance = $old_instance;
		$instance['title'] = strip_tags($new_instance['title']);
		$instance['type'] = strip_tags($new_instance['type']);
		$instance['mode'] = strip_tags($new_instance['mode']);
		$instance['limit'] = intval($new_instance['limit']);
		$instance['chars'] = intval($new_instance['chars']);
		//$instance['cat_ids'] = strip_tags($new_instance['cat_ids']);
		return $instance;
	}

	// DIsplay Widget Control Form
	function form($instance) {
		global $wpdb;
		$instance = wp_parse_args((array) $instance, array('title' => __('EMail', 'wp-email'), 'type' => 'most_emailed', 'mode' => 'both', 'limit' => 10, 'chars' => 200));
		$title = esc_attr($instance['title']);
		$type = esc_attr($instance['type']);
		$mode = esc_attr($instance['mode']);
		$limit = intval($instance['limit']);
		$chars = intval($instance['chars']);
		//$cat_ids = esc_attr($instance['cat_ids']);
?>
		<p>
			<label for="<?php echo $this->get_field_id('title'); ?>"><?php _e('Title:', 'wp-email'); ?> <input class="widefat" id="<?php echo $this->get_field_id('title'); ?>" name="<?php echo $this->get_field_name('title'); ?>" type="text" value="<?php echo $title; ?>" /></label>
		</p>
		<p>
			<label for="<?php echo $this->get_field_id('type'); ?>"><?php _e('Statistics Type:', 'wp-email'); ?>
				<select name="<?php echo $this->get_field_name('type'); ?>" id="<?php echo $this->get_field_id('type'); ?>" class="widefat">
					<option value="most_emailed"<?php selected('most_emailed', $type); ?>><?php _e('Most Emailed', 'wp-email'); ?></option>
				</select>
			</label>
		</p>
		<p>
			<label for="<?php echo $this->get_field_id('mode'); ?>"><?php _e('Include Views From:', 'wp-email'); ?>
				<select name="<?php echo $this->get_field_name('mode'); ?>" id="<?php echo $this->get_field_id('mode'); ?>" class="widefat">
					<option value="both"<?php selected('both', $mode); ?>><?php _e('Posts &amp; Pages', 'wp-email'); ?></option>
					<option value="post"<?php selected('post', $mode); ?>><?php _e('Posts Only', 'wp-email'); ?></option>
					<option value="page"<?php selected('page', $mode); ?>><?php _e('Pages Only', 'wp-email'); ?></option>
				</select>
			</label>
		</p>
		<p>
			<label for="<?php echo $this->get_field_id('limit'); ?>"><?php _e('No. Of Records To Show:', 'wp-email'); ?> <input class="widefat" id="<?php echo $this->get_field_id('limit'); ?>" name="<?php echo $this->get_field_name('limit'); ?>" type="text" value="<?php echo $limit; ?>" /></label>
		</p>
		<p>
			<label for="<?php echo $this->get_field_id('chars'); ?>"><?php _e('Maximum Post Title Length (Characters):', 'wp-email'); ?> <input class="widefat" id="<?php echo $this->get_field_id('chars'); ?>" name="<?php echo $this->get_field_name('chars'); ?>" type="text" value="<?php echo $chars; ?>" /></label><br />
			<small><?php _e('<strong>0</strong> to disable.', 'wp-email'); ?></small>
		</p>
		<input type="hidden" id="<?php echo $this->get_field_id('submit'); ?>" name="<?php echo $this->get_field_name('submit'); ?>" value="1" />
<?php
	}
}


### Function: Init WP-EMail Widget
add_action('widgets_init', 'widget_email_init');
function widget_email_init() {
	email_textdomain();
	register_widget('WP_Widget_Email');
}


### Function: Activate Plugin
register_activation_hook( __FILE__, 'email_activation' );
function email_activation( $network_wide )
{
	if ( is_multisite() && $network_wide )
	{
		$ms_sites = wp_get_sites();

		if( 0 < sizeof( $ms_sites ) )
		{
			foreach ( $ms_sites as $ms_site )
			{
				switch_to_blog( $ms_site['blog_id'] );
				email_activate();
			}
		}

		restore_current_blog();
	}
	else
	{
		email_activate();
	}
}

function email_activate() {
	global $wpdb;

	if(@is_file(ABSPATH.'/wp-admin/upgrade-functions.php')) {
		include_once(ABSPATH.'/wp-admin/upgrade-functions.php');
	} elseif(@is_file(ABSPATH.'/wp-admin/includes/upgrade.php')) {
		include_once(ABSPATH.'/wp-admin/includes/upgrade.php');
	} else {
		die('We have problem finding your \'/wp-admin/upgrade-functions.php\' and \'/wp-admin/includes/upgrade.php\'');
	}

	$charset_collate = '';
	if($wpdb->supports_collation()) {
		if(!empty($wpdb->charset)) {
			$charset_collate = "DEFAULT CHARACTER SET $wpdb->charset";
		}
		if(!empty($wpdb->collate)) {
			$charset_collate .= " COLLATE $wpdb->collate";
		}
	}

	// Create E-Mail Table
	$create_table = "CREATE TABLE $wpdb->email (".
		"email_id int(10) NOT NULL auto_increment,".
		"email_yourname varchar(200) NOT NULL default '',".
		"email_youremail varchar(200) NOT NULL default '',".
		"email_yourremarks text NOT NULL,".
		"email_friendname varchar(200) NOT NULL default '',".
		"email_friendemail varchar(200) NOT NULL default '',".
		"email_postid int(10) NOT NULL default '0',".
		"email_posttitle text NOT NULL,".
		"email_timestamp varchar(20) NOT NULL default '',".
		"email_ip varchar(100) NOT NULL default '',".
		"email_host varchar(200) NOT NULL default '',".
		"email_status varchar(20) NOT NULL default '',".
		"PRIMARY KEY (email_id)) $charset_collate;";
	maybe_create_table($wpdb->email, $create_table);

	// Add In Options
	add_option('email_smtp', array('username' => '', 'password' => '', 'server' => ''));
	add_option('email_contenttype', 'text/html');
	add_option('email_mailer', 'php');
	add_option('email_template_subject', __('Recommended Article By %EMAIL_YOUR_NAME%: %EMAIL_POST_TITLE%', 'wp-email'));
	add_option('email_template_body', __('<p>Hi <strong>%EMAIL_FRIEND_NAME%</strong>,<br />Your friend, <strong>%EMAIL_YOUR_NAME%</strong>, has recommended this article entitled \'<strong>%EMAIL_POST_TITLE%</strong>\' to you.</p><p><strong>Here is his/her remark:</strong><br />%EMAIL_YOUR_REMARKS%</p><p><strong>%EMAIL_POST_TITLE%</strong><br />Posted By %EMAIL_POST_AUTHOR% On %EMAIL_POST_DATE% In %EMAIL_POST_CATEGORY%</p>%EMAIL_POST_CONTENT%<p>Article taken from %EMAIL_BLOG_NAME% - <a href="%EMAIL_BLOG_URL%">%EMAIL_BLOG_URL%</a><br />URL to article: <a href="%EMAIL_PERMALINK%">%EMAIL_PERMALINK%</a></p>', 'wp-email'));
	add_option('email_template_bodyalt', __('Hi %EMAIL_FRIEND_NAME%,'."\n".
		'Your friend, %EMAIL_YOUR_NAME%, has recommended this article entitled \'%EMAIL_POST_TITLE%\' to you.'."\n\n".
		'Here is his/her remarks:'."\n".
		'%EMAIL_YOUR_REMARKS%'."\n\n".
		'%EMAIL_POST_TITLE%'."\n".
		'Posted By %EMAIL_POST_AUTHOR% On %EMAIL_POST_DATE% In %EMAIL_POST_CATEGORY%'."\n".
		'%EMAIL_POST_CONTENT%'."\n".
		'Article taken from %EMAIL_BLOG_NAME% - %EMAIL_BLOG_URL%'."\n".
		'URL to article: %EMAIL_PERMALINK%', 'wp-email'));
	add_option('email_template_sentsuccess', '<p>'.__('Article: <strong>%EMAIL_POST_TITLE%</strong> has been sent to <strong>%EMAIL_FRIEND_NAME% (%EMAIL_FRIEND_EMAIL%)</strong></p><p>&laquo; <a href="%EMAIL_PERMALINK%">'.__('Back to %EMAIL_POST_TITLE%', 'wp-email').'</a></p>', 'wp-email'));
	add_option('email_template_sentfailed', '<p>'.__('An error has occurred when trying to send this email: ', 'wp-email').'<br /><strong>&raquo;</strong> %EMAIL_ERROR_MSG%</p>');
	add_option('email_template_error', '<p>'.__('An error has occurred: ', 'wp-email').'<br /><strong>&raquo;</strong> %EMAIL_ERROR_MSG%</p>');
	add_option('email_interval', 10);
	add_option('email_snippet', 0);
	add_option('email_multiple', 5);

	// Version 2.05 Options
	add_option('email_imageverify', 1);

	// Version 2.10 Options
	$email_options = array('post_text' => __('Email This Post', 'wp-email'), 'page_text' => __('Email This Page', 'wp-email'), 'email_icon' => 'email_famfamfam.png', 'email_type' => 1, 'email_style' => 1, 'email_html' => '<a href="%EMAIL_URL%" rel="nofollow" title="%EMAIL_TEXT%">%EMAIL_TEXT%</a>');
	add_option('email_options', $email_options);
	$email_fields = array('yourname' => 1, 'youremail' => 1, 'yourremarks' => 1, 'friendname' => 1, 'friendemail' => 1);
	add_option('email_fields', $email_fields);

	// Version 2.11 Options
	add_option('email_template_title', __('E-Mail \'%EMAIL_POST_TITLE%\' To A Friend', 'wp-email'));
	add_option('email_template_subtitle', '<p style="text-align: center;">'.__('Email a copy of <strong>\'%EMAIL_POST_TITLE%\'</strong> to a friend', 'wp-email').'</p>');

	// Set 'manage_email' Capabilities To Administrator
	$role = get_role('administrator');
	if(!$role->has_cap('manage_email')) {
		$role->add_cap('manage_email');
	}

	// Flush Rewrite Rules
	flush_rewrite_rules();
}
