# WP-EMail
Contributors: GamerZ  
Donate link: https://lesterchan.net/site/donation/  
Tags: email, e-mail, wp-email, mail, recommend  
Requires at least: 6.8  
Tested up to: 7.1  
Stable tag: 3.0.0  
Requires PHP: 8.2  
License: GPLv2 or later  
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Allows people to recommend/send your WordPress blog's post/page to a friend.

## Description
WP-EMail puts an "Email This Post" link on your posts and pages. A visitor clicking it gets a small form, fills in their name, their friend's name and address and an optional remark, and the article goes out through `wp_mail()` — so it uses whatever WordPress itself is set up to send with.

Every message is logged, so you can see what was sent, to whom, from which address and whether it arrived. The page title, the subject line, the HTML body, the plain text body and every message the visitor sees are templates you can edit, each with its own list of variables and a button to put the original back.

### Features
* An "Email This Post" link built from an HTML template you edit, with the envelope glyph, the wording and the post type as variables
* A standalone e-mail page or a popup window
* HTML or plain text messages, with the article whole or cut to a word count
* Image verification, and a minimum interval between sends, to keep the form off spammers' lists
* A sortable, paginated log of every message, with totals and a one-click purge
* A widget and template tags for the most emailed posts and pages
* A block on the WP-Stats page, if you have WP-Stats installed

### Donations
I spent most of my free time creating, updating, maintaining and supporting these plugins, if you really love my plugins and could spare me a couple of bucks, I will really appreciate it. If not feel free to use it without any obligations.

## Installation

1. Install and activate the plugin.
1. Re-save your permalinks at `WP-Admin -> Settings -> Permalinks -> Save Changes`, so the e-mail endpoint is registered.
1. Decide where the link appears: type `[email_link]` into a post, or call `email_link()` in your theme. Usage below covers both.
1. Templates and options are at `WP-Admin -> E-Mail -> E-Mail Settings`, and every message sent is listed under `E-Mail Logs`.

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

Everything else is configured under `WP-Admin -> E-Mail -> Settings`, which has two tabs: **Settings** for the link, the form and delivery, and **Templates** for the eight message templates. The log of what has been sent is under `WP-Admin -> E-Mail -> Manage E-Mail`.

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
Edit the **E-Mail Link Template** under `WP-Admin -> E-Mail -> Settings`. It is the whole of the link's markup, and it ships as:

```
<a href="%EMAIL_URL%" data-wp-email-popup="%EMAIL_POPUP%" title="Email This %POST_TYPE%" rel="nofollow">%EMAIL_ICON% Email This %POST_TYPE%</a>
```

Four variables are available:

* `%EMAIL_URL%` — the e-mail page or popup for this post
* `%EMAIL_POPUP%` — `1` when the **E-Mail Link Type** is the popup, `0` otherwise; it belongs inside the `data-wp-email-popup` attribute
* `%EMAIL_ICON%` — the envelope glyph, drawn inline
* `%POST_TYPE%` — the singular name of what is being viewed: `Post`, `Page`, or a custom post type's own label

Leave one out to drop it. A template without `%EMAIL_ICON%` is a text link; one holding nothing but `%EMAIL_ICON%` is an icon, and the anchor's `title` is then what a screen reader announces. **Restore Default Template** puts the shipped markup back.

An unrecognised variable is left in the markup as written rather than blanked, so a typo shows up on the page instead of silently emptying the link.

Setting the third parameter of `email_link()` to `false` returns the link instead of echoing it:

```php
$email_link = function_exists( 'email_link' ) ? email_link( '', '', false ) : '';

echo $email_link;
```

The first two parameters are still accepted so that a theme written before 3.0.0 does not fatal, but they no longer do anything.

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

1. E-Mail -> Settings, covering the link, the form, the checks and delivery
2. The Templates tab, holding all eight templates: the page, the message itself, and what a visitor is told when a send succeeds or fails
3. E-Mail -> Manage E-Mail, the log of what was sent, to whom, and whether it arrived
4. The e-mail link in a post, placed with the shortcode
5. The form behind the link, with its image verification

## Changelog
### 3.0.0
* FIXED: Uninstalling left one flood record behind per address that had sent recently. They expire on their own, but until they did the site was still counting down a cooldown for a plugin that was no longer installed
* FIXED: Every render of the form minted a ten-minute verification challenge, and rendering it is free and needs no account — so a request loop grew `wp_options` by two rows a time with nothing to stop it. A visitor may now hold ten live challenges at once, which is far more than anyone with several tabs open needs and far fewer than a loop wants. Collapsing to one challenge per visitor would have bounded it too, and would have brought back the bug 3.0.0 fixed, where opening a second form invalidated the first
* FIXED: The reverse-DNS lookup for the log ran once per recipient rather than once per send, so the default maximum of five multiplied a blocking call — whose duration is chosen by whoever owns the address's reverse zone — by five
* FIXED: The endpoint checked only that a post was published, so any *published* post of a non-public post type — a reusable block, a template, a plugin's private records — could be mailed to an address of the sender's choosing by an anonymous visitor, with its title coming back in the response. The post type has to be publicly viewable now
* FIXED: The flood interval was a read of the log followed, much later, by a write, so requests arriving together all decided they were clear and all sent — it bounded a polite sender and did nothing to a parallel one. The slot is claimed before anything is sent, and an attempt counts whether or not the mailer accepted it; a refused delivery used to cost no cooldown at all
* FIXED: Image verification failed *open*. With the setting on and GD missing — the state a host lands in after a PHP upgrade — the check was skipped entirely and the form's only anti-automation control disappeared with nothing to say so. The form now refuses to submit until an administrator turns it off or installs GD. `wp_email_captcha_available` overrides the detection
* FIXED: The verification image was 55x15, one call to the built-in GD font at a fixed pitch from a fixed origin, no noise and no distortion, which made it readable by thirty-odd reference bitmaps and a per-cell crop. Each character is now drawn, rotated and placed on its own, over speckle and through two lines that share its ink
* FIXED: The name, address and header-token checks ran only when the matching field was switched *on* — so turning a field off turned its validation off with it, while the value was still read and still used. A submission does not have to come from the form; the checks are unconditional now, and only "this field is required" still follows the setting
* BREAKING: WordPress 6.8 and PHP 8.2 are now the minimum. A site on anything older will not be offered this update at all.
* BREAKING: The `email_form-fieldvalues` filter is renamed `wp_email_form_field_values`. There is no compatibility shim.
* BREAKING: The eighteen `wp_options` rows the plugin used are consolidated into `wp_email_options` and `wp_email_version`. Your settings are migrated automatically on upgrade and the old rows are deleted.
* BREAKING: WP-EMail's three checkboxes on the WP-Stats options screen become one setting on its own settings screen, and the shared `stats_display` and `stats_mostlimit` rows are no longer read. Update all seven WP-Stats plugins together.
* BREAKING: `EMAIL_SHOW_REMARKS` is renamed `WP_EMAIL_SHOW_REMARKS`.
* BREAKING: The settings screen moves from `?page=wp-email-options` to `?page=wp-email-settings` and is gated on `manage_options`; the logs screen keeps `manage_email`.
* BREAKING: `email_wpstats_instance()`, `email_page_admin_general_stats()`, `email_page_admin_most_stats()`, `email_page_general_stats()` and `email_page_most_stats()` are removed, along with the global `email_popup()` in JavaScript.
* BREAKING: The E-Mail Icon setting and the two bundled GIFs are gone, replaced by one inline SVG. A custom link template using `%EMAIL_ICON_URL%` is rewritten to `%EMAIL_ICON%` automatically, the whole `<img>` at a time where it sat in one.
* BREAKING: The E-Mail Text Link For Post, E-Mail Text Link For Page and E-Mail Text Link Style settings are removed, and `%EMAIL_TEXT%` with them. The link is one HTML template, migrated from whatever those three said. The first two parameters of `email_link()` are accepted and ignored.
* BREAKING: `%EMAIL_POPUP%` is now the *value* of a `data-wp-email-popup` attribute rather than the whole attribute, because a bare variable in attribute position did not survive the sanitizer.
* NEW: A `%POST_TYPE%` link variable, which becomes the post type's singular label — `Post`, `Page`, or a custom type's own.
* CHANGED: The settings screen is two tabs, Settings and Templates, rather than one page with the eight templates several screenfuls down.
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
* FIXED: The e-mail log's `Logs per page` setting under Screen Options was thrown away when applied, so the log always paged at twenty whatever you set it to.
* NOTE: Four translated strings carried whitespace a translator cannot see in a msgid list, let alone reproduce: the Mail It button's label was padded with five spaces either side to widen it, and three messages ended in a trailing space so a value could be glued on. The padding moved to CSS and the values moved into `%s` placeholders. Those four msgids changed, so existing translations of them fall back to English until they are retranslated
* NOTE: The "From" and "To" log columns carry translator context now, because a single word out of context is not enough to translate from. Those two msgids changed, so existing translations of them fall back to English until they are retranslated

## Upgrade Notice

### 3.0.0

Requires WordPress 6.8 and PHP 8.2.

**Settings migrate on the first admin page load, and the old rows are deleted.** The eighteen rows — `email_options`, `email_fields`, the eight `email_template_*` rows and the rest — become one `wp_email_options` row, and `email_db_version` becomes `wp_email_version`. Point any backup script, migration tool or `wp-config.php` snippet naming an old row at the new one.

**The settings screen is `WP-Admin -> E-Mail -> Settings`** at `?page=wp-email-settings`, gated on `manage_options` rather than the plugin's own `manage_email`. The e-mail log keeps `manage_email`, being a data screen rather than a settings screen. Administrators hold both, so this matters only if you had granted `manage_email` to a role that is not an administrator: that role now sees the log but not the settings. The new `wp_email_capability` filter is there for a different arrangement.

**One filter renamed, six functions removed.** `email_form-fieldvalues` — the only hook WP-EMail fired carrying neither the prefix nor an underscore — is now `wp_email_form_field_values`, taking the same argument and returning the same array. There is no shim, so code hooking the old name silently stops pre-filling the form. `wp_email_ipaddress`, `wp_email_template_redirect` and `wp_email_trust_proxy` are unchanged.

Removed: `email_wpstats_instance()`, `email_page_admin_general_stats()`, `email_page_admin_most_stats()`, `email_page_general_stats()` and `email_page_most_stats()`, which existed only to answer WP-Stats' `wp_stats_page_*` filters that WP-Stats 3.0.0 retired outright; and the global JavaScript `email_popup()`, which sat on `window` so the inline `onclick` the plugin printed could reach it. A link opts into the popup with a data attribute now. Custom link HTML calling `email_popup(...)` from an `onclick` should use the `%EMAIL_POPUP%` variable instead.

**WP-Stats integration is one block**, switched on from **Show WP-EMail On The WP-Stats Page** under `WP-Admin -> E-Mail -> Settings`, drawing the totals and both lists together. It replaces three separate checkboxes; if any of them was ticked, the block stays on.

**Update all seven WP-Stats plugins together.** WP-EMail, WP-DownloadManager, WP-Polls, WP-PostRatings, WP-PostViews, WP-Stats and WP-UserOnline shared one unprefixed `stats_display` row, and each deletes it once it has copied the value into its own settings, so whichever you update first is the only one that finds anything there. A missing row means "show", so a block you had hidden may reappear; each plugin's toggle now lives on its own settings screen.

**No images, one stylesheet, and no theme stylesheet override.** The two envelope icons and the loading GIF are gone: the link draws an inline SVG envelope taking its colour from your theme, and the loading indicator is drawn in CSS and holds still under `prefers-reduced-motion`. The **E-Mail Icon** setting is removed, and `%EMAIL_ICON_URL%` is replaced by `%EMAIL_ICON%`, which inserts the glyph itself. `email-css.css` and `email-css-rtl.css` become one `css/wp-email.css` written with logical properties.

A theme copy of `email-css.css` is no longer loaded. Move those rules into your theme's own stylesheet: everything the plugin styles sits under `.wp-email`, so they keep working, and they now add to the plugin's rules rather than replacing the whole file.

Custom link HTML using `%EMAIL_ICON_URL%` is converted for you on upgrade, and it is the only variable that is: it named a URL to a bundled GIF and sat inside an `<img src="…">`, where nothing sensible is left to put — a URL that no longer exists draws a broken image, and dropping the glyph into the attribute would give you `<img src="<svg …>">`. So the whole `<img>` becomes `%EMAIL_ICON%`, which draws the glyph itself, and a bare `%EMAIL_ICON_URL%` outside an image is renamed. `%EMAIL_TEXT%` is deliberately not converted: only you know what the wording should say, so it is left in the markup for you to see and edit.

**The link is one template now.** **E-Mail Text Link For Post**, **E-Mail Text Link For Page** and **E-Mail Text Link Style** are gone, and so is the `%EMAIL_TEXT%` variable they fed. What is left is **E-Mail Link Template**, the anchor's markup, with `%EMAIL_URL%`, `%EMAIL_POPUP%`, `%EMAIL_ICON%` and the new `%POST_TYPE%` in it. Your template is built on upgrade from the style and the wording you had, so an icon-only link stays icon-only.

Where the two link texts were still the shipped "Email This Post" and "Email This Page", they collapse to `Email This %POST_TYPE%`, which reads correctly on both — and on a custom post type, which the old pair could not do. **Where you had changed them and the two differ, the post wording is kept verbatim and the page wording is lost**: one template cannot carry two arbitrary strings. Check the template if this applies to you. A link already using the Custom style keeps its markup untouched.

`%EMAIL_POPUP%` moved: it is the value of a `data-wp-email-popup` attribute rather than a bare variable standing in for the whole attribute, because a bare one was stripped by the sanitizer every time the screen was saved. Write `data-wp-email-popup="%EMAIL_POPUP%"` on the anchor. An unrecognised variable is left in the markup as written rather than blanked, so a template still holding `%EMAIL_TEXT%` shows it on the page rather than losing its link text in silence.

The first two parameters of `email_link()` are accepted and ignored, so a theme calling `email_link( 'Send this on' )` still renders the link — from the template, not from the string.

**The settings screen has two tabs**, `Settings` and `Templates`, at `?page=wp-email-settings&tab=…`. Both write the same option row and each saves without touching the other's values.

**Proxy headers are no longer trusted unless you say so.** Until 3.0.0 the plugin read `HTTP_X_FORWARDED_FOR` and friends whenever they were present, and those headers are set by the visitor — so anyone could walk past the interval between e-mails by sending a different value on each request. If your site is behind a proxy, name the exact header it sets in `Header That Contains The IP`. Otherwise do nothing.
