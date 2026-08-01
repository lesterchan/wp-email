/**
 * The log screen, and the counts hanging off it.
 *
 * The log is what the plugin has instead of a mailbox: it is where a site owner
 * goes to answer "did it send?", and it is the only record of who was sent what.
 * So these tests care about three things -- that every column shows what it
 * claims to, that the totals under the table agree with the rows in it, and that
 * "delete every row" is gated twice and holds both ways.
 */

const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );
const {
	LOGS_URL,
	countLogs,
	createLog,
	installMailInterceptor,
	logInAs,
	resetPlugin,
	uniqueTitle,
	wpEval,
} = require( './helpers.js' );

test.describe( 'The e-mail log', () => {
	let post;

	test.beforeAll( async () => {
		installMailInterceptor();
	} );

	test.beforeEach( async ( { requestUtils } ) => {
		resetPlugin();
		await requestUtils.deleteAllPosts();

		post = await requestUtils.createPost( {
			title: uniqueTitle( 'The logged article' ),
			content: 'Body.',
			status: 'publish',
		} );
	} );

	test.afterAll( async () => {
		resetPlugin();
	} );

	test( 'the fixture really is an empty log that says so', async ( { page } ) => {
		// The precondition every count below leans on. A log left full by the last
		// run would make "three e-mails, two sent, one failed" pass or fail for
		// reasons that have nothing to do with this test.
		expect( countLogs() ).toBe( 0 );

		await page.goto( LOGS_URL );

		await expect( page.getByRole( 'heading', { name: 'Manage E-Mail' } ) ).toBeVisible();
		await expect( page.locator( '.wp-list-table' ) ).toContainText( 'No E-Mail Logs Found' );
	} );

	test( 'each row shows both parties, the post, the address it came from and the outcome', async ( {
		page,
	} ) => {
		createLog( {
			postId: post.id,
			postTitle: post.title.raw,
			yourName: 'Alice Sender',
			yourEmail: 'alice@example.com',
			friendName: 'Bob Friend',
			friendEmail: 'bob@example.com',
			ip: '203.0.113.7',
			status: 'Success',
		} );

		await page.goto( LOGS_URL );

		const row = page.locator( '.wp-list-table tbody tr' ).first();

		await expect( row ).toContainText( 'Alice Sender' );
		await expect( row ).toContainText( 'alice@example.com' );
		await expect( row ).toContainText( 'Bob Friend' );
		await expect( row ).toContainText( 'bob@example.com' );
		await expect( row ).toContainText( '203.0.113.7' );
		await expect( row ).toContainText( post.title.raw );
		await expect( row ).toContainText( 'Success' );
	} );

	test( 'the totals under the table agree with the rows in it', async ( { page } ) => {
		createLog( { postId: post.id, status: 'Success' } );
		createLog( { postId: post.id, status: 'Success' } );
		createLog( { postId: post.id, status: 'Failed' } );

		await page.goto( LOGS_URL );

		const stats = page.locator( 'h2', { hasText: 'E-Mail Logs Stats' } ).locator( '+ table' );

		await expect( stats.locator( 'tr', { hasText: 'Total E-Mails:' } ) ).toContainText( '3' );
		await expect( stats.locator( 'tr', { hasText: 'Total E-Mail Sent:' } ) ).toContainText( '2' );
		await expect( stats.locator( 'tr', { hasText: 'Total E-Mail Failed:' } ) ).toContainText(
			'1',
		);
	} );

	test( 'the log sorts by the column headed, in both directions', async ( { page } ) => {
		createLog( { postId: post.id, friendName: 'Aaron First', ageInDays: 2 } );
		createLog( { postId: post.id, friendName: 'Zoe Last', ageInDays: 1 } );

		await page.goto( LOGS_URL );

		// The header link, not the footer's copy of it: core prints the sortable
		// columns at both ends of the table, so a bare columnheader matches twice.
		const sortByRecipient = page.locator( 'thead #to a' );

		await sortByRecipient.click();
		await expect( page.locator( '.wp-list-table tbody tr' ).first() ).toContainText(
			'Aaron First',
		);

		await sortByRecipient.click();
		await expect( page.locator( '.wp-list-table tbody tr' ).first() ).toContainText( 'Zoe Last' );
	} );

	test( 'the log pages at the size Screen Options asks for', async ( { page } ) => {
		for ( let i = 0; i < 3; i++ ) {
			createLog( { postId: post.id, friendEmail: `friend${ i }@example.com` } );
		}

		await page.goto( LOGS_URL );

		await page.getByRole( 'button', { name: 'Screen Options' } ).click();
		await page.locator( '#wp_email_logs_per_page' ).fill( '2' );
		await page.locator( '#screen-options-apply' ).click();

		await expect( page.locator( '.wp-list-table tbody tr' ) ).toHaveCount( 2 );
		await expect( page.locator( '.tablenav-pages .total-pages' ).first() ).toHaveText( '2' );

		// Per user, not per site, which is what makes it worth putting back.
		expect(
			wpEval(
				`echo '<<<' . get_user_meta( get_current_user_id(), 'wp_email_logs_per_page', true ) . '>>>';`,
			),
		).toBe( '2' );

		wpEval(
			`delete_user_meta( get_current_user_id(), 'wp_email_logs_per_page' );
			echo '<<<done>>>';`,
		);
	} );

	test( 'deleting every row needs the confirm and the tick, and both of them hold', async ( {
		page,
	} ) => {
		createLog( { postId: post.id } );
		createLog( { postId: post.id } );

		await page.goto( LOGS_URL );

		// A dismissed confirm that deleted anyway would be worse than no confirm.
		page.once( 'dialog', ( dialog ) => dialog.dismiss() );
		await page.getByRole( 'button', { name: 'Delete', exact: true } ).click();
		expect( countLogs() ).toBe( 2 );

		// Accepting the confirm without ticking the box is the second gate, and
		// the screen says which one stopped it.
		page.once( 'dialog', ( dialog ) => dialog.accept() );
		await page.getByRole( 'button', { name: 'Delete', exact: true } ).click();

		await expect( page.locator( '.notice-warning' ) ).toContainText(
			'the confirmation box was not ticked',
		);
		expect( countLogs() ).toBe( 2 );

		// Both together, which is the only way through.
		await page.locator( 'input[name="delete_logs_yes"]' ).check();
		page.once( 'dialog', ( dialog ) => dialog.accept() );
		await page.getByRole( 'button', { name: 'Delete', exact: true } ).click();

		await expect( page.locator( '.notice-success' ) ).toContainText(
			'All E-Mail Logs Have Been Deleted.',
		);
		expect( countLogs() ).toBe( 0 );
	} );

	test( 'the remarks column is on by default and the constant takes it away', async ( {
		page,
	} ) => {
		// A remark is something a stranger typed about somebody else's reading
		// habits. The plugin shows it, and gives a site one constant to say it
		// would rather not -- so both sides are asked, because "the column is
		// there" and "the constant hides it" are two different claims and either
		// one alone passes for the wrong reason.
		createLog( { postId: post.id, remarks: 'A private note about the reader.' } );

		await page.goto( LOGS_URL );

		await expect( page.getByRole( 'columnheader', { name: 'Remarks' } ).first() ).toBeVisible();
		await expect( page.locator( '.wp-list-table' ) ).toContainText(
			'A private note about the reader.',
		);

		// Defined from an mu-plugin, which is the only place a constant can be set
		// before the plugin reads it without editing wp-config.php.
		wpEval(
			`$dir = defined( 'WPMU_PLUGIN_DIR' ) ? WPMU_PLUGIN_DIR : WP_CONTENT_DIR . '/mu-plugins';
			file_put_contents( $dir . '/wp-email-e2e-remarks.php', "<?php\ndefine( 'WP_EMAIL_SHOW_REMARKS', false );\n" );
			echo '<<<done>>>';`,
		);

		await page.goto( LOGS_URL );

		await expect( page.getByRole( 'columnheader', { name: 'Remarks' } ) ).toHaveCount( 0 );
		await expect( page.locator( '.wp-list-table' ) ).not.toContainText(
			'A private note about the reader.',
		);

		wpEval(
			`$dir = defined( 'WPMU_PLUGIN_DIR' ) ? WPMU_PLUGIN_DIR : WP_CONTENT_DIR . '/mu-plugins';
			wp_delete_file( $dir . '/wp-email-e2e-remarks.php' );
			echo '<<<done>>>';`,
		);
	} );

	test( 'the Logs screen is shut to a subscriber and open to an admin', async ( {
		page,
		requestUtils,
	} ) => {
		// Both directions on purpose. "The subscriber sees nothing" passes with
		// the plugin deactivated; the admin half is what proves the gate is the
		// capability and not a missing page.
		await page.goto( '/wp-admin/index.php' );
		await expect( page.locator( '#adminmenu' ) ).toContainText( 'WP-EMail' );

		await page.goto( LOGS_URL );
		await expect( page.getByRole( 'heading', { name: 'Manage E-Mail' } ) ).toBeVisible();

		const visitor = await logInAs( page, requestUtils, 'email_subscriber', 'subscriber' );

		await visitor.page.goto( '/wp-admin/index.php' );
		await expect( visitor.page.locator( '#adminmenu' ).getByText( 'WP-EMail' ) ).toHaveCount( 0 );

		await visitor.page.goto( LOGS_URL );
		await expect( visitor.page.locator( 'body' ) ).toContainText(
			/not allowed to access this page|do not have permission/,
		);

		await visitor.context.close();
	} );
} );
