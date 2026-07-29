/**
 * WP-EMail front-end behaviour.
 *
 * Vanilla fetch and delegated listeners: the plugin dropped its last library
 * dependency in 3.0.0, and the inline onclick attributes went with it. Every
 * handler is bound once on document, so a form swapped in over AJAX keeps
 * working without rebinding, and nothing is declared on window.
 *
 * Server data arrives in wpEmailL10n, localised by WP_Email::enqueue_scripts().
 */
( function() {
	'use strict';

	const NAME_PATTERN = /[(\*\(\)\[\]\+\,\/\?\:\;\'\"\`\~\\#\$\%\^\&\<\>)+]/;
	const EMAIL_PATTERN = /^([a-zA-Z0-9_\.\-])+\@(([a-zA-Z0-9\-])+\.)+([a-zA-Z0-9]{2,})+$/;
	const INJECTION_STRINGS = [
		'apparently-to',
		'content-disposition',
		'content-type',
		'content-transfer-encoding',
		'errors-to',
		'in-reply-to',
		'message-id',
		'mime-version',
		'multipart/mixed',
		'multipart/alternative',
		'multipart/related',
		'reply-to',
		'x-mailer',
		'x-sender',
		'x-uidl',
	];

	function byId( id ) {
		return document.getElementById( id );
	}

	function valueOf( id ) {
		const el = byId( id );
		return el ? el.value : null;
	}

	function isEmpty( value ) {
		return ! value || '' === value.trim();
	}

	function isValidName( value ) {
		return ! NAME_PATTERN.test( value.trim() );
	}

	function isValidEmail( value ) {
		return EMAIL_PATTERN.test( value.trim() );
	}

	function isValidRemarks( value ) {
		const haystack = value.trim().toLowerCase();

		return ! INJECTION_STRINGS.some( function( needle ) {
			return -1 !== haystack.indexOf( needle );
		} );
	}

	function splitList( value ) {
		return value.split( /[,;]+/ ).map( function( part ) {
			return part.trim();
		} );
	}

	/**
	 * Collect the field values, returning null when validation fails.
	 *
	 * @return {Object|null} The values to post.
	 */
	function collect() {
		const max = parseInt( window.wpEmailL10n.max_allowed, 10 ) || 1;
		const errors = [];
		const values = {};
		let names = [];
		let emails = [];
		let value;

		value = valueOf( 'yourname' );
		if ( null !== value ) {
			values.yourname = value;
			if ( isEmpty( value ) || ! isValidName( value ) ) {
				errors.push( window.wpEmailL10n.text_name_invalid );
			}
		}

		value = valueOf( 'youremail' );
		if ( null !== value ) {
			values.youremail = value;
			if ( isEmpty( value ) || ! isValidEmail( value ) ) {
				errors.push( window.wpEmailL10n.text_email_invalid );
			}
		}

		value = valueOf( 'yourremarks' );
		if ( null !== value ) {
			values.yourremarks = value;
			if ( ! isEmpty( value ) && ! isValidRemarks( value ) ) {
				errors.push( window.wpEmailL10n.text_remarks_invalid );
			}
		}

		value = valueOf( 'friendname' );
		if ( null !== value ) {
			values.friendname = value;

			if ( isEmpty( value ) ) {
				errors.push( window.wpEmailL10n.text_friend_names_empty );
			} else {
				names = splitList( value );

				names.forEach( function( name ) {
					if ( isEmpty( name ) || ! isValidName( name ) ) {
						errors.push( window.wpEmailL10n.text_friend_name_invalid + name );
					}
				} );

				if ( names.length > max ) {
					errors.push( window.wpEmailL10n.text_max_friend_names_allowed );
				}
			}
		}

		value = valueOf( 'friendemail' );
		values.friendemail = value || '';

		if ( isEmpty( values.friendemail ) ) {
			errors.push( window.wpEmailL10n.text_friend_emails_empty );
		} else {
			emails = splitList( values.friendemail );

			emails.forEach( function( email ) {
				if ( isEmpty( email ) || ! isValidEmail( email ) ) {
					errors.push( window.wpEmailL10n.text_friend_email_invalid + email );
				}
			} );

			if ( emails.length > max ) {
				errors.push( window.wpEmailL10n.text_max_friend_emails_allowed );
			}
		}

		if ( null !== valueOf( 'friendname' ) && names.length !== emails.length ) {
			errors.push( window.wpEmailL10n.text_friends_tally );
		}

		value = valueOf( 'imageverify' );
		if ( null !== value ) {
			values.imageverify = value;
			values.imageverify_token = valueOf( 'imageverify_token' ) || '';

			if ( isEmpty( value ) ) {
				errors.push( window.wpEmailL10n.text_image_verify_empty );
			}
		}

		if ( errors.length ) {
			// A blocking alert is what this form has always used to report
			// validation errors, and the server repeats every check anyway.
			// eslint-disable-next-line no-alert
			window.alert(
				window.wpEmailL10n.text_error +
					'\n__________________________________\n\n' +
					errors.join( '\n' ) +
					'\n',
			);
			return null;
		}

		[ 'p', 'page_id' ].forEach( function( id ) {
			const found = valueOf( id );
			if ( null !== found ) {
				values[ id ] = found;
			}
		} );

		values[ 'wp-email_nonce' ] = valueOf( 'wp-email_nonce' ) || '';

		return values;
	}

	/**
	 * Disable the form while the request is in flight.
	 *
	 * @param {boolean} busy Whether a request is running.
	 */
	function setBusy( busy ) {
		[
			'wp-email-submit',
			'yourname',
			'youremail',
			'yourremarks',
			'friendname',
			'friendemail',
			'imageverify',
		].forEach( function( id ) {
			const el = byId( id );
			if ( el ) {
				el.disabled = busy;
			}
		} );

		const loading = byId( 'wp-email-loading' );
		if ( loading ) {
			loading.style.display = busy ? 'block' : 'none';
		}
	}

	function submitForm() {
		const values = collect();

		if ( null === values ) {
			return;
		}

		const body = new URLSearchParams();
		body.append( 'action', 'email' );

		Object.keys( values ).forEach( function( key ) {
			body.append( key, values[ key ] );
		} );

		setBusy( true );

		window
			.fetch( window.wpEmailL10n.ajax_url, {
				method: 'POST',
				credentials: 'same-origin',
				headers: {
					'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
				},
				body: body.toString(),
			} )
			.then( function( response ) {
				return response.text();
			} )
			.then( function( html ) {
				const container = byId( 'wp-email-content' );

				if ( container ) {
					container.innerHTML = html;
				}
			} )
			.catch( function() {
				setBusy( false );
			} );
	}

	/**
	 * Open the e-mail page in a popup window.
	 *
	 * Closure-scoped: the plugin declared a global email_popup() until 3.0.0,
	 * for the inline onclick it also emitted. Both are gone, and a link opts
	 * into the popup with the %EMAIL_POPUP% data attribute instead.
	 *
	 * @param {string} url Where to point the popup.
	 */
	function openPopup( url ) {
		window.open(
			url,
			'_blank',
			'width=500,height=500,toolbar=0,menubar=0,location=0,resizable=0,scrollbars=1,status=0',
		);
	}

	function repositionPopup() {
		const content = byId( 'wp-email-popup' );

		if ( ! content ) {
			return;
		}

		const width = content.offsetWidth + 30;
		const height = content.offsetHeight + 50;

		window.resizeTo( width, height );
		window.moveTo(
			( window.screen.width - width ) / 2,
			( window.screen.height - height ) / 2,
		);
	}

	function onClick( event ) {
		const target = event.target;

		if ( ! target || ! target.closest ) {
			return;
		}

		if ( target.closest( '#wp-email-submit' ) ) {
			event.preventDefault();
			submitForm();
			return;
		}

		const popup = target.closest( '[data-wp-email-popup]' );
		if ( popup && popup.href ) {
			event.preventDefault();
			openPopup( popup.href );
			return;
		}

		if ( target.closest( '[data-wp-email-close]' ) ) {
			event.preventDefault();
			window.close();
		}
	}

	function init() {
		document.addEventListener( 'click', onClick );

		if ( document.body && document.body.hasAttribute( 'data-wp-email-reposition' ) ) {
			repositionPopup();
		}
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );
