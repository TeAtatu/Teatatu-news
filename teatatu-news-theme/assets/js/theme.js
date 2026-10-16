/**
 * Teatatu News Theme — progressive enhancement only. Every feature here
 * works without JavaScript (forms submit, links navigate); this just makes
 * them nicer.
 */
( function () {
	var data = window.ttnTheme || {};

	// Sort dropdown submits on change.
	document.querySelectorAll( '[data-ttn-autosubmit] select' ).forEach( function ( select ) {
		select.addEventListener( 'change', function () {
			select.form.submit();
		} );
	} );

	// Share: native share sheet, else copy the link.
	document.querySelectorAll( '[data-ttn-share]' ).forEach( function ( btn ) {
		btn.addEventListener( 'click', function () {
			var url = btn.getAttribute( 'data-ttn-share' );
			if ( navigator.share ) {
				navigator.share( { title: document.title, url: url } ).catch( function () {} );
			} else if ( navigator.clipboard ) {
				navigator.clipboard.writeText( url ).then( function () {
					btn.setAttribute( 'aria-label', data.copied || 'Link copied' );
					btn.title = data.copied || 'Link copied';
				} );
			}
		} );
	} );
} )();
