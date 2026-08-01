/**
 * WP-EMail options screen behaviour.
 *
 * Replaces the inline onclick handlers and the generated JavaScript that used
 * to carry every default template as a string literal. The defaults now travel
 * in data attributes, so they are escaped once as attribute text rather than
 * being interpolated into executable markup.
 */
( function() {
	'use strict';

	function onClick( event ) {
		const target = event.target;

		if ( ! target || ! target.closest ) {
			return;
		}

		const restore = target.closest( '[data-wp-email-restore]' );

		if ( restore ) {
			event.preventDefault();

			const field = document.getElementById(
				restore.getAttribute( 'data-wp-email-restore' ),
			);

			if ( field ) {
				field.value = restore.getAttribute( 'data-wp-email-default' );
			}

			return;
		}

		const confirmer = target.closest( '[data-wp-email-confirm]' );

		// Deleting every log row is irreversible and has always been gated on
		// a confirm; the checkbox beside it is the other half of that gate.
		// eslint-disable-next-line no-alert
		const confirmed = confirmer && window.confirm( confirmer.getAttribute( 'data-wp-email-confirm' ) );

		if ( confirmer && ! confirmed ) {
			event.preventDefault();
		}
	}

	function init() {
		document.addEventListener( 'click', onClick );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );
