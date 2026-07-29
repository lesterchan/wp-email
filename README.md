# WP-EMail
Contributors: GamerZ  
Donate link: https://lesterchan.net/site/donation/  
Tags: email, e-mail, wp-email, mail, recommend  
Requires at least: 6.8  
Tested up to: 7.0  
Stable tag: 3.0.0  
Requires PHP: 8.2  
License: GPLv2 or later  
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Allows people to recommend/send your WordPress blog's post/page to a friend.

## Description
WP-EMail puts an "Email This Post" link on your posts and pages. A visitor clicking it gets a small form, fills in their name, their friend's name and address and an optional remark, and the article goes out through `wp_mail()` — so it uses whatever WordPress itself is set up to send with.

Every message is logged, so you can see what was sent, to whom, from which address and whether it arrived. The page title, the subject line, the HTML body, the plain text body and every message the visitor sees are templates you can edit, each with its own list of variables and a button to put the original back.

### Features
* An "Email This Post" link as an icon, as text, as both, or as HTML you write yourself
* A standalone e-mail page or a popup window
* HTML or plain text messages, with the article whole or cut to a word count
* Image verification, and a minimum interval between sends, to keep the form off spammers' lists
* A sortable, paginated log of every message, with totals and a one-click purge
* A widget and template tags for the most emailed posts and pages
* A block on the WP-Stats page, if you have WP-Stats installed

### Donations
I spent most of my free time creating, updating, maintaining and supporting these plugins, if you really love my plugins and could spare me a couple of bucks, I will really appreciate it. If not feel free to use it without any obligations.

## Usage
The plugin works as soon as it is activated. All you have to decide is where the e-mail link appears.

**On selected posts and pages**, type the shortcode into the content:

```
[email_link]
```

This works in both block and classic themes, and puts the link exactly where you want it rather than on everything.

**On every post and page**, call the template tag from your theme instead. In a classic theme, open `wp-content/themes/<YOUR THEME NAME>/index.php` — or `single.php`, `page.php`, and so on — find:

```php
<?php while ( have_posts() ) : the_post(); ?>
```

and add this inside the loop, where you want the link to show:

```php
<?php if ( function_exists( 'email_link' ) ) { email_link(); } ?>
```

Use one or the other, not both, or the link appears twice.

If the link is there but clicking it gives a 404, re-save your permalinks under `WP-Admin -> Settings -> Permalinks`. The plugin registers its `/email/` endpoint on activation, but a site with unusual rewrite rules can need the nudge.

Everything else is configured under `WP-Admin -> E-Mail -> Settings`, and the log of what has been sent is under `WP-Admin -> E-Mail -> Manage E-Mail`.

### Filters
Use `wp_email_capability` to hand a screen to a capability other than the default. The logs screen is gated on `manage_email` and the settings screen on `manage_options`:

```php
add_filter( 'wp_email_capability', function ( $capability, $context ) {
	return 'logs' === $context ? 'edit_posts' : $capability;
}, 10, 2 );
```

Use `wp_email_form_field_values` to pre-fill the form. The plugin uses it itself to fill in a logged-in visitor's name and address:

```php
add_filter( 'wp_email_form_field_values', function ( $values ) {
	$values['yourremarks'] = 'Thought you would like this.';

	return $values;
} );
```

The other three are `wp_email_ipaddress` (the address a send is attributed to), `wp_email_template_redirect` (whether the plugin takes over the request for its own endpoints) and `wp_email_trust_proxy` (whether the usual forwarding headers may be trusted).

## Frequently Asked Questions

### How does the plugin send mail?
Through `wp_mail()`, so it uses whatever WordPress itself is configured to use. The plugin dropped its own SMTP settings in 2.68.0; if you need SMTP, install an SMTP plugin and WP-EMail will follow it.

### How can I customize my E-Mail link?
Most of it comes from `WP-Admin -> E-Mail -> Settings`: the link text for posts and pages, whether it opens the standalone page or a popup, and whether it renders as an icon, as text, as both, or as HTML you write yourself.

You can also override the two text settings with the first two parameters of `email_link()`:

```php
if ( function_exists( 'email_link' ) ) {
	email_link( 'E-Mail Text Link for Post', 'E-Mail Text Link for Page' );
}
```

Setting the third parameter to `false` returns the link instead of echoing it:

```php
$email_link = function_exists( 'email_link' )
	? email_link( 'E-Mail Text Link for Post', 'E-Mail Text Link for Page', false )
	: '';

echo $email_link;
```

### How can I show my E-Mail stats?
Two ways. Add the widget named "Email" under `WP-Admin -> Appearance -> Widgets`, or call one of the template tags from your theme.

### How can I display the Most E-Mailed Posts?
Insert this into your theme:

```php
if ( function_exists( 'get_mostemailed' ) ) {
	get_mostemailed( 'both', 10 );
}
```

The first parameter is what to list — `post`, `page` or `both` — and the second is how many.

### How can I display the totals?
`get_emails()` for everything sent, `get_emails_success()` for what went out successfully, `get_emails_failed()` for what did not, and `get_email_count()` for one post:

```php
if ( function_exists( 'get_emails' ) ) {
	get_emails();
}
```

### Visitors are told to wait before sending, or every log row shows the same IP
Your site is behind a reverse proxy or CDN — Cloudflare, a load balancer, nginx in front of Apache — so the address PHP sees is the proxy's, not the visitor's. Because the interval between e-mails is keyed on that address, every visitor shares one interval and each of them is told to wait for somebody else's send.

The real address is in a forwarded header, but WP-EMail ignores those by default: any client can send one with any value, so trusting them blindly lets a visitor forge an address and bypass the interval entirely. Opt in only if a proxy you control actually sets the header.

If you know which header it is, name it under `WP-Admin -> E-Mail -> Settings` in the `Header That Contains The IP` field — for example `HTTP_CF_CONNECTING_IP` for Cloudflare. That is the narrowest option, and the one to prefer.

To trust the usual set instead (`X-Forwarded-For`, `CF-Connecting-IP` and friends), add this to `wp-config.php` above the `/* That's all, stop editing! */` line:

```php
define( 'WP_EMAIL_TRUST_PROXY', true );
```

If you need to decide per request — say, only trust the header when the request arrives from your load balancer — use the filter instead:

```php
add_filter( 'wp_email_trust_proxy', function () {
	return isset( $_SERVER['REMOTE_ADDR'] ) && '10.0.0.1' === $_SERVER['REMOTE_ADDR'];
} );
```

With none of the three set, the plugin uses `REMOTE_ADDR` — correct on a plain host, and the proxy's address behind one.

### How do I hide remarks when viewing E-Mail logs in WP-Admin?
Add this to your `wp-config.php`:

```php
define( 'WP_EMAIL_SHOW_REMARKS', false );
```

The constant was called `EMAIL_SHOW_REMARKS` before 3.0.0. The plugin only defines it if you have not, so your setting survives upgrades.

### How can I keep some post text from being sent in the E-Mail?
Wrap it:

```
[donotemail]Text within this tag will not be displayed when emailed[/donotemail]
```

The text inside will not appear in the message, but it will still show on the post or page as normal. Note that if you also run WP-Print, text inside `[donotemail]` will not be printed either.

### I made changes to the CSS, how can I keep them from being overridden on the next upgrade?
Put them in your theme's own stylesheet. Everything the plugin styles sits under the `.wp-email` class, so `.wp-email label { ... }` in your theme is enough to override a rule, and your changes are never touched by an upgrade.

Before 3.0.0 the plugin looked for a copy of `email-css.css` in your theme directory and loaded that instead of its own. That is gone: it meant a theme had to reproduce the whole file to change one rule, and every upgrade that added a rule silently skipped those themes.

### How can I make the E-Mail title different from the post title?
Add a custom field with the key `wp-email-title`, and it will be used as the E-Mail title.

### How can I set a default or suggested remark for the user?
Add a custom field with the key `wp-email-remark`, and it will be placed in the remarks field on the E-Mail form.

### What does the plugin store in my database?
Two option rows — `wp_email_options` for the settings and `wp_email_version` for the version it last ran — and one table holding the log. Deleting the plugin from the Plugins screen removes all three, along with every pre-3.0.0 row.

## Screenshots

1. Admin - E-Mail Logs
2. Admin - E-Mail Settings
3. Admin - E-Mail Settings, template section
4. Sample E-Mail Post link
5. Sample E-Mail Post screen

## Changelog
### 3.0.0
* BREAKING: WordPress 6.8 and PHP 8.2 are now the minimum. A site on anything older will not be offered this update at all.
* BREAKING: The `email_form-fieldvalues` filter is renamed `wp_email_form_field_values`. There is no compatibility shim.
* BREAKING: The eighteen `wp_options` rows the plugin used are consolidated into `wp_email_options` and `wp_email_version`. Your settings are migrated automatically on upgrade and the old rows are deleted.
* BREAKING: WP-EMail's three checkboxes on the WP-Stats options screen become one setting on its own settings screen, and the shared `stats_display` and `stats_mostlimit` rows are no longer read. Update all seven WP-Stats plugins together.
* BREAKING: `EMAIL_SHOW_REMARKS` is renamed `WP_EMAIL_SHOW_REMARKS`.
* BREAKING: The settings screen moves from `?page=wp-email-options` to `?page=wp-email-settings` and is gated on `manage_options`; the logs screen keeps `manage_email`.
* BREAKING: `email_wpstats_instance()`, `email_page_admin_general_stats()`, `email_page_admin_most_stats()`, `email_page_general_stats()` and `email_page_most_stats()` are removed, along with the global `email_popup()` in JavaScript.
* BREAKING: The `%EMAIL_ICON_URL%` template variable is replaced by `%EMAIL_ICON%`, and the E-Mail Icon setting is removed.
* BREAKING: The plugin no longer loads `email-css.css` from your theme directory.
* NEW: Restructured into `includes/class-wp-email-*.php`. The template tags — `email_link()`, `get_emails()`, `get_mostemailed()` and the rest — keep their names and signatures.
* NEW: The settings screen is built on the WordPress Settings API, and the e-mail log is a standard admin list table with sortable columns and a per-page screen option.
* NEW: Image verification no longer uses PHP sessions. Sessions are unavailable behind most page caches and on a lot of hosting, which made verification fail outright; the challenge now lives in a short-lived transient issued per form. If your server has no GD library the option is shown as unavailable instead of silently rejecting every message.
* NEW: A `wp_email_capability` filter for handing either screen to a capability of your own.
* NEW: The e-mail table gained indexes on the post ID, status and IP columns.
* NEW: Added a PHPUnit test suite, a Vitest suite for the JavaScript, and GitHub Actions CI.
* CHANGED: Proxy headers such as `X-Forwarded-For` are no longer trusted by default, because trusting them let anyone bypass the interval between e-mails. Sites behind Cloudflare or another reverse proxy must opt in via the `Header That Contains The IP` setting, the `WP_EMAIL_TRUST_PROXY` constant, or the `wp_email_trust_proxy` filter. See the FAQ.
* CHANGED: The JavaScript was rewritten without jQuery, moved to `js/`, and every inline `onclick` is gone.
* CHANGED: The two envelope images and the loading GIF are replaced by one inline SVG and a CSS spinner, so the plugin ships no images at all.
* CHANGED: One stylesheet, `css/wp-email.css`, written with CSS logical properties, serves both text directions. `email-css-rtl.css` is deleted.
* FIXED: Uninstalling on a multisite network called `wp_get_sites()`, removed in WordPress 5.1, and fatalled instead of cleaning up. It also stopped at the hundredth site, leaving options and tables behind on every site after that.
* FIXED: The e-mail form on a Page posted to an `emailpage/` URL that was never registered.
* FIXED: The first validation error lost part of its text and rendered a stray `</strong>`.
* FIXED: A failed submission came back with every field blank instead of keeping what you typed.
* FIXED: Logged names and remarks were escaped twice, so apostrophes accumulated backslashes.
* FIXED: `[email_link]` and `[donotemail]` stopped working for the rest of the page once an e-mail body had been rendered.
* FIXED: The library the form used to load on every page of the site, whether or not anything on that page used it.
* FIXED: The e-mail status was stored translated, so changing your site language orphaned old log rows from the totals. Existing rows are corrected on upgrade.

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
* FIXED: Notices

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
* NEW: Added noindex, nofollow to meta tag to the standalone e-mail page
* FIXED: Use get_the_author() instead of the_author('', false)

## Upgrade Notice
### 3.0.0
The first release since 2.69.4, and there is a fair amount in it. Nothing you have configured is lost, but seven things are worth knowing before you update.

**Your site must be on WordPress 6.8 or later and PHP 8.2 or later.** Anything older will simply not be offered the update. If your host still runs PHP 7.4, ask to be moved to a supported version before updating — 7.4 stopped receiving security fixes in 2022.

**Your settings move to new rows in the database, and the old ones are deleted.** The eighteen rows the plugin used — `email_options`, `email_fields`, the eight `email_template_*` rows and the rest — become one `wp_email_options` row, and `email_db_version` becomes `wp_email_version`. Nothing is lost and there is nothing to do: the move runs by itself the first time an administrator loads wp-admin after the update. If you have a backup script, a migration tool or a `wp-config.php` snippet that names any of the old rows, point it at the new ones.

**The settings screen moved and changed name.** It is `WP-Admin -> E-Mail -> Settings` now, at `?page=wp-email-settings`, and it is gated on `manage_options` rather than the plugin's own `manage_email`. The e-mail log stays where it was and keeps `manage_email`, because it is a data screen rather than a settings screen. Administrators hold both capabilities, so in practice nothing changes unless you had granted `manage_email` to a role that is not an administrator — that role now sees the log but not the settings. The new `wp_email_capability` filter is there if you want a different arrangement.

**One filter was renamed and six functions removed.** `email_form-fieldvalues` — the only hook WP-EMail fired that carried neither the plugin's prefix nor an underscore — is now `wp_email_form_field_values`, and it takes the same argument and returns the same array. There is no compatibility shim, so a theme or snippet still hooking the old name simply stops pre-filling the form. The plugin's other filters (`wp_email_ipaddress`, `wp_email_template_redirect`, `wp_email_trust_proxy`) are unchanged.

The removed functions are `email_wpstats_instance()`, `email_page_admin_general_stats()`, `email_page_admin_most_stats()`, `email_page_general_stats()` and `email_page_most_stats()`, which existed only to answer WP-Stats' `wp_stats_page_*` filters and which WP-Stats 3.0.0 retired outright, plus the global `email_popup()` in JavaScript. `email_popup()` sat on `window` so that the inline `onclick` the plugin printed could reach it; both are gone, and a link opts into the popup with a data attribute instead. If you wrote your own custom link HTML calling `email_popup(...)` from an `onclick`, replace that call with the `%EMAIL_POPUP%` variable — the example under the Custom setting shows where it goes.

**WP-Stats integration is now one block, switched on from WP-EMail's own settings.** WP-EMail used to have three checkboxes on the WP-Stats options screen — the e-mail totals, the most-emailed posts and the most-emailed pages — and all three lived in a `stats_display` row that seven plugins shared and none owned. There is now a single **Show WP-EMail On The WP-Stats Page** setting under `WP-Admin -> E-Mail -> Settings`, and it draws the totals and both lists together. Your old setting is carried across: if any of the three boxes was ticked, the block stays on.

**Update all seven WP-Stats plugins together.** WP-EMail, WP-DownloadManager, WP-Polls, WP-PostRatings, WP-PostViews, WP-Stats and WP-UserOnline all read that shared row, and each one deletes it once it has copied the value into its own settings. Whichever you update first is the only one that finds anything there, so update them in one go. If you update them apart, a plugin that finds the row missing switches its block **on** rather than off — nothing disappears, but you may need to untick a block you had hidden. Each plugin's toggle lives on its own settings screen now.

**The plugin ships no images, one stylesheet, and no theme stylesheet override.** The two envelope icons and the loading GIF are gone: the link draws one inline SVG envelope that takes its colour from your theme, and the "Loading ..." indicator is drawn in CSS and stands still for a visitor whose system asks for reduced motion. Because there is no longer a file to choose between, the **E-Mail Icon** setting has been removed and the `%EMAIL_ICON_URL%` template variable is replaced by `%EMAIL_ICON%`, which inserts the glyph itself. `email-css.css` and `email-css-rtl.css` become a single `css/wp-email.css` written with logical properties, so it lays out correctly in right-to-left languages without a second file to keep in step.

If you had customised `email-css.css` in your theme directory, that copy is no longer loaded. Move those rules into your theme's own stylesheet: everything the plugin styles sits under the `.wp-email` class, so they will keep working, and they can now add to the plugin's rules rather than replacing the whole file. If your custom link HTML uses `%EMAIL_ICON_URL%`, edit it under `WP-Admin -> E-Mail -> Settings` — an unrecognised variable is left in the markup as written rather than blanked, so you will see it.

**Proxy headers are no longer trusted unless you say so.** Until 3.0.0 the plugin read `HTTP_X_FORWARDED_FOR` and friends whenever they were present, and those headers are set by the visitor — so anyone could walk past the interval between e-mails by sending a different value on each request. If your site really is behind Cloudflare, a load balancer or any other proxy, open `WP-Admin -> E-Mail -> Settings` after updating and name the exact header your proxy sets in the `Header That Contains The IP` field. If it is not behind a proxy, do nothing: the interval will simply start working properly.
