/**
 * js/wp-email.js, driven the way a visitor drives it.
 */
import { beforeAll, beforeEach, describe, expect, it, vi } from 'vitest';
import {
	click,
	fillForm,
	formMarkup,
	l10n,
	loadPluginScript,
	readPluginFile,
	respondWith,
} from './helpers.js';

beforeAll( () => {
	// wpEmailL10n is read as the handlers run, but the IIFE is evaluated once,
	// so it has to exist before the script is loaded.
	window.wpEmailL10n = l10n();

	loadPluginScript( 'js/wp-email.js' );
} );

beforeEach( () => {
	document.body.innerHTML = formMarkup();
	document.body.removeAttribute( 'data-wp-email-reposition' );

	window.fetch = vi.fn( () => respondWith() );
	window.alert = vi.fn();
	window.open = vi.fn();
	window.close = vi.fn();
} );

describe( 'the script itself', () => {
	it( 'reaches for no jQuery and declares nothing on window', () => {
		const source = readPluginFile( 'js/wp-email.js' );

		expect( source ).not.toMatch( /jQuery/ );
		expect( source ).not.toMatch( /\$\(/ );
		expect( source ).not.toMatch( /window\.\w+ =/ );
	} );

	it( 'reads its strings from wpEmailL10n', () => {
		expect( readPluginFile( 'js/wp-email.js' ) ).toMatch( /window\.wpEmailL10n/ );
	} );
} );

describe( 'submitting the form', () => {
	it( 'posts every field, the action and the nonce', async () => {
		fillForm();

		await click( '#wp-email-submit' );

		expect( window.fetch ).toHaveBeenCalledTimes( 1 );

		const [ url, options ] = window.fetch.mock.calls[ 0 ];
		const body = new URLSearchParams( options.body );

		expect( url ).toBe( l10n().ajax_url );
		expect( options.method ).toBe( 'POST' );
		expect( options.credentials ).toBe( 'same-origin' );
		expect( body.get( 'action' ) ).toBe( 'email' );
		expect( body.get( 'yourname' ) ).toBe( 'Alice' );
		expect( body.get( 'friendemail' ) ).toBe( 'bob@example.com' );
		expect( body.get( 'p' ) ).toBe( '12' );
		expect( body.get( 'wp-email_nonce' ) ).toBe( 'nonce-from-php' );
	} );

	it( 'replaces the container with whatever the server returned', async () => {
		window.fetch = vi.fn( () => respondWith( '<p>Article sent.</p>' ) );

		fillForm();

		await click( '#wp-email-submit' );

		expect( document.getElementById( 'wp-email-content' ).innerHTML ).toBe(
			'<p>Article sent.</p>',
		);
	} );

	it( 'disables the fields and shows the loading block while in flight', async () => {
		fillForm();

		let inFlight;

		window.fetch = vi.fn( () => {
			inFlight = {
				submit: document.getElementById( 'wp-email-submit' ).disabled,
				loading: document.getElementById( 'wp-email-loading' ).style.display,
			};

			return respondWith();
		} );

		await click( '#wp-email-submit' );

		expect( inFlight.submit ).toBe( true );
		expect( inFlight.loading ).toBe( 'block' );
	} );

	it( 'lets the visitor try again when the request fails outright', async () => {
		fillForm();

		window.fetch = vi.fn( () => Promise.reject( new Error( 'offline' ) ) );

		await click( '#wp-email-submit' );

		expect( document.getElementById( 'wp-email-submit' ).disabled ).toBe( false );
	} );
} );

describe( 'validation before the request', () => {
	it.each( [
		[ 'an empty name', { yourname: '' }, 'text_name_invalid' ],
		[ 'a name with markup in it', { yourname: 'Alice <b>' }, 'text_name_invalid' ],
		[ 'an unparseable address', { youremail: 'not-an-address' }, 'text_email_invalid' ],
		[
			'remarks that look like header injection',
			{ yourremarks: 'hi\ncontent-type: text/html' },
			'text_remarks_invalid',
		],
		[ 'no recipients at all', { friendemail: '' }, 'text_friend_emails_empty' ],
		[
			'more names than addresses',
			{ friendname: 'Bob, Carol' },
			'text_friends_tally',
		],
	] )( 'refuses to post %s', async ( _label, values, message ) => {
		fillForm( values );

		await click( '#wp-email-submit' );

		expect( window.fetch ).not.toHaveBeenCalled();
		expect( window.alert ).toHaveBeenCalledTimes( 1 );
		expect( window.alert.mock.calls[ 0 ][ 0 ] ).toContain( l10n()[ message ] );
	} );

	it( 'refuses more recipients than the server allows', async () => {
		fillForm( {
			friendname: 'A, B, C, D, E, F',
			friendemail: 'a@e.com, b@e.com, c@e.com, d@e.com, e@e.com, f@e.com',
		} );

		await click( '#wp-email-submit' );

		expect( window.fetch ).not.toHaveBeenCalled();
		expect( window.alert.mock.calls[ 0 ][ 0 ] ).toContain(
			l10n().text_max_friend_emails_allowed,
		);
	} );

	it( 'accepts a comma separated list within the maximum', async () => {
		fillForm( {
			friendname: 'Bob, Carol',
			friendemail: 'bob@example.com, carol@example.com',
		} );

		await click( '#wp-email-submit' );

		expect( window.alert ).not.toHaveBeenCalled();
		expect( window.fetch ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'skips the checks for fields the settings screen switched off', async () => {
		document.getElementById( 'yourname' ).remove();
		document.getElementById( 'friendname' ).remove();

		fillForm( { youremail: 'alice@example.com', friendemail: 'bob@example.com' } );

		await click( '#wp-email-submit' );

		expect( window.alert ).not.toHaveBeenCalled();
		expect( window.fetch ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'requires the verification answer only when the image is on', async () => {
		fillForm();

		const field = document.createElement( 'input' );
		field.id = 'imageverify';
		document.querySelector( 'form' ).appendChild( field );

		await click( '#wp-email-submit' );

		expect( window.fetch ).not.toHaveBeenCalled();
		expect( window.alert.mock.calls[ 0 ][ 0 ] ).toContain(
			l10n().text_image_verify_empty,
		);
	} );
} );

describe( 'the popup link', () => {
	it( 'opens a window instead of following the href', async () => {
		document.body.innerHTML =
			'<a href="http://example.test/hello-world/emailpopup/" data-wp-email-popup="1">Email</a>';

		await click( '[data-wp-email-popup]' );

		expect( window.open ).toHaveBeenCalledTimes( 1 );
		expect( window.open.mock.calls[ 0 ][ 0 ] ).toBe(
			'http://example.test/hello-world/emailpopup/',
		);
	} );

	it( 'closes the popup from the close link', async () => {
		document.body.innerHTML = '<a href="#" data-wp-email-close="1">Close</a>';

		await click( '[data-wp-email-close]' );

		expect( window.close ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'leaves an ordinary link alone', async () => {
		// A fragment rather than a full URL: jsdom has no navigation, and a
		// real href turns an ordinary click into an unimplemented-feature
		// warning that says nothing about the handler.
		document.body.innerHTML = '<a href="#read">Read</a>';

		await click( 'a' );

		expect( window.open ).not.toHaveBeenCalled();
		expect( window.close ).not.toHaveBeenCalled();
	} );
} );
