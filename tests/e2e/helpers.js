/**
 * Shared steps for the WP-EMail end-to-end suite.
 *
 * Two things here are worth reading before the specs.
 *
 * Nothing may leave the machine. The plugin's whole job is to hand a message to
 * wp_mail(), and a suite that let that through would send real mail to whatever
 * address a fixture happened to use. wp_mail() is filterable at pre_wp_mail, so
 * a small mu-plugin is installed once per run: it records the arguments in an
 * option and short-circuits the send. That is the only honest place to cut the
 * wire -- anything closer to the plugin would stop testing the plugin, and
 * anything further away would be a mail server.
 *
 * The log is a table of the plugin's own, so there is no REST route for it and
 * requestUtils cannot help. Log fixtures go in through WP-CLI; posts and pages
 * go through requestUtils; and anything a visitor would type goes through the
 * form, so the form is exercised by the tests that depend on it.
 */

const { execFileSync } = require( 'child_process' );
const path = require( 'path' );

const { expect } = require( '@wordpress/e2e-test-utils-playwright' );

/** The plugin root, which is where wp-env reads .wp-env.json from. */
const PLUGIN_ROOT = path.join( __dirname, '../..' );

const SETTINGS_URL = '/wp-admin/admin.php?page=wp-email-settings';
const LOGS_URL = '/wp-admin/admin.php?page=wp-email';

/**
 * Where the interceptor parks the last message it caught.
 *
 * Deliberately outside the plugin's own wp_email_ prefix. The metadata suite
 * asserts that uninstall.php removes every row that prefix owns, and a test
 * fixture squatting in that namespace would make it fail -- correctly, because
 * the plugin would indeed have left a row behind.
 */
const MAIL_OPTION = 'e2e_intercepted_mail';

/** Set to make the interceptor report a failed send instead of a successful one. */
const FAIL_OPTION = 'e2e_intercepted_mail_fails';

/**
 * Run PHP inside the tests environment and hand back what it printed.
 *
 * The code is base64'd rather than passed as itself: a template holding quotes,
 * angle brackets and a script tag is exactly the sort of string that arrives at
 * the other end subtly different, and a fixture that is not the payload byte for
 * byte proves nothing about escaping it.
 *
 * @param {string} code PHP to evaluate, without an opening tag.
 * @return {string} Whatever the code echoed between its markers.
 */
function wpEval( code ) {
	const encoded = Buffer.from( code, 'utf8' ).toString( 'base64' );

	const output = execFileSync(
		'npx',
		[
			'--yes',
			'@wordpress/env',
			'run',
			'tests-cli',
			'wp',
			'eval',
			`eval( base64_decode( '${ encoded }' ) );`,
		],
		{ cwd: PLUGIN_ROOT, encoding: 'utf8', stdio: [ 'ignore', 'pipe', 'pipe' ] },
	);

	// wp-env prints its own progress around the command's output, so the code
	// wraps what it wants to return in markers rather than the caller trying to
	// tell the two apart by position. Greedy, because a stored value ending in
	// "</script>" puts a ">" hard up against the closing marker and a lazy match
	// would stop one character short of the end.
	const matched = output.match( /<<<([\s\S]*)>>>/ );

	return matched ? matched[ 1 ] : '';
}

/**
 * Install the mu-plugin that stops mail leaving the machine.
 *
 * pre_wp_mail is the documented short circuit: returning a boolean from it
 * makes wp_mail() report that value and send nothing. Recording $atts first is
 * what lets a test assert on the recipients, the subject and the body that the
 * plugin actually built -- which is the far end of every "it sent" test here,
 * and is invisible to anything that only looks at the screen.
 *
 * Idempotent, so a spec can call it in beforeAll without caring whether another
 * file got there first.
 *
 * @return {void}
 */
function installMailInterceptor() {
	const php = `<?php
/**
 * Plugin Name: WP-EMail E2E mail interceptor
 * Description: Records wp_mail() calls and sends nothing. Installed by the Playwright suite.
 */
add_filter(
	'pre_wp_mail',
	static function ( $short_circuit, $atts ) {
		update_option( '${ MAIL_OPTION }', $atts, false );

		return ! get_option( '${ FAIL_OPTION }', false );
	},
	10,
	2
);
`;

	const encoded = Buffer.from( php, 'utf8' ).toString( 'base64' );

	wpEval(
		`$dir = defined( 'WPMU_PLUGIN_DIR' ) ? WPMU_PLUGIN_DIR : WP_CONTENT_DIR . '/mu-plugins';
		wp_mkdir_p( $dir );
		file_put_contents( $dir . '/wp-email-e2e-mail.php', base64_decode( '${ encoded }' ) );
		echo '<<<' . ( is_file( $dir . '/wp-email-e2e-mail.php' ) ? 'installed' : 'missing' ) . '>>>';`,
	);
}

/**
 * Forget the last intercepted message, and stop pretending sends fail.
 *
 * @return {void}
 */
function resetMail() {
	wpEval(
		`delete_option( '${ MAIL_OPTION }' );
		delete_option( '${ FAIL_OPTION }' );
		echo '<<<done>>>';`,
	);
}

/**
 * Make the interceptor report the next send as having failed.
 *
 * wp_mail() returning false is what puts the plugin down its "sent failed"
 * branch, and there is no other way to reach it without breaking a real mail
 * server.
 *
 * @param {boolean} fail Whether sends should fail.
 * @return {void}
 */
function failNextMail( fail = true ) {
	wpEval(
		fail
			? `update_option( '${ FAIL_OPTION }', 1, false ); echo '<<<done>>>';`
			: `delete_option( '${ FAIL_OPTION }' ); echo '<<<done>>>';`,
	);
}

/**
 * The message the plugin last handed to wp_mail().
 *
 * @return {Object|null} Keys 'to', 'subject', 'message' and 'headers', or null.
 */
function lastMail() {
	const raw = wpEval(
		`$mail = get_option( '${ MAIL_OPTION }', null );
		echo '<<<' . wp_json_encode( null === $mail ? null : $mail ) . '>>>';`,
	);

	return raw ? JSON.parse( raw ) : null;
}

/**
 * Empty the log table and put every setting back to a fresh install's.
 *
 * One round trip rather than three: `wp-env run` starts a container command each
 * time, and this runs before every test in the suite.
 *
 * @return {void}
 */
function resetPlugin() {
	wpEval(
		`global $wpdb;
		$wpdb->query( "TRUNCATE TABLE {$wpdb->email}" );
		WP_Email_Options::update( WP_Email_Options::defaults() );
		WP_Email_Options::flush();
		delete_option( '${ MAIL_OPTION }' );
		delete_option( '${ FAIL_OPTION }' );

		// The flood record. A send starts a ten-minute cooldown for the
		// address that sent it, and the address here is the same for every
		// test in the run -- so without this the first spec to send an e-mail
		// makes every later spec's form render "please wait" instead of a
		// form, and the failure surfaces in whichever file happens to come
		// after it.
		$flood = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
				$wpdb->esc_like( '_transient_' ) . '%' . $wpdb->esc_like( 'wp_email_flood_' ) . '%'
			)
		);
		foreach ( $flood as $name ) {
			delete_option( $name );
		}
		wp_cache_flush();

		echo '<<<done>>>';`,
	);
}

/**
 * The settings row as the database holds it, with no defaults merged in.
 *
 * Not the same question as setting() above, and the difference is the whole of
 * §7.6.1: WP_Email_Options::all() merges over the defaults, so it answers
 * identically for a row holding the defaults and for no row at all -- which is
 * what a migration that read, deleted and never wrote leaves behind. Ask the
 * database when the question is "was it written".
 *
 * @return {Object|false} The stored array, or false when there is no row.
 */
function rawOptions() {
	return JSON.parse(
		wpEval( "echo '<<<' . wp_json_encode( get_option( WP_Email_Options::OPTION ) ) . '>>>';" ),
	);
}

/**
 * The defaults the running code would fall back to.
 *
 * @return {Object} The default settings.
 */
function defaultOptions() {
	return JSON.parse(
		wpEval( "echo '<<<' . wp_json_encode( WP_Email_Options::defaults() ) . '>>>';" ),
	);
}

/**
 * Put the install back into the shape a pre-3.0.0 site is in.
 *
 * The prefixed rows go away and whichever unprefixed ones the caller names take
 * their place: the flat email_options row of 2.x and its fifteen companions,
 * plus the two WP-Stats rows this plugin shared with five siblings.
 *
 * **It hands back what it can see, and that is not a convenience.**
 * maybe_upgrade() is hooked to `init`, which a WP-CLI request reaches like any
 * other. So the moment this call ends, the next `wp eval` boots WordPress with
 * the markers behind and performs the upgrade itself, before running a line of
 * the code it was given -- and a test that read the rows back through another
 * helper would be asserting on WP-CLI's run rather than on the browser's, with
 * nothing left for the browser to do.
 *
 * @param {Object}      rows      Legacy option name => value, stored exactly as given.
 * @param {Object|null} [current] A row already under the current name, for the
 *                                install that has one and still holds the retired
 *                                link keys.
 * @param {Object|null} [markers] Version markers to stamp, for the same case.
 * @return {{legacy: string[], options: *, version: *}} The state as just seeded.
 */
function installLegacyRows( rows, current = null, markers = null ) {
	const encoded = Buffer.from(
		JSON.stringify( { rows, current, markers } ),
		'utf8',
	).toString( 'base64' );

	return JSON.parse(
		wpEval(
			`$data = json_decode( base64_decode( '${ encoded }' ), true );
			delete_option( WP_Email_Options::OPTION );
			delete_option( WP_Email_Options::VERSION );
			foreach ( WP_Email_Options::LEGACY_ROWS as $row ) {
				delete_option( $row );
			}
			foreach ( $data['rows'] as $name => $value ) {
				update_option( $name, $value );
			}
			if ( null !== $data['current'] ) {
				update_option( WP_Email_Options::OPTION, $data['current'] );
			}
			if ( null !== $data['markers'] ) {
				update_option( WP_Email_Options::VERSION, $data['markers'] );
			}
			WP_Email_Options::flush();
			$alive = array();
			foreach ( WP_Email_Options::LEGACY_ROWS as $row ) {
				if ( false !== get_option( $row, false ) ) {
					$alive[] = $row;
				}
			}
			echo '<<<' . wp_json_encode( array(
				'legacy'  => array_values( $alive ),
				'options' => get_option( WP_Email_Options::OPTION ),
				'version' => get_option( WP_Email_Options::VERSION ),
			) ) . '>>>';`,
		),
	);
}

/**
 * Which pre-3.0.0 rows are still in the database.
 *
 * Read through the plugin's own list rather than a set typed out here, so a row
 * that is added to the migration and forgotten by the cleanup -- or the other
 * way round -- shows up as a failure instead of going unnoticed.
 *
 * @return {string[]} The legacy rows that survive.
 */
function survivingLegacyRows() {
	return JSON.parse(
		wpEval(
			`$alive = array();
			foreach ( WP_Email_Options::LEGACY_ROWS as $row ) {
				if ( false !== get_option( $row, false ) ) {
					$alive[] = $row;
				}
			}
			echo '<<<' . wp_json_encode( array_values( $alive ) ) . '>>>';`,
		),
	);
}

/**
 * The upgrade markers, as the database holds them.
 *
 * @return {Object|false} The stored array, or false when there is no row.
 */
function versionRow() {
	return JSON.parse(
		wpEval( "echo '<<<' . wp_json_encode( get_option( WP_Email_Options::VERSION ) ) . '>>>';" ),
	);
}

/**
 * Stamp the upgrade markers.
 *
 * @param {Object} versions The two markers.
 * @return {void}
 */
function setVersionRow( versions ) {
	const encoded = Buffer.from( JSON.stringify( versions ), 'utf8' ).toString( 'base64' );

	wpEval(
		`update_option( WP_Email_Options::VERSION, json_decode( base64_decode( '${ encoded }' ), true ) );
		echo '<<<done>>>';`,
	);
}

/**
 * The version numbers the running code expects to find stamped.
 *
 * @return {{plugin: string, db: string}} The two markers.
 */
function runningVersions() {
	return JSON.parse(
		wpEval(
			`echo '<<<' . wp_json_encode( array(
				'plugin' => WP_EMAIL_VERSION,
				'db'     => WP_EMAIL_DB_VERSION,
			) ) . '>>>';`,
		),
	);
}

/**
 * Write one setting straight into the option row.
 *
 * For the preconditions a test needs but is not itself testing -- switching the
 * verification image off so the form can be filled in, say. Settings whose
 * saving is under test go through the form instead.
 *
 * @param {string} group Group key, e.g. 'sending' or 'link'.
 * @param {string} key   Setting within that group.
 * @param {string} value Value to store.
 * @return {void}
 */
function setSetting( group, key, value ) {
	const encoded = Buffer.from( String( value ), 'utf8' ).toString( 'base64' );

	wpEval(
		`WP_Email_Options::flush();
		$all = WP_Email_Options::all();
		$all['${ group }']['${ key }'] = base64_decode( '${ encoded }' );
		WP_Email_Options::update( $all );
		echo '<<<done>>>';`,
	);
}

/**
 * One stored setting, as a string.
 *
 * @param {string} group Group key.
 * @param {string} key   Setting within that group.
 * @return {string} The stored value.
 */
function setting( group, key ) {
	return wpEval(
		`WP_Email_Options::flush();
		echo '<<<' . WP_Email_Options::get( '${ group }', '${ key }' ) . '>>>';`,
	);
}

/**
 * One stored template, as a string.
 *
 * @param {string} name Template key.
 * @return {string} The stored template.
 */
function template( name ) {
	return wpEval(
		`WP_Email_Options::flush();
		echo '<<<' . WP_Email_Options::template( '${ name }' ) . '>>>';`,
	);
}

/**
 * Switch the site onto pretty permalinks and write the rewrite rules out.
 *
 * The plugin's two front-end screens are rewrite endpoints -- /email/ and
 * /emailpopup/ -- and a site on plain permalinks matches no rewrite rules at
 * all, so both would 404 on a suite that left the tests environment as it ships.
 * The plugin does have a query-argument fallback for exactly that case, and it
 * has a test of its own; this is the configuration everything else is about.
 *
 * @return {void}
 */
function usePrettyPermalinks() {
	wpEval(
		`global $wp_rewrite;
		if ( '/%postname%/' !== get_option( 'permalink_structure' ) ) {
			$wp_rewrite->set_permalink_structure( '/%postname%/' );
		}
		// Registered again, and deliberately after the structure is set:
		// WP_Rewrite::init() empties the endpoints array, and
		// set_permalink_structure() calls it -- so a flush that followed it
		// straight away would write out a rule set with no /email/ or
		// /emailpopup/ in it, and every front-end test would be about a 404.
		WP_Email::get_instance()->register_endpoints();
		$wp_rewrite->flush_rules( true );
		echo '<<<' . get_option( 'permalink_structure' ) . '>>>';`,
	);
}

/**
 * Insert a log row straight into the table.
 *
 * The log accumulates as a side effect of sending, so building a page of it
 * through the form would mean sending a page of mail. Anything a test needs to
 * count, sort or page through goes in here instead.
 *
 * @param {Object} spec               What to insert.
 * @param {number} spec.postId        Post the row belongs to.
 * @param {string} [spec.postTitle]   Post title as the row stores it.
 * @param {string} [spec.yourName]    Sender name.
 * @param {string} [spec.yourEmail]   Sender address.
 * @param {string} [spec.friendName]  Recipient name.
 * @param {string} [spec.friendEmail] Recipient address.
 * @param {string} [spec.remarks]     Sender's remark.
 * @param {string} [spec.ip]          Sender's IP.
 * @param {string} [spec.status]      'Success' or 'Failed'.
 * @param {number} [spec.ageInDays]   How long ago it was sent, for ordering.
 * @return {number} The new row's id.
 */
function createLog( spec ) {
	const data = Buffer.from(
		JSON.stringify( {
			postTitle: 'A post',
			yourName: 'Sender',
			yourEmail: 'sender@example.com',
			friendName: 'Friend',
			friendEmail: 'friend@example.com',
			remarks: 'Have a read.',
			ip: '203.0.113.7',
			status: 'Success',
			ageInDays: 0,
			...spec,
		} ),
		'utf8',
	).toString( 'base64' );

	return parseInt(
		wpEval(
			`global $wpdb;
			$data = json_decode( base64_decode( '${ data }' ), true );
			$wpdb->insert(
				$wpdb->email,
				array(
					'email_yourname'    => $data['yourName'],
					'email_youremail'   => $data['yourEmail'],
					'email_yourremarks' => $data['remarks'],
					'email_friendname'  => $data['friendName'],
					'email_friendemail' => $data['friendEmail'],
					'email_postid'      => (int) $data['postId'],
					'email_posttitle'   => $data['postTitle'],
					'email_timestamp'   => current_time( 'timestamp' ) - ( (int) $data['ageInDays'] * DAY_IN_SECONDS ),
					'email_ip'          => $data['ip'],
					'email_host'        => 'example.test',
					'email_status'      => $data['status'],
				),
				array( '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s' )
			);
			echo '<<<' . (int) $wpdb->insert_id . '>>>';`,
		),
		10,
	);
}

/**
 * How many rows the log holds.
 *
 * @return {number} Row count.
 */
function countLogs() {
	return parseInt(
		wpEval(
			`global $wpdb;
			echo '<<<' . (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->email}" ) . '>>>';`,
		),
		10,
	);
}

/**
 * One column of the newest log row.
 *
 * @param {string} column Column of the log table.
 * @return {string} The stored value, or the empty string when the log is empty.
 */
function newestLog( column ) {
	return wpEval(
		`global $wpdb;
		echo '<<<' . $wpdb->get_var( "SELECT ${ column } FROM {$wpdb->email} ORDER BY email_id DESC LIMIT 1" ) . '>>>';`,
	);
}

/**
 * A title no earlier run can have used.
 *
 * @param {string} base What the title should say.
 * @return {string} That, plus enough to tell this run from the last.
 */
function uniqueTitle( base ) {
	return `${ base } ${ Date.now().toString( 36 ) }`;
}

/**
 * Publish a post carrying the e-mail link shortcode.
 *
 * @param {Object} requestUtils The e2e-test-utils request helper.
 * @param {string} title        Post title.
 * @param {string} [content]    Post content, before the shortcode.
 * @return {Promise<Object>} The created post.
 */
function createEmailablePost( requestUtils, title, content = 'The body of the article.' ) {
	return requestUtils.createPost( {
		title,
		content: `${ content }\n\n[email_link]`,
		status: 'publish',
	} );
}

/**
 * The e-mail page for one post, in whichever shape the settings ask for.
 *
 * @param {Object} post    A post from requestUtils.
 * @param {string} [which] Either 'email' or 'emailpopup'.
 * @return {string} A URL.
 */
function emailPageUrl( post, which = 'email' ) {
	return `${ post.link.replace( /\/$/, '' ) }/${ which }/`;
}

/**
 * Fill in the e-mail form and press the button.
 *
 * The button is not a submit: the script collects the fields, validates them and
 * posts to admin-ajax, then swaps the response into #wp-email-content. So the
 * wait is on that container changing rather than on a navigation.
 *
 * @param {import('@playwright/test').Page} page            Page showing the form.
 * @param {Object}                          fields          What to type in.
 * @param {boolean}                         [fields.submit] Whether to press the button; false to fill in a field the form only sometimes has first.
 * @return {Promise<void>} Resolves once the form has been filled in.
 */
async function fillForm( page, fields = {} ) {
	const {
		yourname = 'Alice Sender',
		youremail = 'alice@example.com',
		yourremarks = 'Thought of you.',
		friendname = 'Bob Friend',
		friendemail = 'bob@example.com',
		submit = true,
	} = fields;

	for ( const [ id, value ] of Object.entries( {
		yourname,
		youremail,
		yourremarks,
		friendname,
		friendemail,
	} ) ) {
		const field = page.locator( `#${ id }` );

		if ( await field.count() ) {
			await field.fill( value );
		}
	}

	if ( submit ) {
		await page.locator( '#wp-email-submit' ).click();
	}
}

/**
 * A second page carrying no session at all.
 *
 * The suite runs as an administrator, whose name and address the form pre-fills.
 * A visitor who has never logged in gets an empty form, which is the one every
 * validation rule is written for.
 *
 * @param {import('@playwright/test').Page} page Page under test, for its browser.
 * @return {Promise<Object>} Keys 'context' and 'visitor'.
 */
async function anonymously( page ) {
	const context = await page.context().browser().newContext( { storageState: undefined } );

	return { context, visitor: await context.newPage() };
}

/**
 * Create a user, if they are not there already, and log a fresh browser in.
 *
 * @param {import('@playwright/test').Page} page         Page under test, for its browser.
 * @param {Object}                          requestUtils The e2e-test-utils request helper.
 * @param {string}                          username     Login name.
 * @param {string}                          role         Role to give them.
 * @return {Promise<Object>} Keys 'context' and 'page'.
 */
async function logInAs( page, requestUtils, username, role ) {
	await requestUtils
		.rest( {
			method: 'POST',
			path: '/wp/v2/users',
			data: {
				username,
				email: `${ username }@example.com`,
				password: 'correct-horse-battery-staple',
				roles: [ role ],
			},
		} )
		.catch( () => {} ); // Already there from an earlier run.

	const context = await page.context().browser().newContext( { storageState: undefined } );
	const other = await context.newPage();

	await other.goto( '/wp-login.php' );

	// wp-login.php focuses and selects #user_login on a 200ms timer, so that a
	// visitor can start typing. Filling across that moment puts the password into
	// the username box: Playwright focuses #user_pass, the timer takes focus back
	// and selects what is there, and the typed text replaces the selection.
	// Waiting for the timer's own effect is the signal that it has already fired.
	await expect( other.locator( '#user_login' ) ).toBeFocused();

	await other.locator( '#user_login' ).fill( username );
	await other.locator( '#user_pass' ).fill( 'correct-horse-battery-staple' );
	await other.locator( '#wp-submit' ).click();
	await expect( other.locator( '#wpadminbar' ) ).toBeVisible();

	return { context, page: other };
}

module.exports = {
	LOGS_URL,
	SETTINGS_URL,
	anonymously,
	countLogs,
	createEmailablePost,
	createLog,
	defaultOptions,
	emailPageUrl,
	failNextMail,
	fillForm,
	installLegacyRows,
	installMailInterceptor,
	lastMail,
	logInAs,
	newestLog,
	rawOptions,
	resetMail,
	resetPlugin,
	runningVersions,
	setSetting,
	setVersionRow,
	setting,
	survivingLegacyRows,
	template,
	uniqueTitle,
	usePrettyPermalinks,
	versionRow,
	wpEval,
};
