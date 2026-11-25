/**
 * BusinessAPI Forms settings screen: show/hide rows, ABN add-on dependency, appearance preview,
 * copy buttons and the Help tab accordion. Strings come from bapiFormsAdmin (wp_localize_script).
 */
( function () {
	var cfg = window.bapiFormsAdmin || {};

	function toggle( radioName, rowSelector, show ) {
		var radios = document.querySelectorAll( 'input[name="' + radioName + '"]' );
		if ( ! radios.length ) { return; }
		function apply() {
			var checked = document.querySelector( 'input[name="' + radioName + '"]:checked' );
			document.querySelectorAll( rowSelector ).forEach( function ( row ) {
				row.hidden = ! show( row, checked ? checked.value : '' );
			} );
		}
		radios.forEach( function ( radio ) { radio.addEventListener( 'change', apply ); } );
		apply();
	}
	toggle( 'environment', '.bapi-forms .bapi-forms-key-row', function ( row, value ) { return row.dataset.env === value; } );
	toggle( 'asic_notices[mode]', '.bapi-forms .bapi-forms-notices-field', function ( row, value ) { return 'partner' === value; } );

	// The ABN add-on only makes sense with company registration on.
	var company = document.getElementById( 'product-company_registration' );
	var addon = document.getElementById( 'product-abn_addon' );
	if ( company && addon ) {
		var addonAllowed = ! addon.disabled;
		var sync = function () {
			addon.disabled = ! addonAllowed || ! company.checked;
			if ( ! company.checked ) { addon.checked = false; }
		};
		company.addEventListener( 'change', sync );
		sync();
	}

	// Button text: the same APCA rule as Business API (Branding::recommendedOnPrimary), which
	// applies it whenever no choice is stored.
	var preview = document.querySelector( '.bapi-forms .bapi-forms-preview' );
	if ( preview ) {
		var colour = document.getElementById( 'bapi-forms-primary' );
		var radius = document.getElementById( 'bapi-forms-radius' );
		var range = document.getElementById( 'bapi-forms-radius-range' );
		var font = document.getElementById( 'bapi-forms-font' );
		var choice = document.querySelector( '.bapi-forms .bapi-forms-on-primary' );
		var textRadios = document.querySelectorAll( '.bapi-forms input[name="branding[on_primary]"]' );
		var badges = document.querySelectorAll( '.bapi-forms .bapi-forms-recommended' );
		var warning = document.querySelector( '.bapi-forms .bapi-forms-contrast' );
		var fillWarning = document.querySelector( '.bapi-forms .bapi-forms-fill-contrast' );
		var white = cfg.white;
		var dark = cfg.dark;
		var names = {};
		names[ white ] = cfg.whiteName;
		names[ dark ] = cfg.darkName;
		var apcaY = function ( hex ) {
			var y = [ 1, 3, 5 ].map( function ( i ) {
				return Math.pow( parseInt( hex.substr( i, 2 ), 16 ) / 255, 2.4 );
			} ).reduce( function ( sum, c, i ) { return sum + c * [ 0.2126729, 0.7151522, 0.0721750 ][ i ]; }, 0 );
			return y < 0.022 ? y + Math.pow( 0.022 - y, 1.414 ) : y;
		};
		var apcaLc = function ( text, background ) {
			var t = apcaY( text ), b = apcaY( background ), sapc;
			if ( Math.abs( b - t ) < 0.0005 ) { return 0; }
			if ( b > t ) {
				sapc = ( Math.pow( b, 0.56 ) - Math.pow( t, 0.57 ) ) * 1.14;
				return sapc < 0.1 ? 0 : ( sapc - 0.027 ) * 100;
			}
			sapc = ( Math.pow( b, 0.65 ) - Math.pow( t, 0.62 ) ) * 1.14;
			return sapc > -0.1 ? 0 : ( sapc + 0.027 ) * 100;
		};
		var recommend = function ( hex ) {
			return Math.abs( apcaLc( dark, hex ) ) > Math.abs( apcaLc( white, hex ) ) + cfg.bias ? dark : white;
		};
		var luminance = function ( hex ) {
			return [ 1, 3, 5 ].map( function ( i ) {
				var c = parseInt( hex.substr( i, 2 ), 16 ) / 255;
				return c <= 0.03928 ? c / 12.92 : Math.pow( ( c + 0.055 ) / 1.055, 2.4 );
			} ).reduce( function ( sum, c, i ) { return sum + c * [ 0.2126, 0.7152, 0.0722 ][ i ]; }, 0 );
		};
		var chosen = function () {
			var checked = document.querySelector( '.bapi-forms input[name="branding[on_primary]"]:checked' );
			return checked ? checked.value : white;
		};
		var currentHex = function ( value ) {
			var hex = ( typeof value === 'string' ? value : colour.value ).replace( '#', '' ).toLowerCase();
			return '#' + ( /^[0-9a-f]{6}$/.test( hex ) ? hex : '2563eb' );
		};
		// Follows the recommendation (stored as '') until the partner picks the other one.
		var stored = choice ? choice.dataset.choice || '' : '';
		var follow = '' === stored || stored === recommend( currentHex() );
		var update = function ( value ) {
			var hex = currentHex( value );
			var rec = recommend( hex );
			badges.forEach( function ( badge ) { badge.hidden = badge.dataset.value !== rec; } );
			if ( follow ) {
				textRadios.forEach( function ( radio ) { radio.checked = radio.value === rec; } );
			}
			var on = chosen();
			preview.style.setProperty( '--bp', hex );
			preview.style.setProperty( '--bpo', on );
			var r = parseInt( radius.value, 10 );
			preview.style.setProperty( '--br', ( isNaN( r ) ? 8 : Math.min( 24, Math.max( 0, r ) ) ) + 'px' );
			preview.style.setProperty( '--bf', font.value ? '"' + font.value + '", ui-sans-serif, system-ui, sans-serif' : 'ui-sans-serif, system-ui, sans-serif' );
			var lc = Math.abs( apcaLc( on, hex ) );
			warning.hidden = lc >= 45;
			warning.querySelector( '.bapi-forms-contrast-text' ).textContent = cfg.lowText.replace( '%1$s', names[ on ] ).replace( '%2$s', lc.toFixed( 1 ) ).replace( '%3$s', names[ on === white ? dark : white ].toLowerCase() );
			var ratio = 1.05 / ( luminance( hex ) + 0.05 );
			fillWarning.hidden = ratio >= 3;
			fillWarning.querySelector( '.bapi-forms-fill-contrast-text' ).textContent = cfg.fillText.replace( '%s', ratio.toFixed( 2 ) );
		};
		textRadios.forEach( function ( radio ) {
			radio.addEventListener( 'change', function () { follow = chosen() === recommend( currentHex() ); update(); } );
		} );
		radius.addEventListener( 'input', function () { range.value = radius.value || 8; update(); } );
		range.addEventListener( 'input', function () { radius.value = range.value; update(); } );
		font.addEventListener( 'change', update );
		colour.addEventListener( 'input', update );
		// Following the recommendation is saved as no choice, so it keeps following the colour.
		if ( colour.form ) {
			colour.form.addEventListener( 'submit', function () {
				if ( follow ) {
					textRadios.forEach( function ( radio ) { radio.checked = false; } );
				}
			} );
		}
		update();
		if ( window.jQuery ) {
			jQuery( function () {
				if ( jQuery.fn.wpColorPicker ) {
					jQuery( colour ).wpColorPicker( {
						change: function ( event, ui ) { update( ui.color.toString() ); },
						clear: function () { update( '' ); }
					} );
				}
			} );
		}
	}

	document.querySelectorAll( '.bapi-forms .bapi-forms-copy' ).forEach( function ( button ) {
		button.addEventListener( 'click', function () {
			var label = button.textContent;
			navigator.clipboard.writeText( button.dataset.copy ).then( function () {
				button.textContent = cfg.copied;
				setTimeout( function () { button.textContent = label; }, 1500 );
			} );
		} );
	} );

	// Help tab: one section open at a time (also for browsers without <details name>).
	var items = document.querySelectorAll( '.bapi-forms details.bapi-forms-acc' );
	items.forEach( function ( d ) {
		d.addEventListener( 'toggle', function () {
			if ( ! d.open ) { return; }
			items.forEach( function ( o ) { if ( o !== d ) { o.open = false; } } );
		} );
	} );
	// Links to #compatibility or #bapi-forms-webhooks open their section.
	function openHash() {
		if ( ! location.hash ) { return; }
		var t = document.getElementById( location.hash.slice( 1 ) );
		var d = t && ( t.matches( 'details.bapi-forms-acc' ) ? t : t.closest( 'details.bapi-forms-acc' ) );
		if ( d ) { d.open = true; t.scrollIntoView(); }
	}
	openHash();
	window.addEventListener( 'hashchange', openHash );
}() );
