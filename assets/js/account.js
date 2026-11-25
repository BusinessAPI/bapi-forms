/** My Account > Monitoring: confirm before Stop. */
( function () {
	document.querySelectorAll( 'form[data-bapi-forms-confirm]' ).forEach( function ( form ) {
		form.addEventListener( 'submit', function ( event ) {
			if ( ! window.confirm( form.getAttribute( 'data-bapi-forms-confirm' ) ) ) {
				event.preventDefault();
			}
		} );
	} );
}() );
