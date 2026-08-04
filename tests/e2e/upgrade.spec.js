/**
 * The pre-3.0.0 migration, run the way a real site runs it.
 *
 * Activation does not fire when a plugin is merely updated -- a site that
 * updates from the Plugins screen never calls activate() -- so maybe_upgrade()
 * also hangs off admin_init. That is the hook every real upgrade goes through,
 * and loading an admin page in a browser is the only way to reach it.
 *
 * There are two migrations here and they are gated separately, which is the
 * thing this file exists to hold still:
 *
 *   * with no schema counter at all, sixteen unprefixed rows fold into one;
 *   * below counter 2, the link settings collapse -- a style out of four and two
 *     link labels become the single HTML template 3.0.0 keeps.
 *
 * The second is the one only a browser can answer. Lester's ask #18 was
 * explicit that a 2.69.3 site has to come out of the upgrade rendering what it
 * was rendering before, and "the same link" is a question about a page: the
 * words, the glyph, and whether the two survived being turned into markup.
 *
 * Every row is read *raw*. WP_Email_Options::all() merges over the defaults, so
 * it answers identically for a row holding the defaults and for no row at all --
 * the §7.6.1 failure exactly. Ask the database, not the plugin.
 */

const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );
const {
	SETTINGS_URL,
	createEmailablePost,
	defaultOptions,
	installLegacyRows,
	rawOptions,
	resetPlugin,
	runningVersions,
	setVersionRow,
	survivingLegacyRows,
	uniqueTitle,
	versionRow,
	wpEval,
} = require( './helpers.js' );

/** The Dashboard: an ordinary admin request, which is what an update goes through. */
const DASHBOARD_URL = '/wp-admin/index.php';

/**
 * The rows a 2.69.3 install carries, in the shapes it wrote them.
 *
 * One of each kind the migration handles differently: the flat email_options
 * row that held the link settings, a companion row holding a scalar, a template
 * stored slashed by the old options screen, and the field list.
 *
 * @param {Object} overrides Anything this particular site had changed.
 * @return {Object} Legacy option name => value.
 */
function legacyInstall( overrides = {} ) {
	return {
		email_options: {
			post_text: 'Email This Post',
			page_text: 'Email This Page',
			email_style: 1,
			email_type: 1,
			ip_header: 'HTTP_X_FORWARDED_FOR',
		},
		email_contenttype: 'text/html',
		email_interval: 60,
		email_fields: { yourname: 1, youremail: 1, yourremarks: 0, friendname: 1 },
		email_template_title: "It\\'s from %BLOG_NAME%",
		email_db_version: '2.69.3',
		...overrides,
	};
}

test.describe( 'The pre-3.0.0 upgrade', () => {
	test.afterEach( async () => {
		// Back to a current install: markers stamped, settings at a fresh
		// install's, no legacy rows anywhere. Every other spec in this suite
		// starts from that, and this is the only file that takes it apart.
		wpEval(
			`foreach ( WP_Email_Options::LEGACY_ROWS as $row ) {
				delete_option( $row );
			}
			delete_option( 'stats_display' );
			delete_option( 'stats_mostlimit' );
			echo '<<<done>>>';`,
		);
		setVersionRow( runningVersions() );
		resetPlugin();
	} );

	test( 'the scattered rows fold into one, every old row goes, and the markers are stamped', async ( {
		page,
	} ) => {
		installLegacyRows( legacyInstall() );

		// The fixture really is a pre-3.0.0 install: old rows present, new ones
		// absent. Without this the assertions below could be describing a site
		// that was already migrated, and would pass with the fold-in deleted.
		expect( survivingLegacyRows() ).toContain( 'email_options' );
		expect( rawOptions() ).toBe( false );
		expect( versionRow() ).toBe( false );

		await page.goto( DASHBOARD_URL );

		const stored = rawOptions();

		// Written, not merely readable through the defaults.
		expect( stored ).not.toBe( false );
		expect( stored.sending.contenttype ).toBe( 'text/html' );
		expect( stored.sending.interval ).toBe( 60 );
		expect( stored.sending.ip_header ).toBe( 'HTTP_X_FORWARDED_FOR' );
		expect( stored.fields.yourremarks ).toBe( 0 );

		// The template comes across unslashed: the old options screen ran every
		// value through addslashes() on the way in, so a row corrected here is
		// one the new code can read straight.
		expect( stored.templates.title ).toContain( "It's from" );
		expect( stored.templates.title ).not.toContain( '\\' );

		// Every old row gone rather than left to rot, read through the plugin's
		// own list so a row added to the migration and forgotten by the cleanup
		// shows up here rather than going unnoticed.
		expect( survivingLegacyRows() ).toEqual( [] );

		// One write, both markers, matching the code that is running.
		expect( versionRow() ).toEqual( runningVersions() );
	} );

	test( 'a stock 2.69.3 link renders the same words it always did', async ( {
		page,
		requestUtils,
	} ) => {
		// The commonest install of all: never touched a link setting in twenty
		// years. Its two labels said Post on posts and Page on pages, and one
		// template with the post type in it says both -- which is the whole
		// reason four settings could become one.
		installLegacyRows( legacyInstall() );

		await page.goto( DASHBOARD_URL );

		const stored = rawOptions();

		// The retired keys are off the row, not merely unread. A setting the
		// screen no longer draws is one the next release has to keep thinking
		// about.
		expect( stored.link.post_text ).toBeUndefined();
		expect( stored.link.page_text ).toBeUndefined();
		expect( stored.link.style ).toBeUndefined();
		expect( stored.link.html ).toBe( defaultOptions().link.html );

		// Present is not alive: the synthesised template has to be what a reader
		// is actually shown.
		const post = await createEmailablePost(
			requestUtils,
			uniqueTitle( 'Emailable after the upgrade' ),
		);

		await page.goto( post.link );

		await expect( page.locator( '.entry-content' ).getByRole( 'link', { name: /Email This/ } ) ).toBeVisible();
	} );

	test( 'a site that customised the link label keeps its own words', async ( {
		page,
		requestUtils,
	} ) => {
		// One template cannot express two arbitrary strings, so the migration
		// keeps post_text verbatim and the Upgrade Notice says the page wording
		// is lost. This is that rule, in the only place it can be seen.
		installLegacyRows(
			legacyInstall( {
				email_options: {
					post_text: 'Send this to a friend',
					page_text: 'Send this page to a friend',
					email_style: 3,
					email_type: 1,
				},
			} ),
		);

		await page.goto( DASHBOARD_URL );

		expect( rawOptions().link.html ).toContain( 'Send this to a friend' );

		const post = await createEmailablePost(
			requestUtils,
			uniqueTitle( 'Customised link after the upgrade' ),
		);

		await page.goto( post.link );

		await expect(
			page.locator( '.entry-content' ).getByRole( 'link', { name: 'Send this to a friend' } ),
		).toBeVisible();
	} );

	test( 'a site already writing its own template is left to it', async ( { page } ) => {
		// Style 4 was "use my HTML". Nothing the migration could synthesise
		// improves on markup somebody typed, and rewriting it would throw that
		// markup away.
		const html = '<p class="my-email"><a href="%EMAIL_URL%">Post this to a friend</a></p>';

		installLegacyRows(
			legacyInstall( {
				email_options: {
					post_text: 'Email This Post',
					page_text: 'Email This Page',
					email_style: 4,
					email_html: html,
				},
			} ),
		);

		await page.goto( DASHBOARD_URL );

		expect( rawOptions().link.html ).toBe( html );
	} );

	test( 'a row that still holds the retired link keys is collapsed on its own', async ( {
		page,
	} ) => {
		// The other half, and the reason there are two gates. This install has
		// been through the row rename already -- schema counter 1, no legacy
		// rows left -- and still holds the style picker and the two labels,
		// because that build had not collapsed them yet.
		const current = {
			...defaultOptions(),
			link: {
				type: 1,
				html: defaultOptions().link.html,
				post_text: 'Post it onwards',
				page_text: 'Page it onwards',
				style: 3,
			},
		};

		installLegacyRows( {}, current, { plugin: '3.0.0', db: '1' } );

		expect( rawOptions().link.post_text ).toBe( 'Post it onwards' );

		await page.goto( DASHBOARD_URL );

		const stored = rawOptions();

		expect( stored.link.post_text ).toBeUndefined();
		expect( stored.link.style ).toBeUndefined();
		expect( stored.link.html ).toContain( 'Post it onwards' );
		expect( versionRow() ).toEqual( runningVersions() );
	} );

	test( "this plugin's share of the WP-Stats rows is folded in and the rows deleted", async ( {
		page,
	} ) => {
		// The two rows as the last of the seven plugins to save that screen left
		// them. WP-EMail owned three of the toggles and contributes one section
		// now, so one question survives.
		installLegacyRows(
			legacyInstall( {
				stats_display: { email: 0, polls: 1 },
				stats_mostlimit: 4,
			} ),
		);

		await page.goto( DASHBOARD_URL );

		const stored = rawOptions();

		expect( stored.stats_display ).toBe( false );
		expect( stored.stats_most_limit ).toBe( 4 );

		// Deleted by the migration that folded them in -- and by nothing else.
		// §13.2 splits the two jobs: uninstall must leave them alone, because up
		// to five siblings that have not upgraded are still reading them.
		expect( survivingLegacyRows() ).toEqual( [] );
	} );

	test( 'an absent shared row means on, not off', async ( { page } ) => {
		// A sibling upgraded first and took the rows with it. Reading that as a
		// deliberate opt-out would take the e-mail block off the WP-Stats page
		// of any site that updated a sibling first, with nothing to say why.
		installLegacyRows( legacyInstall() );

		await page.goto( DASHBOARD_URL );

		expect( rawOptions().stats_display ).toBe( true );
	} );

	test( 'an install already on this version is left alone', async ( { page } ) => {
		// A legacy row that should never be read, alongside markers saying both
		// upgrades have already happened. maybe_upgrade() returning early is
		// what keeps every admin request from being an option write, and the
		// proof it returned early is that this row survives untouched.
		wpEval( "update_option( 'email_contenttype', 'text/plain' ); echo '<<<done>>>';" );
		setVersionRow( runningVersions() );

		await page.goto( DASHBOARD_URL );

		expect( survivingLegacyRows() ).toContain( 'email_contenttype' );
		expect( rawOptions().sending.contenttype ).toBe( defaultOptions().sending.contenttype );
	} );

	test( 'the settings screen is reachable after all of it', async ( { page } ) => {
		await page.goto( SETTINGS_URL );

		await expect(
			page.getByRole( 'heading', { name: 'E-Mail Settings' } ).first(),
		).toBeVisible();
	} );
} );
