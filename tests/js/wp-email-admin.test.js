/**
 * js/wp-email-admin.js, driven the way an administrator drives it.
 */
import { beforeAll, beforeEach, describe, expect, it, vi } from 'vitest';
import { click, loadPluginScript, readPluginFile } from './helpers.js';

const DEFAULT_TEMPLATE = "E-Mail '%EMAIL_POST_TITLE%' To A Friend";

// The link template is markup, so every character esc_attr() touches is in it.
// A fixture carrying it verbatim would end the attribute at its first quote.
const DEFAULT_LINK_TEMPLATE =
	'<a href="%EMAIL_URL%" data-wp-email-popup="%EMAIL_POPUP%" title="Email This %POST_TYPE%" rel="nofollow">%EMAIL_ICON% Email This %POST_TYPE%</a>';

/**
 * Escape a value into an attribute the way PHP's esc_attr() does.
 *
 * @param {string} value Raw value.
 * @return {string} The same value, safe between double quotes.
 */
function escAttr( value ) {
	return value
		.replace( /&/g, '&amp;' )
		.replace( /</g, '&lt;' )
		.replace( />/g, '&gt;' )
		.replace( /"/g, '&quot;' )
		.replace( /'/g, '&#039;' );
}

/**
 * The markup WP_Email_Settings prints for the fields this script touches.
 *
 * @return {string} Markup.
 */
function settingsMarkup() {
	const linkDefault = escAttr( DEFAULT_LINK_TEMPLATE );

	return `
		<input type="text" id="wp_email_template_title" value="something else" />
		<p>
			<button type="button" class="button button-secondary"
				data-wp-email-restore="wp_email_template_title"
				data-wp-email-default="${ DEFAULT_TEMPLATE }">Restore Default Template</button>
		</p>
		<textarea id="wp_email_link_html">something else again</textarea>
		<p>
			<button type="button" class="button button-secondary"
				data-wp-email-restore="wp_email_link_html"
				data-wp-email-default="${ linkDefault }">Restore Default Template</button>
		</p>
		<input type="submit" name="delete_logs" value="Delete"
			data-wp-email-confirm="You are about to delete all e-mail logs." />
	`;
}

beforeAll( () => {
	loadPluginScript( 'js/wp-email-admin.js' );
} );

beforeEach( () => {
	// The script's listeners live on document, so a fresh fixture needs no
	// re-initialisation -- and the initial hidden state is PHP's, not the
	// script's.
	document.body.innerHTML = settingsMarkup();

	window.confirm = vi.fn( () => true );
} );

describe( 'the script itself', () => {
	it( 'reaches for no jQuery and declares nothing on window', () => {
		const source = readPluginFile( 'js/wp-email-admin.js' );

		expect( source ).not.toMatch( /jQuery/ );
		expect( source ).not.toMatch( /\$\(/ );
		expect( source ).not.toMatch( /window\.\w+ =/ );
	} );
} );

describe( 'Restore Default Template', () => {
	it( 'replaces the field with the default carried in the data attribute', async () => {
		await click( '[data-wp-email-restore]' );

		expect( document.getElementById( 'wp_email_template_title' ).value ).toBe(
			DEFAULT_TEMPLATE,
		);
	} );

	it( 'leaves the %TOKEN% names exactly as PHP wrote them', async () => {
		await click( '[data-wp-email-restore]' );

		const value = document.getElementById( 'wp_email_template_title' ).value;

		expect( value ).toContain( '%EMAIL_POST_TITLE%' );
		expect( value ).not.toContain( '%1$EMAIL_POST_TITLE%' );
	} );

	it( 'does nothing when the target field is not on the screen', async () => {
		document.getElementById( 'wp_email_template_title' ).remove();

		await expect( click( '[data-wp-email-restore]' ) ).resolves.toBeUndefined();
	} );
} );

describe( 'Restore Default Template on the link template', () => {
	it( 'puts the markup back, angle brackets and quotes and all', async () => {
		await click( '[data-wp-email-restore="wp_email_link_html"]' );

		// Read back through the DOM's own attribute decoding, which is the whole
		// of the mechanism: PHP escapes once with esc_attr() and the browser
		// undoes it. A template that arrived HTML-encoded would render as text.
		expect( document.getElementById( 'wp_email_link_html' ).value ).toBe(
			DEFAULT_LINK_TEMPLATE,
		);
	} );

	it( 'leaves %POST_TYPE% as PHP wrote it', async () => {
		await click( '[data-wp-email-restore="wp_email_link_html"]' );

		const value = document.getElementById( 'wp_email_link_html' ).value;

		expect( value ).toContain( '%POST_TYPE%' );
		expect( value ).not.toContain( '%EMAIL_TEXT%' );
	} );
} );

describe( 'deleting every log row', () => {
	it( 'asks for confirmation before the form submits', async () => {
		await click( '[data-wp-email-confirm]' );

		expect( window.confirm ).toHaveBeenCalledTimes( 1 );
		expect( window.confirm.mock.calls[ 0 ][ 0 ] ).toContain(
			'delete all e-mail logs',
		);
	} );

	it( 'cancels the submit when the confirmation is declined', () => {
		window.confirm = vi.fn( () => false );

		const button = document.querySelector( '[data-wp-email-confirm]' );
		const event = new window.MouseEvent( 'click', {
			bubbles: true,
			cancelable: true,
		} );

		button.dispatchEvent( event );

		expect( event.defaultPrevented ).toBe( true );
	} );

	it( 'lets the submit through when it is accepted', () => {
		const button = document.querySelector( '[data-wp-email-confirm]' );
		const event = new window.MouseEvent( 'click', {
			bubbles: true,
			cancelable: true,
		} );

		button.dispatchEvent( event );

		expect( event.defaultPrevented ).toBe( false );
	} );
} );
