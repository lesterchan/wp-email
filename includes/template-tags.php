<?php
/**
 * WP-EMail template tags.
 *
 * The plugin's public API. These names are what themes call, so they keep
 * their pre-3.0.0 signatures and global scope even though the work behind them
 * now lives in classes.
 *
 * @package WP-EMail
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Display the "Email This Post" link.
 *
 * @param string $email_post_text Override for the post link text.
 * @param string $email_page_text Override for the page link text.
 * @param bool   $echo_output     Whether to echo rather than return.
 *
 * @return string
 */
function email_link( $email_post_text = '', $email_page_text = '', $echo_output = true ) {
	$output = Email_Link::render( $email_post_text, $email_page_text );

	if ( $echo_output ) {
		echo $output . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped at each sink in Email_Link.
		return '';
	}

	return $output;
}

/**
 * Display the e-mail form.
 *
 * @param string $content     Unused; present because this also runs as a filter.
 * @param bool   $echo_output Whether to echo rather than return.
 * @param bool   $subtitle    Whether to render the subtitle template.
 * @param bool   $div         Whether to wrap in the container div.
 * @param array  $error_field Values the visitor already typed.
 *
 * @return string
 */
function email_form( $content = '', $echo_output = true, $subtitle = true, $div = true, $error_field = array() ) {
	return Email_Form::render( $content, $echo_output, $subtitle, $div, (array) $error_field );
}

/**
 * The opening tag of the e-mail form.
 *
 * @param int  $temp_id     Post being e-mailed.
 * @param bool $echo_output Whether to echo rather than return.
 *
 * @return string
 */
function email_form_header( $temp_id = 0, $echo_output = true ) {
	$output = Email_Form::header( $temp_id, false );

	if ( $echo_output ) {
		echo $output; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped at each sink in Email_Form.
		return '';
	}

	return $output;
}

/**
 * The opening tag of the popup e-mail form.
 *
 * @param bool $echo_output Whether to echo rather than return.
 * @param int  $temp_id     Post being e-mailed.
 *
 * @return string
 */
function email_popup_form_header( $echo_output = true, $temp_id = 0 ) {
	$output = Email_Form::header( $temp_id, true );

	if ( $echo_output ) {
		echo $output; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped at each sink in Email_Form.
		return '';
	}

	return $output;
}

/**
 * The flood interval, in minutes.
 *
 * @param bool $echo_output Whether to echo rather than return.
 *
 * @return int
 */
function email_flood_interval( $echo_output = true ) {
	$interval = Email_Form::flood_interval();

	if ( $echo_output ) {
		echo esc_html( $interval );
		return $interval;
	}

	return $interval;
}

/**
 * The "separate multiple entries with a comma" hint.
 *
 * @param bool $echo_output Whether to echo rather than return.
 *
 * @return string
 */
function email_multiple( $echo_output = true ) {
	$output = Email_Form::multiple_hint();

	if ( $echo_output ) {
		echo $output; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in Email_Form::multiple_hint().
		return '';
	}

	return $output;
}

/**
 * The visitor's IP address, as the plugin attributes it.
 *
 * @return string
 */
function email_get_ipaddress() {
	return Email_Form::ip_address();
}

/**
 * Whether the visitor is clear of the flood interval.
 *
 * @return bool
 */
function not_spamming() {
	return Email_Form::not_spamming();
}

/**
 * The title of the post being e-mailed.
 *
 * @return string
 */
function email_get_title() {
	return Email_Template::title();
}

/**
 * The author's pre-filled remark for the post being e-mailed.
 *
 * @return string
 */
function email_get_remark() {
	return Email_Template::remark();
}

/**
 * The post's categories as a linked list.
 *
 * @param string $separator Separator between categories.
 * @param string $parents   How to display parents.
 *
 * @return string
 */
function email_category( $separator = ', ', $parents = '' ) {
	return Email_Template::category( $separator, $parents );
}

/**
 * The post content for an HTML e-mail.
 *
 * @return string
 */
function email_content() {
	return Email_Template::content();
}

/**
 * The post content for a plain text e-mail.
 *
 * @return string
 */
function email_content_alt() {
	return Email_Template::content_alt();
}

/**
 * The raw post content, with the plugin's own shortcodes neutered.
 *
 * @return string
 */
function get_email_content() {
	return Email_Template::raw_content();
}

/**
 * The page title for the e-mail page.
 *
 * @param string $page_title Existing title.
 *
 * @return string
 */
function email_pagetitle( $page_title ) {
	return Email::filter_page_title( $page_title );
}

/**
 * Replace the post title with the page-title template.
 *
 * @param string $page_title Existing title.
 *
 * @return string
 */
function email_title( $page_title ) {
	return Email::filter_title( $page_title );
}

/**
 * Total number of e-mails ever sent.
 *
 * @param bool $echo_output Whether to echo rather than return.
 *
 * @return string
 */
function get_emails( $echo_output = true ) {
	$total = number_format_i18n( Email_Logs::count_all() );

	if ( $echo_output ) {
		echo esc_html( $total );
		return $total;
	}

	return $total;
}

/**
 * Number of e-mails that were sent successfully.
 *
 * @param bool $echo_output Whether to echo rather than return.
 *
 * @return string
 */
function get_emails_success( $echo_output = true ) {
	$total = number_format_i18n( Email_Logs::count_by_status( Email_Logs::STATUS_SUCCESS ) );

	if ( $echo_output ) {
		echo esc_html( $total );
		return $total;
	}

	return $total;
}

/**
 * Number of e-mails that failed to send.
 *
 * @param bool $echo_output Whether to echo rather than return.
 *
 * @return string
 */
function get_emails_failed( $echo_output = true ) {
	$total = number_format_i18n( Email_Logs::count_by_status( Email_Logs::STATUS_FAILED ) );

	if ( $echo_output ) {
		echo esc_html( $total );
		return $total;
	}

	return $total;
}

/**
 * Number of e-mails sent for one post.
 *
 * @param int  $post_id     Post ID, or 0 for the current post.
 * @param bool $echo_output Whether to echo rather than return.
 *
 * @return string
 */
function get_email_count( $post_id = 0, $echo_output = true ) {
	$post_id = (int) $post_id;

	if ( 0 === $post_id ) {
		$post_id = (int) get_the_ID();
	}

	$total = number_format_i18n( Email_Logs::count_for_post( $post_id ) );

	if ( $echo_output ) {
		echo esc_html( $total );
		return $total;
	}

	return $total;
}

/**
 * A list of the most e-mailed posts.
 *
 * @param string $mode        'post', 'page' or 'both'.
 * @param int    $limit       Maximum entries.
 * @param int    $chars       Truncate titles to this many characters; 0 for no limit.
 * @param bool   $echo_output Whether to echo rather than return.
 *
 * @return string
 */
function get_mostemailed( $mode = '', $limit = 10, $chars = 0, $echo_output = true ) {
	$rows   = Email_Logs::most_emailed( $mode, $limit );
	$output = '';

	if ( empty( $rows ) ) {
		$output = '<li>' . esc_html__( 'N/A', 'wp-email' ) . '</li>' . "\n";
	} else {
		foreach ( $rows as $row ) {
			$total = (int) $row->email_total;
			$title = get_the_title( $row->ID );

			if ( $chars > 0 ) {
				$title = Email_Template::characters( $title, $chars );
			} else {
				$title = esc_html( $title );
			}

			$output .= '<li><a href="' . esc_url( get_permalink( $row->ID ) ) . '">' . $title . '</a> - ' . sprintf(
				/* translators: %s: Number of e-mails. */
				esc_html( _n( '%s email', '%s emails', $total, 'wp-email' ) ),
				esc_html( number_format_i18n( $total ) )
			) . '</li>' . "\n";
		}
	}

	if ( $echo_output ) {
		echo $output; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped at each sink above.
		return '';
	}

	return $output;
}
