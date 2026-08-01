/**
 * The settings screen, and what each setting changes.
 *
 * A setting that saves but does nothing, and a setting that does something but
 * will not save, are the two failures a screenshot cannot tell apart. So each
 * test here drives the form and then checks the far end: the stored row for the
 * values the logic reads, and the rendered link, the rendered form or the
 * intercepted message for the ones somebody meets.
 */

const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );
const {
	SETTINGS_URL,
	anonymously,
	createEmailablePost,
	emailPageUrl,
	fillForm,
	installMailInterceptor,
	lastMail,
	logInAs,
	resetPlugin,
	setSetting,
	setting,
	template,
	uniqueTitle,
	usePrettyPermalinks,
} = require( './helpers.js' );

/**
 * Open the settings screen, on one of its two tabs.
 *
 * @param {import('@playwright/test').Page} page  Page under test.
 * @param {string}                          [tab] Either 'settings' or 'templates'.
 * @return {Promise<void>} Resolves once the screen is up.
 */
async function openSettings( page, tab = 'settings' ) {
	await page.goto( `${ SETTINGS_URL }&tab=${ tab }` );

	await expect( page.getByRole( 'heading', { name: 'E-Mail Settings' } ).first() ).toBeVisible();
}

/**
 * Save the settings form and wait for the confirmation.
 *
 * @param {import('@playwright/test').Page} page Page under test.
 * @return {Promise<void>} Resolves once "Settings saved." is on screen.
 */
async function saveSettings( page ) {
	await page.getByRole( 'button', { name: 'Save Changes' } ).click();

	// The notice rather than the redirect: options.php sends the browser back
	// whether or not anything was written, so arriving here again says nothing.
	// It is also the specific guard against scoping settings_errors() to the
	// plugin's own option, which filters the core confirmation out and leaves a
	// screen that saves in silence.
	await expect( page.locator( '.settings-error, .notice-success' ).first() ).toContainText(
		'Settings saved.',
	);
}

test.describe( 'E-mail settings', () => {
	let post;
	let visitor;
	let context;

	test.beforeAll( async () => {
		installMailInterceptor();
		usePrettyPermalinks();
	} );

	test.beforeEach( async ( { page, requestUtils } ) => {
		resetPlugin();
		setSetting( 'sending', 'imageverify', '0' );

		// And the flood interval off. It ships at ten minutes, which is right for
		// a real site and wrong for a test that sends twice to compare two
		// outcomes -- the second send would be refused for a reason the test is
		// not about. The interval has a test of its own, which switches it back on.
		setSetting( 'sending', 'interval', '0' );
		await requestUtils.deleteAllPosts();

		post = await createEmailablePost( requestUtils, uniqueTitle( 'Configurable' ) );

		( { context, visitor } = await anonymously( page ) );
	} );

	test.afterEach( async () => {
		await context.close();
	} );

	test.afterAll( async () => {
		resetPlugin();
	} );

	test( 'the fixture really is two tabs of one settings screen', async ( { page } ) => {
		// The precondition: four sections registered across two tabs of one page,
		// which is what "settings never span more than one page" looks like when
		// it is working.
		await openSettings( page, 'settings' );

		// The section headings, by their own level. The h1 is "E-Mail Settings"
		// and the sections beneath it are named for what they hold rather than
		// repeating the plugin's name in each one -- so this list is Link,
		// Sending and WP-Stats. It used to read "E-Mail Link" and
		// "E-Mail Settings"; those are the pre-rename titles
		// (class-wp-email-settings.php:174-176).
		for ( const heading of [ 'Link', 'Sending', 'WP-Stats' ] ) {
			await expect(
				page.locator( 'h2' ).filter( { hasText: heading } ).first(),
				heading,
			).toBeVisible();
		}

		await expect( page.locator( '#wp_email_link_html' ) ).toBeVisible();

		// The templates are on the other tab, and only on the other tab.
		await expect( page.locator( '#wp_email_template_body' ) ).toHaveCount( 0 );

		await page.getByRole( 'link', { name: 'Templates', exact: true } ).click();

		await expect( page.locator( '#wp_email_template_body' ) ).toBeVisible();
		await expect( page.locator( '#wp_email_link_html' ) ).toHaveCount( 0 );
		await expect( page.locator( '.nav-tab-active' ) ).toHaveText( 'Templates' );
	} );

	test( 'saving one tab leaves the other tab settings alone', async ( { page } ) => {
		// The regression two tabs writing one option row invite: the Settings API
		// hands the sanitizer only what the submitting form posted, so a screen
		// that rebuilt the whole row would wipe eight written templates the first
		// time anybody saved the other tab.
		await openSettings( page, 'templates' );
		await page.locator( '#wp_email_template_sentfailed' ).fill( '<p>Written by hand.</p>' );
		await saveSettings( page );

		await openSettings( page, 'settings' );
		await page.locator( '#wp_email_sending_interval' ).fill( '3' );
		await saveSettings( page );

		expect( setting( 'sending', 'interval' ) ).toBe( '3' );
		expect( template( 'sentfailed' ) ).toBe( '<p>Written by hand.</p>' );

		// And back the other way, so neither tab is the privileged one.
		await openSettings( page, 'templates' );
		await page.locator( '#wp_email_template_sentfailed' ).fill( '<p>Written again.</p>' );
		await saveSettings( page );

		expect( template( 'sentfailed' ) ).toBe( '<p>Written again.</p>' );
		expect( setting( 'sending', 'interval' ) ).toBe( '3' );
	} );

	test( 'a save comes back to the tab it was submitted from', async ( { page } ) => {
		await openSettings( page, 'templates' );
		await saveSettings( page );

		await expect( page ).toHaveURL( /tab=templates/ );
		await expect( page.locator( '.nav-tab-active' ) ).toHaveText( 'Templates' );
	} );

	test( 'the link template saves and is what the post and the page render', async ( {
		page,
		requestUtils,
	} ) => {
		await openSettings( page );
		await page
			.locator( '#wp_email_link_html' )
			.fill( '<a class="my-own-link" href="%EMAIL_URL%">Send this %POST_TYPE% on</a>' );
		await saveSettings( page );

		expect( setting( 'link', 'html' ) ).toBe(
			'<a class="my-own-link" href="%EMAIL_URL%">Send this %POST_TYPE% on</a>',
		);

		// Both contexts, because %POST_TYPE% is the whole reason one template can
		// replace two settings: a plugin that resolved it once would pass a
		// one-sided test.
		await visitor.goto( post.link );
		await expect( visitor.locator( '.entry-content a.my-own-link' ) ).toHaveCount( 1 );
		await expect( visitor.locator( '.entry-content' ) ).toContainText( 'Send this Post on' );

		const page_ = await requestUtils.createPage( {
			title: uniqueTitle( 'A page to send' ),
			content: '[email_link]',
			status: 'publish',
		} );

		await visitor.goto( page_.link );
		await expect( visitor.locator( '.entry-content' ) ).toContainText( 'Send this Page on' );
	} );

	test( 'the template decides whether the link is an icon, some text, or both', async ( {
		page,
	} ) => {
		const cases = [
			{
				name: 'icon and text',
				html: '<a href="%EMAIL_URL%" title="Email This %POST_TYPE%">%EMAIL_ICON% Email This %POST_TYPE%</a>',
				icons: 1,
				text: true,
			},
			{
				name: 'icon only',
				html: '<a href="%EMAIL_URL%" title="Email This %POST_TYPE%">%EMAIL_ICON%</a>',
				icons: 1,
				text: false,
			},
			{
				name: 'text only',
				html: '<a href="%EMAIL_URL%" title="Email This %POST_TYPE%">Email This %POST_TYPE%</a>',
				icons: 0,
				text: true,
			},
		];

		for ( const { name, html, icons, text } of cases ) {
			await openSettings( page );
			await page.locator( '#wp_email_link_html' ).fill( html );
			await saveSettings( page );

			await visitor.goto( post.link );

			const links = visitor.locator( '.entry-content a[href$="/email/"]' );

			await expect( links.locator( 'svg.wp-email-icon' ), name ).toHaveCount( icons );

			if ( text ) {
				await expect( visitor.locator( '.entry-content' ) ).toContainText(
					'Email This Post',
				);
			} else {
				// Icon only: the wording is the link's accessible name rather than
				// anything printed beside it.
				await expect( links.first() ).toHaveAttribute( 'title', 'Email This Post' );
			}
		}
	} );

	test( 'an unrecognised variable is left in the markup rather than blanked', async ( {
		page,
	} ) => {
		// %EMAIL_TEXT% was retired with the two link-text settings. A template
		// nobody updated has to look wrong on the page, not lose its link text in
		// silence -- which is the whole of the migration notice's promise.
		await openSettings( page );
		await page
			.locator( '#wp_email_link_html' )
			.fill( '<a class="stale" href="%EMAIL_URL%">%EMAIL_TEXT%</a>' );
		await saveSettings( page );

		await visitor.goto( post.link );

		await expect( visitor.locator( '.entry-content a.stale' ) ).toHaveText( '%EMAIL_TEXT%' );
	} );

	test( 'the shipped template keeps its popup marker through a save', async ( { page } ) => {
		// wp_kses_post() runs over every saved template, and it strips anything
		// in attribute position that is not an attribute it allows. A bare
		// %EMAIL_POPUP% was exactly that, so the popup stopped opening the moment
		// the screen was saved. As a data attribute's value it survives.
		await openSettings( page );
		await page.locator( '#wp_email_link_type' ).selectOption( '2' );
		await saveSettings( page );

		expect( setting( 'link', 'html' ) ).toContain( 'data-wp-email-popup="%EMAIL_POPUP%"' );

		await visitor.goto( post.link );

		await expect(
			visitor.locator( '.entry-content a[href$="/emailpopup/"]' ).first(),
		).toHaveAttribute( 'data-wp-email-popup', '1' );
	} );

	test( 'the popup link type opens a window rather than navigating', async ( { page } ) => {
		await openSettings( page, 'settings' );
		await page.locator( '#wp_email_link_type' ).selectOption( '2' );
		await saveSettings( page );

		expect( setting( 'link', 'type' ) ).toBe( '2' );

		await visitor.goto( post.link );

		const link = visitor.locator( '.entry-content a[href$="/emailpopup/"]' ).first();
		await expect( link ).toHaveCount( 1 );
		await expect( link ).toHaveAttribute( 'data-wp-email-popup', '1' );

		// The script turns the click into window.open(), which is a behaviour no
		// PHPUnit test can see: the markup is identical either way but for the
		// attribute, and the whole point is that the page does not navigate.
		const [ popup ] = await Promise.all( [ visitor.waitForEvent( 'popup' ), link.click() ] );

		await expect( popup.locator( '#wp-email-popup' ) ).toBeVisible();
		await expect( visitor ).toHaveURL( post.link );

		await popup.close();
	} );

	test( 'unticking a field takes it off the form and out of the rules', async ( { page } ) => {
		await openSettings( page );

		// input[type="checkbox"], because a Settings API checkbox is two inputs
		// under one name -- the hidden "0" that makes an unticked box post at
		// all, plus the box itself -- and the bare name selector matches both.
		for ( const key of [ 'yourname', 'youremail', 'yourremarks', 'friendname' ] ) {
			await page
				.locator( `input[type="checkbox"][name="wp_email_options[fields][${ key }]"]` )
				.uncheck();
		}

		await saveSettings( page );

		for ( const key of [ 'yourname', 'youremail', 'yourremarks', 'friendname' ] ) {
			expect( setting( 'fields', key ), `${ key } should be stored as off` ).toBe( '0' );
		}

		await visitor.goto( emailPageUrl( post ) );

		for ( const id of [ 'yourname', 'youremail', 'yourremarks', 'friendname' ] ) {
			await expect( visitor.locator( `#${ id }` ) ).toHaveCount( 0 );
		}

		// The friend's address is the one field the form cannot work without, so
		// the screen offers no way to switch it off and the sanitizer forces it on.
		await expect( visitor.locator( '#friendemail' ) ).toBeVisible();
		expect( setting( 'fields', 'friendemail' ) ).toBe( '1' );

		// And a send still goes through with only that field filled in, which is
		// what "off" has to mean for a required-looking field.
		await fillForm( visitor, { friendemail: 'bob@example.com' } );
		await expect( visitor.locator( '#wp-email-content' ) ).toContainText( 'has been sent to' );
	} );

	test( 'the content type decides which body template is sent', async ( { page } ) => {
		await openSettings( page );
		await page.locator( '#wp_email_sending_contenttype' ).selectOption( 'text/plain' );
		await saveSettings( page );

		await visitor.goto( emailPageUrl( post ) );
		await fillForm( visitor );
		await expect( visitor.locator( '#wp-email-content' ) ).toContainText( 'has been sent to' );

		const plain = lastMail();

		expect( plain.headers.join( ' ' ) ).toContain( 'text/plain' );
		expect( plain.message ).not.toContain( '<p>' );
		expect( plain.message ).toContain( 'The body of the article.' );

		// And back the other way, so the setting is shown to be what decides it.
		await openSettings( page );
		await page.locator( '#wp_email_sending_contenttype' ).selectOption( 'text/html' );
		await saveSettings( page );

		await visitor.goto( emailPageUrl( post ) );
		await fillForm( visitor );
		await expect( visitor.locator( '#wp-email-content' ) ).toContainText( 'has been sent to' );

		const html = lastMail();

		expect( html.headers.join( ' ' ) ).toContain( 'text/html' );
		expect( html.message ).toContain( '<p>' );
	} );

	test( 'the word limit cuts the article down before it is sent', async ( {
		page,
		requestUtils,
	} ) => {
		const wordy = await createEmailablePost(
			requestUtils,
			uniqueTitle( 'A long one' ),
			'alpha bravo charlie delta echo foxtrot golf hotel india juliet',
		);

		await openSettings( page );
		await page.locator( '#wp_email_sending_snippet' ).fill( '3' );
		await saveSettings( page );

		expect( setting( 'sending', 'snippet' ) ).toBe( '3' );

		await visitor.goto( emailPageUrl( wordy ) );
		await fillForm( visitor );
		await expect( visitor.locator( '#wp-email-content' ) ).toContainText( 'has been sent to' );

		const mail = lastMail();

		expect( mail.message ).toContain( 'alpha' );
		expect( mail.message ).not.toContain( 'juliet' );
	} );

	test( 'the recipient limit is what the form and the endpoint both enforce', async ( {
		page,
	} ) => {
		await openSettings( page );
		await page.locator( '#wp_email_sending_multiple' ).fill( '2' );
		await saveSettings( page );

		expect( setting( 'sending', 'multiple' ) ).toBe( '2' );

		await visitor.goto( emailPageUrl( post ) );

		// The hint beside the field is built from the same number, so a limit the
		// form does not mention is a limit nobody finds out about until they hit it.
		await expect( visitor.locator( '#wp-email-content' ) ).toContainText(
			'Maximum 2 entries.',
		);

		let message = '';
		visitor.once( 'dialog', async ( dialog ) => {
			message = dialog.message();
			await dialog.dismiss();
		} );

		await fillForm( visitor, {
			friendname: 'One, Two, Three',
			friendemail: 'one@example.com, two@example.com, three@example.com',
		} );

		await expect.poll( () => message ).toContain( 'Maximum 2 Friend' );
		expect( lastMail() ).toBeNull();
	} );

	test( 'a template saved on the screen is the template that is sent', async ( { page } ) => {
		const marker = uniqueTitle( 'Subject from the settings screen' );

		await openSettings( page, 'templates' );
		await page.locator( '#wp_email_template_subject' ).fill( `${ marker } %EMAIL_POST_TITLE%` );
		await page.locator( '#wp_email_template_sentsuccess' ).fill( '<p>Off it goes.</p>' );
		await saveSettings( page );

		await visitor.goto( emailPageUrl( post ) );
		await fillForm( visitor );

		// One template that only exists inside the message and one that only
		// exists on the screen, so both ends of the template system are covered.
		await expect( visitor.locator( '#wp-email-content' ) ).toContainText( 'Off it goes.' );
		expect( lastMail().subject ).toBe( `${ marker } ${ post.title.raw }` );
	} );

	test( 'the subject template may not carry markup and the body may', async ( { page } ) => {
		await openSettings( page, 'templates' );

		// The subject lands in a mail header, where a tag is either meaningless
		// or an injection; the body is HTML by design.
		await page
			.locator( '#wp_email_template_subject' )
			.fill( 'Read <strong>this</strong><script>window.__pwned = 1;</script>' );
		await page.locator( '#wp_email_template_body' ).fill( '<p>Body <strong>markup</strong></p>' );
		await saveSettings( page );

		expect( template( 'subject' ) ).toBe( 'Read this' );
		expect( template( 'body' ) ).toBe( '<p>Body <strong>markup</strong></p>' );
	} );

	test( 'Restore Default Template puts the shipped text back in the field', async ( { page } ) => {
		await openSettings( page, 'templates' );

		await page.locator( '#wp_email_template_sentfailed' ).fill( 'nothing here' );
		await page
			.locator( '[data-wp-email-restore="wp_email_template_sentfailed"]' )
			.click();

		// The button is pure client-side: it writes the default the server put in
		// a data attribute, and it does not save. Both halves matter -- the field
		// changes, and the stored value has not.
		await expect( page.locator( '#wp_email_template_sentfailed' ) ).toHaveValue(
			/An error has occurred when trying to send this email\./,
		);
		expect( template( 'sentfailed' ) ).toContain(
			'An error has occurred when trying to send this email.',
		);
	} );

	test( 'the trusted-header setting only accepts a header name', async ( { page } ) => {
		await openSettings( page );
		await page.locator( '#wp_email_sending_ip_header' ).fill( 'HTTP_X_FORWARDED_FOR' );
		await saveSettings( page );

		expect( setting( 'sending', 'ip_header' ) ).toBe( 'HTTP_X_FORWARDED_FOR' );

		// Anything that is not a header name is dropped rather than stored: the
		// value is used to index $_SERVER, and the flood interval is keyed on what
		// comes back from it.
		await page.locator( '#wp_email_sending_ip_header' ).fill( 'not a header; drop table' );
		await saveSettings( page );

		expect( setting( 'sending', 'ip_header' ) ).toBe( '' );
	} );

	test( 'the WP-Stats toggle and its row limit both save, and the toggle unticks', async ( {
		page,
	} ) => {
		await openSettings( page );

		// An unticked checkbox posts nothing at all, so "off" is only
		// distinguishable from "this form was not submitted" because the rest of
		// the screen always posts. Unticking it and getting a stored 0 back is
		// the test of that.
		await page.locator( '#wp_email_stats_display' ).uncheck();
		await page.locator( '#wp_email_stats_most_limit' ).fill( '25' );
		await saveSettings( page );

		await expect( page.locator( '#wp_email_stats_display' ) ).not.toBeChecked();
		await expect( page.locator( '#wp_email_stats_most_limit' ) ).toHaveValue( '25' );

		await page.locator( '#wp_email_stats_display' ).check();
		await saveSettings( page );

		await expect( page.locator( '#wp_email_stats_display' ) ).toBeChecked();
	} );

	test( 'the settings screen is shut to an editor and open to an admin', async ( {
		page,
		requestUtils,
	} ) => {
		// The logs screen takes the plugin's own capability and the settings take
		// manage_options, so the two have to part company for exactly this user.
		const editor = await logInAs( page, requestUtils, 'email_editor', 'editor' );

		await editor.page.goto( SETTINGS_URL );
		await expect( editor.page.locator( 'body' ) ).toContainText(
			/not allowed to access this page|do not have permission/,
		);

		await page.goto( SETTINGS_URL );
		await expect(
			page.getByRole( 'heading', { name: 'E-Mail Settings' } ).first(),
		).toBeVisible();

		await editor.context.close();
	} );
} );
