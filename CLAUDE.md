# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

WP-EMail follows `_standards/STANDARDS.md` in the parent folder, which is the
contract for all nineteen plugins in the collection. Where this file and that
one disagree, that one wins.

## What it is

"Email this post to a friend": a link on a post, a form on a standalone or popup
page, an image-verification challenge, a flood interval, eight editable message
templates, and a log of everything sent. Two front-end endpoints — `/email/`
(themed) and `/emailpopup/` (standalone). One top-level menu: **Settings**
first, then **Logs**.

Settings first is deliberate (§4.1): the log is a *record* rather than a
workspace — you open it to look something up occasionally, so Settings leads.

## Data

* `wp_email_options` — absorbs **eighteen** legacy rows: `email_options`,
  `email_fields`, the eight `email_template_*` rows and the rest.
  `LEGACY_ROWS` is the single list, read by both the migration and the
  uninstaller so the two cannot disagree.
* `wp_email_version` — from `email_db_version`.
* **A custom table**, `$wpdb->email`, holding the send log.
* One of the seven WP-Stats plugins (§13).

## Capabilities

`manage_email` is the plugin's own capability and it is kept for the **Logs**
screen only; Settings is `manage_options` (§2.7). Administrators hold both, so
this only bites where `manage_email` was granted to a non-administrator role —
that role now sees the log but not the settings. `wp_email_capability` is the
one filter.

## Traps

* **The captcha is a transient keyed by a random token, not a PHP session, and
  the rewrite fixed three separate failures.** `email-image-verify.php` used to
  call `session_start()` outside WordPress: sessions are unavailable or
  non-persistent on much hosting and behind most page caches; the answer was one
  session-wide value, so two open forms invalidated each other; and any hit on
  the image URL rotated it. Now the challenge is issued **when the form
  renders** — the image endpoint only draws a challenge already issued, and each
  is consumed exactly once. Do not make the image endpoint able to mint one.
* **Proxy headers are untrusted by default, and here it is not only a spoofing
  question — the flood interval is keyed on the IP.** Before 3.0.0 anyone could
  walk past the interval by sending a different `X-Forwarded-For` each time.
  Opt in by naming the exact header, or `WP_EMAIL_TRUST_PROXY`, or the
  `wp_email_trust_proxy` filter.
* **`current_time( 'timestamp' )` in the flood check is deliberate and carries a
  `phpcs:ignore`.** It is compared against `email_timestamp`, which has held
  site-local time since 2.x; a UTC value would misjudge the interval by the
  site's offset.
* **Log statuses are stored untranslated** (`STATUS_SUCCESS = 'Success'`).
  Before 3.0.0 the translated string went into the column, so a site that changed
  language could no longer match its own historical rows.
* **`WP_Email_Logs::table()` falls back to `$wpdb->prefix . 'email'`** because
  `uninstall.php` loads the class without booting the plugin, so nothing has
  registered `$wpdb->email`.
* **`includes/deprecated.php` keeps its `function_exists()` guards, and that is
  not cargo cult.** wp-print and wp-postratings define some of the same
  unprefixed names (`get_ipaddress()`, `is_valid_name()`), and whichever plugin
  loads first wins. Removing the guard makes a fatal out of having two of these
  plugins installed.
* **`%EMAIL_POPUP%` is now the *value* of a `data-wp-email-popup` attribute**,
  not a bare variable standing in for the whole attribute — a bare one was
  stripped by the sanitizer on every save. Templates must read
  `data-wp-email-popup="%EMAIL_POPUP%"`.
* **An unrecognised template variable is left in the markup as written, never
  blanked.** A template still holding `%EMAIL_TEXT%` shows it on the page rather
  than silently losing its link text. `email_link()`'s first two parameters are
  accepted and ignored for the same reason.
* **The link is one template.** The four-way style select, `post_text` and
  `page_text` are retired (`RETIRED_LINK_KEYS`); the migration synthesises the
  template from the old style *and* wording, collapsing to `%POST_TYPE%` only
  when both texts are the stock pair. Where they differed, the **page wording is
  lost** — one template cannot express two arbitrary strings, and the Upgrade
  Notice says so.
* **A theme copy of `email-css.css` is no longer loaded.** Everything is scoped
  under `.wp-email`, so the rules keep working from a theme's own stylesheet —
  but they now *add* to the plugin's rather than replacing the whole file.
* **`includes/screen-popup.php` and `screen-standalone.php` are `screen-*.php`
  partials, not classes**, per §1. The popup deliberately does not run the theme
  but does enqueue `get_stylesheet_uri()`, so the form does not look foreign; the
  standalone one goes through the theme and uses `document_title_parts` because
  `wp_title()` has been deprecated since WP 4.4.
* The global JS `email_popup()` is gone. It lived on `window` only so the inline
  `onclick` the plugin printed could reach it; opting in is a data attribute now.
* `email_form-fieldvalues` — the only hook the plugin fired carrying neither the
  prefix nor an underscore — became `wp_email_form_field_values`. No shim; it
  silently stops pre-filling the form.

## WP-Stats coupling

Three separate checkboxes collapse into one block. Read `stats_display` through
`WP_Email_Options`, never `get_option()` directly, and **keep the shared
`stats_display` / `stats_mostlimit` rows off the uninstall list** — wp-polls and
wp-downloadmanager currently get that wrong (`_standards/RESUME.md`).

## Tests

The largest suite in the collection at 331 tests, and the best documented: 94.9%
of assertions carry a failure message, the highest measured (`_standards/RESUME.md`).

**`helper-ajax-testcase.php` exists because AJAX tests must go through
`_handleAjax()`**, not the handler directly. Catch `WPDieException`, the parent —
`wp_die()` only throws the `WPAjaxDie*` subclasses while `wp_doing_ajax()` is
true.

§7.2's PHP 8.2 lesson came from this plugin: **one undeclared `private $mail`
dynamic property failed all 41 tests in its class**, because the floor moved to
8.2 *and* the shared config sets `convertDeprecationsToExceptions` (commit
`2426531`).

`tests/e2e/` is 5 specs and 54 tests. `upgrade.spec.js` (9) is green as of
2026-08-05; **the other four were not re-run that day**, so verify before
trusting them.

## Pending, not started

Task #18 (the link-settings collapse) has largely landed here already — see
commit `bb95952`. Check before redoing it. Task #20 brings the proxy-header
label into line with wp-polls and wp-postratings; commit `190f659` may already
have done it.
