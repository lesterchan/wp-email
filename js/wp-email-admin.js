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

	/**
	 * Show or hide a block depending on a select's value.
	 *
	 * The hidden attribute rather than an inline display style, so the markup
	 * PHP prints carries no style attribute for the handler to fight with.
	 *
	 * @param {HTMLSelectElement} select The controlling select.
	 */
	function applyToggle( select ) {
		const target = document.getElementById(
			select.getAttribute( 'data-wp-email-toggle' ),
		);

		if ( ! target ) {
			return;
		}

		const wanted = select.getAttribute( 'data-wp-email-toggle-value' );

		target.hidden = select.value !== wanted;
	}

	/**
	 * Re-apply a toggle whenever its select changes.
	 *
	 * Delegated on document like the click handler, rather than bound to each
	 * select: one listener, and a field the Settings API redraws keeps working
	 * without anything having to rebind it.
	 *
	 * @param {Event} event The change event.
	 */
	function onChange( event ) {
		const target = event.target;

		if ( ! target || ! target.closest ) {
			return;
		}

		const select = target.closest( '[data-wp-email-toggle]' );

		if ( select ) {
			applyToggle( select );
		}
	}

	function init() {
		document.addEventListener( 'click', onClick );
		document.addEventListener( 'change', onChange );

		Array.prototype.forEach.call(
			document.querySelectorAll( '[data-wp-email-toggle]' ),
			applyToggle,
		);
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );
