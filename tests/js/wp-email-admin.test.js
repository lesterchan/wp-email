/**
 * js/wp-email-admin.js, driven the way an administrator drives it.
 */
import { beforeAll, beforeEach, describe, expect, it, vi } from 'vitest';
import { click, loadPluginScript, readPluginFile } from './helpers.js';

const DEFAULT_TEMPLATE = "E-Mail '%EMAIL_POST_TITLE%' To A Friend";

/**
 * The markup WP_Email_Settings prints for the fields this script touches.
 *
 * @return {string} Markup.
 */
function settingsMarkup() {
	return `
		<input type="text" id="wp_email_template_title" value="something else" />
		<p>
			<button type="button" class="button button-secondary"
				data-wp-email-restore="wp_email_template_title"
				data-wp-email-default="${ DEFAULT_TEMPLATE }">Restore Default Template</button>
		</p>
		<select id="wp_email_link_style"
			data-wp-email-toggle="wp_email_link_custom" data-wp-email-toggle-value="4">
			<option value="1" selected>Icon With Text Link</option>
			<option value="4">Custom</option>
		</select>
		<div id="wp_email_link_custom" hidden>
			<textarea id="wp_email_link_html"></textarea>
		</div>
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

/**
 * Change the link style the way a browser does.
 *
 * The event has to bubble: the script listens on document, and a change event
 * built without { bubbles: true } never leaves the select.
 *
 * @param {string} value The style to select.
 */
function chooseStyle( value ) {
	const select = document.getElementById( 'wp_email_link_style' );

	select.value = value;
	select.dispatchEvent( new window.Event( 'change', { bubbles: true } ) );
}

describe( 'the custom link markup toggle', () => {
	it( 'stays hidden while another style is selected', () => {
		expect( document.getElementById( 'wp_email_link_custom' ).hidden ).toBe( true );
	} );

	it( 'appears when Custom is chosen', () => {
		chooseStyle( '4' );

		expect( document.getElementById( 'wp_email_link_custom' ).hidden ).toBe( false );
	} );

	it( 'hides again when the style is changed back', () => {
		chooseStyle( '4' );
		chooseStyle( '1' );

		expect( document.getElementById( 'wp_email_link_custom' ).hidden ).toBe( true );
	} );

	it( 'uses the hidden property rather than an inline style', () => {
		chooseStyle( '4' );

		expect(
			document.getElementById( 'wp_email_link_custom' ).getAttribute( 'style' ),
		).toBeNull();
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
