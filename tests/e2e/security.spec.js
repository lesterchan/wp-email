/**
 * Stored and reflected markup, in a real browser.
 *
 * Two different problems live here, and they need different fixtures.
 *
 * The log holds five attacker-supplied strings per row -- both names, both
 * addresses and the remark -- written by whoever filled the form in. §7.2.4 says
 * to put the payloads into the row unsanitised, because that is the row a
 * compromised or pre-fix install already has, and then look at every surface
 * that renders them.
 *
 * The form is the other half. It comes back holding whatever the visitor typed
 * when a field is rejected, which is a reflected surface rather than a stored
 * one: nothing is in the database yet, and the value is echoed into an attribute
 * one moment after it arrived.
 *
 * The assertion is the same everywhere and has two halves: the sentinel the
 * payload would set is never defined, and the payload's text is still there.
 * Escaping that ate the value entirely passes the first half and is its own bug,
 * because a form that quietly empties a field the visitor is being asked to
 * correct is a form nobody can get past.
 */

const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );
const {
	LOGS_URL,
	anonymously,
	createEmailablePost,
	createLog,
	emailPageUrl,
	installMailInterceptor,
	lastMail,
	resetPlugin,
	setSetting,
	uniqueTitle,
	usePrettyPermalinks,
	wpEval,
} = require( './helpers.js' );

const SCRIPT_PAYLOAD = '<script>window.__pwned = 1;</script>';
const IMG_PAYLOAD = '<img src=x onerror="window.__pwned = 1">';
const ATTR_PAYLOAD = '" onmouseover="window.__pwned = 1';

/**
 * Whether any payload managed to run.
 *
 * @param {import('@playwright/test').Page} page Page to ask.
 * @return {Promise<boolean>} True if the sentinel was set.
 */
function pwned( page ) {
	return page.evaluate( () => window.__pwned === 1 );
}

test.describe( 'Stored markup stays inert', () => {
	let post;

	test.beforeAll( async () => {
		installMailInterceptor();
		usePrettyPermalinks();
	} );

	test.beforeEach( async ( { requestUtils } ) => {
		resetPlugin();
		setSetting( 'sending', 'imageverify', '0' );
		await requestUtils.deleteAllPosts();

		post = await createEmailablePost( requestUtils, uniqueTitle( 'A perfectly ordinary post' ) );
	} );

	test.afterAll( async () => {
		resetPlugin();
	} );

	test( 'the fixture really is a log row stored exactly as written, unsanitised', async () => {
		createLog( {
			postId: post.id,
			postTitle: `Title ${ SCRIPT_PAYLOAD }`,
			yourName: `Sender ${ IMG_PAYLOAD }`,
		} );

		// If the fixture builder were quietly cleaning the payload, every
		// assertion below would pass while testing nothing at all.
		expect(
			wpEval(
				`global $wpdb;
				echo '<<<' . $wpdb->get_var( "SELECT email_posttitle FROM {$wpdb->email} ORDER BY email_id DESC LIMIT 1" ) . '>>>';`,
			),
		).toContain( SCRIPT_PAYLOAD );
	} );

	test( 'the log screen renders a hostile row as text', async ( { page } ) => {
		createLog( {
			postId: post.id,
			postTitle: `Title ${ SCRIPT_PAYLOAD }`,
			yourName: `Sender ${ IMG_PAYLOAD }`,
			yourEmail: `sender${ ATTR_PAYLOAD }@example.com`,
			friendName: `Friend ${ ATTR_PAYLOAD }`,
			friendEmail: 'friend@example.com',
			ip: `203.0.113.7 ${ SCRIPT_PAYLOAD }`,
		} );

		await page.goto( LOGS_URL );

		expect( await pwned( page ) ).toBe( false );

		// As text, not merely absent: every one of those columns still says what
		// the row says.
		await expect( page.locator( '.wp-list-table' ) ).toContainText( 'window.__pwned' );
		await expect( page.locator( '.wp-list-table' ) ).toContainText( 'onmouseover' );
		await expect( page.locator( '#wpbody-content img[onerror]' ) ).toHaveCount( 0 );
		await expect( page.locator( '.wp-list-table script' ) ).toHaveCount( 0 );
	} );

	test( 'the most-emailed widget renders a hostile post title as text', async ( {
		page,
		requestUtils,
	} ) => {
		// The title is read back from wp_posts rather than from the log, so the
		// payload goes in there -- the row an editor with unfiltered_html, a
		// restored backup or an import can produce.
		const hostile = await requestUtils.createPost( {
			title: uniqueTitle( 'Hostile headline' ),
			content: 'Body.',
			status: 'publish',
		} );

		const encoded = Buffer.from(
			`${ uniqueTitle( 'Hostile headline' ) } ${ SCRIPT_PAYLOAD } ${ IMG_PAYLOAD }`,
			'utf8',
		).toString( 'base64' );

		wpEval(
			`global $wpdb;
			$wpdb->update( $wpdb->posts, array( 'post_title' => base64_decode( '${ encoded }' ) ), array( 'ID' => ${ hostile.id } ) );
			clean_post_cache( ${ hostile.id } );
			update_option(
				'widget_email',
				array(
					2              => array( 'title' => 'Most emailed', 'type' => 'most_emailed', 'mode' => 'both', 'limit' => 5, 'chars' => 0 ),
					'_multiwidget' => 1,
				)
			);
			$sidebars = (array) get_option( 'sidebars_widgets', array() );
			$sidebars['sidebar-1'] = array( 'email-2' );
			update_option( 'sidebars_widgets', $sidebars );
			echo '<<<done>>>';`,
		);

		createLog( { postId: hostile.id } );

		// Viewed from a page rather than from the hostile post itself. WordPress
		// prints a post title raw wherever it prints one -- the heading, the
		// adjacent-post navigation -- so a page that shows the hostile post's own
		// title would set the sentinel through core rather than through anything
		// this plugin did. A plain page shows only the sidebar.
		const plain = await requestUtils.createPage( {
			title: uniqueTitle( 'Somewhere with a sidebar' ),
			content: 'Nothing here but the widgets.',
			status: 'publish',
		} );

		await page.goto( plain.link );

		expect( await pwned( page ) ).toBe( false );

		// The widget escapes the title on the way out, so it is here as text --
		// which is stricter than core is with the same value, and is the property
		// worth pinning.
		await expect( page.locator( '.widget-area' ).first() ).toContainText( 'window.__pwned' );
		await expect( page.locator( '.widget-area img[onerror]' ) ).toHaveCount( 0 );
		await expect( page.locator( '.widget-area script' ) ).toHaveCount( 0 );

		wpEval(
			`delete_option( 'widget_email' );
			$sidebars = (array) get_option( 'sidebars_widgets', array() );
			$sidebars['sidebar-1'] = array();
			update_option( 'sidebars_widgets', $sidebars );
			echo '<<<done>>>';`,
		);
	} );

	test( 'the rejected form comes back holding the payload as text', async ( { page } ) => {
		// The reflected surface. A rejected submission is re-rendered with the
		// visitor's own values in the fields, so every one of them is echoed into
		// a value attribute -- and an attribute break is enough there.
		const { context, visitor } = await anonymously( page );

		await visitor.goto( emailPageUrl( post ) );

		const body = await visitor.evaluate(
			async ( payloads ) => {
				const form = document.querySelector( '#wp-email-content form' );
				const values = new URLSearchParams( new FormData( form ) );

				values.set( 'action', 'email' );
				values.set( 'yourname', `Alice ${ payloads.attr }` );
				values.set( 'youremail', `alice ${ payloads.script }` );
				values.set( 'yourremarks', `Remark ${ payloads.img }` );
				values.set( 'friendname', `Bob ${ payloads.attr }` );
				values.set( 'friendemail', `bob ${ payloads.script }` );

				const response = await fetch( '/wp-admin/admin-ajax.php', {
					method: 'POST',
					credentials: 'same-origin',
					headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
					body: values.toString(),
				} );

				const html = await response.text();
				const container = document.getElementById( 'wp-email-content' );

				container.innerHTML = html;

				return html;
			},
			{ script: SCRIPT_PAYLOAD, img: IMG_PAYLOAD, attr: ATTR_PAYLOAD },
		);

		expect( body ).toContain( 'is invalid' );

		// Swapped into the live page by the same code path the plugin's own
		// script uses, so this is the markup a browser really executes -- which is
		// a surface no PHPUnit test can reach.
		expect( await pwned( visitor ) ).toBe( false );
		await expect( visitor.locator( '#wp-email-content img[onerror]' ) ).toHaveCount( 0 );
		await expect( visitor.locator( '#wp-email-content script' ) ).toHaveCount( 0 );

		// And the values survived, so the visitor can correct the field rather
		// than retype it. The two attribute-breakout fields are the ones asked
		// about: sanitize_text_field() leaves quotes alone, which is what makes
		// them a reflected-attribute question at all, while it strips a tag out of
		// the other three before the form ever gets them back.
		await expect( visitor.locator( '#yourname' ) ).toHaveValue( /onmouseover/ );
		await expect( visitor.locator( '#friendname' ) ).toHaveValue( /onmouseover/ );

		expect( lastMail() ).toBeNull();

		await context.close();
	} );
} );
