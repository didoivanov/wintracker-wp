/* Wintracker slots grid: "Show more" reveals the next games (already in the page). No dependencies. */
( function () {
	'use strict';
	document.addEventListener( 'click', function ( e ) {
		var btn = e.target.closest && e.target.closest( '.wintracker__gg-more-btn' );
		if ( ! btn ) {
			return;
		}
		var widget = btn.closest( '.wintracker' );
		var hidden = widget ? widget.querySelectorAll( '.wintracker__gg-card[hidden]' ) : [];
		var step = parseInt( btn.getAttribute( 'data-step' ), 10 ) || 12;
		for ( var i = 0; i < hidden.length && i < step; i++ ) {
			hidden[ i ].hidden = false;
		}
		var left = hidden.length - Math.min( step, hidden.length );
		if ( left <= 0 ) {
			btn.parentNode.removeChild( btn );
		} else {
			btn.textContent = btn.textContent.replace( /[\d.,\s]+(?=\)\s*$)/, left );
		}
	} );
} )();
