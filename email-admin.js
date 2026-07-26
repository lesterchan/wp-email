/**
 * WP-EMail options screen behaviour.
 *
 * Replaces the inline onclick handlers and the generated JavaScript that used
 * to carry every default template as a string literal. The defaults now travel
 * in data attributes, so they are escaped once as attribute text rather than
 * being interpolated into executable markup.
 */
( function () {
	'use strict';

	function onClick( event ) {
		var target = event.target;

		if ( ! target || ! target.closest ) {
			return;
		}

		var restore = target.closest( '[data-wp-email-restore]' );

		if ( restore ) {
			event.preventDefault();

			var field = document.getElementById(
				restore.getAttribute( 'data-wp-email-restore' )
			);

			if ( field ) {
				field.value = restore.getAttribute( 'data-wp-email-default' );
			}

			return;
		}

		var confirmer = target.closest( '[data-wp-email-confirm]' );

		if (
			confirmer &&
			! window.confirm( confirmer.getAttribute( 'data-wp-email-confirm' ) )
		) {
			event.preventDefault();
		}
	}

	/**
	 * Show or hide a block depending on a select's value.
	 *
	 * @param {HTMLSelectElement} select The controlling select.
	 */
	function applyToggle( select ) {
		var target = document.getElementById(
			select.getAttribute( 'data-wp-email-toggle' )
		);

		if ( ! target ) {
			return;
		}

		var wanted = select.getAttribute( 'data-wp-email-toggle-value' );

		target.style.display = select.value === wanted ? '' : 'none';
	}

	function init() {
		document.addEventListener( 'click', onClick );

		var toggles = document.querySelectorAll( '[data-wp-email-toggle]' );

		Array.prototype.forEach.call( toggles, function ( select ) {
			applyToggle( select );

			select.addEventListener( 'change', function () {
				applyToggle( select );
			} );
		} );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
