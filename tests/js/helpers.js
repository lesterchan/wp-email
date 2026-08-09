/**
 * Helpers for the WP-EMail script tests.
 *
 * There is no build step in this plugin, so the scripts are loaded the way the
 * browser loads them: read off disk and evaluated as-is.
 */
import { readFileSync } from 'node:fs';

/**
 * Read and evaluate a plugin script in the current jsdom window.
 *
 * Evaluate this ONCE per test file. Each script attaches its listener to
 * document, so a second evaluation adds a second listener and every handler
 * fires twice. Reset document.body.innerHTML between tests instead -- the
 * listener lives on document and survives that.
 *
 * @param {string} name Script filename, relative to the plugin root.
 */
export function loadPluginScript( name ) {
	const src = readFileSync( new URL( '../../' + name, import.meta.url ), 'utf8' );

	new Function( src )();
}

/**
 * Read a plugin file as text, for asserting across the PHP/JS boundary.
 *
 * @param {string} name Filename, relative to the plugin root.
 * @return {string} File contents.
 */
export function readPluginFile( name ) {
	return readFileSync( new URL( '../../' + name, import.meta.url ), 'utf8' );
}

/**
 * The strings WP_Email::enqueue_scripts() localises into the page.
 *
 * @return {Object} A stand-in for wpEmailL10n.
 */
export function l10n() {
	return {
		ajax_url: 'http://example.test/wp-admin/admin-ajax.php',
		max_allowed: 5,
		text_error: 'The Following Error Occurs:',
		text_name_invalid: '- Your Name is empty/invalid',
		text_email_invalid: '- Your Email is empty/invalid',
		text_remarks_invalid: '- Your Remarks is invalid',
		text_friend_names_empty: '- Friend Name(s) is empty',
		text_friend_name_invalid: '- Friend Name is empty/invalid: %s',
		text_max_friend_names_allowed: '- Maximum 5 Friend Names allowed',
		text_friend_emails_empty: '- Friend Email(s) is empty',
		text_friend_email_invalid: '- Friend Email is invalid: %s',
		text_max_friend_emails_allowed: '- Maximum 5 Friend Emails allowed',
		text_friends_tally:
			'- Friend Name(s) count does not tally with Friend Email(s) count',
		text_image_verify_empty: '- Image Verification is empty',
	};
}

/**
 * The markup WP_Email_Form::render_fields() produces, with every field on.
 *
 * @return {string} Markup.
 */
export function formMarkup() {
	return `
		<div id="wp-email-content" class="wp-email">
			<form action="http://example.test/hello-world/email/" method="post">
				<p hidden><input type="hidden" id="p" name="p" value="12" /></p>
				<p hidden><input type="hidden" id="wp-email_nonce" name="wp-email_nonce" value="nonce-from-php" /></p>
				<p><input type="text" id="yourname" name="yourname" value="" /></p>
				<p><input type="email" id="youremail" name="youremail" value="" /></p>
				<p><textarea id="yourremarks" name="yourremarks"></textarea></p>
				<p><input type="text" id="friendname" name="friendname" value="" /></p>
				<p><input type="text" id="friendemail" name="friendemail" value="" /></p>
				<p><input type="button" value="Mail It!" id="wp-email-submit" /></p>
			</form>
			<div id="wp-email-loading" class="wp-email-loading"></div>
		</div>
	`;
}

/**
 * Fill the sender and recipient fields with values that pass validation.
 *
 * @param {Object} values Field id => value overrides.
 */
export function fillForm( values = {} ) {
	const defaults = {
		yourname: 'Alice',
		youremail: 'alice@example.com',
		yourremarks: 'Worth a read.',
		friendname: 'Bob',
		friendemail: 'bob@example.com',
	};

	Object.entries( { ...defaults, ...values } ).forEach( ( [ id, value ] ) => {
		const field = document.getElementById( id );

		if ( field ) {
			field.value = value;
		}
	} );
}

/**
 * Build a Response-alike for the mocked fetch.
 *
 * @param {string} body Response body.
 * @return {Promise<Object>} Resolved response.
 */
export function respondWith( body = '<p>Sent.</p>' ) {
	return Promise.resolve( {
		ok: true,
		status: 200,
		text: () => Promise.resolve( body ),
	} );
}

/**
 * Click and let the whole promise chain settle.
 *
 * A macrotask, not a counted number of microtask turns: the submit handler
 * chains fetch -> then -> then -> catch, and counting turns by hand leaves the
 * last one unrun.
 *
 * @param {string} selector CSS selector for the element to click.
 */
export async function click( selector ) {
	document.querySelector( selector ).click();

	await new Promise( ( resolve ) => setTimeout( resolve, 0 ) );
}
