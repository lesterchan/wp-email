# WP-EMail
Contributors: GamerZ  
Donate link: https://lesterchan.net/site/donation/  
Tags: email, e-mail, wp-email, mail, recommend  
Requires at least: 6.0  
Tested up to: 7.0  
Stable tag: 3.0.0  
Requires PHP: 7.4  
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Allows people to recommend/send your WordPress blog's post/page to a friend.

## Description

### General Usage
1. Under E-Mail Settings, modify the setting Method Used To Send E-Mail accordingly. If the method is wrong, no email will get sent.
1. You Need To Re-Generate The Permalink (WP-Admin -> Settings -> Permalinks -> Save Changes)
1. Open `wp-content/themes/<YOUR THEME NAME>/index.php` (You may place it in single.php, post.php, page.php, etc also)
 * Find: `<?php while (have_posts()) : the_post(); ?>`
 * Simply add this code inside the loop where you want the email link to display: <code>if(function_exists('email_link')) { email_link(); }</code>

If you DO NOT want the email link to appear in every post/page, DO NOT use the code above. Just use the shortcode by typing [email_link] into the selected post/page content and it will embed the email link into that post/page only.

### Development
* [https://github.com/lesterchan/wp-email](https://github.com/lesterchan/wp-email "https://github.com/lesterchan/wp-email")

### Credits
* Plugin icon by [Yannick](https://yanlu.de) from [Flaticon](https://www.flaticon.com)

### Donations
I spent most of my free time creating, updating, maintaining and supporting these plugins, if you really love my plugins and could spare me a couple of bucks as my school allowance, I will really appreciate it. If not feel free to use it without any obligations.

## Screenshots

1. Admin - E-Mail Logs
2. Admin - Options Page
3. Admin - Templates Page
4. Sample E-Mail Post link
5. Sample E-Mail Post screen

## Frequently Asked Questions

### How does the plugin send mail?

Through `wp_mail()`, so it uses whatever WordPress itself is configured to use. The plugin dropped its own SMTP settings in 2.68.0; if you need SMTP, install an SMTP plugin and WP-EMail will follow it.

### How do I add this to my theme?

1. Open `wp-content/themes/<YOUR THEME NAME>/index.php` (You may place it in single.php, post.php, page.php, etc also)
1. Find: `<?php while (have_posts()) : the_post(); ?>`
1. Simply add this code <strong>inside the loop</strong> where you want the email link to display: <code>if(function_exists('email_link')) { email_link(); }</code>

### How can I customize my E-Mail link?

Many customizations can be made from the options page (WP Admin->E-Mail->E-Mail Options).

Additionally, you can override the "E-Mail Text Link for Post" and "E-Mail Text Link for Page" options with the first two parameters of the email_link function like this:
```
if(function_exists('email_link'))
	email_link( 'E-Mail Text Link for Post', 'E-Mail Text Link for Page');
```

You can also force `email_link()` to return the link rather than echo it by setting the third parameter to false:
```
if(function_exists('email_link')) {
	$email_link = email_link( 'E-Mail Text Link for Post', 'E-Mail Text Link for Page', false );
} else {
	$email_link = '';
}

echo $email_link;
```

### How can I show my E-Mail stats?

There are two options for this:
1. You can use the included widget by going to Wp-Admin -> Appearance -> Widgets" and using the widget named "Email"
1. You can use a number of included theme functions for displaying various stats.  Please continue to read these FAQs for more information.

### How can I display the Most E-Mailed Posts?

Simply insert this code into your theme:
```
if (function_exists('get_mostemailed'))
	get_mostemailed('both', 10);
```

The first parameter is what you want to get, 'post', 'page', or 'both' and defaults to 'both'.
The second parameter is the maximum number of posts/pages you want to get.

### How can I display the Total E-Mails Sent?

Simply insert this code into your theme:
```
if (function_exists('get_emails'))
	get_emails();
```

### How can I display the Total E-Mails Sent Successfully?

Simply insert this code into your theme:
```
if (function_exists('get_emails_success'))
	get_emails_success();
```

### How can I display the Total E-Mails Sent Unsuccessfully?

Simply insert this code into your theme:
```
if (function_exists('get_emails_failed'))
	get_emails_failed();
```

### Visitors are told to wait before sending, or every log row shows the same IP

Your site is behind a reverse proxy or CDN — Cloudflare, a load balancer, nginx in
front of Apache — so the address PHP sees is the proxy's, not the visitor's. Because
the interval between e-mails is keyed on that address, every visitor shares one
interval and each of them is told to wait for somebody else's send.

The real address is in a forwarded header, but WP-EMail ignores those by default: any
client can send one with any value, so trusting them blindly lets a visitor forge an
address and bypass the interval entirely. Opt in only if a proxy you control actually
sets the header.

If you know which header it is, name it under `WP-Admin -> E-Mail -> E-Mail Options`
in the `Header That Contains The IP` field — for example `HTTP_CF_CONNECTING_IP` for
Cloudflare. That is the narrowest option, and the one to prefer.

To trust the usual set instead (`X-Forwarded-For`, `CF-Connecting-IP` and friends), add
this to `wp-config.php` above the `/* That's all, stop editing! */` line:

```php
define( 'WP_EMAIL_TRUST_PROXY', true );
```

If you need to decide per request — say, only trust the header when the request
arrives from your load balancer — use the filter instead:

```php
add_filter( 'wp_email_trust_proxy', function () {
	return isset( $_SERVER['REMOTE_ADDR'] ) && '10.0.0.1' === $_SERVER['REMOTE_ADDR'];
} );
```

With none of the three set, the plugin uses `REMOTE_ADDR` — correct on a plain host,
and the proxy's address behind one.

### How do I hide remarks when viewing E-Mail logs in WP-Admin?

Add this to your `wp-config.php`:
```
define( 'EMAIL_SHOW_REMARKS', false );
```
Since 3.0.0 the plugin only defines this if you have not, so your setting survives upgrades.

### How can I keep some post text from being sent in the E-Mail?

If you do not want to email a portion of your post's content, do the following:

`[donotemail]Text within this tag will not be displayed when emailed[/donotemail]`

The text within [donotemail][/donotemail] will not be displayed when you are emailing a post or page.
However, it will still be displayed as normal on a normal post or page view.
Do note that if you are using WP-Print, any text within [donotemail][/donotemail] will not be printed as well.

### I made changes to the CSS, how can I keep them from being overridden on the next upgrade?

WP-Email will load `email-css.css` from your theme's directory if it exists.  If it doesn't exist then it will load the default `email-css.css` that comes with WP-Email.  Just move your custom CSS to the appropriate file in your theme directory and it will be "upgrade-proof"

### How can I make the E-Mail title different from the post title?

If you add a custom field with the key "wp-email-title" it will be used as the E-Mail title.

### How can I set a default or suggested remark for the user?

If you add a custom field with the key "wp-email-remark" it will be placed in the remarks field in the E-Mail form.

## Changelog
### 3.0.0
* NEW: Restructured into `includes/class-email-*.php`. The template tags (`email_link()`, `get_emails()`, `get_mostemailed()` and the rest) keep their names and signatures, and the `wp_email_ipaddress`, `wp_email_template_redirect` and `email_form-fieldvalues` filters are unchanged.
* NEW: The fifteen `wp_options` rows the plugin used are consolidated into the single `email_options` row it already owned. Your settings are migrated automatically on upgrade.
* NEW: The options page is built on the WordPress Settings API, and the e-mail log is a standard admin list table with sortable columns and a per-page screen option.
* NEW: Image verification no longer uses PHP sessions. Sessions are unavailable behind most page caches and on a lot of hosting, which made verification fail outright; the challenge now lives in a short-lived transient issued per form. If your server has no GD library the option is shown as unavailable instead of silently rejecting every message.
* NEW: The JavaScript was rewritten without jQuery, and every inline `onclick` is gone.
* NEW: The e-mail table gained indexes on the post ID, status and IP columns.
* **IMPORTANT:** Proxy headers such as `X-Forwarded-For` are no longer trusted by default, because trusting them let anyone bypass the interval between e-mails. Sites behind Cloudflare or another reverse proxy must opt in via the `Header That Contains The IP` setting, the `WP_EMAIL_TRUST_PROXY` constant, or the `wp_email_trust_proxy` filter. See the FAQ.
* **IMPORTANT:** Requires WordPress 6.0 and PHP 7.4.
* FIXED: Uninstalling on a multisite network called `wp_get_sites()`, removed in WordPress 5.1, and fatalled instead of cleaning up. It also stopped at the hundredth site, leaving options and tables behind on every site after that.
* FIXED: The e-mail form on a Page posted to an `emailpage/` URL that was never registered.
* FIXED: The first validation error lost part of its text and rendered a stray `</strong>`.
* FIXED: A failed submission came back with every field blank instead of keeping what you typed.
* FIXED: Logged names and remarks were escaped twice, so apostrophes accumulated backslashes.
* FIXED: `[email_link]` and `[donotemail]` stopped working for the rest of the page once an e-mail body had been rendered.
* FIXED: jQuery was loaded on every page of the site whether or not anything used it.
* FIXED: The e-mail status was stored translated, so changing your site language orphaned old log rows from the totals. Existing rows are corrected on upgrade.
* NEW: Added a PHPUnit test suite and GitHub Actions CI.

### 2.69.4
* NEW: Bump to WordPress 7.0
* FIXED: Undefined array key warnings on missing stats_display options
* FIXED: Escape e-mail log values on output in the admin log viewer

### 2.69.3
* FIXED: Remove email_textdomain()

### 2.69.2
* FIXED: PHP Warning
* FIXED: Remove load_plugin_textdomain since it is no longer needed since WP 4.6

### 2.69.1
* FIXED: XSS for text links

### 2.69.0 
* NEW: Supports specifying which header to read the user's IP from. Props Marc Montpas.
* FIXED: Added more nonce check to email-manager.php

### 2.68.2
* FIXED: PHP8 deprecated notices

### 2.68.1
* FIXED: Fatal Error on activation as it suppose to be delete_option() and not remove_option

### 2.68.0
* NEW: Uses `wp_mail()` instead of PHPMailer
* NEW: Removed SMTP & Mailer Settings

### 2.67.6
FIXED: Notices

### 2.67.5
* FIXED: Email form not appearing if user is not using nice permalink

### 2.67.4
* FIXED: Use `wp_email` instead of `email` as query var.
* FIXED: Use `wp_email_popup` instead of `emailpopup` as query var.

### 2.67.3
* FIXED: esc_attr() on form fields to prevent XSS. Props Edward Woodfall.

### 2.67.2
* FIXED: Fixed SQL Injection in inserting email logs. Props [Jxs.nl](https://jxs.nl).

### 2.67.1
* FIXED: Fixed vulnerability in `get_email_ipaddress()`

### 2.67
* FIXED: Notices in Widget Constructor for WordPress 4.3
* FIXED: Remove clean_pre() because it is deprecated.

### 2.66
* NEW: Add viewport meta tag. Props @Luanramos
* FIXED: Proper loading of templates. Props @ocean90
* FIXED: Apply custom filters only to the main query. Props @ocean90

### 2.65
* FIXED: Integration with WP-Stats
* FIXED: Added in wp_nonce_field to email-options page

### 2.64
* NEW: Added in `wp_email_template_redirect` filter to allow other plugins disable template redirect when query var contains 'email'

### 2.63
* NEW: Finally there is custom post type support. Props [nimmolo](https://andrewnimmo.org/ "nimmolo").
* NEW: Allow Multisite Network Activate
* NEW: Uses WordPress uninstall.php file to uninstall the plugin
* NEW: Added noindex, nofollow to meta tag to email-standalone.php
* FIXED: Use get_the_author() instead of the_author('', false)
