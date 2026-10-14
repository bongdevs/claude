/**
 * Captures checkout field values as the visitor types/blurs and reports them (debounced)
 * to the server, so we have name/email/phone/address even if they never click "Place order".
 *
 * Supports both the classic shortcode checkout (#billing_first_name …) and the
 * WooCommerce Blocks checkout (#email, #billing-first_name, #shipping-phone …).
 */
( function ( $ ) {
	'use strict';

	if ( typeof WCCR_Public === 'undefined' || WCCR_Public.is_order_received ) {
		return;
	}

	var FIELD_IDS = [
		'billing_first_name', 'billing_last_name', 'billing_email', 'billing_phone',
		'billing_address_1', 'billing_address_2', 'billing_city', 'billing_state', 'billing_postcode', 'billing_country', 'billing_company',
		'shipping_first_name', 'shipping_last_name',
		'shipping_address_1', 'shipping_address_2', 'shipping_city', 'shipping_state', 'shipping_postcode', 'shipping_country',
		'order_comments'
	];

	/*
	 * Block checkout input ids -> our field keys. In the block checkout the shipping
	 * address is entered first and billing is "same as shipping" by default, so shipping
	 * values are used as a fallback for the billing columns.
	 */
	var BLOCK_MAP = {
		'email': 'billing_email',
		'billing-first_name': 'billing_first_name', 'billing-last_name': 'billing_last_name',
		'billing-phone': 'billing_phone', 'billing-company': 'billing_company',
		'billing-address_1': 'billing_address_1', 'billing-address_2': 'billing_address_2',
		'billing-city': 'billing_city', 'billing-state': 'billing_state',
		'billing-postcode': 'billing_postcode', 'billing-country': 'billing_country',
		'shipping-first_name': 'shipping_first_name', 'shipping-last_name': 'shipping_last_name',
		'shipping-phone': 'shipping_phone', 'shipping-company': 'shipping_company',
		'shipping-address_1': 'shipping_address_1', 'shipping-address_2': 'shipping_address_2',
		'shipping-city': 'shipping_city', 'shipping-state': 'shipping_state',
		'shipping-postcode': 'shipping_postcode', 'shipping-country': 'shipping_country'
	};

	var debounceTimer = null;
	var lastPayload   = '';

	function val( id ) {
		var $el = $( document.getElementById( id ) );
		return $el.length ? $el.val() : undefined;
	}

	function collectFields() {
		var data = {};
		FIELD_IDS.forEach( function ( id ) {
			var v = val( id );
			if ( v !== undefined ) {
				data[ 'order_comments' === id ? 'order_note' : id ] = v;
			}
		} );

		// Block checkout.
		var block = {};
		Object.keys( BLOCK_MAP ).forEach( function ( id ) {
			var v = val( id );
			if ( v !== undefined && v !== null && v !== '' ) {
				block[ BLOCK_MAP[ id ] ] = v;
			}
		} );
		if ( Object.keys( block ).length ) {
			[ 'first_name', 'last_name', 'phone', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country' ].forEach( function ( part ) {
				var b = block[ 'billing_' + part ], s = block[ 'shipping_' + part ];
				if ( ( b === undefined || b === '' ) && s ) {
					block[ 'billing_' + part ] = s; // "Use same address for billing".
				}
			} );
			delete block.shipping_phone;
			delete block.shipping_company;
			$.extend( data, block );
			var noteEl = document.querySelector( '.wc-block-checkout__order-notes textarea' );
			if ( noteEl ) {
				data.order_note = noteEl.value;
			}
		}
		return data;
	}

	function hasConsent() {
		if ( 'yes' !== WCCR_Public.require_consent ) {
			return true;
		}
		var $consent = $( '#wccr_consent' );
		return $consent.length ? $consent.is( ':checked' ) : false;
	}

	function shipToDifferent() {
		var $classic = $( '#ship-to-different-address-checkbox' );
		if ( $classic.length ) {
			return $classic.is( ':checked' ) ? 1 : 0;
		}
		// Block checkout: when "use same address for billing" is UNchecked, addresses differ.
		var $same = $( '.wc-block-checkout__use-address-for-billing input[type=checkbox]' );
		return $same.length && ! $same.is( ':checked' ) ? 1 : 0;
	}

	function sendFields() {
		if ( ! hasConsent() ) {
			return;
		}

		var fields = collectFields();
		if ( ! Object.keys( fields ).length ) {
			return;
		}

		var payload = {
			action: 'wccr_save_field',
			nonce: WCCR_Public.nonce,
			fields: fields,
			ship_to_different: shipToDifferent(),
			consent: hasConsent() ? 1 : 0
		};

		// Skip identical re-sends (updated_checkout fires often).
		var signature = JSON.stringify( payload );
		if ( signature === lastPayload ) {
			return;
		}
		lastPayload = signature;

		$.post( WCCR_Public.ajax_url, payload );
	}

	function debouncedSend() {
		clearTimeout( debounceTimer );
		debounceTimer = setTimeout( sendFields, 900 );
	}

	var selectors = FIELD_IDS.concat( Object.keys( BLOCK_MAP ) ).map( function ( id ) {
		return '[id="' + id + '"]';
	} ).join( ',' ) + ',.wc-block-checkout__order-notes textarea';

	$( document.body ).on( 'blur change', selectors, debouncedSend );

	// Also fire once shortly after checkout fields update (classic re-render), in case the
	// browser autofilled several fields at once without individual blur events firing on each.
	$( document.body ).on( 'updated_checkout', debouncedSend );

	// Last chance before the tab closes — sendBeacon survives page unload.
	window.addEventListener( 'pagehide', function () {
		if ( ! WCCR_Public.is_checkout || ! navigator.sendBeacon || ! hasConsent() ) {
			return;
		}
		var fields = collectFields();
		if ( ! Object.keys( fields ).length ) {
			return;
		}
		var form = new FormData();
		form.append( 'action', 'wccr_save_field' );
		form.append( 'nonce', WCCR_Public.nonce );
		form.append( 'ship_to_different', shipToDifferent() );
		form.append( 'consent', hasConsent() ? 1 : 0 );
		Object.keys( fields ).forEach( function ( k ) {
			form.append( 'fields[' + k + ']', fields[ k ] == null ? '' : fields[ k ] );
		} );
		navigator.sendBeacon( WCCR_Public.ajax_url, form );
	} );

	/*
	 * Live shopper tracking. The heartbeat only runs when the browser actually has a
	 * WooCommerce cart (woocommerce_items_in_cart cookie) — previously EVERY visitor on
	 * EVERY page hit admin-ajax.php every 30 seconds, which is heavy on shared hosting.
	 */
	function getPageType() {
		if ( WCCR_Public.is_checkout ) return 'checkout';
		if ( $( 'body' ).hasClass( 'single-product' ) ) return 'product';
		if ( $( 'body' ).hasClass( 'woocommerce-cart' ) ) return 'cart';
		return 'page';
	}

	function hasCart() {
		return WCCR_Public.is_checkout || /(?:^|;\s*)woocommerce_items_in_cart=1/.test( document.cookie );
	}

	function sendHeartbeat() {
		if ( document.hidden || ! hasCart() ) {
			return;
		}
		$.post( WCCR_Public.ajax_url, {
			action: 'wccr_heartbeat',
			nonce: WCCR_Public.nonce,
			page_url: window.location.href,
			page_type: getPageType()
		} );
	}

	// First ping shortly after load, then keep presence fresh every 30 seconds.
	setTimeout( sendHeartbeat, 1200 );
	setInterval( sendHeartbeat, parseInt( WCCR_Public.heartbeat_interval || 30000, 10 ) );

	// When a visitor returns to the tab, immediately refresh presence.
	document.addEventListener( 'visibilitychange', function () {
		if ( ! document.hidden ) {
			sendHeartbeat();
		}
	} );

	// A product was just added via AJAX — start the heartbeat right away.
	$( document.body ).on( 'added_to_cart', function () {
		setTimeout( sendHeartbeat, 800 );
	} );

}( jQuery ) );
