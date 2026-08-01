/**
 * The link, the form and the send, as a visitor meets them.
 *
 * No message leaves the machine: an mu-plugin filters pre_wp_mail, records the
 * arguments and short-circuits the send, so "it sent" is asserted against what
 * the plugin actually handed to wp_mail() rather than against a mailbox. See
 * helpers.js.
 *
 * Every test runs in a browser with no session. The suite's own browser is an
 * administrator, whose name and address the form pre-fills from their profile,
 * and a form that arrives already filled in is not the form the validation rules
 * were written for.
 */

const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );
const {
	anonymously,
	createEmailablePost,
	emailPageUrl,
	failNextMail,
	fillForm,
	installMailInterceptor,
	lastMail,
	newestLog,
	countLogs,
	resetPlugin,
	setSetting,
	uniqueTitle,
	usePrettyPermalinks,
	wpEval,
} = require( './helpers.js' );

test.describe( 'Emailing a post to a friend', () => {
	let post;
	let visitor;
	let context;

	test.beforeAll( async () => {
		installMailInterceptor();
		usePrettyPermalinks();
	} );

	test.beforeEach( async ( { page, requestUtils } ) => {
		resetPlugin();
		await requestUtils.deleteAllPosts();

		// The verification image is on by default and cannot be read by a test,
		// so it is switched off wherever it is not the thing under test. It has a
		// test of its own further down.
		setSetting( 'sending', 'imageverify', '0' );

		// And the flood interval off. It ships at ten minutes, which is right for
		// a real site and wrong for a test that sends twice to compare two
		// outcomes -- the second send would be refused for a reason the test is
		// not about. The interval has a test of its own, which switches it back on.
		setSetting( 'sending', 'interval', '0' );

		post = await createEmailablePost( requestUtils, uniqueTitle( 'Worth passing on' ) );

		( { context, visitor } = await anonymously( page ) );
	} );

	test.afterEach( async () => {
		await context.close();
	} );

	test.afterAll( async () => {
		resetPlugin();
	} );

	test( 'the fixture really is a published post carrying a working e-mail link', async () => {
		// The precondition the whole file rests on: the shortcode rendered, the
		// link points at the plugin's own endpoint, and that endpoint answers.
		await visitor.goto( post.link );

		const link = visitor.locator( '.entry-content a[href$="/email/"]' ).first();

		await expect( link ).toHaveCount( 1 );

		await link.click();

		await expect( visitor.locator( '#wp-email-content' ) ).toBeVisible();
		await expect( visitor.locator( '#friendemail' ) ).toBeVisible();
	} );

	test( 'the e-mail page carries the post title, the form and a noindex tag', async () => {
		await visitor.goto( emailPageUrl( post ) );

		// The title template replaces the post title on the page, which is the
		// one thing that says the plugin took the request over rather than the
		// theme rendering the post as usual.
		await expect( visitor.locator( 'body' ) ).toContainText(
			`E-Mail '${ post.title.raw }' To A Friend`,
		);
		await expect( visitor.locator( 'body' ) ).toContainText(
			`Email a copy of '${ post.title.raw }' to a friend`,
		);
		await expect( visitor.locator( 'meta[name="robots"][content*="noindex"]' ) ).toHaveCount( 1 );
		await expect( visitor.locator( 'link[href*="wp-email.css"]' ) ).toHaveCount( 1 );
	} );

	test( 'a filled-in form sends the article and reports it', async () => {
		await visitor.goto( emailPageUrl( post ) );

		await fillForm( visitor, {
			yourname: 'Alice Sender',
			youremail: 'alice@example.com',
			yourremarks: 'You will like this one.',
			friendname: 'Bob Friend',
			friendemail: 'bob@example.com',
		} );

		await expect( visitor.locator( '#wp-email-content' ) ).toContainText( 'has been sent to' );

		// The far end is the message the plugin built, not the notice: a screen
		// that says "sent" while handing wp_mail() an empty body is the failure
		// this whole interception exists to be able to see.
		const mail = lastMail();

		expect( mail.to ).toBe( 'Bob Friend <bob@example.com>' );
		expect( mail.subject ).toContain( 'Recommended Article By Alice Sender' );
		expect( mail.subject ).toContain( post.title.raw );
		expect( mail.message ).toContain( 'You will like this one.' );
		expect( mail.message ).toContain( 'The body of the article.' );
		expect( mail.headers ).toContain( 'From: Alice Sender <alice@example.com>' );
		expect( mail.headers.join( ' ' ) ).toContain( 'text/html' );
	} );

	test( 'every send is written to the log, one row per recipient', async () => {
		setSetting( 'sending', 'multiple', '5' );

		await visitor.goto( emailPageUrl( post ) );

		await fillForm( visitor, {
			friendname: 'Bob Friend, Carol Friend',
			friendemail: 'bob@example.com, carol@example.com',
		} );

		await expect( visitor.locator( '#wp-email-content' ) ).toContainText( 'has been sent to' );

		// One message with two recipients, and one log row each -- the log is
		// per-friend, which is what makes the "most emailed" counts mean anything.
		expect( lastMail().to ).toBe( 'Bob Friend <bob@example.com>, Carol Friend <carol@example.com>' );
		expect( countLogs() ).toBe( 2 );
		expect( newestLog( 'email_friendemail' ) ).toBe( 'carol@example.com' );
		expect( newestLog( 'email_status' ) ).toBe( 'Success' );
		expect( newestLog( 'email_posttitle' ) ).toBe( post.title.raw );
	} );

	test( 'a send wp_mail refuses is reported as failed and logged as failed', async () => {
		failNextMail();

		await visitor.goto( emailPageUrl( post ) );
		await fillForm( visitor );

		await expect( visitor.locator( '#wp-email-content' ) ).toContainText(
			'An error has occurred when trying to send this email.',
		);

		// Stored untranslated, so a site that changes language can still match
		// its own historical rows.
		expect( newestLog( 'email_status' ) ).toBe( 'Failed' );

		failNextMail( false );
	} );

	test( 'the script refuses each bad field before anything is sent', async () => {
		// Eight cases, each with a reload between them so the next one is the only
		// thing wrong with the form. That is more than the default budget allows.
		test.slow();

		await visitor.goto( emailPageUrl( post ) );

		// One case per rejected input. The script puts its complaints in a
		// blocking alert, so each is read off the dialog and dismissed; nothing
		// reaches the server, which is the point of validating in the browser.
		const cases = [
			[ { yourname: '' }, 'Your Name is empty/invalid' ],
			[ { yourname: 'Bad<Name>' }, 'Your Name is empty/invalid' ],
			[ { youremail: 'not-an-address' }, 'Your Email is empty/invalid' ],
			[ { yourremarks: 'Content-Type: text/html' }, 'Your Remarks is invalid' ],
			[ { friendname: '' }, 'Friend Name(s) is empty' ],
			[ { friendemail: '' }, 'Friend Email(s) is empty' ],
			[ { friendemail: 'nope' }, 'Friend Email is invalid' ],
			[ { friendname: 'One, Two' }, 'count does not tally' ],
		];

		for ( const [ fields, expected ] of cases ) {
			let message = '';

			visitor.once( 'dialog', async ( dialog ) => {
				message = dialog.message();
				await dialog.dismiss();
			} );

			await fillForm( visitor, fields );

			await expect
				.poll( () => message, { message: JSON.stringify( fields ) } )
				.toContain( expected );

			// Put the offending field back, so the next case is the only thing
			// wrong with the form.
			await visitor.reload();
		}

		expect( lastMail() ).toBeNull();
		expect( countLogs() ).toBe( 0 );
	} );

	test( 'the server refuses the same fields when the script is bypassed', async () => {
		// The script is a convenience; the endpoint is the thing that has to hold.
		// Posting straight to admin-ajax with the form's own nonce is how a
		// request that never ran the script arrives.
		await visitor.goto( emailPageUrl( post ) );

		const body = await visitor.evaluate( async () => {
			const form = document.querySelector( '#wp-email-content form' );
			const values = new URLSearchParams( new FormData( form ) );

			values.set( 'action', 'email' );
			// A bracket rather than a tag: sanitize_text_field() strips the tag
			// before validate() ever sees it, so a payload that only offends the
			// name rule once it has been sanitized away tests nothing. A bracket
			// is on the rejected list and survives sanitizing.
			values.set( 'yourname', 'Alice (Bad)' );
			values.set( 'youremail', 'not-an-address' );
			values.set( 'yourremarks', 'X-Mailer: something' );
			values.set( 'friendname', '' );
			values.set( 'friendemail', 'also-not-an-address' );

			const response = await fetch( '/wp-admin/admin-ajax.php', {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
				body: values.toString(),
			} );

			return response.text();
		} );

		expect( body ).toContain( 'Your Name is invalid' );
		expect( body ).toContain( 'Your Email is invalid' );
		expect( body ).toContain( 'Your Remarks is invalid' );
		expect( body ).toContain( 'Friend Name(s) is empty' );
		expect( body ).toContain( 'Friend Email is invalid' );

		expect( lastMail() ).toBeNull();
		expect( countLogs() ).toBe( 0 );
	} );

	test( 'a bad nonce is refused outright', async () => {
		// Navigated first, so the fetch below has an origin to resolve against
		// and arrives with the site's cookies.
		await visitor.goto( emailPageUrl( post ) );

		const body = await visitor.evaluate( async () => {
			const values = new URLSearchParams( {
				action: 'email',
				'wp-email_nonce': 'not-a-nonce',
				friendemail: 'bob@example.com',
			} );

			const response = await fetch( '/wp-admin/admin-ajax.php', {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
				body: values.toString(),
			} );

			return response.text();
		} );

		expect( body ).toContain( 'Failed To Verify Referrer' );
		expect( lastMail() ).toBeNull();
	} );

	test( 'the flood interval stops a second send and says how long to wait', async () => {
		setSetting( 'sending', 'interval', '10' );

		await visitor.goto( emailPageUrl( post ) );
		await fillForm( visitor );
		await expect( visitor.locator( '#wp-email-content' ) ).toContainText( 'has been sent to' );

		// Nothing on the client knows about the interval, so this is the server
		// refusing on its own -- and the form comes back saying how long.
		await visitor.goto( emailPageUrl( post ) );

		await expect( visitor.locator( '#wp-email-content' ) ).toContainText(
			'Please wait for 10 Minutes',
		);
		await expect( visitor.locator( '#friendemail' ) ).toHaveCount( 0 );

		// And zero means no interval at all, which is the documented way off.
		setSetting( 'sending', 'interval', '0' );
		await visitor.goto( emailPageUrl( post ) );
		await expect( visitor.locator( '#friendemail' ) ).toBeVisible();
	} );

	test( 'the verification image is issued, drawn and checked', async () => {
		setSetting( 'sending', 'imageverify', '1' );

		await visitor.goto( emailPageUrl( post ) );

		const token = await visitor.locator( '#imageverify_token' ).inputValue();

		expect( token ).toHaveLength( 32 );

		// The image endpoint only draws a challenge that was issued with a form,
		// which is the whole of the 3.0.0 rewrite: no PHP session, one challenge
		// per rendered form, and a token nobody issued gets nothing.
		const image = await visitor.request.get(
			`/wp-admin/admin-ajax.php?action=wp_email_captcha&token=${ token }`,
		);

		expect( image.status() ).toBe( 200 );
		expect( image.headers()[ 'content-type' ] ).toContain( 'image/jpeg' );

		const unissued = await visitor.request.get(
			`/wp-admin/admin-ajax.php?action=wp_email_captcha&token=${ 'a'.repeat( 32 ) }`,
		);

		expect( unissued.status() ).toBe( 404 );

		// A wrong answer is refused by the server -- the script only checks that
		// the box is not empty, so this branch is unreachable from the client.
		await fillForm( visitor, { submit: false } );
		await visitor.locator( '#imageverify' ).fill( 'ZZZZZ' );
		await visitor.locator( '#wp-email-submit' ).click();

		await expect( visitor.locator( '#wp-email-content' ) ).toContainText(
			'Image Verification failed',
		);
		expect( lastMail() ).toBeNull();

		// And the right answer goes through, so the refusal above is the check
		// doing its job rather than a challenge nothing can ever satisfy. A fresh
		// form, because verify() consumes a challenge whether or not the answer
		// was right -- which is what stops a five-character code being guessed at
		// leisure.
		await visitor.goto( emailPageUrl( post ) );

		const fresh = await visitor.locator( '#imageverify_token' ).inputValue();
		const code = wpEval(
			`echo '<<<' . get_transient( 'wp_email_captcha_' . '${ fresh }' ) . '>>>';`,
		);

		expect( code ).toHaveLength( 5 );

		await fillForm( visitor, { submit: false } );
		await visitor.locator( '#imageverify' ).fill( code );
		await visitor.locator( '#wp-email-submit' ).click();

		await expect( visitor.locator( '#wp-email-content' ) ).toContainText( 'has been sent to' );
	} );

	test( 'the popup endpoint renders the form outside the theme', async () => {
		await visitor.goto( emailPageUrl( post, 'emailpopup' ) );

		await expect( visitor.locator( 'body.wp-email-popup-body' ) ).toHaveCount( 1 );
		await expect( visitor.locator( '#wp-email-popup #friendemail' ) ).toBeVisible();
		await expect( visitor.getByRole( 'link', { name: 'Close This Window' } ) ).toHaveCount( 1 );

		// The theme's own header is deliberately absent: this renders inside a
		// small window.open() popup rather than through the template hierarchy.
		await expect( visitor.locator( '#wpadminbar' ) ).toHaveCount( 0 );
		await expect( visitor.locator( 'meta[name="robots"][content*="noindex"]' ) ).toHaveCount( 1 );
	} );

	test( 'the query-argument URL works where there are no pretty permalinks', async () => {
		// The documented fallback for a site with plain permalinks, where no
		// rewrite endpoint can match at all. Restored afterwards, because the
		// permalink structure is global and the rest of the suite needs it.
		wpEval(
			`global $wp_rewrite;
			$wp_rewrite->set_permalink_structure( '' );
			$wp_rewrite->flush_rules( true );
			echo '<<<' . get_option( 'permalink_structure' ) . '>>>';`,
		);

		await visitor.goto( `/?p=${ post.id }` );

		const link = visitor.locator( '.entry-content a[href*="wp_email=1"]' ).first();
		await expect( link ).toHaveCount( 1 );

		await visitor.goto( `/?p=${ post.id }&wp_email=1` );
		await expect( visitor.locator( '#friendemail' ) ).toBeVisible();

		usePrettyPermalinks();
	} );

	test( 'the donotemail shortcode keeps its content off the message', async ( {
		requestUtils,
	} ) => {
		const marked = await requestUtils.createPost( {
			title: uniqueTitle( 'Partly private' ),
			content: 'Everyone may read this. [donotemail]But not this bit.[/donotemail]\n\n[email_link]',
			status: 'publish',
		} );

		// Both halves: the page shows it, the message does not. Either on its own
		// passes for a shortcode that was simply never registered.
		await visitor.goto( marked.link );
		await expect( visitor.locator( '.entry-content' ) ).toContainText( 'But not this bit.' );

		await visitor.goto( emailPageUrl( marked ) );
		await fillForm( visitor );
		await expect( visitor.locator( '#wp-email-content' ) ).toContainText( 'has been sent to' );

		const mail = lastMail();

		expect( mail.message ).toContain( 'Everyone may read this.' );
		expect( mail.message ).not.toContain( 'But not this bit.' );
	} );

	test( 'the post meta overrides the title and pre-fills the remark', async ( {
		requestUtils,
	} ) => {
		const overridden = await requestUtils.createPost( {
			title: uniqueTitle( 'The stored title' ),
			content: 'Body.\n\n[email_link]',
			status: 'publish',
			meta: {},
		} );

		wpEval(
			`update_post_meta( ${ overridden.id }, 'wp-email-title', 'A better headline for e-mail' );
			update_post_meta( ${ overridden.id }, 'wp-email-remark', 'The author suggested remark.' );
			echo '<<<done>>>';`,
		);

		await visitor.goto( emailPageUrl( overridden ) );

		await expect( visitor.locator( 'body' ) ).toContainText( 'A better headline for e-mail' );
		await expect( visitor.locator( '#yourremarks' ) ).toHaveValue(
			'The author suggested remark.',
		);
	} );

	test( 'a logged-in visitor gets their own name and address filled in', async ( {
		page,
	} ) => {
		// The administrator this suite runs as, on the same form the anonymous
		// visitor above got empty. Both directions, so the pre-fill is shown to be
		// about the session rather than about a value baked into the markup.
		await page.goto( emailPageUrl( post ) );

		await expect( page.locator( '#yourname' ) ).toHaveValue( 'admin' );
		await expect( page.locator( '#youremail' ) ).not.toHaveValue( '' );

		await visitor.goto( emailPageUrl( post ) );
		await expect( visitor.locator( '#yourname' ) ).toHaveValue( '' );
	} );
} );
