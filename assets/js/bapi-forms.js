/**
 * BusinessAPI Forms embed: turns each .bapi-forms-form container into a Business API hosted iframe.
 *
 * - The application is only created when the visitor clicks Start. Each tab keeps its opaque
 *   ref in sessionStorage, tied to an opaque visitor id, so a refresh resumes it and a shared
 *   computer never reopens someone else's draft (also checked on back/forward cache restores).
 * - Nonces come from an uncached URL. When the REST API is blocked for visitors, the same routes
 *   go through admin-ajax. Only this plugin's own error messages are shown.
 * - postMessage is accepted from the Business API origin only and re-dispatched as bubbling
 *   `bapi-forms:<type>` events for analytics.
 * - Modes: form first (completed -> cart -> checkout), amend (data-amend-ref), pay first start
 *   (data-start-ref) and waiting for the order (data-pending-order). Amend and pay first send a
 *   signed proof, never the order key.
 *
 * Nothing the applicant types passes through this script.
 */
( function () {
	'use strict';

	var cfg = window.bapiForms;
	if ( ! cfg || ! window.fetch ) {
		return;
	}

	var MAX_SESSION_RETRIES = 3;
	var LOAD_TIMEOUT_MS = 20000;
	var forms = [];
	var nonces = null;

	function storeKey( form ) {
		return 'bapiForms:ref:' + form.product;
	}

	function ownerKey( form ) {
		return 'bapiForms:owner:' + form.product;
	}

	// The visitor id the tab's ref was saved for ('' when unknown, e.g. saved by an older version).
	function storedOwner( form ) {
		try {
			return window.sessionStorage.getItem( ownerKey( form ) ) || '';
		} catch ( e ) {
			return '';
		}
	}

	function storedRef( form ) {
		try {
			return window.sessionStorage.getItem( storeKey( form ) );
		} catch ( e ) {
			return null;
		}
	}

	function storeRef( form, ref ) {
		try {
			if ( ref ) {
				window.sessionStorage.setItem( storeKey( form ), ref );
				if ( visitor ) {
					window.sessionStorage.setItem( ownerKey( form ), visitor );
				}
			} else {
				window.sessionStorage.removeItem( storeKey( form ) );
				window.sessionStorage.removeItem( ownerKey( form ) );
			}
		} catch ( e ) {}
	}

	// Whether a URL (absolute or relative) is on this site. False for anything new URL() refuses.
	function sameOrigin( url ) {
		try {
			return new URL( url, window.location.href ).origin === window.location.origin;
		} catch ( e ) {
			return false;
		}
	}

	var noncePromise = null;
	// Opaque id of the current visitor, from the nonce endpoint.
	var visitor = null;
	// Set once the REST API answered with something that is not this plugin (blocked by a
	// security plugin, firewall or host): later calls go through admin-ajax.
	var useAjax = false;

	function fetchNonces() {
		if ( ! noncePromise ) {
			noncePromise = fetch( cfg.nonceUrl, { credentials: 'same-origin', cache: 'no-store' } ).then( function ( res ) {
				if ( ! res.ok ) {
					throw new Error( 'nonce' );
				}
				return res.json();
			} ).then( function ( data ) {
				nonces = data;
				visitor = data && 'string' === typeof data.visitor ? data.visitor : null;
				noncePromise = null;
				return data;
			}, function ( err ) {
				noncePromise = null;
				throw err;
			} );
		}
		return noncePromise;
	}

	// An error body from this plugin (codes start with bapi_forms), not from core or a firewall.
	function isOwnError( data ) {
		return !! data && 'string' === typeof data.code && 0 === data.code.indexOf( 'bapi_forms' );
	}

	// Only this plugin's own translated messages are shown, never raw core or third-party ones.
	function errorText( err, fallback ) {
		if ( isOwnError( err ) && 'string' === typeof err.message && err.message ) {
			return err.message;
		}
		if ( err && ( 'rest_cookie_invalid_nonce' === err.code || 'bapi_forms_nonce' === err.code ) ) {
			return cfg.i18n.sessionExpired || fallback;
		}
		return fallback;
	}

	function send( path, body, n ) {
		var url = useAjax && cfg.ajaxUrl ? cfg.ajaxUrl + '&route=' + encodeURIComponent( path ) : cfg.restUrl + path;
		return fetch( url, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': n.restNonce,
				'X-Bapi-Forms-Nonce': n.nonce
			},
			body: JSON.stringify( body )
		} ).then( function ( res ) {
			return res.json().then( function ( data ) {
				return { res: res, data: data && 'object' === typeof data ? data : {}, json: true };
			}, function () {
				return { res: res, data: {}, json: false };
			} );
		} );
	}

	// A stale nonce (page open too long, login or logout in another tab) is fetched again and the
	// call retried once. A blocked REST API is retried once through admin-ajax.
	function post( path, body, retried ) {
		retried = retried || {};
		return ( nonces ? Promise.resolve( nonces ) : fetchNonces() ).then( function ( n ) {
			return send( path, body, n );
		} ).then( function ( r ) {
			var code = r.data.code;
			var err;
			if ( r.res.ok && r.json ) {
				return r.data;
			}
			if ( 403 === r.res.status && ! retried.nonce && ( 'rest_cookie_invalid_nonce' === code || 'bapi_forms_nonce' === code ) ) {
				nonces = null;
				return post( path, body, { nonce: true, ajax: retried.ajax } );
			}
			// Not this plugin answering: a non-JSON error page, or a typical "REST blocked" status.
			// A 2xx that is not JSON is not a blocked REST API (e.g. a mangled body): no fallback.
			if ( ! useAjax && ! retried.ajax && cfg.ajaxUrl && ! isOwnError( r.data ) && ( ( ! r.json && ! r.res.ok ) || [ 401, 403, 404, 405, 406, 501 ].indexOf( r.res.status ) !== -1 ) ) {
				useAjax = true;
				return post( path, body, { nonce: retried.nonce, ajax: true } );
			}
			err = r.data;
			err.httpStatus = r.res.ok ? 502 : r.res.status;
			throw err;
		} );
	}

	function emit( form, type, detail ) {
		var event;
		detail = detail || {};
		detail.product = form.product;
		try {
			event = new CustomEvent( 'bapi-forms:' + type, { bubbles: true, detail: detail } );
		} catch ( e ) {
			return;
		}
		form.el.dispatchEvent( event );
	}

	function setStatus( form, text ) {
		var status = form.el.querySelector( '.bapi-forms-form__status' );
		if ( ! status ) {
			status = document.createElement( 'p' );
			status.className = 'bapi-forms-form__status';
			status.setAttribute( 'role', 'status' );
			form.el.insertBefore( status, form.el.firstChild );
		}
		status.textContent = text || '';
		status.hidden = ! text;
	}

	function isBapiUrl( url ) {
		try {
			return new URL( url ).origin === cfg.bapiOrigin;
		} catch ( e ) {
			return false;
		}
	}

	function start( form ) {
		var card = form.el.querySelector( '.bapi-forms-form__start' );
		if ( card ) {
			card.hidden = true;
		}
		setStatus( form, cfg.i18n.loading );
		return loadSession( form );
	}

	function loadSession( form ) {
		var body = { product: form.product };
		var path = 'session';
		var ref = storedRef( form );
		var resuming = ! form.amend && ! form.start && !! form.resumeRef;
		if ( form.amend ) {
			// The order's refused application: BAPI opens its amendment.
			path = 'amend-session';
			body = { order: form.amend.order, proof: form.amend.proof, applicationRef: form.amend.ref };
		} else if ( form.start ) {
			// Pay first: the application the order created for this client.
			path = 'prepaid-session';
			body = { order: form.start.order, proof: form.start.proof, applicationRef: form.start.ref };
		} else if ( resuming ) {
			// My Account link: reopen this draft or nothing (never a new application).
			body.applicationRef = form.resumeRef;
			body.resumeOnly = true;
		} else if ( ref ) {
			body.applicationRef = ref;
		}
		form.resumeRef = null;
		hideOverlay( form );

		return ( nonces ? Promise.resolve( nonces ) : fetchNonces() ).then( function () {
			// The tab's saved ref belongs to someone else now (logout, another login): drop it and
			// show the start card; nothing is created without a click.
			if ( body.applicationRef && body.applicationRef === ref && ! resuming && ! form.amend && ! form.start && visitor && storedOwner( form ) && storedOwner( form ) !== visitor ) {
				storeRef( form, null );
				showStartCard( form, '' );
				return 'dropped';
			}
			return post( path, body );
		} ).then( function ( data ) {
			if ( 'dropped' === data ) {
				return;
			}
			if ( ! data || ! isBapiUrl( data.url ) ) {
				throw new Error( 'invalid session url' );
			}
			if ( 'string' === typeof data.visitor && data.visitor ) {
				visitor = data.visitor; // A guest's first /session starts their WooCommerce session.
			}
			if ( ! form.amend && ! form.start ) {
				// Kept only now that the server confirmed it.
				storeRef( form, typeof data.applicationRef === 'string' ? data.applicationRef : null );
			}
			if ( ! form.iframe ) {
				form.iframe = document.createElement( 'iframe' );
				form.iframe.title = cfg.i18n.title;
				form.iframe.style.cssText = 'display:block;width:100%;min-height:400px;border:0;';
				form.iframe.setAttribute( 'scrolling', 'no' );
				form.el.appendChild( form.iframe );
			}
			armLoadTimeout( form );
			form.iframe.src = data.url;
		} ).catch( function ( err ) {
			var code = err && err.code;
			var shop = err && err.data && 'string' === typeof err.data.productUrl ? err.data.productUrl : '';
			if ( 'bapi_forms_pay_first' === code ) {
				// Bought first: send the visitor to the product page (same site only).
				showStartCard( form, errorText( err, cfg.i18n.payFirst ) );
				if ( shop && sameOrigin( shop ) ) {
					showOverlay( form, errorText( err, cfg.i18n.payFirst ), cfg.i18n.buyNow, function () {
						window.location.assign( shop );
					}, true );
				}
				emit( form, 'error', { code: 'pay_first' } );
				return;
			}
			if ( ( form.amend || form.start ) && ( 'bapi_forms_proof_expired' === code || 'bapi_forms_forbidden' === code ) ) {
				// The page's signed proof ran out (open a long time) or no longer matches who is
				// logged in: a reload signs a new one (or shows the login form).
				unloadFrame( form );
				showOverlay( form, cfg.i18n.amendStale || errorText( err, cfg.i18n.error ), cfg.i18n.reloadPage || cfg.i18n.reload, function () {
					window.location.reload();
				} );
				emit( form, 'error', { code: 'session_failed' } );
				return;
			}
			if ( ! form.amend && ! form.start && ( resuming || 'bapi_forms_ref_ordered' === code || 'bapi_forms_resume_unavailable' === code ) ) {
				if ( 'bapi_forms_ref_ordered' === code || 'bapi_forms_resume_unavailable' === code ) {
					if ( ! resuming ) {
						storeRef( form, null ); // On an order, or gone: this tab starts fresh.
					}
				}
				// Back to the start card: a new application only on a click.
				showStartCard( form, errorText( err, cfg.i18n.error ) );
				emit( form, 'error', { code: 'session_failed' } );
				return;
			}
			setStatus( form, errorText( err, cfg.i18n.error ) );
			emit( form, 'error', { code: 'session_failed' } );
		} );
	}

	// about:blank first, so nothing of the form stays in memory or the back/forward cache.
	function unloadFrame( form ) {
		if ( ! form.iframe ) {
			return;
		}
		try {
			form.iframe.src = 'about:blank';
		} catch ( e ) {}
		if ( form.iframe.parentNode ) {
			form.iframe.parentNode.removeChild( form.iframe );
		}
		form.iframe = null;
	}

	// The "Start your application" card again (with a short message), button enabled.
	function showStartCard( form, message ) {
		var card = form.el.querySelector( '.bapi-forms-form__start' );
		var button = form.el.querySelector( '.bapi-forms-form__start-button' );
		unloadFrame( form );
		window.clearTimeout( form.loadTimer );
		form.ready = false;
		if ( card ) {
			card.hidden = false;
		}
		if ( button ) {
			button.disabled = false;
		}
		setStatus( form, message || '' );
	}

	// Amend mode: the corrected application was resubmitted inside the iframe.
	function onResubmitted( form ) {
		if ( ! form.amend || form.resubmitted ) {
			return;
		}
		form.resubmitted = true;
		emit( form, 'resubmitted', { applicationRef: form.amend.ref } );
		setStatus( form, cfg.i18n.resubmitted );
		post( 'amended', { order: form.amend.order, proof: form.amend.proof, applicationRef: form.amend.ref } ).catch( function () {} );
	}

	// No `ready` from the iframe in time: say so instead of leaving an empty box.
	function armLoadTimeout( form ) {
		window.clearTimeout( form.loadTimer );
		form.ready = false;
		form.loadTimer = window.setTimeout( function () {
			if ( ! form.ready ) {
				setStatus( form, cfg.i18n.loadTimeout );
				emit( form, 'error', { code: 'load_timeout' } );
			}
		}, LOAD_TIMEOUT_MS );
	}

	// On phones a new step can start below the fold. top: offset in px inside the iframe.
	function scrollToForm( form, top ) {
		var rect, target, offset;
		if ( ! form.iframe || ! form.iframe.getBoundingClientRect ) {
			return;
		}
		offset = typeof top === 'number' && top > 0 && top < 20000 ? Math.floor( top ) : 0;
		rect = form.iframe.getBoundingClientRect();
		target = rect.top + offset;
		if ( target < 0 || target > window.innerHeight * 0.5 ) {
			try {
				window.scrollTo( { top: window.pageYOffset + target - 16, behavior: 'smooth' } );
			} catch ( e ) {
				window.scrollTo( 0, window.pageYOffset + target - 16 );
			}
		}
	}

	// Pay first: submitted. awaiting_release means the partner paused it.
	function onPrepaidDone( form, data ) {
		if ( form.completing ) {
			return;
		}
		form.completing = true;
		emit( form, 'completed', { applicationRef: data.applicationRef, applicationType: data.applicationType, addons: data.addons, prepaid: true, status: data.status } );
		setStatus( form, ( 'awaiting_release' === data.status || 'draft' === data.status ) ? cfg.i18n.prepaidHeld : cfg.i18n.prepaidDone );
		if ( form.start ) {
			post( 'prepaid-done', { order: form.start.order, proof: form.start.proof, applicationRef: form.start.ref } ).catch( function () {} );
			markInProgress( form.start.ref );
		}
	}

	// The order's "Your applications" list was rendered before the form was sent.
	function markInProgress( ref ) {
		var items = document.querySelectorAll( '.bapi-forms-applications li[data-bapi-ref]' );
		var i, status, button;
		for ( i = 0; i < items.length; i++ ) {
			if ( items[ i ].getAttribute( 'data-bapi-ref' ) !== String( ref ).toUpperCase() ) {
				continue;
			}
			status = items[ i ].querySelector( '.bapi-forms-app-status' );
			if ( status && cfg.i18n.inProgress ) {
				status.textContent = cfg.i18n.inProgress;
			}
			button = items[ i ].querySelector( '.bapi-forms-start-button' );
			if ( button ) {
				button.parentNode.removeChild( button );
			}
		}
	}

	// Pay first: tell the server once about an error code it acts on (already_monitored).
	function onPrepaidError( form, code ) {
		form.prepaidErrors = form.prepaidErrors || {};
		if ( ! form.start || form.prepaidErrors[ code ] ) {
			return;
		}
		form.prepaidErrors[ code ] = true;
		post( 'prepaid-done', { order: form.start.order, proof: form.start.proof, applicationRef: form.start.ref, code: code } ).catch( function () {} );
	}

	// Pay first: ask a few times whether the order created the application, then give up politely.
	function pollReady( form, attempt ) {
		var delays = [ 3000, 4000, 5000, 6000, 8000, 10000, 12000, 12000 ];
		if ( attempt >= delays.length ) {
			setStatus( form, cfg.i18n.preparingSlow );
			return;
		}
		window.setTimeout( function () {
			post( 'prepaid-ready', { order: form.pending.order, proof: form.pending.proof } ).then( function ( res ) {
				var target;
				if ( res && res.ready && 'string' === typeof res.url ) {
					target = new URL( res.url, window.location.href );
					if ( target.origin === window.location.origin ) {
						window.location.assign( target.href );
						return;
					}
				}
				pollReady( form, attempt + 1 );
			} ).catch( function ( err ) {
				if ( err && err.httpStatus >= 500 ) {
					pollReady( form, attempt + 1 );
					return;
				}
				setStatus( form, errorText( err, cfg.i18n.preparingSlow ) );
			} );
		}, delays[ attempt ] );
	}

	function onCompleted( form, data ) {
		if ( typeof data.applicationRef !== 'string' ) {
			return;
		}
		if ( form.start || true === data.prepaid ) {
			onPrepaidDone( form, data );
			return;
		}
		if ( form.amend || form.completing ) {
			return;
		}
		form.completing = true;
		emit( form, 'completed', { applicationRef: data.applicationRef, applicationType: data.applicationType, addons: data.addons } );
		setStatus( form, cfg.i18n.checkout );

		post( 'cart', {
			applicationRef: data.applicationRef,
			product: form.product,
			addons: Array.isArray( data.addons ) ? data.addons : []
		} ).then( function ( res ) {
			if ( ! res || 'string' !== typeof res.redirect || ! sameOrigin( res.redirect ) ) {
				throw new Error( 'unexpected redirect' );
			}
			storeRef( form, null ); // On an order now; this tab starts fresh next time.
			window.location.assign( new URL( res.redirect, window.location.href ).href );
		} ).catch( function ( err ) {
			form.completing = false;
			if ( err && 'bapi_forms_ref_ordered' === err.code ) {
				storeRef( form, null ); // Paid for elsewhere: this tab starts a new application next time.
			}
			emit( form, 'error', { code: 'cart_failed' } );
			if ( err && err.httpStatus >= 500 ) {
				// Temporary (our server or Business API): the same request can simply be sent again.
				showOverlay( form, cfg.i18n.cartRetry, cfg.i18n.tryAgain, function () {
					hideOverlay( form );
					onCompleted( form, data );
				}, false );
				return;
			}
			setStatus( form, errorText( err, cfg.i18n.cartFail ) );
		} );
	}

	// A message and one button in place of the iframe.
	function showOverlay( form, message, label, onClick, hideFrame ) {
		var box = form.el.querySelector( '.bapi-forms-form__overlay' );
		var text;
		var button;
		if ( ! box ) {
			box = document.createElement( 'div' );
			box.className = 'bapi-forms-form__overlay';
			box.setAttribute( 'role', 'alert' );
			box.style.cssText = 'padding:16px;margin:0 0 12px;border:1px solid #e5e7eb;border-radius:8px;text-align:center;background:#fff;';
			text = document.createElement( 'p' );
			text.style.margin = '0 0 12px';
			button = document.createElement( 'button' );
			button.type = 'button';
			button.className = 'button wp-element-button';
			accentButton( button );
			box.appendChild( text );
			box.appendChild( button );
			form.el.insertBefore( box, form.el.firstChild );
		}
		box.querySelector( 'p' ).textContent = message;
		button = box.querySelector( 'button' );
		button.textContent = label;
		button.onclick = onClick;
		box.hidden = false;
		setStatus( form, '' );
		if ( form.iframe && false !== hideFrame ) {
			// Not the hidden attribute: the iframe's inline display:block would override it.
			form.iframe.style.display = 'none';
		}
		if ( box.scrollIntoView ) {
			box.scrollIntoView( { block: 'nearest' } );
		}
	}

	function hideOverlay( form ) {
		var box = form.el.querySelector( '.bapi-forms-form__overlay' );
		if ( box ) {
			box.hidden = true;
		}
		if ( form.iframe ) {
			form.iframe.style.display = 'block';
		}
	}

	// The partner's accent colour on the page's own buttons, with the hosted form's text colour.
	function accentButton( button ) {
		if ( ! cfg.accent || ! /^#[0-9a-f]{6}$/i.test( cfg.accent ) ) {
			return;
		}
		button.style.backgroundColor = cfg.accent;
		button.style.borderColor = cfg.accent;
		button.style.color = /^#[0-9a-f]{6}$/i.test( cfg.accentText || '' ) ? cfg.accentText : '#ffffff';
	}

	// Back from the cart after removing this application: say it is saved, offer checkout.
	function takeReturnFlag( form ) {
		var params, url;
		try {
			params = new URLSearchParams( window.location.search );
		} catch ( e ) {
			return false;
		}
		if ( ! cfg.returnArg || params.get( cfg.returnArg ) !== form.product ) {
			return false;
		}
		// Once only: a refresh shows the normal start card again.
		try {
			url = new URL( window.location.href );
			url.searchParams.delete( cfg.returnArg );
			window.history.replaceState( window.history.state, '', url.toString() );
		} catch ( e ) {}
		return true;
	}

	// From My Account "Continue your application": reopen that draft in this tab (once).
	function takeResumeLink( form ) {
		var params, url, ref;
		try {
			params = new URLSearchParams( window.location.search );
		} catch ( e ) {
			return;
		}
		ref = params.get( 'bapi_resume' );
		if ( ! ref || params.get( 'bapi_product' ) !== form.product || ! /^[0-9A-HJKMNP-TV-Z]{26}$/i.test( ref ) ) {
			return;
		}
		// Never replaces the application this tab already has; adopted once the server confirms.
		if ( ! storedRef( form ) ) {
			form.resumeRef = ref.toUpperCase();
		}
		try {
			url = new URL( window.location.href );
			url.searchParams.delete( 'bapi_resume' );
			url.searchParams.delete( 'bapi_product' );
			window.history.replaceState( window.history.state, '', url.toString() );
		} catch ( e ) {}
	}

	// Another tab took this application over: offer to carry on here instead.
	function showTakeover( form ) {
		showOverlay( form, cfg.i18n.otherTab, cfg.i18n.continueHere, function () {
			form.retries = 0; // A fresh start: expiry retries count again from here.
			loadSession( form );
		} );
	}

	// The session lapsed and could not be renewed automatically: reload on request.
	function showExpired( form ) {
		showOverlay( form, cfg.i18n.expired, cfg.i18n.reload, function () {
			form.retries = 0;
			loadSession( form );
		} );
	}

	function onMessage( event ) {
		var data = event.data;
		var form = null;
		var i;

		if ( event.origin !== cfg.bapiOrigin || ! data || data.source !== 'bapi-forms' || typeof data.type !== 'string' ) {
			return;
		}
		for ( i = 0; i < forms.length; i++ ) {
			if ( forms[ i ].iframe && forms[ i ].iframe.contentWindow === event.source ) {
				form = forms[ i ];
				break;
			}
		}
		if ( ! form ) {
			return;
		}

		switch ( data.type ) {
			case 'ready':
			case 'resize':
				if ( typeof data.height === 'number' && data.height > 0 ) {
					form.iframe.style.height = Math.min( Math.ceil( data.height ), 20000 ) + 'px';
				}
				if ( 'ready' === data.type ) {
					form.ready = true;
					form.readyAt = Date.now();
					window.clearTimeout( form.loadTimer );
					setStatus( form, '' );
					emit( form, 'ready', { height: data.height } );
				}
				break;
			case 'scroll':
				scrollToForm( form, data.top );
				break;
			case 'step':
				scrollToForm( form, data.top );
				emit( form, 'step', { step: data.step, index: data.index, total: data.total } );
				break;
			case 'completed':
				onCompleted( form, data );
				break;
			case 'resubmitted':
				onResubmitted( form );
				break;
			case 'error':
				emit( form, 'error', { code: data.code } );
				if ( 'action_needed' === data.code || 'lodgement_failed' === data.code ) {
					// Pay first: fixed and submitted again later, that completion counts again.
					form.completing = false;
					setStatus( form, '' );
				}
				if ( 'already_monitored' === data.code ) {
					onPrepaidError( form, data.code ); // The form shows the refund wording itself.
					break;
				}
				if ( 'session_revoked' === data.code ) {
					window.clearTimeout( form.loadTimer );
					showTakeover( form );
					break;
				}
				if ( 'session_expired' === data.code ) {
					window.clearTimeout( form.loadTimer );
					// Only a session that ran for a while resets the expiry retries, so an
					// expired page can never reload in a loop.
					if ( form.readyAt && Date.now() - form.readyAt > 60000 ) {
						form.retries = 0;
					}
					form.readyAt = 0;
					if ( form.retries < MAX_SESSION_RETRIES ) {
						// Reopens this browser's draft in a new session (a new one only if it is gone).
						form.retries++;
						loadSession( form );
					} else {
						showExpired( form );
					}
				}
				break;
		}
	}

	function init() {
		var nodes = document.querySelectorAll( '.bapi-forms-form[data-product], .bapi-forms-form[data-pending-order]' );
		var fresh = [];
		var i;
		for ( i = 0; i < nodes.length; i++ ) {
			if ( nodes[ i ].getAttribute( 'data-bapi-init' ) ) {
				continue;
			}
			nodes[ i ].setAttribute( 'data-bapi-init', '1' );
			forms.push( {
				el: nodes[ i ],
				product: nodes[ i ].getAttribute( 'data-product' ),
				amend: nodes[ i ].getAttribute( 'data-amend-ref' ) ? {
					ref: nodes[ i ].getAttribute( 'data-amend-ref' ),
					order: parseInt( nodes[ i ].getAttribute( 'data-order' ), 10 ) || 0,
					proof: nodes[ i ].getAttribute( 'data-proof' ) || ''
				} : null,
				start: nodes[ i ].getAttribute( 'data-start-ref' ) ? {
					ref: nodes[ i ].getAttribute( 'data-start-ref' ),
					order: parseInt( nodes[ i ].getAttribute( 'data-order' ), 10 ) || 0,
					proof: nodes[ i ].getAttribute( 'data-proof' ) || ''
				} : null,
				pending: nodes[ i ].getAttribute( 'data-pending-order' ) ? {
					order: parseInt( nodes[ i ].getAttribute( 'data-pending-order' ), 10 ) || 0,
					proof: nodes[ i ].getAttribute( 'data-proof' ) || ''
				} : null,
				resumeRef: null,
				iframe: null, retries: 0, readyAt: 0, completing: false, resubmitted: false, ready: false, loadTimer: 0
			} );
			fresh.push( forms[ forms.length - 1 ] );
		}
		if ( ! fresh.length ) {
			return;
		}

		// Start is wired up at once and post() fetches nonces itself, so an early click (or one
		// replayed by a "delay JavaScript" optimiser) is not lost.
		fresh.forEach( function ( form ) {
			var button = form.el.querySelector( '.bapi-forms-form__start-button' );
			var text;
			if ( form.pending ) {
				pollReady( form, 0 ); // Pay first: the order is still creating the application.
				return;
			}
			if ( form.amend || form.start ) {
				start( form ); // The amend and pay-first forms open straight away.
				return;
			}
			takeResumeLink( form );
			if ( button ) {
				button.disabled = false;
				button.addEventListener( 'click', function () {
					button.disabled = true;
					start( form );
				} );
			}
			if ( form.resumeRef || storedRef( form ) ) {
				start( form ); // A draft to reopen (this tab's, or the My Account link's).
				return;
			}
			if ( button && takeReturnFlag( form ) ) {
				// /session reopens the returned application (RestController::take_returned()),
				// whose completed form goes straight on to checkout.
				text = form.el.querySelectorAll( '.bapi-forms-form__start p' );
				if ( text.length > 1 ) {
					text[ text.length - 1 ].textContent = cfg.i18n.returned;
				}
				button.textContent = cfg.i18n.toCheckout;
			}
		} );

		// Warm up the nonces so Start responds quickly; a failure here is retried on click.
		if ( ! nonces ) {
			fetchNonces().catch( function () {} );
		}
	}

	window.addEventListener( 'message', onMessage );

	// Back/forward cache. Back from checkout may continue to checkout again, but the page may also
	// come back after a logout on a shared computer, so forms stay hidden until a fresh visitor id
	// confirms the same visitor. This replaces Clear-Site-Data on
	// logout, which also wiped other plugins' storage.
	window.addEventListener( 'pageshow', function ( event ) {
		var before;
		if ( ! event.persisted ) {
			return;
		}
		before = visitor;
		forms.forEach( function ( form ) {
			form.completing = false;
			if ( form.iframe ) {
				form.iframe.style.display = 'none';
			}
		} );
		nonces = null;
		fetchNonces().then( function () {
			var same = !! before && before === visitor;
			forms.forEach( function ( form ) {
				if ( ! form.iframe ) {
					return;
				}
				if ( same ) {
					form.iframe.style.display = 'block';
					if ( form.ready ) {
						setStatus( form, '' );
					}
					return;
				}
				if ( form.amend || form.start ) {
					// The server checks the order and proof again (a logged-out customer is refused).
					unloadFrame( form );
					start( form );
				} else {
					// Someone else now: forget the previous visitor's application in this tab.
					storeRef( form, null );
					showStartCard( form, '' );
				}
			} );
		} ).catch( function () {
			forms.forEach( function ( form ) {
				if ( form.iframe ) {
					showStartCard( form, cfg.i18n.error );
				}
			} );
		} );
	} );

	// Pay first: WooCommerce's guest email check drops the #bapi-forms-start fragment of the start link:
	// bring the form into view when the page was opened for it.
	function scrollToStart() {
		var target = document.getElementById( 'bapi-forms-start' );
		if ( target && ! window.location.hash && /[?&]bapi_start=/.test( window.location.search ) ) {
			target.scrollIntoView( { block: 'start' } );
		}
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', function () {
			init();
			scrollToStart();
		} );
	} else {
		init();
		scrollToStart();
	}
}() );
