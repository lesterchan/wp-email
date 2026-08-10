# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What it is

"Email this post to a friend": a link on a post, a form on a standalone or popup
page, an image-verification challenge, a flood interval, eight editable message
templates, and a log of everything sent. Two front-end endpoints — `/email/`
(themed) and `/emailpopup/` (standalone). One top-level menu: **Settings**
first, then **Logs**.

Settings first is deliberate: the log is a *record* rather than a workspace —
you open it to look something up occasionally, so Settings leads.

## Data

* `wp_email_options` — absorbs **eighteen** legacy rows: `email_options`,
  `email_fields`, the eight `email_template_*` rows and the rest.
  `LEGACY_ROWS` is the single list, read by both the migration and the
  uninstaller so the two cannot disagree.
* `wp_email_version` — the `plugin` and `db` upgrade markers, from
  `email_db_version`. Keep them out of the settings array: a marker in there has
  to be rescued from the stored value on every save, because the settings form
  never posts one.
* **A custom table**, `$wpdb->email`, holding the send log.
* It contributes a section to **WP-Stats**, a separate plugin, by answering the
  `wp_stats_sections` filter.

## Capabilities

`manage_email` is the plugin's own capability and it is kept for the **Logs**
screen only; Settings is `manage_options`. Administrators hold both, so
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
* **`%EMAIL_ICON_URL%` is the one exception, and it is converted rather than
  shown.** It named a URL to a bundled GIF and lived inside `<img src="…">`,
  where nothing sensible survives: the URL is gone, so leaving it draws a broken
  image, and putting the glyph in the attribute would give
  `<img src="<svg …>">`. So the migration replaces the whole `<img>` with
  `%EMAIL_ICON%`, and renames a bare one. The split is deliberate — the icon has
  exactly one replacement, while only the site knows what its wording should
  say, so `%EMAIL_TEXT%` is left to be seen and edited.
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
  partials, not classes.** The popup deliberately does not run the theme
  but does enqueue `get_stylesheet_uri()`, so the form does not look foreign; the
  standalone one goes through the theme and uses `document_title_parts` because
  `wp_title()` has been deprecated since WP 4.4.
* The global JS `email_popup()` is gone. It lived on `window` only so the inline
  `onclick` the plugin printed could reach it; opting in is a data attribute now.
* `email_form-fieldvalues` — the only hook the plugin fired carrying neither the
  prefix nor an underscore — became `wp_email_form_field_values`. No shim; it
  silently stops pre-filling the form.

## WP-Stats coupling

WP-Stats is a separate plugin; this one contributes a section to its page by
answering the `wp_stats_sections` filter. Three separate checkboxes collapse
into one block.

Read `stats_display` through `WP_Email_Options`, never `get_option()` directly:
a raw read cannot tell "a sibling plugin already migrated the shared row away"
from "the site opted out", and would turn a fresh install's section off.

**Keep the shared `stats_display` / `stats_mostlimit` rows off the uninstall
list.** They were never this plugin's to own — several plugins wrote into them —
so the migration deletes them once it has folded them in, and uninstall leaves
them alone, because a sibling that has not upgraded is still reading them.

## Migrations, and why they are tested through a browser

There are two, gated separately, and `tests/e2e/upgrade.spec.js` holds both
still: with no schema counter, sixteen unprefixed rows fold into one; below
counter 2, the four link settings collapse into the single HTML template the
plugin now keeps.

The second is the half only a browser can answer. A stored install has to come
out of the upgrade rendering what it was rendering before, and "the same link"
is a question about a page — so three tests take the three shapes an install can
be in (stock wording, customised wording, a site already writing its own HTML)
and read the answer off a rendered post rather than off the row.

Read rows **raw**: `WP_Email_Options::all()` merges over the defaults, so it
answers identically for a row holding them and for no row at all — the state a
migration that read, deleted and never wrote leaves behind. And assert the
retired link keys are *absent* rather than merely unread; a setting the screen
no longer draws is one the next release has to keep thinking about.

## Tests

`bin/test.sh` runs PHPUnit, `bin/test-multisite.sh` the network pass, and
`bin/test-e2e.sh` the Playwright suite. **Run them rather than trusting a note
about their last result** — CI is the authority, and this file cannot be.

This is a large suite (330-odd PHPUnit tests), and every assertion in it carries
a failure message. Keep it that way.

**`helper-ajax-testcase.php` exists because AJAX tests must go through
`_handleAjax()`**, not the handler directly. Catch `WPDieException`, the parent —
`wp_die()` only throws the `WPAjaxDie*` subclasses while `wp_doing_ajax()` is
true.

**One undeclared `private $mail` dynamic property failed all 41 tests in its
class**, because the PHP floor is 8.2 *and* `phpunit.xml.dist` sets
`convertDeprecationsToExceptions` (commit `2426531`). A dynamic property is a
fatal-shaped failure here, not a notice.

## Pending

Nothing outstanding. The link-settings collapse landed in commit `bb95952` and
the proxy-header label and description were brought into line in `190f659` —
check those before redoing either.
