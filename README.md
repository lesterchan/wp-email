# WP-EMail
Contributors: GamerZ  
Donate link: http://lesterchan.net/site/donation/  
Tags: email, e-mail, wp-email, mail, send, recommend, ajax, friend  
Requires at least: 3.9  
Tested up to: 4.5  
Stable tag: 2.67.1  
License: GPLv2 or later  

Allows people to recommend/send your WordPress blog's post/page to a friend.

## Description

### Build Status
[![Build Status](https://travis-ci.org/lesterchan/wp-email.svg?branch=master)](https://travis-ci.org/lesterchan/wp-email)

### Development
* [https://github.com/lesterchan/wp-email](https://github.com/lesterchan/wp-email "https://github.com/lesterchan/wp-email")

### Translations
* [http://dev.wp-plugins.org/browser/wp-email/i18n/](http://dev.wp-plugins.org/browser/wp-email/i18n/ "http://dev.wp-plugins.org/browser/wp-email/i18n/")

### Credits
* Plugin icon by [Yannick](http://yanlu.de) from [Flaticon](http://www.flaticon.com)
* Icons courtesy of [FamFamFam](http://www.famfamfam.com/).

### Donations
I spent most of my free time creating, updating, maintaining and supporting these plugins, if you really love my plugins and could spare me a couple of bucks as my school allowance, I will really appreciate it. If not feel free to use it without any obligations.

## Installation

You can either install it automatically from the WordPress admin, or do it manually:

1. Upload the whole `wp-email` directory into your plugins folder(`/wp-content/plugins/`)
1. Activate the plugin through the 'Plugins' menu in WordPress

Once installed take the following steps to set it up:

1. Under E-Mail Settings, modify the setting Method Used To Send E-Mail accordingly. If the method is wrong, no email will get sent.
1. You Need To Re-Generate The Permalink (WP-Admin -> Settings -> Permalinks -> Save Changes)
1. Open `wp-content/themes/<YOUR THEME NAME>/index.php` (You may place it in single.php, post.php, page.php, etc also)
 * Find: `<?php while (have_posts()) : the_post(); ?>`
 * Simply add this code inside the loop where you want the email link to display: <code>if(function_exists('email_link')) { email_link(); }</code>

If you DO NOT want the email link to appear in every post/page, DO NOT use the code above. Just use the shortcode by typing [email_link] into the selected post/page content and it will embed the email link into that post/page only.

## Upgrade Notice
N/A

## Screenshots

1. Admin - E-Mail Logs
2. Admin - Options Page
3. Admin - Templates Page
4. Sample E-Mail Post link
5. Sample E-Mail Post screen

## Frequently Asked Questions

### Does it support SMTP authentication with servers utilizing SSL encryption?

1. Yes. Go to `WP-Admin -> E-Mail -> Email Options`, under `SMTP Server`, use `ssl://smtp.gmail.com:465` if you are using Gmail SMTP.

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
	$email_link email_link( 'E-Mail Text Link for Post', 'E-Mail Text Link for Page', false);
} else {
	$email_link '';
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

### How do I hide remarks when viewing E-Mail logs in WP-Admin?

1. Open `wp-email.php`
1. Find `define('EMAIL_SHOW_REMARKS', true);`
1. Replace with `define('EMAIL_SHOW_REMARKS', false);`

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
* NEW: Finally there is custom post type support. Props [nimmolo](http://andrewnimmo.org/ "nimmolo").
* NEW: Allow Multisite Network Activate
* NEW: Uses WordPress uninstall.php file to uninstall the plugin
* NEW: Added noindex, nofollow to meta tag to email-standalone.php
* FIXED: Use get_the_author() instead of the_author('', false)

### 2.62
* NEW: WordPress 3.9 Dashicons

### 2.61
* FIXED: Unable to load WP-Email on Password Protected posts

### 2.60
* Move AJAX Request to wp-admin/admin-ajax.php
* Added nonce To Email Form

### 2.52
* Added support for the wp-email-title and wp-email-remark custom fields

### 2.51
* FIXED: Warnings of non-existant array indices

### 2.50
* NEW: Works For WordPress 2.8 Only
* NEW: Javascript Now Placed At The Footer
* NEW: Uses jQuery Instead Of tw-sack
* NEW: Minified Javascript Instead Of Packed Javascript
* NEW: Renamed email-js-packed.js To email-js.js
* NEW: Renamed email-js.js To email-js.dev.js
* NEW: Translate Javascript Variables Using wp_localize_script()
* NEW: Fill In "Your Name" And "Your Email" Fields If User Is Logged In (By Aaron Campbell)
* NEW: Added [donotemail][/donotemail] Short Code (Refer To Usage Tab)
* NEW: Added In Most Emailed Pages To WP-Stats
* NEW: Use _n() Instead Of __ngettext() And _n_noop() Instead Of __ngettext_noop()
* NEW: Uses New Widget Class From WordPress
* NEW: Merge Widget Code To wp-email.php And Remove wp-email-widget.php
* FIXED: Uses $_SERVER['PHP_SELF'] With plugin_basename(__FILE__) Instead Of Just $_SERVER['REQUEST_URI']
* FIXED: Nested ShortCode Issues
* FIXED: Double Slashes In SMTP Username

### 2.40
* NEW: Works For WordPress 2.7 Only
* NEW: Load Admin JS And CSS Only In WP-Email Admin Pages
* NEW: Added email-admin-css.css For WP-Email Admin CSS Styles
* NEW: Uses wp_register_style(), wp_print_styles(), plugins_url() And site_url()
* NEW: Better Translation Using __ngetext() by Anna Ozeritskaya
* NEW: Right To Left Language Support by Kambiz R. Khojasteh
* NEW: Called email_textdomain() In create_email_table() by Kambiz R. Khojasteh
* NEW: Added "email-css-rtl.css" by Kambiz R. Khojasteh
* NEW: E-mail Form Is More CSS Friendly by Kambiz R. Khojasteh
* NEW: Use language_attributes() To Get Attributes Of HTML Tag For Popup Window by Kambiz R. Khojasteh
* NEW: Popup Window Is Now Auto Sized And Centralized by Kambiz R. Khojasteh
* NEW: Page Title Is Now "Post Title -> Email" Instead Of "Email -> Post Title" by Kambiz R. Khojasteh
* FIXED: remove_filter('the_content', 'email_form', ''); By TripleM
* FIXED: Missing Display Of Friend's Invalid Email Address In Javascript Alert Box

### 2.31
* NEW: Works For WordPress 2.6
* FIXED: MYSQL Charset Issue Should Be Solved

### 2.30
* NEW: Works For WordPress 2.5 Only
* NEW: WP-Email Will Load 'email.php' Inside Your Theme Directory If It Exists. This Will Allow Some Flexibility Instead Of Using 'page.php' As The Default Template.
* NEW: WP-Email Will Load 'email-css.css' Inside Your Theme Directory If It Exists. If Not, It Will Just Load The Default 'email-css.css' By WP-Email
* NEW: Changed CSS Style For Input Field From 'Forms' To 'TextField' And For Buttons From 'Buttons' To 'Button'
* NEW: Renamed email-js.php To email-js.js and Move The Dynamic Javascript Variables To The PHP Pages
* NEW: Uses email-js-packed.js
* NEW: Uses /wp-email/ Folder Instead Of /email/
* NEW: Uses wp-email.php Instead Of email.php
* NEW: Uses wp-email-widget.php Instead Of email-widget.php
* NEW: Changed wp-email.php To email-standalone.php
* NEW: Changed wp-email-popup.php To email-popup.php
* NEW: Use number_format_i18n() Instead
* NEW: Show 'Remarks' In 'WP-Admin -> E-Mail -> E-Mail Logs' Page By Default. See Usage Tab On How To Hide It.

### 2.20
* NEW: Works For WordPress 2.3 Only
* NEW: Removed PHPMailer Files From The Zip As It Is Included In WordPress
* NEW: Ability To Embed [email_link] Into Excerpt
* NEW: AJAX Used To Email The Post/Page
* NEW: Most Emailed Widget Added
* NEW: Ability To Uninstall WP-EMail
* NEW: Uses WP-Stats Filter To Add Stats Into WP-Stats Page
* FIXED: Displaying Friend's E-Mail Field Is Compulsory To Prevent Error
* FIXED: Method Of Storing SMTP Information Updated
* FIXED: If There Is No Trailing Slash In Your Permalink, WP-Email Will Add It For You
* FIXED: Use @session_start() Instead To Compress Session Already Started Error

### 2.11
* NEW: Added Template For Page Title And Page Subtitle In 'WP-Admin -> E-Mail -> E-Mail Options'
* NEW: Putting [email_link] In Your Post/Page Content Will Display A Link To The E-Mail Post/Page
* FIXED: Suppress gethostbyaddr() Error
* FIXED: Duplicate Page Title When Listing Pages With wp_list_pages()
* FIXED: If page.php Is Not Found, single.php or index.php Will Be Used
* FIXED: Wrong URL For Page Under Most E-Mailed Posts Listing
* FIXED: Wrong URL If Front Page Is A Static Page
* FIXED: Fixed A Minor Grammer Mistake For Remark (Singular)
* FIXED: Some Text Not Translated

### 2.10
* NEW: Works For WordPress 2.1 Only
* NEW: Added Fam Fam Fam's E-Mail Icon
* NEW: Localize WP-EMail
* NEW: Ability To Configure The Text For E-Mail Links Via 'WP-Admin -> E-Mail -> E-Mail Options'
* NEW: Ability To Set E-Mail Link Type (Standalone Page Or Popup Page) Via 'WP-Admin -> E-Mail -> E-Mail Options'
* NEW: The Text For E-Mail Links Can No Longer Be Pass To The Function email_link(), email_link_image(), email_popup() or email_popup_image().
* NEW: Ability To Select Which Field You Want To Display In The E-Mail Form
* FIXED: Name Fields No Longer Check For Validity Due To Localization
* FIXED: Special HTML Characters No Longer Get Converted Into Its Symbolic Form When Displaying In E-Mail -> E-Mail Options
* FIXED: Extra ; When Displaying Error Message
* FIXED: Removed 1 0 I O From The Image Verify To Avoid Confusion

### 2.07
* NEW: WP-EMail-Popup Now Have Nice Permalinks /emailpopup/ Or /emailpopuppage/
* NEW: Added rel="nofollow" To All Links Generated By WP-EMail
* NEW: Added noindex, nofollow To Robots Meta Tag In wp-email-popup.php
* NEW: Error Messages Will Now Be Displayed Together With The E-Mail Form
* FIXED: PHP5 Compatibility Issue
* FIXED: Image Verify Is Now Not Case Sensitive
* FIXED: Error In Logging Due To Post ID Being Blank
* FIXED: Form Input Data Will No Longer Be Lost After Encountering An Error
* FIXED: WP-EMail-Popup Not Working With Other Nice Permalinks

### 2.06
* FIXED: Modified Get Most Emailed Post Function

### 2.05
* NEW: Spam Prevention - Image Verification
* NEW: EMail Administration Panel And The Code That WP-EMail Generated Is XHTML 1.0 Transitional
* NEW: Added <label> Tag For Form Fields
* FIXED: Remarks Column Removed From E-Mail Logs Due To Privacy Issue
* FIXED: Duplicate Subject/Name When Sent Using PHP
* FIXED: Quotes Not Displaying When Sending In Plain Text

### 2.04a
* FIXED: PHP Mail Not Working Properly (Thanks To Pablo)

### 2.04
* NEW: Ability To Sent To Multiple EMails (Config Via Admin Panel)l
* NEW: Added wp-email-popup.php For Using WP-EMail In A Pop Up Windowl
* NEW: Combined functions-wp-email.php With email.phpl
* NEW: Moved wp-email.php/wp-email-popup.php To Plugin Folderl

### 2.03
* NEW: Improved On 'manage_email' Capabilities
* NEW: Neater Structure
* NEW: No More Install/Upgrade File, It Will Install/Upgrade When You Activate The Plugin
* NEW: Added E-Mail Stats Function
* NEW: Per Page Option In email-manager.php
* NEW: Added Excerpt As A Template Variable
* NEW: Added EMail Image With email_link_image()
* FIXED: Now Paginate Have Sort Options
* FIXED: Default Mailer Type Is Now PHP
* FIXED: Charset Is Now UTF-8
* FIXED: Quotes Not Displaying

### 2.02
* NEW: Added 'manage_email' Capabilities To Administrator Roles
* FIXED: Able To View Password Protected Blog

### 2.01
* NEW: Compatible With WordPress 2.0 Only
* NEW: EMail A Snippet Of The Post Rather Than The Whole Post. Able To Specify The No. Of Words Before Cutting Off
* NEW: Spam Prevention - Better Checking Of Names, EMail Addresses And Remarks
* NEW: Spam Prevention - Able To Specify The No. Of Mins Before User Is Allowed To Send A 2nd Article
* NEW: GPL License Added
* NEW: Page Title Added To wp-email.php
* NEW: Automated Permalink
* FIXED: Date Not Showing Correctly In EMail Logs
* FIXED: Friend's Name Is Displayed Instead Of Friend's EMail On The Results Page
* UPDATE: Moved All The WP-EMail Functions To wp-includes/functions-wp-email.php

### 2.00b
* FIXED: Error In Sending E-Mail With Pages

### 2.00a
* FIXED: exit(); Missing in wp-email.php

### 2.00
* FIXED: Did Not Strip Slashes In Remarks Field
* FIXED: All Of WordPress Permlink Styles Should Work Now
* FIXED: Better Localization Support (80% Done, Will Leave It In The Mean Time)
* NEW: EMail Administration Panel
* NEW: EMail Templates Editable Online Via The Administration Panel
* NEW: Change EMail Options Online Via The Administration Panel
* NEW: Every EMail Sent Will Be Logged
* NEW: Uses WordPress Default Query Instead Of Own
* NEW: Uses Most Of The WordPress Functions To Get Data Instead Of Own
* NEW: Able To EMail Page Also
* NEW: If No Remarks Is Made, It Is Known As N/A
