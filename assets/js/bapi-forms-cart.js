/**
 * Block cart: shows the "application removed" notice from the Store API cart response, because a
 * WooCommerce session notice would only appear on the next page.
 */
( function ( wp ) {
	'use strict';

	if ( ! wp || ! wp.data || ! wp.data.subscribe ) {
		return;
	}

	var shown = {};

	function check() {
		var store = wp.data.select( 'wc/store/cart' );
		var data = store && store.getCartData ? store.getCartData() : null;
		var ext = data && data.extensions ? data.extensions[ 'bapi-forms' ] : null;
		var notice = ext && ext.notice;
		if ( ! notice || ! notice.id || shown[ notice.id ] ) {
			return;
		}
		shown[ notice.id ] = true;
		wp.data.dispatch( 'core/notices' ).createNotice( 'info', notice.text, {
			id: 'bapi-forms-removed',
			context: 'wc/cart',
			isDismissible: true
		} );
	}

	wp.data.subscribe( check );
	check();
}( window.wp ) );
