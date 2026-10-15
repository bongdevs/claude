/**
 * Fixo Cart — admin dashboard.
 * Uses wp.element (React bundled with WordPress) + wp.apiFetch: no build step required.
 * Every view talks to the wccr/v1 REST namespace.
 */
( function ( wp ) {
	'use strict';

	var el = wp.element.createElement;
	var Fragment = wp.element.Fragment;
	var useState = wp.element.useState;
	var useEffect = wp.element.useEffect;
	var useRef = wp.element.useRef;
	var apiFetch = wp.apiFetch;
	var __ = wp.i18n.__;
	var D = 'fixo-cart';
	var CFG = window.WCCR_Admin || {};

	apiFetch.use( apiFetch.createRootURLMiddleware( CFG.rest_url ) );
	apiFetch.use( apiFetch.createNonceMiddleware( CFG.nonce ) );

	var CURRENCY = CFG.currency_symbol || '$';

	function t( s ) { return __( s, D ); }
	function api( path, opts ) { return apiFetch( Object.assign( { path: '/wccr/v1' + path }, opts || {} ) ); }
	function errMsg( err ) {
		if ( err && err.message ) return err.message;
		if ( err && err.code ) return err.code;
		return t( 'Could not connect to the Fixo Cart API.' );
	}
	function cx() {
		var out = [];
		for ( var i = 0; i < arguments.length; i++ ) if ( arguments[ i ] ) out.push( arguments[ i ] );
		return out.join( ' ' );
	}
	function money( n ) {
		var v = parseFloat( n || 0 );
		var frac = Math.abs( v % 1 ) > 0.004;
		return CURRENCY + v.toLocaleString( undefined, { minimumFractionDigits: frac ? 2 : 0, maximumFractionDigits: 2 } );
	}
	function num( n ) { return ( parseFloat( n || 0 ) ).toLocaleString(); }
	function parseDate( str ) {
		if ( ! str ) return null;
		var d = new Date( String( str ).replace( ' ', 'T' ) );
		return isNaN( d.getTime() ) ? null : d;
	}
	function formatDate( str ) {
		var d = parseDate( str );
		if ( ! d ) return str ? String( str ) : '—';
		return d.toLocaleDateString( [], { day: 'numeric', month: 'short', year: 'numeric' } ) + ', ' + d.toLocaleTimeString( [], { hour: '2-digit', minute: '2-digit' } );
	}
	function shortTime( str ) {
		var d = parseDate( str );
		if ( ! d ) return '—';
		var tm = d.toLocaleTimeString( [], { hour: '2-digit', minute: '2-digit' } );
		return d.toDateString() === new Date().toDateString() ? tm : d.toLocaleDateString( [], { day: 'numeric', month: 'short' } ) + ', ' + tm;
	}
	function duration( seconds ) {
		seconds = parseInt( seconds, 10 );
		if ( isNaN( seconds ) || seconds < 0 ) return '—';
		if ( seconds < 60 ) return seconds + 's';
		var m = Math.floor( seconds / 60 );
		if ( m < 60 ) return m + 'm ' + ( seconds % 60 ) + 's';
		var h = Math.floor( m / 60 );
		if ( h < 24 ) return h + 'h ' + ( m % 60 ) + 'm';
		return Math.floor( h / 24 ) + 'd ' + ( h % 24 ) + 'h';
	}
	function ago( seconds ) {
		seconds = parseInt( seconds, 10 );
		if ( isNaN( seconds ) ) return t( 'Unknown' );
		if ( seconds < 60 ) return t( 'Just now' );
		var m = Math.floor( seconds / 60 );
		if ( m < 60 ) return m + ' ' + t( 'min ago' );
		var h = Math.floor( m / 60 );
		if ( h < 24 ) return h + ' ' + ( 1 === h ? t( 'hour ago' ) : t( 'hours ago' ) );
		var d = Math.floor( h / 24 );
		return 1 === d ? t( 'Yesterday' ) : d + ' ' + t( 'days ago' );
	}
	function shortUrl( url ) {
		if ( ! url ) return '';
		var s = String( url ).replace( /^https?:\/\/(www\.)?/, '' );
		return s.length > 60 ? s.slice( 0, 57 ) + '…' : s;
	}
	function initials( name ) {
		if ( ! name ) return '?';
		var p = String( name ).trim().split( /\s+/ );
		return ( ( p[ 0 ] || '' ).charAt( 0 ) + ( ( p[ 1 ] || '' ).charAt( 0 ) ) ).toUpperCase() || '?';
	}
	function hue( name ) { return ( String( name || 'x' ).charCodeAt( 0 ) * 37 ) % 360; }
	function qs( obj ) {
		return Object.keys( obj ).filter( function ( k ) { return obj[ k ] !== '' && obj[ k ] !== null && obj[ k ] !== undefined; } )
			.map( function ( k ) { return encodeURIComponent( k ) + '=' + encodeURIComponent( obj[ k ] ); } ).join( '&' );
	}
	function cartName( c ) { return ( c && ( c.full_name || [ c.first_name, c.last_name ].filter( Boolean ).join( ' ' ) ) ) || ''; }
	function variationText( it ) {
		if ( ! it || ! it.variation || 'object' !== typeof it.variation ) return '';
		return Object.keys( it.variation ).map( function ( k ) { return it.variation[ k ]; } ).filter( Boolean ).join( ' / ' );
	}
	function storageGet( k, d ) { try { var v = window.localStorage.getItem( k ); return null === v ? d : v; } catch ( e ) { return d; } }
	function storageSet( k, v ) { try { window.localStorage.setItem( k, v ); } catch ( e ) {} }

	/** Fill gaps so a 30-day chart always has 30 columns. */
	function fillDays( rows, days, keys ) {
		var map = {};
		( rows || [] ).forEach( function ( r ) { map[ String( r.day ).slice( 0, 10 ) ] = r; } );
		var out = [];
		var now = new Date();
		for ( var i = days - 1; i >= 0; i-- ) {
			var d = new Date( now.getFullYear(), now.getMonth(), now.getDate() - i );
			var key = d.getFullYear() + '-' + String( d.getMonth() + 1 ).padStart( 2, '0' ) + '-' + String( d.getDate() ).padStart( 2, '0' );
			var row = { day: key, label: d.toLocaleDateString( [], { day: 'numeric', month: 'short' } ) };
			keys.forEach( function ( k ) { row[ k ] = map[ key ] ? parseFloat( map[ key ][ k ] || 0 ) : 0; } );
			out.push( row );
		}
		return out;
	}

	/* ------------------------------------------------------------------ toasts + confirm */
	var toastListeners = [];
	var toastSeq = 0;
	function toast( type, text, opts ) {
		opts = opts || {};
		var item = { id: ++toastSeq, type: type || 'info', text: text, link: opts.link || '', linkText: opts.linkText || t( 'Open' ), duration: undefined !== opts.duration ? opts.duration : ( 'error' === type ? 7000 : 4500 ) };
		toastListeners.forEach( function ( fn ) { fn( item ); } );
	}
	toast.success = function ( s, o ) { toast( 'success', s, o ); };
	toast.error = function ( s, o ) { toast( 'error', s, o ); };
	toast.info = function ( s, o ) { toast( 'info', s, o ); };
	toast.warning = function ( s, o ) { toast( 'warning', s, o ); };

	var confirmListeners = [];
	function confirmDialog( req ) {
		return new Promise( function ( resolve ) { confirmListeners.forEach( function ( fn ) { fn( Object.assign( {}, req, { resolve: resolve } ) ); } ); } );
	}

	/** Esc closes the top-most overlay; the page behind it stops scrolling. */
	var modalStack = [];
	document.addEventListener( 'keydown', function ( e ) {
		if ( 'Escape' === e.key && modalStack.length ) modalStack[ modalStack.length - 1 ].current();
	} );
	function useModal( active, onClose ) {
		var ref = useRef( onClose );
		ref.current = onClose;
		useEffect( function () {
			if ( ! active ) return undefined;
			modalStack.push( ref );
			document.body.classList.add( 'cr-modal-open' );
			return function () {
				modalStack = modalStack.filter( function ( r ) { return r !== ref; } );
				if ( ! modalStack.length ) document.body.classList.remove( 'cr-modal-open' );
			};
		}, [ active ] );
	}

	/* ------------------------------------------------------------------ Icon */
	function P( d ) { return [ 'path', { d: d } ]; }
	function Ci( x, y, r, fill ) { return [ 'circle', { cx: x, cy: y, r: r, fill: fill ? 'currentColor' : 'none', stroke: fill ? 'none' : undefined } ]; }
	function Re( x, y, w, h, rx ) { return [ 'rect', { x: x, y: y, width: w, height: h, rx: rx } ]; }
	var ICONS = {
		cart: [ P( 'M3 4h2.2l2.3 10.2a1 1 0 0 0 1 .8h8.8a1 1 0 0 0 1-.8L20 8H6.3' ), Ci( 9.5, 19, 1.4 ), Ci( 16.5, 19, 1.4 ) ],
		mail: [ Re( 3, 5, 18, 14, 2 ), P( 'm3.5 7 8.5 6 8.5-6' ) ],
		chat: [ P( 'M20 12a8 8 0 0 1-11.6 7.1L4 20l1.1-4.2A8 8 0 1 1 20 12z' ), P( 'M9 10.5c.3 1.8 2.2 3.7 4 4l1.2-1.2 1.6.8' ) ],
		phone: [ P( 'M5.5 4h2.8l1.8 4.6-2.3 1.4a11 11 0 0 0 6.2 6.2l1.4-2.3 4.6 1.8v2.8a1.5 1.5 0 0 1-1.6 1.5A16 16 0 0 1 4 5.6 1.5 1.5 0 0 1 5.5 4z' ) ],
		eye: [ P( 'M2.5 12s3.5-6.5 9.5-6.5 9.5 6.5 9.5 6.5-3.5 6.5-9.5 6.5S2.5 12 2.5 12z' ), Ci( 12, 12, 2.8 ) ],
		route: [ Ci( 6, 18.5, 2 ), Ci( 18, 5.5, 2 ), P( 'M8 18.5h8.5a3.25 3.25 0 0 0 0-6.5h-9a3.25 3.25 0 0 1 0-6.5H16' ) ],
		trash: [ P( 'M4 7h16' ), P( 'M10 11v6' ), P( 'M14 11v6' ), P( 'M6 7l1 12a2 2 0 0 0 2 2h6a2 2 0 0 0 2-2l1-12' ), P( 'M9 7V4h6v3' ) ],
		bell: [ P( 'M6 16v-5a6 6 0 1 1 12 0v5l1.5 2h-15z' ), P( 'M10 20.5a2 2 0 0 0 4 0' ) ],
		refresh: [ P( 'M19.5 11A7.5 7.5 0 0 0 6 6.6L4.5 8' ), P( 'M4.5 4v4h4' ), P( 'M4.5 13A7.5 7.5 0 0 0 18 17.4l1.5-1.4' ), P( 'M19.5 20v-4h-4' ) ],
		download: [ P( 'M12 4v11' ), P( 'm7.5 10.5 4.5 4.5 4.5-4.5' ), P( 'M5 20h14' ) ],
		search: [ Ci( 11, 11, 6.5 ), P( 'm20 20-4.3-4.3' ) ],
		filter: [ P( 'M4 5h16l-6 7.5V19l-4-2v-4.5z' ) ],
		columns: [ Re( 3, 4, 18, 16, 2 ), P( 'M9 4v16' ), P( 'M15 4v16' ) ],
		chevronDown: [ P( 'm6 9 6 6 6-6' ) ],
		chevronLeft: [ P( 'm15 6-6 6 6 6' ) ],
		chevronRight: [ P( 'm9 6 6 6-6 6' ) ],
		x: [ P( 'M6 6l12 12M18 6 6 18' ) ],
		check: [ P( 'm5 12.5 4.5 4.5L19.5 7' ) ],
		alert: [ P( 'M10.3 4.2 2.6 18a2 2 0 0 0 1.7 3h15.4a2 2 0 0 0 1.7-3L13.7 4.2a2 2 0 0 0-3.4 0z' ), P( 'M12 9.5v4' ), P( 'M12 17.2v.1' ) ],
		info: [ Ci( 12, 12, 9 ), P( 'M12 11v5' ), P( 'M12 7.8v.1' ) ],
		clock: [ Ci( 12, 12, 9 ), P( 'M12 7v5l3 2' ) ],
		link: [ P( 'M10 14a4 4 0 0 0 5.7 0l3-3a4 4 0 0 0-5.7-5.7l-1 1' ), P( 'M14 10a4 4 0 0 0-5.7 0l-3 3a4 4 0 0 0 5.7 5.7l1-1' ) ],
		copy: [ Re( 8, 8, 12, 12, 2 ), P( 'M16 8V6a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v8a2 2 0 0 0 2 2h2' ) ],
		external: [ P( 'M14 4h6v6' ), P( 'M20 4l-9 9' ), P( 'M18 14v4a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4' ) ],
		sliders: [ P( 'M4 6h10' ), P( 'M18 6h2' ), P( 'M4 12h4' ), P( 'M12 12h8' ), P( 'M4 18h12' ), Ci( 16, 6, 2 ), Ci( 10, 12, 2 ), Ci( 18, 18, 2 ) ],
		grid: [ Re( 4, 4, 7, 7, 1.5 ), Re( 13, 4, 7, 7, 1.5 ), Re( 4, 13, 7, 7, 1.5 ), Re( 13, 13, 7, 7, 1.5 ) ],
		users: [ Ci( 9, 8, 3.5 ), P( 'M3 20a6 6 0 0 1 12 0' ), P( 'M16 4.6a3.5 3.5 0 0 1 0 6.8' ), P( 'M18 14.2a6 6 0 0 1 3 5.8' ) ],
		box: [ P( 'M3.5 7.5 12 3.2l8.5 4.3v9L12 20.8l-8.5-4.3z' ), P( 'M3.5 7.5 12 12l8.5-4.5' ), P( 'M12 12v8.8' ) ],
		megaphone: [ P( 'M4 10v4a1 1 0 0 0 1 1h2l8 4V5L7 9H5a1 1 0 0 0-1 1z' ), P( 'M18.5 9a4 4 0 0 1 0 6' ), P( 'M7.5 15l1 5' ) ],
		coins: [ Ci( 9, 9, 6 ), P( 'M15.6 9.4a6 6 0 1 1-6.2 6.2' ) ],
		pulse: [ P( 'M3 12h4l2-5 4 10 2-5h6' ) ],
		user: [ Ci( 12, 8, 4 ), P( 'M4 21a8 8 0 0 1 16 0' ) ],
		pin: [ P( 'M12 21s-7-6.2-7-11.5a7 7 0 0 1 14 0C19 14.8 12 21 12 21z' ), Ci( 12, 9.5, 2.5 ) ],
		note: [ P( 'M5 4h10l4 4v12H5z' ), P( 'M14 4v5h5' ), P( 'M8 13h8' ), P( 'M8 17h5' ) ],
		zap: [ P( 'M13 3 4.5 14H11l-1 7 8.5-11H12z' ) ],
		arrowUp: [ P( 'M12 19V5' ), P( 'm6 11 6-6 6 6' ) ],
		arrowDown: [ P( 'M12 5v14' ), P( 'm6 13 6 6 6-6' ) ],
		monitor: [ Re( 3, 4, 18, 12, 2 ), P( 'M8 20h8' ), P( 'M12 16v4' ) ],
		smartphone: [ Re( 7, 3, 10, 18, 2 ), P( 'M11 17.5h2' ) ],
		plus: [ P( 'M12 5v14M5 12h14' ) ],
		receipt: [ P( 'M6 3h12v18l-3-2-3 2-3-2-3 2z' ), P( 'M9 8h6' ), P( 'M9 12h6' ) ],
		tag: [ P( 'M3.5 12.2V4.5a1 1 0 0 1 1-1h7.7l8.3 8.3a1 1 0 0 1 0 1.4l-7.3 7.3a1 1 0 0 1-1.4 0z' ), Ci( 8, 8, 1.5 ) ],
		shield: [ P( 'M12 3 5 6v5c0 4.5 3 8.3 7 10 4-1.7 7-5.5 7-10V6z' ) ],
		send: [ P( 'M21 3 10 14' ), P( 'M21 3l-7 18-4-7-7-4z' ) ],
		telegram: [ P( 'M21 4 3 11l5 2 2 6 3-4 5 4z' ), P( 'm8 13 9-6-7 8' ) ],
		undo: [ P( 'M9 14 4 9l5-5' ), P( 'M4 9h10.5a5.5 5.5 0 0 1 0 11H11' ) ],
		moon: [ P( 'M20 14.5A8 8 0 1 1 9.5 4a6.5 6.5 0 0 0 10.5 10.5z' ) ],
		sun: [ Ci( 12, 12, 4 ), P( 'M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4' ) ],
		bag: [ P( 'M5 8h14l-1 12H6z' ), P( 'M9 8V6a3 3 0 0 1 6 0v2' ) ],
		sidebar: [ Re( 3, 4, 18, 16, 2 ), P( 'M9 4v16' ), P( 'm15 10-2 2 2 2' ) ],
		sidebarOpen: [ Re( 3, 4, 18, 16, 2 ), P( 'M9 4v16' ), P( 'm13 10 2 2-2 2' ) ]
	};
	function Icon( props ) {
		var size = props.size || 16;
		var shapes = ICONS[ props.name ] || ICONS.info;
		return el( 'svg', {
			className: cx( 'cr-icon', props.className ), width: size, height: size, viewBox: '0 0 24 24', fill: 'none', stroke: 'currentColor',
			strokeWidth: props.strokeWidth || 1.7, strokeLinecap: 'round', strokeLinejoin: 'round', 'aria-hidden': 'true', focusable: 'false'
		}, shapes.map( function ( s, i ) { return el( s[ 0 ], Object.assign( { key: i }, s[ 1 ] ) ); } ) );
	}

	/* ------------------------------------------------------------------ primitives */
	function Button( props ) {
		var variant = props.variant || 'secondary';
		var size = props.size || 'md';
		var iconOnly = props.icon && ! props.children;
		var rest = Object.assign( {}, props );
		[ 'variant', 'size', 'icon', 'iconRight', 'children', 'className', 'loading' ].forEach( function ( k ) { delete rest[ k ]; } );
		var tag = props.href ? 'a' : 'button';
		if ( ! props.href && ! rest.type ) rest.type = 'button';
		if ( iconOnly && ! rest[ 'aria-label' ] ) rest[ 'aria-label' ] = rest.title;
		if ( props.href && props.disabled ) { delete rest.href; rest[ 'aria-disabled' ] = 'true'; }
		return el( tag, Object.assign( rest, { className: cx( 'cr-btn', 'cr-btn--' + variant, 'cr-btn--' + size, iconOnly && 'cr-btn--icon', props.loading && 'is-loading', props.className ) } ),
			props.loading ? el( 'span', { className: 'cr-spinner', 'aria-hidden': 'true' } ) : ( props.icon ? el( Icon, { name: props.icon, size: 'sm' === size ? 14 : 16 } ) : null ),
			props.children ? el( 'span', null, props.children ) : null,
			props.iconRight ? el( Icon, { name: props.iconRight, size: 14 } ) : null
		);
	}

	var STATUS = { active: t( 'Active' ), abandoned: t( 'Abandoned' ), recovered: t( 'Recovering' ), converted: t( 'Converted' ), emptied: t( 'Emptied' ) };
	function StatusBadge( props ) {
		var s = STATUS[ props.status ] ? props.status : 'emptied';
		return el( 'span', { className: 'cr-badge cr-badge--' + s }, el( 'span', { className: 'cr-badge-dot', 'aria-hidden': 'true' } ), props.label || STATUS[ s ] );
	}
	var ORDER_TONE = { pending: 'recovered', processing: 'active', 'on-hold': 'recovered', completed: 'converted', cancelled: 'emptied', refunded: 'emptied', failed: 'abandoned', 'checkout-draft': 'emptied' };
	function OrderBadge( props ) {
		return el( 'span', { className: 'cr-badge cr-badge--' + ( ORDER_TONE[ props.status ] || 'emptied' ) }, el( 'span', { className: 'cr-badge-dot', 'aria-hidden': 'true' } ), props.label || props.status );
	}
	function RiskBadge( props ) {
		var r = props.risk;
		if ( ! r || 'normal' === r ) return null;
		return el( 'span', { className: 'cr-risk cr-risk--' + r, title: props.title }, el( Icon, { name: 'bot' === r ? 'shield' : 'copy', size: 12 } ), 'bot' === r ? t( 'Bot' ) : t( 'Duplicate' ) );
	}
	function Presence( props ) {
		var c = props.cart || {};
		var state = 'live' === c.presence_status ? 'live' : ( 'inactive' === c.presence_status ? 'inactive' : 'recent' );
		var label = 'live' === state ? t( 'Live now' ) : ( null !== c.presence_age && undefined !== c.presence_age ? ago( c.presence_age ) : t( 'Unknown' ) );
		return el( 'span', { className: 'cr-presence cr-presence--' + state, title: formatDate( c.updated_at ) }, el( 'span', { className: 'cr-presence-dot', 'aria-hidden': 'true' } ), label );
	}
	function SourceChip( props ) {
		var src = props.source, medium = props.medium;
		if ( ! src ) return el( 'span', { className: 'cr-muted' }, t( 'Direct / none' ) );
		return el( 'span', { className: 'cr-source', title: props.title }, el( 'span', { className: 'cr-source-name' }, src ), medium ? el( 'span', { className: 'cr-source-medium' }, medium ) : null );
	}
	function cartSource( c ) {
		if ( c.utm_source ) return { source: c.utm_source, medium: c.utm_medium };
		if ( c.source_label && ! /direct/i.test( c.source_label ) ) return { source: c.source_label };
		return { source: '' };
	}
	function Sparkline( props ) {
		var data = props.data || [];
		var w = props.width || 84, h = props.height || 28;
		if ( data.length < 2 ) return null;
		var max = Math.max.apply( null, data ), min = Math.min.apply( null, data ), span = max - min || 1;
		var pts = data.map( function ( v, i ) { return [ ( i / ( data.length - 1 ) ) * w, h - 2 - ( ( v - min ) / span ) * ( h - 4 ) ]; } );
		var line = pts.map( function ( p, i ) { return ( i ? 'L' : 'M' ) + p[ 0 ].toFixed( 1 ) + ' ' + p[ 1 ].toFixed( 1 ); } ).join( ' ' );
		return el( 'svg', { className: 'cr-spark cr-tone-' + ( props.tone || 'brand' ), width: w, height: h, viewBox: '0 0 ' + w + ' ' + h, 'aria-hidden': 'true' },
			el( 'path', { d: line + ' L' + w + ' ' + h + ' L0 ' + h + ' Z', className: 'cr-spark-area' } ),
			el( 'path', { d: line, className: 'cr-spark-line' } ),
			el( 'circle', { cx: pts[ pts.length - 1 ][ 0 ] - 1.5, cy: pts[ pts.length - 1 ][ 1 ], r: 2.5, className: 'cr-spark-dot' } ) );
	}
	function pctDelta( cur, prev ) {
		cur = parseFloat( cur || 0 ); prev = parseFloat( prev || 0 );
		if ( ! prev ) return null;
		return Math.round( ( ( cur - prev ) / prev ) * 1000 ) / 10;
	}
	function KpiCard( props ) {
		var d = props.delta;
		var hasDelta = null !== d && undefined !== d && ! isNaN( d );
		var good = props.invert ? d < 0 : d >= 0;
		return el( 'div', { className: 'cr-kpi' },
			el( 'div', { className: 'cr-kpi-head' },
				props.icon ? el( 'span', { className: 'cr-kpi-icon cr-tone-' + ( props.tone || 'brand' ) }, el( Icon, { name: props.icon, size: 15 } ) ) : null,
				el( 'span', { className: 'cr-kpi-label' }, props.label ) ),
			el( 'div', { className: 'cr-kpi-body' },
				el( 'div', { className: 'cr-kpi-value' }, props.loading ? el( 'span', { className: 'cr-skel cr-skel--kpi' } ) : props.value ),
				props.trend ? el( Sparkline, { data: props.trend, tone: props.tone } ) : null ),
			hasDelta ? el( 'div', { className: 'cr-kpi-foot' },
				el( 'span', { className: cx( 'cr-delta', good ? 'is-good' : 'is-bad' ) }, el( Icon, { name: d >= 0 ? 'arrowUp' : 'arrowDown', size: 12, strokeWidth: 2 } ), Math.abs( d ) + '%' ),
				el( 'span', { className: 'cr-muted' }, props.deltaLabel || t( 'vs previous period' ) ) )
				: ( props.hint ? el( 'div', { className: 'cr-kpi-foot cr-muted' }, props.hint ) : null ) );
	}
	function Card( props ) {
		return el( 'section', { className: cx( 'cr-card', props.flush && 'cr-card--flush', props.className ) },
			props.title || props.actions ? el( 'header', { className: 'cr-card-head' },
				el( 'div', null, props.title ? el( 'h3', { className: 'cr-card-title' }, props.title ) : null, props.description ? el( 'p', { className: 'cr-card-desc' }, props.description ) : null ),
				props.actions ? el( 'div', { className: 'cr-card-actions' }, props.actions ) : null ) : null,
			el( 'div', { className: 'cr-card-body' }, props.children ) );
	}
	function Segmented( props ) {
		return el( 'div', { className: 'cr-seg', role: 'tablist', 'aria-label': props.label },
			( props.options || [] ).map( function ( o ) {
				var on = o.value === props.value;
				return el( 'button', { key: o.value, type: 'button', role: 'tab', 'aria-selected': on, className: cx( 'cr-seg-item', on && 'is-on' ), onClick: function () { props.onChange( o.value ); } },
					o.icon ? el( Icon, { name: o.icon, size: 14 } ) : null, o.label,
					undefined !== o.count && null !== o.count ? el( 'span', { className: 'cr-seg-count' }, num( o.count ) ) : null );
			} ) );
	}
	function SearchField( props ) {
		var _v = useState( props.value || '' ), v = _v[ 0 ], setV = _v[ 1 ];
		var timer = useRef( null );
		useEffect( function () { setV( props.value || '' ); }, [ props.value ] );
		return el( 'label', { className: 'cr-search' }, el( Icon, { name: 'search', size: 15 } ),
			el( 'input', { type: 'search', value: v, placeholder: props.placeholder, 'aria-label': props.placeholder,
				onChange: function ( e ) {
					var val = e.target.value; setV( val );
					clearTimeout( timer.current );
					timer.current = setTimeout( function () { props.onChange( val ); }, 350 );
				} } ) );
	}
	function Select( props ) {
		return el( 'span', { className: cx( 'cr-select', props.icon && 'has-icon', props.className ) },
			props.icon ? el( Icon, { name: props.icon, size: 15 } ) : null,
			el( 'select', { value: props.value, 'aria-label': props.label, disabled: props.disabled, onChange: function ( e ) { props.onChange( e.target.value ); } },
				( props.options || [] ).map( function ( o ) { return el( 'option', { key: o.value, value: o.value }, o.label ); } ) ),
			el( Icon, { name: 'chevronDown', size: 14, className: 'cr-select-caret' } ) );
	}
	/** Switch bound to a 'yes' / 'no' setting. */
	function Toggle( props ) {
		var on = 'yes' === props.value || true === props.value;
		return el( 'div', { className: cx( 'cr-toggle', props.disabled && 'is-disabled' ) },
			el( 'button', { type: 'button', role: 'switch', 'aria-checked': on, 'aria-label': props.label || props.ariaLabel, disabled: props.disabled, className: cx( 'cr-switch', on && 'is-on' ),
				onClick: function () { props.onChange( on ? 'no' : 'yes' ); } }, el( 'span', { className: 'cr-switch-knob' } ) ),
			props.label ? el( 'div', { className: 'cr-toggle-text', onClick: function () { if ( ! props.disabled ) props.onChange( on ? 'no' : 'yes' ); } },
				el( 'span', { className: 'cr-toggle-label' }, props.label ),
				props.description ? el( 'span', { className: 'cr-toggle-desc' }, props.description ) : null ) : null );
	}
	function Field( props ) {
		return el( 'div', { className: cx( 'cr-field', props.className ) },
			props.label ? el( 'label', { className: 'cr-field-label' }, props.label ) : null,
			el( 'div', { className: 'cr-field-control' }, props.children ),
			props.help ? el( 'p', { className: 'cr-field-help' }, props.help ) : null );
	}
	function TextInput( props ) {
		var rest = Object.assign( {}, props );
		delete rest.onChange; delete rest.suffix;
		var input = el( 'input', Object.assign( { type: 'text', autoComplete: 'password' === props.type ? 'new-password' : 'off' }, rest, {
			value: undefined === props.value || null === props.value ? '' : props.value,
			onChange: function ( e ) { props.onChange( e.target.value ); } } ) );
		return props.suffix ? el( 'div', { className: 'cr-input-suffix' }, input, props.suffix ) : input;
	}
	function EmptyState( props ) {
		return el( 'div', { className: cx( 'cr-empty', props.compact && 'is-compact' ) },
			el( 'span', { className: 'cr-empty-icon' }, el( Icon, { name: props.icon || 'cart', size: 20 } ) ),
			el( 'div', { className: 'cr-empty-title' }, props.title ),
			props.description ? el( 'p', null, props.description ) : null,
			props.action || null );
	}
	function Loading( props ) {
		return el( 'div', { className: 'cr-loading', 'aria-busy': 'true' }, Array.apply( null, Array( props.rows || 5 ) ).map( function ( _, i ) { return el( 'div', { key: i, className: 'cr-skel' } ); } ) );
	}
	function Avatar( props ) {
		return el( 'span', { className: cx( 'cr-avatar', props.large && 'cr-avatar--lg' ), 'aria-hidden': 'true' }, initials( props.name ) );
	}
	function CustomerCell( props ) {
		var meta = [ props.phone, props.email ].filter( Boolean ).join( ' · ' );
		return el( 'div', { className: 'cr-customer' },
			el( Avatar, { name: props.name || props.email || props.phone } ),
			el( 'div', { className: 'cr-customer-text' },
				el( 'div', { className: 'cr-customer-name' }, props.name || el( 'span', { className: 'cr-muted' }, t( 'Guest' ) ), props.risk ? el( RiskBadge, { risk: props.risk } ) : null ),
				el( 'div', { className: 'cr-customer-meta' }, meta || t( 'No contact captured' ) ) ) );
	}
	function Thumb( props ) {
		var it = props.item || {};
		var cls = cx( 'cr-thumb', props.large && 'cr-thumb--lg' );
		if ( it.image ) return el( 'img', { className: cls, src: it.image, alt: '', loading: 'lazy', title: it.name } );
		return el( 'span', { className: cls + ' cr-thumb--ph', title: it.name, style: { '--hue': hue( it.name ) } }, props.single ? initials( it.name ) : initials( it.name ).charAt( 0 ) );
	}
	function ProductStack( props ) {
		var items = props.items || [];
		var max = props.max || 3;
		if ( ! items.length ) return el( 'span', { className: 'cr-muted' }, '—' );
		var qty = items.reduce( function ( s, it ) { return s + ( parseInt( it.quantity, 10 ) || 1 ); }, 0 );
		var first = items[ 0 ];
		var v = variationText( first );
		return el( 'div', { className: 'cr-products' },
			el( 'div', { className: 'cr-thumbs' }, items.slice( 0, max ).map( function ( it, i ) { return el( Thumb, { key: i, item: it, single: 1 === items.length } ); } ),
				items.length > max ? el( 'span', { className: 'cr-thumb cr-thumb--more' }, '+' + ( items.length - max ) ) : null ),
			el( 'div', { className: 'cr-products-text' },
				el( 'div', { className: 'cr-products-name', title: items.map( function ( it ) { return ( it.name || '#' + it.product_id ) + ' × ' + ( it.quantity || 1 ); } ).join( '\n' ) }, ( first.name || '#' + first.product_id ) + ( v ? ' · ' + v : '' ) ),
				el( 'div', { className: 'cr-muted' }, qty + ' ' + ( 1 === qty ? t( 'item' ) : t( 'items' ) ) + ( items.length > 1 ? ' · ' + items.length + ' ' + t( 'products' ) : '' ) ) ) );
	}
	function Pagination( props ) {
		var page = props.page || 1, pages = Math.max( 1, props.pages || 1 ), per = props.perPage || 20, total = props.total || 0;
		var from = total ? ( page - 1 ) * per + 1 : 0, to = Math.min( total, page * per );
		var nums = [];
		for ( var i = 1; i <= pages; i++ ) {
			if ( 1 === i || i === pages || Math.abs( i - page ) <= 1 ) nums.push( i );
			else if ( '…' !== nums[ nums.length - 1 ] ) nums.push( '…' );
		}
		return el( 'nav', { className: 'cr-pager', 'aria-label': t( 'Pagination' ) },
			el( 'span', { className: 'cr-muted' }, total ? t( 'Showing' ) + ' ' + from + '–' + to + ' ' + t( 'of' ) + ' ' + num( total ) : '' ),
			pages > 1 ? el( 'div', { className: 'cr-pager-btns' },
				el( Button, { size: 'sm', variant: 'ghost', icon: 'chevronLeft', title: t( 'Previous page' ), disabled: page <= 1, onClick: function () { props.onPage( page - 1 ); } } ),
				nums.map( function ( n, idx ) {
					return '…' === n ? el( 'span', { key: 'g' + idx, className: 'cr-pager-gap' }, '…' )
						: el( 'button', { key: n, type: 'button', className: cx( 'cr-pager-num', n === page && 'is-on' ), 'aria-current': n === page ? 'page' : undefined, onClick: function () { props.onPage( n ); } }, n );
				} ),
				el( Button, { size: 'sm', variant: 'ghost', icon: 'chevronRight', title: t( 'Next page' ), disabled: page >= pages, onClick: function () { props.onPage( page + 1 ); } } ) ) : null );
	}
	function CopyField( props ) {
		var _c = useState( false ), copied = _c[ 0 ], setCopied = _c[ 1 ];
		function copy() {
			function done() { setCopied( true ); toast.success( t( 'Copied to clipboard' ), { duration: 1800 } ); setTimeout( function () { setCopied( false ); }, 1600 ); }
			if ( navigator.clipboard && window.isSecureContext ) { navigator.clipboard.writeText( props.value ).then( done ).catch( function () {} ); return; }
			var ta = document.createElement( 'textarea' ); ta.value = props.value; document.body.appendChild( ta ); ta.select();
			try { document.execCommand( 'copy' ); done(); } catch ( e ) {} document.body.removeChild( ta );
		}
		return el( 'div', { className: 'cr-copy' },
			props.label ? el( 'span', { className: 'cr-copy-label' }, props.label ) : null,
			el( 'div', { className: 'cr-copy-box' },
				el( 'code', { className: 'cr-mono', title: props.value }, props.value ),
				props.href ? el( Button, { size: 'sm', variant: 'ghost', icon: 'external', title: t( 'Open' ), href: props.href, target: '_blank', rel: 'noreferrer' } ) : null,
				el( Button, { size: 'sm', variant: 'ghost', icon: copied ? 'check' : 'copy', onClick: copy }, copied ? t( 'Copied' ) : t( 'Copy' ) ) ) );
	}
	function DataTable( props ) {
		var cols = props.columns || [];
		if ( ! ( props.rows || [] ).length ) return el( EmptyState, { icon: props.emptyIcon || 'chart', title: props.emptyTitle || t( 'No data in this period' ), description: props.emptyText, compact: true } );
		return el( 'div', { className: 'cr-table-wrap' },
			el( 'table', { className: cx( 'cr-table', 'cr-table--compact', props.onRow && 'is-clickable' ) },
				el( 'thead', null, el( 'tr', null, cols.map( function ( c ) { return el( 'th', { key: c.key, className: 'right' === c.align ? 'is-num' : null }, c.label ); } ) ) ),
				el( 'tbody', null, props.rows.map( function ( r, i ) {
					return el( 'tr', { key: r.id || r.key || i, onClick: props.onRow ? function () { props.onRow( r ); } : undefined },
						cols.map( function ( c ) { return el( 'td', { key: c.key, className: 'right' === c.align ? 'is-num' : null }, c.render ? c.render( r ) : r[ c.key ] ); } ) );
				} ) ) ) );
	}

	/* ------------------------------------------------------------------ charts */
	function BarChart( props ) {
		var data = props.data || [];
		var series = props.series || [];
		var fmt = props.format || num;
		var _h = useState( null ), hover = _h[ 0 ], setHover = _h[ 1 ];
		var _w = useState( 640 ), W = _w[ 0 ], setW = _w[ 1 ];
		var box = useRef( null );
		// Draw at the real pixel width so axis text keeps its size on wide cards.
		useEffect( function () {
			if ( ! box.current ) return undefined;
			function measure() { if ( box.current ) setW( Math.max( 280, Math.round( box.current.getBoundingClientRect().width ) ) ); }
			measure();
			if ( ! window.ResizeObserver ) { window.addEventListener( 'resize', measure ); return function () { window.removeEventListener( 'resize', measure ); }; }
			var ro = new window.ResizeObserver( measure ); ro.observe( box.current );
			return function () { ro.disconnect(); };
		}, [] );
		var H = props.height || 200, padL = 52, padB = 22;
		var max = data.reduce( function ( m, d ) { return Math.max( m, series.reduce( function ( s, se ) { return s + ( d[ se.key ] || 0 ); }, 0 ) ); }, 0 );
		if ( ! max ) return el( 'div', { ref: box }, el( EmptyState, { icon: 'chart', title: t( 'No activity in this period' ), compact: true } ) );
		var mag = Math.pow( 10, Math.floor( Math.log10( max ) ) );
		var nice = Math.ceil( max / mag ) * mag;
		var bw = ( W - padL ) / Math.max( data.length, 1 );
		var y = function ( v ) { return ( H - padB ) - ( v / nice ) * ( H - padB - 8 ); };
		var cur = null !== hover ? data[ hover ] : null;
		var step = Math.ceil( data.length / Math.max( 3, Math.floor( W / 90 ) ) );
		return el( 'div', { className: 'cr-chart', ref: box },
			el( 'div', { className: 'cr-legend' },
				series.map( function ( s ) { return el( 'span', { key: s.key, className: 'cr-legend-item' }, el( 'i', { className: 'cr-sw cr-sw--' + s.tone } ), s.label ); } ),
				cur ? el( 'span', { className: 'cr-legend-read' }, el( 'strong', null, cur.label ), series.map( function ( s ) { return ' · ' + s.label + ' ' + fmt( cur[ s.key ] ); } ).join( '' ) ) : null ),
			el( 'svg', { viewBox: '0 0 ' + W + ' ' + H, width: W, height: H, className: 'cr-chart-svg', role: 'img', 'aria-label': props.label, onMouseLeave: function () { setHover( null ); } },
				[ 0, nice / 2, nice ].map( function ( tk, i ) {
					return el( 'g', { key: i }, el( 'line', { x1: padL, x2: W, y1: y( tk ), y2: y( tk ), className: 'cr-grid' } ),
						el( 'text', { x: padL - 6, y: y( tk ) + 3.5, className: 'cr-axis', textAnchor: 'end' }, props.axisFormat ? props.axisFormat( tk ) : num( tk ) ) );
				} ),
				data.map( function ( d, i ) {
					var x = padL + i * bw + bw * 0.18, w = Math.max( 2, bw * 0.64 );
					var acc = 0;
					return el( 'g', { key: i, className: cx( 'cr-col', hover === i && 'is-hover' ), onMouseEnter: function () { setHover( i ); } },
						el( 'rect', { x: padL + i * bw, y: 0, width: bw, height: H - padB, className: 'cr-col-hit' } ),
						series.map( function ( s, si ) {
							var v = d[ s.key ] || 0;
							var top = y( acc + v ), bottom = y( acc );
							acc += v;
							return v ? el( 'rect', { key: si, x: x, y: top, width: w, height: Math.max( 0, bottom - top - ( si ? 1 : 0 ) ), rx: si === series.length - 1 ? 2 : 0, className: 'cr-bar cr-bar--' + s.tone } ) : null;
						} ),
						0 === i % step ? el( 'text', { x: x + w / 2, y: H - 6, className: 'cr-axis', textAnchor: 'middle' }, d.label ) : null );
				} ) ) );
	}
	function SourceBreakdown( props ) {
		var rows = ( props.rows || [] ).slice( 0, 6 );
		if ( ! rows.length ) return el( EmptyState, { icon: 'megaphone', title: t( 'No traffic data yet' ), compact: true } );
		var max = rows.reduce( function ( m, r ) { return Math.max( m, r.carts ); }, 1 );
		return el( 'ul', { className: 'cr-sources' }, rows.map( function ( r ) {
			var carts = parseInt( r.carts, 10 ) || 0, rec = parseInt( r.converted || r.recovered || 0, 10 ), ab = parseInt( r.abandoned, 10 ) || 0;
			var rate = carts ? Math.round( ( rec / carts ) * 100 ) : 0;
			return el( 'li', { key: r.source },
				el( 'div', { className: 'cr-sources-row' }, el( 'span', { className: 'cr-sources-name' }, r.source ),
					el( 'span', { className: 'cr-muted' }, carts + ' ' + t( 'carts' ) + ' · ', el( 'strong', { className: 'cr-ink' }, rate + '%' ), ' ' + t( 'recovered' ) ) ),
				el( 'div', { className: 'cr-meter', style: { width: ( carts / max * 100 ) + '%' } },
					el( 'span', { className: 'cr-meter-rec', style: { width: ( carts ? rec / carts * 100 : 0 ) + '%' } } ),
					el( 'span', { className: 'cr-meter-ab', style: { width: ( carts ? ab / carts * 100 : 0 ) + '%' } } ) ) );
		} ) );
	}

	/* ------------------------------------------------------------------ toaster + confirm host */
	var TOAST_ICON = { success: 'check', error: 'alert', info: 'info', warning: 'alert' };
	function Toaster() {
		var _i = useState( [] ), items = _i[ 0 ], setItems = _i[ 1 ];
		function remove( id ) { setItems( function ( p ) { return p.filter( function ( x ) { return x.id !== id; } ); } ); }
		useEffect( function () {
			function add( item ) {
				setItems( function ( p ) { return p.concat( [ item ] ).slice( -4 ); } );
				if ( item.duration ) setTimeout( function () { remove( item.id ); }, item.duration );
			}
			toastListeners.push( add );
			return function () { toastListeners = toastListeners.filter( function ( f ) { return f !== add; } ); };
		}, [] );
		return el( 'div', { className: 'cr-toaster', role: 'region', 'aria-live': 'polite', 'aria-label': t( 'Notifications' ) },
			items.map( function ( it ) {
				return el( 'div', { key: it.id, className: 'cr-toast cr-toast--' + it.type, role: 'error' === it.type ? 'alert' : 'status' },
					el( 'span', { className: 'cr-toast-icon' }, el( Icon, { name: TOAST_ICON[ it.type ], size: 14, strokeWidth: 2 } ) ),
					el( 'div', { className: 'cr-toast-body' }, el( 'div', null, it.text ),
						it.link ? el( 'a', { className: 'cr-toast-link', href: it.link, target: '_blank', rel: 'noreferrer' }, it.linkText + ' →' ) : null ),
					el( 'button', { type: 'button', className: 'cr-toast-close', 'aria-label': t( 'Dismiss' ), onClick: function () { remove( it.id ); } }, el( Icon, { name: 'x', size: 14 } ) ),
					it.duration ? el( 'span', { className: 'cr-toast-progress', style: { animationDuration: it.duration + 'ms' } } ) : null );
			} ) );
	}
	function ConfirmHost() {
		var _r = useState( null ), req = _r[ 0 ], setReq = _r[ 1 ];
		useEffect( function () {
			function show( r ) { setReq( r ); }
			confirmListeners.push( show );
			return function () { confirmListeners = confirmListeners.filter( function ( f ) { return f !== show; } ); };
		}, [] );
		function close( ok ) { if ( req ) req.resolve( ok ); setReq( null ); }
		useModal( !! req, function () { close( false ); } );
		if ( ! req ) return null;
		return el( 'div', { className: 'cr-scrim cr-scrim--center', onMouseDown: function ( e ) { if ( e.target === e.currentTarget ) close( false ); } },
			el( 'div', { className: 'cr-dialog', role: 'alertdialog', 'aria-modal': 'true', 'aria-labelledby': 'cr-dlg-title' },
				el( 'span', { className: cx( 'cr-dialog-icon', req.danger && 'is-danger' ) }, el( Icon, { name: req.icon || ( req.danger ? 'trash' : 'info' ), size: 18 } ) ),
				el( 'div', { className: 'cr-dialog-text' }, el( 'h3', { id: 'cr-dlg-title' }, req.title || t( 'Are you sure?' ) ), req.message ? el( 'p', null, req.message ) : null ),
				el( 'div', { className: 'cr-dialog-actions' },
					el( Button, { onClick: function () { close( false ); } }, req.cancelText || t( 'Cancel' ) ),
					el( Button, { variant: req.danger ? 'danger-solid' : 'primary', autoFocus: true, onClick: function () { close( true ); } }, req.confirmText || t( 'Confirm' ) ) ) ) );
	}

	/* ------------------------------------------------------------------ activity timeline */
	function eventData( ev ) {
		if ( ev.event_data_decoded ) return ev.event_data_decoded;
		try { return JSON.parse( ev.event_data || '{}' ) || {}; } catch ( e ) { return {}; }
	}
	function eventText( ev ) {
		var d = eventData( ev );
		switch ( ev.event_type ) {
			case 'email_sent': return t( 'Recovery email sent' ) + ( d.step ? ' (' + t( 'step' ) + ' ' + d.step + ')' : '' );
			case 'recovery_link_opened': return t( 'Customer opened the recovery link' );
			case 'page_view': return t( 'Visited' ) + ' ' + shortUrl( d.page_url || '' );
			case 'add_to_cart': return t( 'Added to cart:' ) + ' ' + ( d.name || '#' + d.product_id ) + ' × ' + ( d.quantity || 1 );
			case 'cart_created': return t( 'Customer journey tracking started' );
			case 'converted_to_order': return t( 'Converted to order' ) + ' #' + d.order_id;
			case 'order_placed': return t( 'Customer completed checkout — order' ) + ' #' + d.order_id;
			case 'status_changed': return t( 'Status changed to' ) + ' ' + ( STATUS[ d.to ] || d.to );
			case 'note_added': return d.note || '';
			case 'coupon_created': return t( 'Coupon created:' ) + ' ' + d.code + ( d.percent ? ' (' + d.percent + '%)' : '' );
			case 'whatsapp_recovery_opened': return t( 'WhatsApp recovery message opened' ) + ( d.coupon ? ' — ' + d.coupon : '' );
			case 'risk_detected': return t( 'Risk detected:' ) + ' ' + d.risk + ( d.duplicate_of ? ' (' + t( 'newer cart' ) + ' #' + d.duplicate_of + ')' : '' );
			case 'alert_abandoned': return t( 'Cart became abandoned' );
			case 'alert_high_value': return t( 'High-value cart alert' );
			case 'alert_recovery': return t( 'Recovered by admin' );
			case 'lead_notified': return d.delay_minutes ? t( 'Lead notification scheduled' ) + ' (' + d.delay_minutes + ' ' + t( 'min delay' ) + ')' : t( 'Lead notification sent to Telegram / WhatsApp' );
			default: return String( ev.event_type || '' ).replace( /_/g, ' ' );
		}
	}
	var EVENT_ICON = { cart_created: 'cart', page_view: 'eye', add_to_cart: 'plus', email_sent: 'mail', recovery_link_opened: 'link', coupon_created: 'tag', whatsapp_recovery_opened: 'chat', note_added: 'note', status_changed: 'pulse', converted_to_order: 'receipt', order_placed: 'receipt', risk_detected: 'shield', alert_abandoned: 'alert', alert_high_value: 'zap', alert_recovery: 'check', lead_notified: 'send' };
	var EVENT_TONE = { converted_to_order: 'brand', order_placed: 'brand', alert_recovery: 'brand', alert_abandoned: 'abandoned', risk_detected: 'danger', alert_high_value: 'accent', email_sent: 'active', whatsapp_recovery_opened: 'active', lead_notified: 'active' };
	function Timeline( props ) {
		var ev = props.events || [];
		if ( ! ev.length ) return el( EmptyState, { icon: 'route', title: t( 'No activity recorded yet' ), compact: true } );
		return el( 'ol', { className: 'cr-timeline' }, ev.map( function ( e, i ) {
			var d = eventData( e );
			var link = 'page_view' === e.event_type && d.page_url ? d.page_url : null;
			return el( 'li', { key: e.id || i, className: cx( 'cr-tl-item', 'cr-tone-' + ( EVENT_TONE[ e.event_type ] || 'neutral' ), 'note_added' === e.event_type && 'is-note' ) },
				el( 'span', { className: 'cr-tl-icon' }, el( Icon, { name: EVENT_ICON[ e.event_type ] || 'info', size: 13 } ) ),
				el( 'div', { className: 'cr-tl-body' },
					el( 'div', { className: 'cr-tl-text' }, 'note_added' === e.event_type ? el( 'strong', null, ( d.author || t( 'Admin' ) ) + ': ' ) : null,
						link ? el( 'a', { href: link, target: '_blank', rel: 'noreferrer' }, eventText( e ) ) : eventText( e ) ),
					el( 'time', { className: 'cr-tl-time' }, formatDate( e.created_at ) ) ) );
		} ) );
	}

	/* ------------------------------------------------------------------ drawer shell */
	function Drawer( props ) {
		useModal( true, props.onClose );
		return el( 'div', { className: 'cr-scrim', onMouseDown: function ( e ) { if ( e.target === e.currentTarget ) props.onClose(); } },
			el( 'aside', { className: 'cr-drawer', role: 'dialog', 'aria-modal': 'true', 'aria-label': props.label }, props.children ) );
	}
	function DL( props ) {
		return el( 'dl', { className: 'cr-dl' }, props.rows.filter( Boolean ).map( function ( r ) {
			return el( 'div', { className: 'cr-dl-row', key: r[ 0 ] }, el( 'dt', null, r[ 0 ] ), el( 'dd', null, r[ 1 ] || 0 === r[ 1 ] ? r[ 1 ] : el( 'span', { className: 'cr-muted' }, '—' ) ) );
		} ) );
	}
	function Section( props ) {
		return el( 'section', { className: 'cr-dsec' }, el( 'h4', null, el( Icon, { name: props.icon, size: 14 } ), props.title ), props.children );
	}

	/* ------------------------------------------------------------------ cart drawer */
	function addressLines( c, prefix ) {
		return [
			c[ prefix + '_company' ],
			[ c[ prefix + '_first_name' ], c[ prefix + '_last_name' ] ].filter( Boolean ).join( ' ' ),
			c[ prefix + '_address_1' ], c[ prefix + '_address_2' ],
			[ c[ prefix + '_city' ], c[ prefix + '_postcode' ] ].filter( Boolean ).join( ' ' ),
			c[ prefix + '_state_name' ] || c[ prefix + '_state' ],
			c[ prefix + '_country_name' ] || c[ prefix + '_country' ]
		].filter( Boolean );
	}
	function Lines( props ) {
		return props.lines.length ? el( 'p', null, props.lines.map( function ( l, i ) { return el( Fragment, { key: i }, l, el( 'br' ) ); } ) ) : el( 'p', { className: 'cr-muted' }, props.empty );
	}

	function CartDrawer( props ) {
		var _c = useState( props.cart ), cart = _c[ 0 ], setCart = _c[ 1 ];
		var _t = useState( props.tab || 'overview' ), tab = _t[ 0 ], setTab = _t[ 1 ];
		var _e = useState( null ), events = _e[ 0 ], setEvents = _e[ 1 ];
		var _b = useState( '' ), busy = _b[ 0 ], setBusy = _b[ 1 ];
		var _n = useState( '' ), note = _n[ 0 ], setNote = _n[ 1 ];
		var _o = useState( 'pending' ), orderStatus = _o[ 0 ], setOrderStatus = _o[ 1 ];
		var _p = useState( 'no' ), sendPayLink = _p[ 0 ], setSendPayLink = _p[ 1 ];
		var settings = props.settings || {};

		function loadEvents() {
			api( '/carts/' + props.cart.id + '/events' ).then( setEvents ).catch( function () { setEvents( [] ); } );
		}
		useEffect( function () {
			api( '/carts/' + props.cart.id ).then( function ( full ) { setCart( full ); if ( full.events ) setEvents( full.events ); } ).catch( function () {} );
			loadEvents();
		}, [ props.cart.id ] );

		function updated( c ) { setCart( c ); if ( props.onUpdated ) props.onUpdated( c ); }

		function sendEmail() {
			setBusy( 'email' );
			api( '/carts/' + cart.id + '/send-email', { method: 'POST' } )
				.then( function ( c ) { toast.success( t( 'Recovery email sent to' ) + ' ' + cart.email ); updated( c ); loadEvents(); } )
				.catch( function ( err ) { toast.error( errMsg( err ) ); } )
				.finally( function () { setBusy( '' ); } );
		}
		function openWhatsApp() {
			// Open the tab synchronously (popup blockers), then point it at wa.me once the link is ready.
			var win = window.open( '', '_blank' );
			setBusy( 'wa' );
			api( '/carts/' + cart.id + '/whatsapp', { method: 'POST' } )
				.then( function ( res ) {
					if ( win && res.url ) { win.opener = null; win.location.href = res.url; }
					toast.success( res.coupon_code ? t( 'WhatsApp message ready with coupon' ) + ' ' + res.coupon_code : t( 'WhatsApp message ready.' ), win ? {} : { link: res.url, linkText: t( 'Open WhatsApp' ), duration: 12000 } );
					loadEvents();
				} )
				.catch( function ( err ) { if ( win ) win.close(); toast.error( errMsg( err ) ); } )
				.finally( function () { setBusy( '' ); } );
		}
		function addNote() {
			if ( ! note.trim() ) return;
			setBusy( 'note' );
			api( '/carts/' + cart.id + '/note', { method: 'POST', data: { note: note } } )
				.then( function () { setNote( '' ); loadEvents(); toast.success( t( 'Note added' ), { duration: 2500 } ); } )
				.catch( function ( err ) { toast.error( errMsg( err ) ); } )
				.finally( function () { setBusy( '' ); } );
		}
		function setStatus( status ) {
			setBusy( 'status' );
			api( '/carts/' + cart.id, { method: 'PATCH', data: { status: status } } )
				.then( function ( c ) { updated( c ); loadEvents(); toast.success( t( 'Status changed to' ) + ' ' + STATUS[ status ] ); } )
				.catch( function ( err ) { toast.error( errMsg( err ) ); } )
				.finally( function () { setBusy( '' ); } );
		}
		var ORDER_STATUS = { pending: t( 'Pending payment' ), processing: t( 'Processing' ), 'on-hold': t( 'On hold' ), completed: t( 'Completed' ) };
		function createOrder() {
			confirmDialog( {
				title: t( 'Create WooCommerce order?' ), icon: 'receipt', confirmText: t( 'Create order' ),
				message: money( cart.cart_total ) + ' · ' + ( cartName( cart ) || cart.phone || t( 'Guest' ) ) + ' · ' + ORDER_STATUS[ orderStatus ] + '. ' + t( 'The traffic source is copied to the order and conversion events are sent if enabled.' )
			} ).then( function ( ok ) {
				if ( ! ok ) return;
				setBusy( 'order' );
				api( '/carts/' + cart.id + '/create-order', { method: 'POST', data: { order_status: orderStatus, send_order_email: 'yes' === sendPayLink } } )
					.then( function ( res ) {
						toast.success( t( 'Order' ) + ' #' + res.order_id + ' ' + t( 'created and attributed to' ) + ' ' + ( cart.source_label || t( 'Direct' ) ), { link: res.edit_link, linkText: t( 'Open order' ), duration: 9000 } );
						( res.tracking || [] ).forEach( function ( r ) {
							if ( r.deferred ) toast.info( r.message, { duration: 7000 } );
							else if ( r.ok ) toast.success( r.channel + ': ' + t( 'Purchase event sent' ), { duration: 6000 } );
							else toast.error( r.channel + ': ' + r.message, { duration: 10000 } );
						} );
						if ( res.cart ) updated( res.cart );
						if ( props.onChanged ) props.onChanged();
						props.onClose();
					} )
					.catch( function ( err ) { toast.error( errMsg( err ) ); } )
					.finally( function () { setBusy( '' ); } );
			} );
		}
		function deleteCart() {
			confirmDialog( { title: t( 'Delete cart' ) + ' #' + cart.id + '?', danger: true, confirmText: t( 'Delete cart' ),
				message: ( cartName( cart ) || cart.phone || cart.email || t( 'Guest' ) ) + ' · ' + money( cart.cart_total ) + '. ' + t( 'This permanently removes the record and its activity.' ) } )
				.then( function ( ok ) {
					if ( ! ok ) return;
					setBusy( 'delete' );
					api( '/carts/' + cart.id + '/delete', { method: 'POST' } )
						.then( function () { toast.success( t( 'Cart' ) + ' #' + cart.id + ' ' + t( 'deleted' ) ); if ( props.onDeleted ) props.onDeleted( cart.id ); props.onClose(); } )
						.catch( function ( err ) { toast.error( errMsg( err ) ); setBusy( '' ); } );
				} );
		}

		var items = cart.cart_contents || [];
		var td = cart.tracking_data || {};
		var clickIds = [ 'fbclid', 'gclid', 'ttclid', 'msclkid', 'fbp', 'fbc', 'ga_client_id' ].filter( function ( k ) { return td[ k ]; } );
		var landing = cart.landing_page || cart.source_url;
		var src = cartSource( cart );
		var whatsappOn = 'no' !== settings.whatsapp_enabled;
		var recoveryUrl = cart.recovery_token && CFG.home_url ? CFG.home_url + ( CFG.home_url.indexOf( '?' ) > -1 ? '&' : '?' ) + 'wccr_recover=' + cart.recovery_token : '';

		var overview = el( Fragment, null,
			el( Section, { icon: 'user', title: t( 'Customer' ) }, el( DL, { rows: [
				[ t( 'Name' ), cartName( cart ) ],
				[ t( 'Phone' ), cart.phone ? el( 'a', { href: 'tel:' + cart.phone }, cart.phone ) : '' ],
				[ t( 'Email' ), cart.email ? el( 'a', { href: 'mailto:' + cart.email }, cart.email ) : '' ],
				[ t( 'Account' ), cart.user_id ? '#' + cart.user_id : t( 'Guest' ) ],
				[ t( 'Consent' ), cart.consent ? t( 'Yes' ) : t( 'No' ) ],
				[ t( 'Device' ), cart.device_type ]
			] } ) ),
			el( Section, { icon: 'pin', title: t( 'Address' ) },
				el( 'div', { className: 'cr-addr-grid' },
					el( 'div', { className: 'cr-addr' }, el( 'span', { className: 'cr-label' }, t( 'Billing' ) ), el( Lines, { lines: addressLines( cart, 'billing' ), empty: t( 'Not captured' ) } ) ),
					el( 'div', { className: 'cr-addr' }, el( 'span', { className: 'cr-label' }, t( 'Shipping' ) ), cart.ship_to_different ? el( Lines, { lines: addressLines( cart, 'shipping' ), empty: t( 'Not captured' ) } ) : el( 'p', { className: 'cr-muted' }, t( 'Same as billing' ) ) ) ) ),
			el( Section, { icon: 'cart', title: t( 'Cart contents' ) },
				items.length ? el( 'ul', { className: 'cr-lines' }, items.map( function ( it, i ) {
					var v = variationText( it );
					return el( 'li', { key: i }, el( Thumb, { item: it, large: true, single: true } ),
						el( 'div', { className: 'cr-lines-main' },
							el( 'div', { className: 'cr-ink' }, it.permalink ? el( 'a', { href: it.permalink, target: '_blank', rel: 'noreferrer' }, it.name || '#' + it.product_id ) : ( it.name || '#' + it.product_id ) ),
							el( 'div', { className: 'cr-muted' }, [ v, it.sku ? 'SKU ' + it.sku : '', ( it.quantity || 1 ) + ' × ' + money( it.price ) ].filter( Boolean ).join( ' · ' ) ) ),
						el( 'strong', null, money( undefined !== it.line_total ? it.line_total : ( it.price || 0 ) * ( it.quantity || 1 ) ) ) );
				} ) ) : el( 'p', { className: 'cr-muted' }, t( 'The cart is empty.' ) ),
				el( 'div', { className: 'cr-lines-total' }, el( 'span', null, t( 'Cart total' ) ), el( 'strong', null, money( cart.cart_total ) ) ) ),
			el( Section, { icon: 'megaphone', title: t( 'Traffic & source' ) }, el( DL, { rows: [
				[ t( 'Attributed to' ), cart.source_label ],
				[ t( 'Referrer' ), cart.referrer ? shortUrl( cart.referrer ) : t( 'Direct / none' ) ],
				cart.utm_source ? [ 'UTM', el( 'span', { className: 'cr-mono' }, [ cart.utm_source, cart.utm_medium, cart.utm_campaign, cart.utm_term, cart.utm_content ].filter( Boolean ).join( ' / ' ) ) ] : null,
				[ t( 'Ad click IDs' ), clickIds.length ? el( 'span', { className: 'cr-chips' }, clickIds.map( function ( k ) { return el( 'span', { key: k, className: 'cr-chip', title: td[ k ] }, k ); } ) ) : t( 'None captured' ) ],
				[ t( 'Country' ), cart.billing_country_name || cart.country_code || cart.billing_country ],
				[ t( 'IP address' ), cart.ip_address ]
			] } ), landing ? el( CopyField, { label: t( 'Landing page' ), value: landing, href: landing } ) : null ),
			el( Section, { icon: 'clock', title: t( 'Visit & recovery' ) }, el( DL, { rows: [
				[ t( 'Entry' ), formatDate( cart.entry_at ) ],
				[ t( 'Exit' ), 'live' === cart.presence_status ? t( 'Still on site' ) : formatDate( cart.exit_at ) ],
				[ t( 'Time on site' ), duration( cart.time_on_site ) ],
				[ t( 'Created' ), formatDate( cart.created_at ) ],
				[ t( 'Last activity' ), formatDate( cart.updated_at ) ],
				[ t( 'Recovery emails sent' ), String( cart.emails_sent || 0 ) + ( cart.last_email_sent_at ? ' · ' + t( 'last' ) + ' ' + formatDate( cart.last_email_sent_at ) : '' ) ],
				cart.recovered_at ? [ t( 'Recovery link opened' ), formatDate( cart.recovered_at ) ] : null,
				[ t( 'Order' ), cart.order_id ? el( 'a', { href: cart.order_edit_link, target: '_blank', rel: 'noreferrer' }, '#' + cart.order_id ) : t( 'Not converted' ) ]
			] } ), recoveryUrl ? el( CopyField, { label: t( 'Recovery link' ), value: recoveryUrl } ) : null ),
			cart.order_note || cart.admin_note ? el( Section, { icon: 'note', title: t( 'Notes' ) },
				cart.order_note ? el( 'div', { className: 'cr-note-box' }, el( 'span', { className: 'cr-label' }, t( 'Customer order note' ) ), el( 'p', null, cart.order_note ) ) : null,
				cart.admin_note ? el( 'div', { className: 'cr-note-box' }, el( 'span', { className: 'cr-label' }, t( 'Admin note' ) ), el( 'p', null, cart.admin_note ) ) : null ) : null
		);

		var activity = el( Fragment, null,
			el( 'div', { className: 'cr-note-form' },
				el( 'textarea', { value: note, rows: 2, placeholder: t( 'Add an internal note…' ), onChange: function ( e ) { setNote( e.target.value ); } } ),
				el( Button, { size: 'sm', loading: 'note' === busy, disabled: ! note.trim(), onClick: addNote }, t( 'Add note' ) ) ),
			null === events ? el( Loading, { rows: 4 } ) : el( Timeline, { events: events } ) );

		var orderTab = el( Section, { icon: 'receipt', title: t( 'Create a WooCommerce order' ) },
			cart.order_id ? el( 'div', null, el( 'p', { className: 'cr-body-muted' }, t( 'This cart is already linked to an order.' ) ), el( Button, { icon: 'external', href: cart.order_edit_link, target: '_blank', rel: 'noreferrer' }, t( 'View order' ) + ' #' + cart.order_id ) )
				: el( Fragment, null,
					el( 'p', { className: 'cr-body-muted' }, t( 'Turn this cart into a real order — for example after a phone call. The traffic source is copied to the order and conversion events are sent if enabled.' ) ),
					el( Field, { label: t( 'Order status' ) }, el( Select, { value: orderStatus, onChange: setOrderStatus, label: t( 'Order status' ), options: Object.keys( ORDER_STATUS ).map( function ( k ) { return { value: k, label: ORDER_STATUS[ k ] }; } ) } ) ),
					el( Toggle, { value: sendPayLink, onChange: setSendPayLink, label: t( 'Email payment link' ), description: t( 'Pending payment: emails the invoice with a "Pay now" link. Other statuses: WooCommerce sends its normal order email.' ) } ),
					el( 'div', { className: 'cr-order-cta' }, el( Button, { variant: 'primary', icon: 'receipt', loading: 'order' === busy, disabled: ! items.length, onClick: createOrder }, t( 'Create order' ) + ' · ' + money( cart.cart_total ) ) ) ) );

		return el( Drawer, { onClose: props.onClose, label: t( 'Cart' ) + ' #' + cart.id },
			el( 'header', { className: 'cr-drawer-head' },
				el( 'div', { className: 'cr-drawer-top' },
					el( 'span', { className: 'cr-muted cr-mono' }, t( 'Cart' ) + ' #' + cart.id + ' · ' + shortTime( cart.created_at ) ),
					el( Button, { variant: 'ghost', size: 'sm', icon: 'x', title: t( 'Close' ), onClick: props.onClose } ) ),
				el( 'div', { className: 'cr-drawer-id' },
					el( Avatar, { name: cartName( cart ) || cart.email || cart.phone, large: true } ),
					el( 'div', { className: 'cr-drawer-who' },
						el( 'h2', null, cartName( cart ) || t( 'Guest' ) ),
						el( 'div', { className: 'cr-drawer-badges' }, el( StatusBadge, { status: cart.status } ), el( Presence, { cart: cart } ), el( RiskBadge, { risk: cart.risk_status } ), src.source ? el( SourceChip, src ) : null ) ),
					el( 'div', { className: 'cr-drawer-total' }, el( 'span', { className: 'cr-muted' }, t( 'Cart total' ) ), el( 'strong', null, money( cart.cart_total ) ) ) ),
				el( 'div', { className: 'cr-drawer-actions' },
					el( Button, { variant: 'primary', icon: 'mail', loading: 'email' === busy, disabled: ! cart.email || !! cart.order_id, title: cart.email ? '' : t( 'No email captured' ), onClick: sendEmail }, t( 'Send recovery email' ) ),
					whatsappOn ? el( Button, { icon: 'chat', loading: 'wa' === busy, disabled: ! cart.phone, onClick: openWhatsApp }, 'WhatsApp' ) : null,
					el( Button, { icon: 'phone', disabled: ! cart.phone, href: cart.phone ? 'tel:' + cart.phone : undefined }, t( 'Call' ) ) ),
				el( 'div', { className: 'cr-tabs', role: 'tablist' }, [ [ 'overview', t( 'Overview' ) ], [ 'activity', t( 'Activity' ) ], [ 'order', t( 'Create order' ) ] ].map( function ( x ) {
					return el( 'button', { key: x[ 0 ], type: 'button', role: 'tab', 'aria-selected': tab === x[ 0 ], className: cx( 'cr-tab', tab === x[ 0 ] && 'is-on' ), onClick: function () { setTab( x[ 0 ] ); } },
						x[ 1 ], 'activity' === x[ 0 ] && events && events.length ? el( 'span', { className: 'cr-seg-count' }, events.length ) : null );
				} ) ) ),
			el( 'div', { className: 'cr-drawer-body' }, 'overview' === tab ? overview : ( 'activity' === tab ? activity : orderTab ) ),
			el( 'footer', { className: 'cr-drawer-foot' },
				el( Button, { variant: 'danger', size: 'sm', icon: 'trash', loading: 'delete' === busy, onClick: deleteCart }, t( 'Delete cart' ) ),
				cart.order_id ? el( 'span', { className: 'cr-muted' }, t( 'Converted' ) ) : el( Select, { value: '', label: t( 'Change status' ), onChange: function ( v ) { if ( v ) setStatus( v ); },
					options: [ { value: '', label: t( 'Change status…' ) }, { value: 'active', label: STATUS.active }, { value: 'abandoned', label: STATUS.abandoned }, { value: 'recovered', label: STATUS.recovered }, { value: 'emptied', label: STATUS.emptied } ] } ) ) );
	}

	/* ------------------------------------------------------------------ order drawer */
	function RecoveryBadge( props ) {
		if ( 'admin' === props.type ) return el( 'span', { className: 'cr-badge cr-badge--converted' }, el( Icon, { name: 'undo', size: 12 } ), t( 'Recovered by admin' ) );
		if ( 'link' === props.type ) return el( 'span', { className: 'cr-badge cr-badge--active' }, el( Icon, { name: 'link', size: 12 } ), t( 'Recovery link' ) );
		return null;
	}
	function OrderDrawer( props ) {
		var _o = useState( null ), order = _o[ 0 ], setOrder = _o[ 1 ];
		useEffect( function () {
			api( '/orders/' + props.id ).then( setOrder ).catch( function ( err ) { toast.error( errMsg( err ) ); props.onClose(); } );
		}, [ props.id ] );
		var o = order || {};
		return el( Drawer, { onClose: props.onClose, label: t( 'Order' ) + ' #' + props.id },
			el( 'header', { className: 'cr-drawer-head' },
				el( 'div', { className: 'cr-drawer-top' }, el( 'span', { className: 'cr-muted cr-mono' }, t( 'Order' ) + ' #' + ( o.number || props.id ) + ( o.date ? ' · ' + formatDate( o.date ) : '' ) ),
					el( Button, { variant: 'ghost', size: 'sm', icon: 'x', title: t( 'Close' ), onClick: props.onClose } ) ),
				order ? el( 'div', { className: 'cr-drawer-id' },
					el( Avatar, { name: o.customer || o.email, large: true } ),
					el( 'div', { className: 'cr-drawer-who' }, el( 'h2', null, o.customer || t( 'Guest' ) ),
						el( 'div', { className: 'cr-drawer-badges' }, el( OrderBadge, { status: o.status, label: o.status_label } ), el( RecoveryBadge, { type: o.recovery_type } ) ) ),
					el( 'div', { className: 'cr-drawer-total' }, el( 'span', { className: 'cr-muted' }, t( 'Total' ) ), el( 'strong', null, money( o.total ) ) ) ) : null,
				order ? el( 'div', { className: 'cr-drawer-actions' },
					el( Button, { variant: 'primary', icon: 'external', href: o.edit_link, target: '_blank', rel: 'noreferrer' }, t( 'Edit in WooCommerce' ) ),
					o.phone ? el( Button, { icon: 'phone', href: 'tel:' + o.phone }, t( 'Call' ) ) : null,
					o.cart_id && props.onOpenCart ? el( Button, { icon: 'cart', onClick: function () { props.onOpenCart( o.cart_id ); } }, t( 'Open cart' ) + ' #' + o.cart_id ) : null ) : null ),
			el( 'div', { className: 'cr-drawer-body' }, ! order ? el( Loading, { rows: 6 } ) : el( Fragment, null,
				el( Section, { icon: 'user', title: t( 'Customer' ) }, el( DL, { rows: [
					[ t( 'Phone' ), o.phone ? el( 'a', { href: 'tel:' + o.phone }, o.phone ) : '' ],
					[ t( 'Email' ), o.email ? el( 'a', { href: 'mailto:' + o.email }, o.email ) : '' ],
					[ t( 'Payment' ), o.payment ],
					[ t( 'Source' ), o.source ],
					o.recovered_by ? [ t( 'Recovered by' ), o.recovered_by ] : null,
					o.note ? [ t( 'Customer note' ), o.note ] : null
				] } ) ),
				el( Section, { icon: 'pin', title: t( 'Address' ) }, el( 'div', { className: 'cr-addr-grid' },
					el( 'div', { className: 'cr-addr' }, el( 'span', { className: 'cr-label' }, t( 'Billing' ) ), el( Lines, { lines: o.billing || [], empty: '—' } ) ),
					el( 'div', { className: 'cr-addr' }, el( 'span', { className: 'cr-label' }, t( 'Shipping' ) ), el( Lines, { lines: o.shipping || [], empty: t( 'Same as billing' ) } ) ) ) ),
				el( Section, { icon: 'bag', title: t( 'Products' ) },
					el( 'ul', { className: 'cr-lines' }, ( o.items || [] ).map( function ( it, i ) {
						return el( 'li', { key: i }, el( Thumb, { item: it, large: true, single: true } ),
							el( 'div', { className: 'cr-lines-main' }, el( 'div', { className: 'cr-ink' }, it.name ), el( 'div', { className: 'cr-muted' }, [ it.sku ? 'SKU ' + it.sku : '', t( 'Qty' ) + ' ' + it.quantity ].filter( Boolean ).join( ' · ' ) ) ),
							el( 'strong', null, money( it.total ) ) );
					} ) ),
					el( 'div', { className: 'cr-totals' },
						el( 'div', null, el( 'span', null, t( 'Subtotal' ) ), el( 'span', null, money( o.subtotal ) ) ),
						o.shipping_total ? el( 'div', null, el( 'span', null, t( 'Shipping' ) ), el( 'span', null, money( o.shipping_total ) ) ) : null,
						o.discount ? el( 'div', null, el( 'span', null, t( 'Discount' ) ), el( 'span', null, '−' + money( o.discount ) ) ) : null,
						el( 'div', { className: 'is-total' }, el( 'span', null, t( 'Total' ) ), el( 'strong', null, money( o.total ) ) ) ) ) ) ) );
	}

	/* ------------------------------------------------------------------ alerts bell */
	var ALERT_ICON = { alert_abandoned: 'alert', alert_high_value: 'zap', alert_recovery: 'check' };
	function AlertsBell( props ) {
		var _a = useState( [] ), alerts = _a[ 0 ], setAlerts = _a[ 1 ];
		var _o = useState( false ), open = _o[ 0 ], setOpen = _o[ 1 ];
		var _s = useState( function () { return parseInt( storageGet( 'wccr_alert_seen', '0' ), 10 ) || 0; } ), seen = _s[ 0 ], setSeen = _s[ 1 ];
		var last = useRef( null );
		function load() {
			api( '/alerts?limit=15' ).then( function ( r ) {
				r = r || [];
				var max = r.reduce( function ( m, a ) { return Math.max( m, parseInt( a.id, 10 ) || 0 ); }, 0 );
				if ( null !== last.current ) {
					r.filter( function ( a ) { return parseInt( a.id, 10 ) > last.current; } ).slice( 0, 3 ).forEach( function ( a ) {
						var d = a.event_data_decoded || {};
						var who = [ a.first_name, a.last_name ].filter( Boolean ).join( ' ' ) || a.phone || a.email || '#' + a.cart_id;
						toast( 'alert_high_value' === a.event_type ? 'warning' : ( 'alert_recovery' === a.event_type ? 'success' : 'info' ), ( d.message || a.event_type ) + ' — ' + who + ( a.cart_total ? ' · ' + money( a.cart_total ) : '' ), { duration: 8000 } );
					} );
				}
				last.current = Math.max( last.current || 0, max );
				setAlerts( r );
			} ).catch( function () {} );
		}
		useEffect( function () { load(); var timer = setInterval( load, 15000 ); return function () { clearInterval( timer ); }; }, [] );
		useEffect( function () {
			if ( ! open ) return undefined;
			function close( e ) { if ( ! e.target.closest || ! e.target.closest( '.cr-bell-wrap' ) ) setOpen( false ); }
			document.addEventListener( 'mousedown', close );
			return function () { document.removeEventListener( 'mousedown', close ); };
		}, [ open ] );
		var unread = alerts.filter( function ( a ) { return parseInt( a.id, 10 ) > seen; } ).length;
		function toggle() {
			var next = ! open;
			setOpen( next );
			if ( next && alerts.length ) {
				var max = alerts.reduce( function ( m, a ) { return Math.max( m, parseInt( a.id, 10 ) || 0 ); }, 0 );
				setSeen( max ); storageSet( 'wccr_alert_seen', String( max ) );
			}
		}
		return el( 'div', { className: 'cr-bell-wrap' },
			el( 'button', { type: 'button', className: cx( 'cr-bell', open && 'is-open' ), 'aria-expanded': open, 'aria-label': t( 'Real-time alerts' ) + ( unread ? ', ' + unread + ' ' + t( 'unread' ) : '' ), onClick: toggle },
				el( Icon, { name: 'bell', size: 17 } ), unread ? el( 'span', { className: 'cr-bell-count' }, unread > 9 ? '9+' : unread ) : null ),
			open ? el( 'div', { className: 'cr-popover cr-bell-pop' },
				el( 'div', { className: 'cr-popover-head' }, el( 'strong', null, t( 'Real-time alerts' ) ) ),
				alerts.length ? el( 'ul', { className: 'cr-alerts' }, alerts.map( function ( a ) {
					var d = a.event_data_decoded || {};
					var who = [ a.first_name, a.last_name ].filter( Boolean ).join( ' ' ) || a.phone || a.email || '#' + a.cart_id;
					return el( 'li', { key: a.id, className: 'cr-alert cr-alert--' + a.event_type },
						el( 'button', { type: 'button', className: 'cr-alert-btn', onClick: function () { setOpen( false ); if ( props.onOpenCart ) props.onOpenCart( parseInt( a.cart_id, 10 ) ); } },
							el( 'span', { className: 'cr-alert-icon' }, el( Icon, { name: ALERT_ICON[ a.event_type ] || 'bell', size: 14 } ) ),
							el( 'span', { className: 'cr-alert-main' },
								el( 'span', { className: 'cr-alert-top' }, el( 'strong', null, who ), a.cart_total ? el( 'span', { className: 'cr-alert-total' }, money( a.cart_total ) ) : null ),
								el( 'span', { className: 'cr-muted' }, ( d.message || a.event_type ) + ' · ' + shortTime( a.created_at ) ) ) ) );
				} ) ) : el( EmptyState, { icon: 'bell', title: t( 'No alerts yet' ), compact: true } ) ) : null );
	}

	/* ------------------------------------------------------------------ page header */
	function PageHeader( props ) {
		return el( 'header', { className: 'cr-page-head' },
			el( 'div', { className: 'cr-page-titles' }, el( 'h1', { className: 'cr-page-title' }, props.title ), props.description ? el( 'p', { className: 'cr-page-desc' }, props.description ) : null ),
			props.actions ? el( 'div', { className: 'cr-page-actions' }, props.actions ) : null );
	}
	function DaysSelect( props ) {
		return el( Select, { icon: 'clock', value: String( props.days ), label: t( 'Date range' ), onChange: function ( v ) { props.onDays( parseInt( v, 10 ) ); },
			options: [ { value: '7', label: t( 'Last 7 days' ) }, { value: '30', label: t( 'Last 30 days' ) }, { value: '90', label: t( 'Last 90 days' ) }, { value: '365', label: t( 'Last 12 months' ) } ] } );
	}

	/* ------------------------------------------------------------------ carts view */
	var EMPTY_FILTERS = { search: '', status: '', date_from: '', date_to: '', min_total: '', max_total: '', source: '', device: '', presence: '', conversion_type: '', product_id: '', risk_status: '' };
	function exportUrl( filters ) {
		var url = CFG.export_csv_url || '';
		Object.keys( filters ).forEach( function ( k ) { if ( filters[ k ] ) url += '&' + encodeURIComponent( k ) + '=' + encodeURIComponent( filters[ k ] ); } );
		return url;
	}
	function cartKpis( stats, loading ) {
		var tt = stats.totals || {};
		var admin = parseInt( tt.admin_recovered || 0, 10 ), ab = parseInt( tt.abandoned || 0, 10 );
		var rate = ab + admin > 0 ? admin / ( ab + admin ) * 100 : 0;
		var daily = stats.daily || [];
		var series = function ( k ) { return daily.length > 1 ? daily.map( function ( d ) { return parseFloat( d[ k ] || 0 ); } ) : null; };
		return el( 'div', { className: 'cr-kpi-grid' },
			el( KpiCard, { loading: loading, icon: 'coins', tone: 'accent', label: t( 'Recovered revenue' ), value: money( tt.recovered_revenue ), trend: series( 'recovered' ), hint: admin + ' ' + t( 'carts recovered by admin' ) } ),
			el( KpiCard, { loading: loading, icon: 'pulse', tone: 'brand', label: t( 'Recovery rate' ), value: rate.toFixed( 1 ) + '%', hint: num( tt.customer_converted || 0 ) + ' ' + t( 'customer checkouts' ) } ),
			el( KpiCard, { loading: loading, icon: 'cart', tone: 'abandoned', label: t( 'Abandoned carts' ), value: num( ab ), trend: series( 'abandoned' ), hint: num( tt.total_carts || 0 ) + ' ' + t( 'carts captured' ) } ),
			el( KpiCard, { loading: loading, icon: 'zap', tone: 'neutral', label: t( 'Revenue at risk' ), value: money( tt.potential_revenue ), hint: ab ? t( 'avg' ) + ' ' + money( ( tt.potential_revenue || 0 ) / ab ) + ' ' + t( 'per abandoned cart' ) : t( 'No abandoned carts' ) } ) );
	}
	function CartsView( props ) {
		var _f = useState( Object.assign( {}, EMPTY_FILTERS ) ), filters = _f[ 0 ], setFilters = _f[ 1 ];
		var _p = useState( 1 ), page = _p[ 0 ], setPage = _p[ 1 ];
		var _r = useState( { items: [], total: 0, total_pages: 1 } ), res = _r[ 0 ], setRes = _r[ 1 ];
		var _l = useState( true ), loading = _l[ 0 ], setLoading = _l[ 1 ];
		var _a = useState( false ), advanced = _a[ 0 ], setAdvanced = _a[ 1 ];
		var _c = useState( false ), colsOpen = _c[ 0 ], setColsOpen = _c[ 1 ];
		var cols = Object.assign( { id: true, customer: true, address: false, products: true, total: true, source: true, times: false, live: true, risk: true }, props.columns || {} );

		function load( silent, manual ) {
			if ( ! silent ) setLoading( true );
			api( '/carts?' + qs( Object.assign( {}, filters, { page: page, per_page: 20 } ) ) )
				.then( function ( r ) { setRes( r ); if ( manual ) toast.info( t( 'Cart list updated' ), { duration: 2000 } ); } )
				.catch( function ( err ) { if ( ! silent || manual ) toast.error( errMsg( err ) ); } )
				.finally( function () { setLoading( false ); } );
		}
		useEffect( function () { load(); }, [ page, filters, props.reloadKey ] );
		useEffect( function () { var timer = setInterval( function () { load( true ); }, 15000 ); return function () { clearInterval( timer ); }; }, [ page, filters ] );

		function patch( p ) { setPage( 1 ); setFilters( Object.assign( {}, filters, p ) ); }
		var advancedCount = [ 'date_from', 'date_to', 'min_total', 'max_total', 'source', 'device', 'presence', 'conversion_type', 'product_id', 'risk_status' ].filter( function ( k ) { return filters[ k ]; } ).length;
		var items = res.items || [];

		function quick( name, c ) {
			return function ( e ) {
				e.stopPropagation();
				if ( 'email' === name ) {
					api( '/carts/' + c.id + '/send-email', { method: 'POST' } ).then( function () { toast.success( t( 'Recovery email sent to' ) + ' ' + c.email ); load( true ); } ).catch( function ( err ) { toast.error( errMsg( err ) ); } );
				} else if ( 'whatsapp' === name ) {
					var win = window.open( '', '_blank' );
					api( '/carts/' + c.id + '/whatsapp', { method: 'POST' } ).then( function ( r ) { if ( win ) { win.opener = null; win.location.href = r.url; } else { toast.info( t( 'WhatsApp message ready.' ), { link: r.url, linkText: t( 'Open WhatsApp' ) } ); } } ).catch( function ( err ) { if ( win ) win.close(); toast.error( errMsg( err ) ); } );
				} else {
					props.onOpenCart( c );
				}
			};
		}

		var colDefs = [
			[ 'id', 'ID' ], [ 'customer', t( 'Customer' ) ], [ 'address', t( 'Address' ) ], [ 'products', t( 'Cart' ) ], [ 'total', t( 'Total' ) ],
			[ 'source', t( 'Source' ) ], [ 'times', t( 'Entry / exit' ) ], [ 'live', t( 'Last seen' ) ], [ 'risk', t( 'Risk' ) ]
		];
		function toggleCol( key ) {
			var next = Object.assign( {}, cols ); next[ key ] = ! cols[ key ];
			props.onColumns( next );
		}

		var table = loading && ! items.length ? el( Loading, { rows: 6 } ) : ( ! items.length ? el( EmptyState, { icon: 'cart', title: t( 'No carts match these filters' ), description: t( 'Carts appear here as soon as a shopper adds a product or starts typing on checkout.' ), action: advancedCount || filters.status || filters.search ? el( Button, { onClick: function () { setPage( 1 ); setFilters( Object.assign( {}, EMPTY_FILTERS ) ); } }, t( 'Clear filters' ) ) : null } )
			: el( 'div', { className: 'cr-table-wrap' }, el( 'table', { className: 'cr-table is-clickable' },
				el( 'thead', null, el( 'tr', null,
					cols.customer ? el( 'th', null, t( 'Customer' ) ) : null,
					cols.products ? el( 'th', null, t( 'Cart' ) ) : null,
					cols.address ? el( 'th', null, t( 'Address' ) ) : null,
					cols.total ? el( 'th', { className: 'is-num' }, t( 'Total' ) ) : null,
					cols.source ? el( 'th', null, t( 'Source' ) ) : null,
					cols.times ? el( 'th', null, t( 'Entry / exit' ) ) : null,
					el( 'th', null, t( 'Status' ) ),
					el( 'th', { className: 'is-actions' }, el( 'span', { className: 'cr-sr' }, t( 'Actions' ) ) ) ) ),
				el( 'tbody', null, items.map( function ( c ) {
					var addr = [ c.billing_city, c.billing_state_name || c.billing_state, c.billing_country_name || c.billing_country ].filter( Boolean ).join( ', ' );
					return el( 'tr', { key: c.id, onClick: function () { props.onOpenCart( c ); } },
						cols.customer ? el( 'td', null, el( CustomerCell, { name: cartName( c ), email: c.email, phone: c.phone, risk: cols.risk ? c.risk_status : null } ) ) : null,
						cols.products ? el( 'td', null, el( ProductStack, { items: c.cart_contents } ) ) : null,
						cols.address ? el( 'td', { className: 'cr-addr-cell' }, addr || el( 'span', { className: 'cr-muted' }, t( 'Not captured' ) ) ) : null,
						cols.total ? el( 'td', { className: 'is-num' }, el( 'strong', null, money( c.cart_total ) ), cols.id ? el( 'div', { className: 'cr-muted cr-mono' }, '#' + c.id ) : null ) : null,
						cols.source ? el( 'td', null, el( SourceChip, cartSource( c ) ) ) : null,
						cols.times ? el( 'td', { className: 'cr-times' }, el( EntryExit, { cart: c } ) ) : null,
						el( 'td', null, el( 'div', { className: 'cr-status-cell' }, el( StatusBadge, { status: c.status } ),
							el( 'div', { className: 'cr-status-sub' }, cols.live ? el( Presence, { cart: c } ) : null, c.emails_sent ? el( 'span', { className: 'cr-muted' }, ' · ' + t( 'email' ) + ' ' + c.emails_sent ) : null ) ) ),
						el( 'td', { className: 'is-actions' }, el( 'div', { className: 'cr-row-actions' },
							el( Button, { size: 'sm', variant: 'ghost', icon: 'mail', title: c.email ? t( 'Send recovery email' ) : t( 'No email captured' ), disabled: ! c.email || !! c.order_id, onClick: quick( 'email', c ) } ),
							'no' !== props.settings.whatsapp_enabled ? el( Button, { size: 'sm', variant: 'ghost', icon: 'chat', title: c.phone ? t( 'WhatsApp recovery' ) : t( 'No phone captured' ), disabled: ! c.phone, onClick: quick( 'whatsapp', c ) } ) : null,
							el( Button, { size: 'sm', variant: 'ghost', icon: 'phone', title: c.phone ? t( 'Call' ) + ' ' + c.phone : t( 'No phone captured' ), disabled: ! c.phone, href: c.phone ? 'tel:' + c.phone : undefined, onClick: function ( e ) { e.stopPropagation(); } } ),
							el( Button, { size: 'sm', variant: 'ghost', icon: 'chevronRight', title: t( 'Open cart' ), onClick: quick( 'view', c ) } ) ) ) );
				} ) ) ) ) );

		return el( Fragment, null,
			el( PageHeader, { title: t( 'Abandoned carts' ), description: t( 'Every cart captured on your store, with contact details and where the visitor came from.' ), actions: [
				el( DaysSelect, { key: 'd', days: props.days, onDays: props.onDays } ),
				el( Button, { key: 'x', icon: 'download', href: exportUrl( filters ) }, t( 'Export CSV' ) ),
				el( Button, { key: 'r', variant: 'ghost', icon: 'refresh', title: t( 'Refresh' ), onClick: function () { load( true, true ); props.onRefreshStats(); } } ),
				el( AlertsBell, { key: 'b', onOpenCart: props.onOpenCartId } ) ] } ),
			cartKpis( props.stats, props.statsLoading ),
			props.cronWarning,
			el( Card, { flush: true },
				el( 'div', { className: 'cr-filterbar' },
					el( Segmented, { label: t( 'Filter by status' ), value: filters.status, onChange: function ( v ) { patch( { status: v } ); }, options: [
						{ value: '', label: t( 'All' ) }, { value: 'abandoned', label: STATUS.abandoned }, { value: 'active', label: STATUS.active },
						{ value: 'recovered', label: STATUS.recovered }, { value: 'converted', label: STATUS.converted }, { value: 'emptied', label: STATUS.emptied } ] } ),
					el( 'div', { className: 'cr-filterbar-right' },
						el( SearchField, { placeholder: t( 'Search name, email or phone' ), value: filters.search, onChange: function ( v ) { patch( { search: v } ); } } ),
						el( Button, { variant: advanced || advancedCount ? 'secondary' : 'ghost', icon: 'filter', onClick: function () { setAdvanced( ! advanced ); } }, t( 'Filters' ) + ( advancedCount ? ' · ' + advancedCount : '' ) ),
						el( 'div', { className: 'cr-pop-wrap' },
							el( Button, { variant: 'ghost', icon: 'columns', title: t( 'Choose columns' ), onClick: function () { setColsOpen( ! colsOpen ); } } ),
							colsOpen ? el( 'div', { className: 'cr-popover cr-cols-pop', onMouseLeave: function () { setColsOpen( false ); } },
								el( 'div', { className: 'cr-popover-head' }, el( 'strong', null, t( 'Visible columns' ) ) ),
								el( 'div', { className: 'cr-cols' }, colDefs.map( function ( d ) {
									return el( 'label', { key: d[ 0 ], className: 'cr-check' }, el( 'input', { type: 'checkbox', checked: !! cols[ d[ 0 ] ], onChange: function () { toggleCol( d[ 0 ] ); } } ), d[ 1 ] );
								} ) ) ) : null ) ) ),
				advanced ? el( 'div', { className: 'cr-advanced' },
					el( Field, { label: t( 'From' ) }, el( TextInput, { type: 'date', value: filters.date_from, onChange: function ( v ) { patch( { date_from: v } ); } } ) ),
					el( Field, { label: t( 'To' ) }, el( TextInput, { type: 'date', value: filters.date_to, onChange: function ( v ) { patch( { date_to: v } ); } } ) ),
					el( Field, { label: t( 'Min cart' ) }, el( TextInput, { type: 'number', min: 0, value: filters.min_total, onChange: function ( v ) { patch( { min_total: v } ); } } ) ),
					el( Field, { label: t( 'Max cart' ) }, el( TextInput, { type: 'number', min: 0, value: filters.max_total, onChange: function ( v ) { patch( { max_total: v } ); } } ) ),
					el( Field, { label: t( 'Source / UTM' ) }, el( TextInput, { placeholder: 'facebook, google…', value: filters.source, onChange: function ( v ) { patch( { source: v } ); } } ) ),
					el( Field, { label: t( 'Device' ) }, el( Select, { value: filters.device, onChange: function ( v ) { patch( { device: v } ); }, options: [ { value: '', label: t( 'All devices' ) }, { value: 'mobile', label: t( 'Mobile' ) }, { value: 'desktop', label: t( 'Desktop' ) } ] } ) ),
					el( Field, { label: t( 'Presence' ) }, el( Select, { value: filters.presence, onChange: function ( v ) { patch( { presence: v } ); }, options: [ { value: '', label: t( 'Any' ) }, { value: 'live', label: t( 'Live now' ) }, { value: 'away', label: t( 'Recently active' ) }, { value: 'inactive', label: t( 'Inactive 3h+' ) } ] } ) ),
					el( Field, { label: t( 'Conversion' ) }, el( Select, { value: filters.conversion_type, onChange: function ( v ) { patch( { conversion_type: v } ); }, options: [ { value: '', label: t( 'All' ) }, { value: 'admin_recovery', label: t( 'Admin recovery' ) }, { value: 'customer_checkout', label: t( 'Customer checkout' ) } ] } ) ),
					el( Field, { label: t( 'Risk' ) }, el( Select, { value: filters.risk_status, onChange: function ( v ) { patch( { risk_status: v } ); }, options: [ { value: '', label: t( 'All' ) }, { value: 'normal', label: t( 'Normal' ) }, { value: 'duplicate', label: t( 'Duplicate' ) }, { value: 'bot', label: t( 'Bot' ) } ] } ) ),
					el( Field, { label: t( 'Product ID' ) }, el( TextInput, { type: 'number', min: 1, value: filters.product_id, onChange: function ( v ) { patch( { product_id: v } ); } } ) ),
					el( 'div', { className: 'cr-advanced-actions' }, el( Button, { variant: 'ghost', onClick: function () { patch( { date_from: '', date_to: '', min_total: '', max_total: '', source: '', device: '', presence: '', conversion_type: '', product_id: '', risk_status: '' } ); } }, t( 'Clear filters' ) ) ) ) : null,
				table,
				items.length ? el( Pagination, { page: page, pages: res.total_pages, total: res.total, perPage: 20, onPage: setPage } ) : null ) );
	}

	/** Entry / exit times with coloured IN (green) and OUT (blue) tags. */
	function EntryExit( props ) {
		var c = props.cart || {};
		var live = 'live' === c.presence_status;
		return el( 'div', { className: 'cr-ee' },
			el( 'div', { className: 'cr-ee-row', title: formatDate( c.entry_at ) }, el( 'span', { className: 'cr-ee-tag is-in' }, t( 'In' ) ), shortTime( c.entry_at ) ),
			el( 'div', { className: 'cr-ee-row', title: live ? '' : formatDate( c.exit_at ) }, el( 'span', { className: 'cr-ee-tag is-out' }, t( 'Out' ) ),
				live ? el( 'span', { className: 'cr-ee-live' }, el( 'span', { className: 'cr-presence-dot', 'aria-hidden': 'true' } ), t( 'On site now' ) ) : shortTime( c.exit_at ) ),
			null !== c.time_on_site && undefined !== c.time_on_site ? el( 'div', { className: 'cr-ee-dur' }, el( Icon, { name: 'clock', size: 12 } ), duration( c.time_on_site ) + ( live ? ' ' + t( 'so far' ) : ' ' + t( 'on site' ) ) ) : null );
	}

	/* ------------------------------------------------------------------ live customers */
	function LiveView( props ) {
		var _i = useState( null ), items = _i[ 0 ], setItems = _i[ 1 ];
		function load() { api( '/live-customers?limit=100' ).then( function ( r ) { setItems( r.items || [] ); } ).catch( function () { setItems( [] ); } ); }
		useEffect( function () { load(); var timer = setInterval( load, 10000 ); return function () { clearInterval( timer ); }; }, [] );
		var live = ( items || [] ).filter( function ( c ) { return 'live' === c.presence_status; } ).length;
		return el( Fragment, null,
			el( PageHeader, { title: t( 'Live customers' ), description: t( 'Shoppers with a cart who were active in the last 10 minutes. Refreshes every 10 seconds.' ), actions: [
				el( 'span', { key: 'p', className: 'cr-presence cr-presence--live' }, el( 'span', { className: 'cr-presence-dot' } ), live + ' ' + t( 'on site now' ) ),
				el( Button, { key: 'r', variant: 'ghost', icon: 'refresh', title: t( 'Refresh' ), onClick: load } ),
				el( AlertsBell, { key: 'b', onOpenCart: props.onOpenCartId } ) ] } ),
			null === items ? el( Loading, { rows: 4 } ) : ( ! items.length ? el( Card, null, el( EmptyState, { icon: 'pulse', title: t( 'No live customers right now' ), description: t( 'Shoppers appear here within seconds of adding a product to their cart.' ) } ) )
				: el( 'div', { className: 'cr-live-grid' }, items.map( function ( c ) {
					return el( 'article', { key: c.id, className: 'cr-live', onClick: function () { props.onOpenCart( c ); }, tabIndex: 0, onKeyDown: function ( e ) { if ( 'Enter' === e.key ) props.onOpenCart( c ); } },
						el( 'div', { className: 'cr-live-top' }, el( CustomerCell, { name: cartName( c ), email: c.email, phone: c.phone } ), el( Presence, { cart: c } ) ),
						el( 'div', { className: 'cr-live-page' }, el( Icon, { name: 'mobile' === c.device_type ? 'smartphone' : 'monitor', size: 14 } ),
							el( 'span', { className: 'cr-muted' }, t( 'Viewing' ) + ' ' ), el( 'span', { className: 'cr-mono cr-ink', title: c.current_page }, shortUrl( c.current_page ) || '—' ) ),
						el( 'div', { className: 'cr-live-foot' }, el( ProductStack, { items: c.cart_contents } ), el( 'strong', null, money( c.cart_total ) ) ) );
				} ) ) ) );
	}

	/* ------------------------------------------------------------------ journeys */
	function JourneyView( props ) {
		var _p = useState( 1 ), page = _p[ 0 ], setPage = _p[ 1 ];
		var _s = useState( '' ), search = _s[ 0 ], setSearch = _s[ 1 ];
		var _r = useState( null ), res = _r[ 0 ], setRes = _r[ 1 ];
		useEffect( function () {
			api( '/carts?' + qs( { per_page: 20, page: page, search: search, orderby: 'updated_at', order: 'DESC' } ) ).then( setRes ).catch( function () { setRes( { items: [] } ); } );
		}, [ page, search ] );
		var items = res ? res.items || [] : [];
		return el( Fragment, null,
			el( PageHeader, { title: t( 'Customer journey' ), description: t( 'Open any shopper to see every step from first visit to recovery or checkout.' ) } ),
			el( Card, { flush: true },
				el( 'div', { className: 'cr-filterbar' }, el( SearchField, { placeholder: t( 'Search name, email or phone' ), value: search, onChange: function ( v ) { setPage( 1 ); setSearch( v ); } } ) ),
				! res ? el( Loading, { rows: 6 } ) : el( DataTable, { rows: items, emptyIcon: 'route', emptyTitle: t( 'No journeys yet' ), onRow: function ( c ) { props.onOpenCart( c, 'activity' ); }, columns: [
					{ key: 'c', label: t( 'Customer' ), render: function ( c ) { return el( CustomerCell, { name: cartName( c ), email: c.email, phone: c.phone } ); } },
					{ key: 's', label: t( 'Source' ), render: function ( c ) { return el( SourceChip, cartSource( c ) ); } },
					{ key: 'e', label: t( 'Entered' ), render: function ( c ) { return shortTime( c.entry_at ); } },
					{ key: 'o', label: t( 'Time on site' ), render: function ( c ) { return duration( c.time_on_site ); } },
					{ key: 'st', label: t( 'Status' ), render: function ( c ) { return el( StatusBadge, { status: c.status } ); } },
					{ key: 'tt', label: t( 'Total' ), align: 'right', render: function ( c ) { return el( 'strong', null, money( c.cart_total ) ); } },
					{ key: 'go', label: '', align: 'right', render: function () { return el( Button, { size: 'sm', variant: 'ghost', iconRight: 'chevronRight' }, t( 'Journey' ) ); } } ] } ),
				res && items.length ? el( Pagination, { page: page, pages: res.total_pages, total: res.total, perPage: 20, onPage: setPage } ) : null ) );
	}

	/* ------------------------------------------------------------------ orders + recovered orders */
	var ORDER_STATUSES = [ [ 'all', t( 'All' ) ], [ 'pending', t( 'Pending' ) ], [ 'processing', t( 'Processing' ) ], [ 'on-hold', t( 'On hold' ) ], [ 'completed', t( 'Completed' ) ], [ 'cancelled', t( 'Cancelled' ) ], [ 'refunded', t( 'Refunded' ) ], [ 'failed', t( 'Failed' ) ] ];
	function OrdersTable( props ) {
		var rows = props.rows;
		var columns = [
			{ key: 'n', label: t( 'Order' ), render: function ( o ) { return el( 'div', null, el( 'strong', { className: 'cr-mono' }, '#' + o.number ), el( 'div', { className: 'cr-muted' }, shortTime( o.date ) ) ); } },
			{ key: 'c', label: t( 'Customer' ), render: function ( o ) { return el( CustomerCell, { name: o.customer, email: o.email, phone: o.phone } ); } },
			{ key: 'p', label: t( 'Products' ), render: function ( o ) { return el( ProductStack, { items: o.items } ); } },
			{ key: 't', label: t( 'Total' ), align: 'right', render: function ( o ) { return el( 'strong', null, money( o.total ) ); } }
		];
		if ( props.recovered ) {
			columns.push( { key: 'r', label: t( 'Recovery' ), render: function ( o ) { return el( 'div', { className: 'cr-status-cell' }, el( RecoveryBadge, { type: o.recovery_type } ), o.recovered_by ? el( 'span', { className: 'cr-muted' }, t( 'by' ) + ' ' + o.recovered_by ) : null ); } } );
			columns.push( { key: 'cart', label: t( 'Cart' ), render: function ( o ) { return o.cart_id ? el( Button, { size: 'sm', variant: 'ghost', onClick: function ( e ) { e.stopPropagation(); props.onOpenCartId( o.cart_id ); } }, '#' + o.cart_id ) : '—'; } } );
		} else {
			columns.push( { key: 'pay', label: t( 'Payment' ), render: function ( o ) { return o.payment || el( 'span', { className: 'cr-muted' }, '—' ); } } );
		}
		columns.push( { key: 's', label: t( 'Source' ), render: function ( o ) { return o.source ? el( 'span', { className: 'cr-source-name' }, o.source ) : el( 'span', { className: 'cr-muted' }, '—' ); } } );
		columns.push( { key: 'st', label: t( 'Status' ), render: function ( o ) { return el( 'div', { className: 'cr-status-cell' }, el( OrderBadge, { status: o.status, label: o.status_label } ), ! props.recovered && o.recovery_type ? el( RecoveryBadge, { type: o.recovery_type } ) : null ); } } );
		return el( DataTable, { rows: rows, columns: columns, onRow: props.onOpen, emptyIcon: props.recovered ? 'undo' : 'receipt', emptyTitle: props.emptyTitle, emptyText: props.emptyText } );
	}

	function OrdersView( props ) {
		var _s = useState( null ), summary = _s[ 0 ], setSummary = _s[ 1 ];
		var _st = useState( 'all' ), status = _st[ 0 ], setStatus = _st[ 1 ];
		var _q = useState( '' ), search = _q[ 0 ], setSearch = _q[ 1 ];
		var _p = useState( 1 ), page = _p[ 0 ], setPage = _p[ 1 ];
		var _r = useState( null ), res = _r[ 0 ], setRes = _r[ 1 ];
		function loadSummary() { api( '/orders/summary?days=' + props.days ).then( setSummary ).catch( function () {} ); }
		function loadOrders() { setRes( null ); api( '/orders?' + qs( { page: page, per_page: 20, status: status, search: search } ) ).then( setRes ).catch( function ( err ) { toast.error( errMsg( err ) ); setRes( { orders: [], total: 0, pages: 1 } ); } ); }
		useEffect( loadSummary, [ props.days ] );
		useEffect( loadOrders, [ page, status, search ] );
		var s = summary || {};
		var counts = s.status_counts || {};
		var daily = fillDays( s.daily || [], Math.min( props.days, 90 ), [ 'sales', 'orders' ] );
		return el( Fragment, null,
			el( PageHeader, { title: t( 'Orders' ), description: t( 'Every WooCommerce order in one place — the same orders your Telegram and WhatsApp alerts report.' ), actions: [
				el( DaysSelect, { key: 'd', days: props.days, onDays: props.onDays } ),
				el( Button, { key: 'r', variant: 'ghost', icon: 'refresh', title: t( 'Refresh' ), onClick: function () { loadSummary(); loadOrders(); } } ),
				el( AlertsBell, { key: 'b', onOpenCart: props.onOpenCartId } ) ] } ),
			el( 'div', { className: 'cr-kpi-grid' },
				el( KpiCard, { loading: ! summary, icon: 'coins', tone: 'accent', label: t( 'Sales' ), value: money( s.sales ), delta: pctDelta( s.sales, s.prev_sales ), trend: daily.length > 1 ? daily.map( function ( d ) { return d.sales; } ) : null } ),
				el( KpiCard, { loading: ! summary, icon: 'receipt', tone: 'brand', label: t( 'Paid orders' ), value: num( s.orders ), delta: pctDelta( s.orders, s.prev_orders ) } ),
				el( KpiCard, { loading: ! summary, icon: 'tag', tone: 'active', label: t( 'Average order' ), value: money( s.aov ) } ),
				el( KpiCard, { loading: ! summary, icon: 'clock', tone: 'neutral', label: t( 'Today' ), value: num( s.today_orders ) + ' ' + t( 'orders' ), hint: money( s.today_sales ) + ' · ' + num( counts.pending || 0 ) + ' ' + t( 'pending payment' ) } ) ),
			el( Card, { title: t( 'Daily sales' ), description: t( 'Processing, on-hold and completed orders' ) }, summary ? el( BarChart, { data: daily, series: [ { key: 'sales', label: t( 'Sales' ), tone: 'checkout' } ], format: money, axisFormat: function ( v ) { return CURRENCY + num( v ); }, label: t( 'Daily sales' ) } ) : el( Loading, { rows: 3 } ) ),
			el( Card, { flush: true },
				el( 'div', { className: 'cr-filterbar' },
					el( Segmented, { label: t( 'Order status' ), value: status, onChange: function ( v ) { setPage( 1 ); setStatus( v ); }, options: ORDER_STATUSES.map( function ( x ) { return { value: x[ 0 ], label: x[ 1 ], count: 'all' === x[ 0 ] ? null : counts[ x[ 0 ] ] }; } ) } ),
					el( 'div', { className: 'cr-filterbar-right' }, el( SearchField, { placeholder: t( 'Order #, name, email or phone' ), value: search, onChange: function ( v ) { setPage( 1 ); setSearch( v ); } } ) ) ),
				! res ? el( Loading, { rows: 6 } ) : el( OrdersTable, { rows: res.orders || [], onOpen: function ( o ) { props.onOpenOrder( o.id ); }, onOpenCartId: props.onOpenCartId, emptyTitle: t( 'No orders found' ) } ),
				res && ( res.orders || [] ).length ? el( Pagination, { page: page, pages: res.pages, total: res.total, perPage: 20, onPage: setPage } ) : null ) );
	}

	function RecoveredView( props ) {
		var _t = useState( 'any' ), type = _t[ 0 ], setType = _t[ 1 ];
		var _q = useState( '' ), search = _q[ 0 ], setSearch = _q[ 1 ];
		var _p = useState( 1 ), page = _p[ 0 ], setPage = _p[ 1 ];
		var _r = useState( null ), res = _r[ 0 ], setRes = _r[ 1 ];
		var _c = useState( {} ), counts = _c[ 0 ], setCounts = _c[ 1 ];
		function load() {
			setRes( null );
			api( '/orders?' + qs( { recovered: type, page: page, per_page: 20, search: search } ) ).then( setRes ).catch( function ( err ) { toast.error( errMsg( err ) ); setRes( { orders: [], total: 0, pages: 1 } ); } );
		}
		useEffect( load, [ type, page, search ] );
		useEffect( function () {
			[ 'any', 'admin', 'link' ].forEach( function ( k ) {
				api( '/orders?' + qs( { recovered: k, per_page: 5 } ) ).then( function ( r ) { setCounts( function ( p ) { var n = Object.assign( {}, p ); n[ k ] = r.total; return n; } ); } ).catch( function () {} );
			} );
		}, [] );
		var tt = props.stats.totals || {};
		var admin = parseInt( tt.admin_recovered || 0, 10 ), ab = parseInt( tt.abandoned || 0, 10 );
		return el( Fragment, null,
			el( PageHeader, { title: t( 'Recovered orders' ), description: t( 'Orders won back from abandoned carts — created by your team from the dashboard, or completed by the customer after a recovery email or WhatsApp link.' ), actions: [
				el( DaysSelect, { key: 'd', days: props.days, onDays: props.onDays } ),
				el( Button, { key: 'r', variant: 'ghost', icon: 'refresh', title: t( 'Refresh' ), onClick: load } ),
				el( AlertsBell, { key: 'b', onOpenCart: props.onOpenCartId } ) ] } ),
			el( 'div', { className: 'cr-kpi-grid' },
				el( KpiCard, { loading: props.statsLoading, icon: 'coins', tone: 'accent', label: t( 'Recovered revenue' ), value: money( tt.recovered_revenue ), hint: t( 'Admin recoveries in the selected period' ) } ),
				el( KpiCard, { loading: props.statsLoading, icon: 'undo', tone: 'brand', label: t( 'Recovered by admin' ), value: num( admin ), hint: ( ab + admin ? ( admin / ( ab + admin ) * 100 ).toFixed( 1 ) : '0.0' ) + '% ' + t( 'recovery rate' ) } ),
				el( KpiCard, { loading: undefined === counts.link, icon: 'link', tone: 'active', label: t( 'Via recovery link' ), value: num( counts.link || 0 ), hint: t( 'All time' ) } ),
				el( KpiCard, { loading: undefined === counts.any, icon: 'receipt', tone: 'neutral', label: t( 'Recovered orders' ), value: num( counts.any || 0 ), hint: t( 'All time' ) } ) ),
			el( Card, { flush: true },
				el( 'div', { className: 'cr-filterbar' },
					el( Segmented, { label: t( 'Recovery type' ), value: type, onChange: function ( v ) { setPage( 1 ); setType( v ); }, options: [
						{ value: 'any', label: t( 'All recovered' ), count: counts.any },
						{ value: 'admin', label: t( 'Recovered by admin' ), icon: 'undo', count: counts.admin },
						{ value: 'link', label: t( 'Recovery link' ), icon: 'link', count: counts.link } ] } ),
					el( 'div', { className: 'cr-filterbar-right' }, el( SearchField, { placeholder: t( 'Order #, name, email or phone' ), value: search, onChange: function ( v ) { setPage( 1 ); setSearch( v ); } } ) ) ),
				! res ? el( Loading, { rows: 6 } ) : el( OrdersTable, { recovered: true, rows: res.orders || [], onOpen: function ( o ) { props.onOpenOrder( o.id ); }, onOpenCartId: props.onOpenCartId,
					emptyTitle: t( 'No recovered orders yet' ), emptyText: t( 'Open an abandoned cart and use "Create order", or send a recovery email — won-back orders will be listed here.' ) } ),
				res && ( res.orders || [] ).length ? el( Pagination, { page: page, pages: res.pages, total: res.total, perPage: 20, onPage: setPage } ) : null ) );
	}

	/* ------------------------------------------------------------------ analytics */
	function AnalyticsView( props ) {
		var s = props.stats || {};
		var daily = fillDays( s.daily || [], Math.min( props.days, 90 ), [ 'abandoned', 'recovered', 'customer_checkout' ] );
		return el( Fragment, null,
			el( PageHeader, { title: t( 'Overview' ), description: t( 'How carts are captured, abandoned and won back.' ), actions: [ el( DaysSelect, { key: 'd', days: props.days, onDays: props.onDays } ), el( AlertsBell, { key: 'b', onOpenCart: props.onOpenCartId } ) ] } ),
			cartKpis( s, props.statsLoading ),
			props.cronWarning,
			el( 'div', { className: 'cr-grid-2' },
				el( Card, { title: t( 'Cart activity' ), description: t( 'Carts created per day, by outcome' ) },
					el( BarChart, { data: daily, label: t( 'Cart activity per day' ), series: [
						{ key: 'recovered', label: t( 'Recovered by admin' ), tone: 'recovered' },
						{ key: 'customer_checkout', label: t( 'Customer checkout' ), tone: 'checkout' },
						{ key: 'abandoned', label: t( 'Abandoned' ), tone: 'abandoned' } ] } ) ),
				el( Card, { title: t( 'Traffic sources' ), description: t( 'Share of carts recovered per source' ) }, el( SourceBreakdown, { rows: s.sources } ) ) ),
			el( Card, { title: t( 'Most abandoned products' ), flush: true, actions: el( Button, { size: 'sm', variant: 'ghost', iconRight: 'chevronRight', onClick: function () { props.go( 'products' ); } }, t( 'All products' ) ) },
				el( DataTable, { rows: s.top_products || [], emptyIcon: 'box', emptyTitle: t( 'No abandoned products in this period' ), columns: [
					{ key: 'name', label: t( 'Product' ), render: function ( p ) { return el( ProductStack, { items: [ { name: p.name || '#' + p.product_id, quantity: p.count } ] } ); } },
					{ key: 'count', label: t( 'Units left in carts' ), align: 'right', render: function ( p ) { return num( p.count ); } } ] } ) ) );
	}
	function ProductsView( props ) {
		var _r = useState( null ), rows = _r[ 0 ], setRows = _r[ 1 ];
		useEffect( function () { setRows( null ); api( '/product-analytics?days=' + props.days + '&limit=100' ).then( function ( r ) { setRows( r.products || [] ); } ).catch( function () { setRows( [] ); } ); }, [ props.days ] );
		return el( Fragment, null,
			el( PageHeader, { title: t( 'Products' ), description: t( 'Which products are left in carts most, and how often they are won back.' ), actions: [ el( DaysSelect, { key: 'd', days: props.days, onDays: props.onDays } ) ] } ),
			el( Card, { flush: true }, null === rows ? el( Loading, { rows: 6 } ) : el( DataTable, { rows: rows, emptyIcon: 'box', columns: [
				{ key: 'name', label: t( 'Product' ), render: function ( p ) { return el( ProductStack, { items: [ { name: p.name, image: p.image, quantity: p.quantity } ] } ); } },
				{ key: 'carts', label: t( 'Carts' ), align: 'right' },
				{ key: 'abandoned', label: t( 'Abandoned' ), align: 'right' },
				{ key: 'admin_recovered', label: t( 'Recovered' ), align: 'right' },
				{ key: 'customer_checkout', label: t( 'Checkout' ), align: 'right' },
				{ key: 'recovery_rate', label: t( 'Recovery' ), align: 'right', render: function ( p ) { return el( 'span', { className: 'cr-rate' }, el( 'span', { className: 'cr-meter cr-meter--inline' }, el( 'span', { className: 'cr-meter-rec', style: { width: Math.min( 100, p.recovery_rate ) + '%' } } ) ), p.recovery_rate + '%' ); } },
				{ key: 'recovered_revenue', label: t( 'Recovered revenue' ), align: 'right', render: function ( p ) { return el( 'strong', null, money( p.recovered_revenue ) ); } } ] } ) ) );
	}
	function UtmView( props ) {
		var _r = useState( null ), rows = _r[ 0 ], setRows = _r[ 1 ];
		useEffect( function () { setRows( null ); api( '/utm-analytics?days=' + props.days ).then( function ( r ) { setRows( r.rows || [] ); } ).catch( function () { setRows( [] ); } ); }, [ props.days ] );
		return el( Fragment, null,
			el( PageHeader, { title: t( 'UTM campaigns' ), description: t( 'Which source, medium and campaign bring carts and recovered revenue.' ), actions: [ el( DaysSelect, { key: 'd', days: props.days, onDays: props.onDays } ) ] } ),
			el( Card, { flush: true }, null === rows ? el( Loading, { rows: 6 } ) : el( DataTable, { rows: rows, emptyIcon: 'megaphone', emptyText: t( 'Add utm_source / utm_medium / utm_campaign to your ad links to see them here.' ), columns: [
				{ key: 'source', label: t( 'Source / medium' ), render: function ( r ) { return el( SourceChip, { source: r.source, medium: '—' !== r.medium ? r.medium : '' } ); } },
				{ key: 'campaign', label: t( 'Campaign' ), render: function ( r ) { return '—' === r.campaign ? el( 'span', { className: 'cr-muted' }, '—' ) : el( 'span', { className: 'cr-mono' }, r.campaign ); } },
				{ key: 'carts', label: t( 'Carts' ), align: 'right' },
				{ key: 'abandoned', label: t( 'Abandoned' ), align: 'right' },
				{ key: 'admin_recovered', label: t( 'Recovered' ), align: 'right' },
				{ key: 'customer_checkout', label: t( 'Checkout' ), align: 'right' },
				{ key: 'recovery_rate', label: t( 'Rate' ), align: 'right', render: function ( r ) { return r.recovery_rate + '%'; } },
				{ key: 'recovered_revenue', label: t( 'Revenue' ), align: 'right', render: function ( r ) { return el( 'strong', null, money( r.recovered_revenue ) ); } } ] } ) ) );
	}
	function RevenueView( props ) {
		var _d = useState( null ), data = _d[ 0 ], setData = _d[ 1 ];
		useEffect( function () { setData( null ); api( '/revenue-analytics?days=' + props.days ).then( setData ).catch( function () { setData( { daily: [], totals: {} } ); } ); }, [ props.days ] );
		var tt = data ? data.totals || {} : {};
		var daily = fillDays( data ? data.daily : [], Math.min( props.days, 90 ), [ 'recovered_revenue', 'customer_checkout_revenue', 'potential_revenue' ] );
		return el( Fragment, null,
			el( PageHeader, { title: t( 'Revenue' ), description: t( 'Revenue split between admin recovery and normal customer checkout.' ), actions: [ el( DaysSelect, { key: 'd', days: props.days, onDays: props.onDays } ) ] } ),
			el( 'div', { className: 'cr-kpi-grid' },
				el( KpiCard, { loading: ! data, icon: 'undo', tone: 'brand', label: t( 'Recovered by admin' ), value: money( tt.recovered_revenue ), hint: num( tt.admin_orders || 0 ) + ' ' + t( 'orders' ) + ' · ' + t( 'avg' ) + ' ' + money( tt.admin_aov ) } ),
				el( KpiCard, { loading: ! data, icon: 'receipt', tone: 'active', label: t( 'Customer checkout' ), value: money( tt.customer_checkout_revenue ), hint: num( tt.customer_orders || 0 ) + ' ' + t( 'orders' ) + ' · ' + t( 'avg' ) + ' ' + money( tt.customer_aov ) } ),
				el( KpiCard, { loading: ! data, icon: 'zap', tone: 'abandoned', label: t( 'Still at risk' ), value: money( tt.potential_revenue ) } ),
				el( KpiCard, { loading: ! data, icon: 'pulse', tone: 'accent', label: t( 'Recovered vs at risk' ), value: ( tt.recovery_rate || 0 ) + '%' } ) ),
			el( Card, { title: t( 'Daily revenue' ) }, ! data ? el( Loading, { rows: 3 } ) : el( BarChart, { data: daily, format: money, axisFormat: function ( v ) { return CURRENCY + num( v ); }, label: t( 'Daily revenue' ), series: [
				{ key: 'recovered_revenue', label: t( 'Recovered by admin' ), tone: 'recovered' },
				{ key: 'customer_checkout_revenue', label: t( 'Customer checkout' ), tone: 'checkout' },
				{ key: 'potential_revenue', label: t( 'At risk' ), tone: 'abandoned' } ] } ) ) );
	}
	var SEGMENT_ICON = { high_value: 'zap', active: 'pulse', abandoned: 'cart', recovered: 'undo', customer_checkout: 'receipt' };
	function SegmentsView( props ) {
		var _s = useState( null ), segs = _s[ 0 ], setSegs = _s[ 1 ];
		useEffect( function () { setSegs( null ); api( '/customer-segments?days=' + props.days ).then( function ( r ) { setSegs( r.segments || [] ); } ).catch( function () { setSegs( [] ); } ); }, [ props.days ] );
		return el( Fragment, null,
			el( PageHeader, { title: t( 'Segments' ), description: t( 'Behaviour and value-based customer groups from captured cart activity.' ), actions: [ el( DaysSelect, { key: 'd', days: props.days, onDays: props.onDays } ) ] } ),
			null === segs ? el( Loading, { rows: 4 } ) : el( 'div', { className: 'cr-seg-grid' }, segs.map( function ( sg ) {
				return el( 'div', { key: sg.key, className: 'cr-kpi' },
					el( 'div', { className: 'cr-kpi-head' }, el( 'span', { className: 'cr-kpi-icon cr-tone-brand' }, el( Icon, { name: SEGMENT_ICON[ sg.key ] || 'users', size: 15 } ) ), el( 'span', { className: 'cr-kpi-label' }, sg.label ) ),
					el( 'div', { className: 'cr-kpi-value' }, num( sg.customers ), el( 'span', { className: 'cr-kpi-unit' }, ' ' + t( 'customers' ) ) ),
					el( 'div', { className: 'cr-kpi-foot cr-muted' }, num( sg.carts ) + ' ' + t( 'carts' ) + ' · ' + num( sg.recovered ) + ' ' + t( 'recovered' ) + ' · ' + money( sg.revenue ) ) );
			} ) ) );
	}

	/* ------------------------------------------------------------------ settings state */
	function useSettings( onSaved ) {
		var _s = useState( null ), settings = _s[ 0 ], setSettings = _s[ 1 ];
		var _d = useState( false ), dirty = _d[ 0 ], setDirty = _d[ 1 ];
		var _b = useState( false ), saving = _b[ 0 ], setSaving = _b[ 1 ];
		var _e = useState( '' ), error = _e[ 0 ], setError = _e[ 1 ];
		var original = useRef( null );
		useEffect( function () {
			api( '/settings' ).then( function ( s ) { original.current = s; setSettings( s ); } ).catch( function ( err ) { setError( errMsg( err ) ); } );
		}, [] );
		function set( key, value ) { setSettings( function ( p ) { var n = Object.assign( {}, p ); n[ key ] = value; return n; } ); setDirty( true ); }
		function save() {
			setSaving( true );
			return api( '/settings', { method: 'POST', data: settings } )
				.then( function ( s ) { original.current = s; setSettings( s ); setDirty( false ); toast.success( t( 'Settings saved' ) ); if ( onSaved ) onSaved( s ); return s; } )
				.catch( function ( err ) { toast.error( errMsg( err ) ); } )
				.finally( function () { setSaving( false ); } );
		}
		function discard() { setSettings( original.current ); setDirty( false ); }
		return { settings: settings, set: set, save: save, discard: discard, dirty: dirty, saving: saving, error: error };
	}
	function SaveBar( props ) {
		var st = props.state;
		return el( 'div', { className: cx( 'cr-savebar', st.dirty && 'is-dirty' ) },
			el( 'span', { className: 'cr-muted' }, st.dirty ? t( 'You have unsaved changes' ) : t( 'All changes saved' ) ),
			el( 'div', { className: 'cr-page-actions' },
				st.dirty ? el( Button, { variant: 'ghost', onClick: st.discard }, t( 'Discard' ) ) : null,
				el( Button, { variant: 'primary', icon: 'check', loading: st.saving, disabled: ! st.dirty, onClick: st.save }, t( 'Save settings' ) ) ) );
	}
	function SettingsSection( props ) {
		return el( 'section', { className: 'cr-settings-sec', id: props.id },
			el( 'div', { className: 'cr-settings-intro' }, el( 'h3', null, props.title ), props.description ? el( 'p', null, props.description ) : null, props.aside || null ),
			el( 'div', { className: 'cr-settings-fields' }, props.children ) );
	}

	/* ------------------------------------------------------------------ notifications */
	var EVENTS = {
		cart: [
			[ 'lead_captured', t( 'New checkout lead' ), t( 'A shopper typed a phone number or email on checkout (see the lead rule below).' ) ],
			[ 'cart_abandoned', t( 'Cart abandoned' ), t( 'A cart with a phone or email stayed inactive past the abandon time.' ) ],
			[ 'cart_high_value', t( 'High-value cart abandoned' ), t( 'Same, when the cart total is above the high-value threshold. Replaces the message above.' ) ],
			[ 'cart_recovered', t( 'Cart recovered by admin' ), t( 'Your team created an order from a cart.' ) ]
		],
		order: [
			[ 'new_order', t( 'New order' ), t( 'Any new WooCommerce order — classic or block checkout, or created in wp-admin.' ) ],
			[ 'order_processing', t( 'Order processing' ), t( 'Payment received / order is being prepared.' ) ],
			[ 'order_completed', t( 'Order completed' ), '' ],
			[ 'order_cancelled', t( 'Order cancelled or failed' ), '' ],
			[ 'order_refunded', t( 'Order refunded' ), '' ]
		]
	};
	var SCOPE_OPTIONS = [ { value: 'cart', label: t( 'Cart' ), icon: 'cart' }, { value: 'order', label: t( 'Order' ), icon: 'receipt' }, { value: 'both', label: t( 'Both' ) } ];

	function DeliveryLog() {
		var _l = useState( null ), log = _l[ 0 ], setLog = _l[ 1 ];
		function load() { api( '/notifications/log' ).then( setLog ).catch( function () { setLog( { items: [], labels: {} } ); } ); }
		useEffect( load, [] );
		function clear() { api( '/notifications/log', { method: 'DELETE' } ).then( load ); }
		var items = log ? log.items || [] : [];
		var labels = log ? log.labels || {} : {};
		return el( Card, { title: t( 'Recent deliveries' ), description: t( 'The last 40 Telegram and WhatsApp messages and why any failed.' ), flush: true, actions: [
			el( Button, { key: 'r', size: 'sm', variant: 'ghost', icon: 'refresh', title: t( 'Refresh' ), onClick: load } ),
			items.length ? el( Button, { key: 'c', size: 'sm', variant: 'ghost', onClick: clear }, t( 'Clear' ) ) : null ] },
			! log ? el( Loading, { rows: 3 } ) : el( DataTable, { rows: items, emptyIcon: 'send', emptyTitle: t( 'Nothing sent yet' ), emptyText: t( 'Use "Send test" above to check a channel.' ), columns: [
				{ key: 'ok', label: '', render: function ( r ) { return el( 'span', { className: cx( 'cr-dot-status', r.ok ? 'is-ok' : 'is-fail' ), title: r.ok ? t( 'Delivered' ) : t( 'Failed' ) }, el( Icon, { name: r.ok ? 'check' : 'x', size: 12, strokeWidth: 2.4 } ) ); } },
				{ key: 'time', label: t( 'When' ), render: function ( r ) { return shortTime( new Date( r.time * 1000 ).toISOString() ); } },
				{ key: 'channel', label: t( 'Channel' ), render: function ( r ) { return 'telegram' === r.channel ? 'Telegram' : ( 'whatsapp' === r.channel ? 'WhatsApp' : r.channel ); } },
				{ key: 'event', label: t( 'Event' ), render: function ( r ) { return el( 'div', null, el( 'div', null, labels[ r.event ] || ( 'test' === r.event ? t( 'Test message' ) : r.event ) ), r.title ? el( 'div', { className: 'cr-muted' }, r.title ) : null ); } },
				{ key: 'message', label: t( 'Result' ), render: function ( r ) { return el( 'span', { className: r.ok ? 'cr-muted' : 'cr-fail-text' }, r.message ); } } ] } ) );
	}

	function NotificationsView( props ) {
		var st = useSettings( props.onSettingsSaved );
		var _t = useState( '' ), testing = _t[ 0 ], setTesting = _t[ 1 ];
		var _k = useState( 0 ), logKey = _k[ 0 ], setLogKey = _k[ 1 ];
		if ( st.error ) return el( Card, null, el( EmptyState, { icon: 'alert', title: t( 'Could not load settings' ), description: st.error } ) );
		if ( ! st.settings ) return el( Loading, { rows: 8 } );
		var s = st.settings;
		var set = st.set;
		var ev = s.notify_events || {};
		function setEvent( event, channel, value ) {
			var next = JSON.parse( JSON.stringify( ev ) );
			next[ event ] = next[ event ] || {};
			next[ event ][ channel ] = value;
			set( 'notify_events', next );
		}
		function test( channel ) {
			setTesting( channel );
			api( '/notifications/test', { method: 'POST', data: { channel: channel, settings: s } } )
				.then( function ( r ) { toast.success( r.message || t( 'Test message sent' ), { duration: 6000 } ); } )
				.catch( function ( err ) { toast.error( errMsg( err ), { duration: 12000 } ); } )
				.finally( function () { setTesting( '' ); setLogKey( logKey + 1 ); } );
		}
		var tgOn = 'yes' === s.notify_telegram_enabled, waOn = 'yes' === s.notify_whatsapp_enabled;
		var provider = s.whatsapp_provider || 'callmebot';

		function channelCell( event, channel, group ) {
			var on = 'telegram' === channel ? tgOn : waOn;
			var scope = s[ channel + '_scope' ] || 'both';
			var inScope = 'both' === scope || scope === group;
			var value = ev[ event ] ? ev[ event ][ channel ] : 'no';
			return el( 'td', { className: 'cr-matrix-cell' },
				el( Toggle, { value: on && inScope ? value : 'no', disabled: ! on || ! inScope, ariaLabel: channel + ' ' + event, onChange: function ( v ) { setEvent( event, channel, v ); } } ) );
		}
		function matrixRows( group ) {
			return EVENTS[ group ].map( function ( e ) {
				return el( 'tr', { key: e[ 0 ] },
					el( 'td', null, el( 'div', { className: 'cr-toggle-label' }, e[ 1 ] ), e[ 2 ] ? el( 'div', { className: 'cr-toggle-desc' }, e[ 2 ] ) : null ),
					channelCell( e[ 0 ], 'telegram', group ), channelCell( e[ 0 ], 'whatsapp', group ) );
			} );
		}
		var LEAD_OPTIONS = [
			[ 'phone', t( 'Phone number entered' ), t( 'Alert as soon as a valid phone number is typed.' ), 'phone' ],
			[ 'email', t( 'Email entered' ), t( 'Alert as soon as a valid email is typed.' ), 'mail' ],
			[ 'any', t( 'Phone or email' ), t( 'Whichever comes first. Recommended.' ), 'user' ],
			[ 'both', t( 'Both phone and email' ), t( 'Only once the shopper has given both.' ), 'check' ]
		];

		return el( Fragment, null,
			el( PageHeader, { title: t( 'Notifications' ), description: t( 'Get Telegram and WhatsApp alerts for new checkout leads, orders and abandoned carts — choose exactly which ones each channel receives.' ) } ),
			el( 'div', null,
				el( SettingsSection, { title: 'Telegram', description: t( 'Messages from your own Telegram bot to you, your team or a group.' ),
					aside: el( 'ol', { className: 'cr-howto' },
						el( 'li', null, t( 'In Telegram, open @BotFather → /newbot and copy the bot token.' ) ),
						el( 'li', null, t( 'Send any message to your new bot (or add it to your group).' ) ),
						el( 'li', null, t( 'Get the chat ID from @userinfobot, or for a group from @RawDataBot (it starts with -100).' ) ) ) },
					el( Toggle, { value: s.notify_telegram_enabled, onChange: function ( v ) { set( 'notify_telegram_enabled', v ); }, label: t( 'Send Telegram notifications' ) } ),
					el( Field, { label: t( 'Send notifications for' ), help: t( 'Cart = leads, abandoned, high-value and recovered carts. Order = new orders and status changes.' ) },
						el( Segmented, { label: t( 'Telegram scope' ), value: s.telegram_scope || 'both', onChange: function ( v ) { set( 'telegram_scope', v ); }, options: SCOPE_OPTIONS } ) ),
					el( 'div', { className: 'cr-form-grid' },
						el( Field, { label: t( 'Bot token' ) }, el( TextInput, { type: 'password', value: s.telegram_bot_token, placeholder: '123456789:AA…', onChange: function ( v ) { set( 'telegram_bot_token', v ); } } ) ),
						el( Field, { label: t( 'Chat IDs' ), help: t( 'Separate several chats with commas.' ) }, el( TextInput, { value: s.telegram_chat_ids, placeholder: '-1001234567890, 98765432', onChange: function ( v ) { set( 'telegram_chat_ids', v ); } } ) ) ),
					el( 'div', null, el( Button, { icon: 'send', loading: 'telegram' === testing, disabled: ! s.telegram_bot_token || ! s.telegram_chat_ids, onClick: function () { test( 'telegram' ); } }, t( 'Save & send test' ) ) ) ),

				el( SettingsSection, { title: 'WhatsApp', description: t( 'Alerts to your own WhatsApp number(s). Pick the gateway you use.' ),
					aside: 'callmebot' === provider ? el( 'ol', { className: 'cr-howto' },
						el( 'li', null, t( 'Free and quickest for alerts to your own phone.' ) ),
						el( 'li', null, t( 'Add +34 644 66 32 62 to your contacts and send it: "I allow callmebot to send me messages".' ) ),
						el( 'li', null, t( 'You will receive your API key on WhatsApp. Repeat for each team member\'s number.' ) ) )
						: ( 'cloud_api' === provider ? el( 'p', { className: 'cr-field-help' }, t( 'Meta delivers free-form text only within 24 hours after the recipient last messaged your business number. For reliable alerts create an approved Utility template with one body variable {{1}} and enter its name — the alert text is sent as that variable.' ) )
							: el( 'p', { className: 'cr-field-help' }, t( 'Works with most SMS / WhatsApp gateways (UltraMsg, WATI, local BD providers…). Use {phone} and {message} in the URL or JSON body.' ) ) ) },
					el( Toggle, { value: s.notify_whatsapp_enabled, onChange: function ( v ) { set( 'notify_whatsapp_enabled', v ); }, label: t( 'Send WhatsApp notifications' ) } ),
					el( Field, { label: t( 'Send notifications for' ) },
						el( Segmented, { label: t( 'WhatsApp scope' ), value: s.whatsapp_scope || 'both', onChange: function ( v ) { set( 'whatsapp_scope', v ); }, options: SCOPE_OPTIONS } ) ),
					el( Field, { label: t( 'Gateway' ) }, el( Select, { value: provider, onChange: function ( v ) { set( 'whatsapp_provider', v ); }, label: t( 'Gateway' ), options: [
						{ value: 'callmebot', label: t( 'CallMeBot — free, for your own numbers' ) },
						{ value: 'cloud_api', label: t( 'WhatsApp Cloud API (Meta)' ) },
						{ value: 'custom', label: t( 'Custom HTTP gateway' ) } ] } ) ),
					'callmebot' === provider ? el( Field, { label: t( 'Recipients' ), help: t( 'One per line: phone number and its CallMeBot API key, e.g. 8801712345678 123456' ) },
						el( 'textarea', { rows: 3, value: s.whatsapp_callmebot_recipients || '', placeholder: '8801712345678 123456', onChange: function ( e ) { set( 'whatsapp_callmebot_recipients', e.target.value ); } } ) ) : null,
					'callmebot' !== provider ? el( Field, { label: t( 'Recipient numbers' ), help: t( 'With country code, separated by commas. Local 01… numbers are treated as Bangladesh (+880).' ) },
						el( TextInput, { value: s.whatsapp_recipients, placeholder: '8801712345678, 8801898765432', onChange: function ( v ) { set( 'whatsapp_recipients', v ); } } ) ) : null,
					'cloud_api' === provider ? el( 'div', { className: 'cr-form-grid' },
						el( Field, { label: t( 'Phone number ID' ) }, el( TextInput, { value: s.whatsapp_cloud_phone_id, placeholder: '1234567890', onChange: function ( v ) { set( 'whatsapp_cloud_phone_id', v ); } } ) ),
						el( Field, { label: t( 'Access token' ) }, el( TextInput, { type: 'password', value: s.whatsapp_cloud_token, placeholder: 'EAAG…', onChange: function ( v ) { set( 'whatsapp_cloud_token', v ); } } ) ),
						el( Field, { label: t( 'Template name (optional)' ) }, el( TextInput, { value: s.whatsapp_cloud_template, placeholder: 'store_alert', onChange: function ( v ) { set( 'whatsapp_cloud_template', v ); } } ) ),
						el( Field, { label: t( 'Template language' ) }, el( TextInput, { value: s.whatsapp_cloud_template_lang, placeholder: 'en_US', onChange: function ( v ) { set( 'whatsapp_cloud_template_lang', v ); } } ) ) ) : null,
					'custom' === provider ? el( Fragment, null,
						el( 'div', { className: 'cr-form-grid' },
							el( Field, { label: t( 'Request URL' ), className: 'cr-span-2' }, el( TextInput, { value: s.whatsapp_custom_url, placeholder: 'https://api.example.com/send?to={phone}&text={message}&key=XXXX', onChange: function ( v ) { set( 'whatsapp_custom_url', v ); } } ) ),
							el( Field, { label: t( 'Method' ) }, el( Select, { value: s.whatsapp_custom_method || 'get', onChange: function ( v ) { set( 'whatsapp_custom_method', v ); }, options: [ { value: 'get', label: 'GET' }, { value: 'post', label: 'POST' } ] } ) ) ),
						'post' === s.whatsapp_custom_method ? el( Field, { label: t( 'POST body' ), help: t( 'JSON is sent as application/json; anything else as a form body.' ) },
							el( 'textarea', { rows: 3, className: 'cr-mono', value: s.whatsapp_custom_body || '', onChange: function ( e ) { set( 'whatsapp_custom_body', e.target.value ); } } ) ) : null ) : null,
					el( 'div', null, el( Button, { icon: 'send', loading: 'whatsapp' === testing, onClick: function () { test( 'whatsapp' ); } }, t( 'Save & send test' ) ) ) ),

				el( SettingsSection, { title: t( 'Checkout lead alerts' ), description: t( 'Get a message the moment a shopper leaves contact details on checkout — before they finish (or abandon) the order.' ) },
					el( Field, { label: t( 'Notify me when' ) },
						el( 'div', { className: 'cr-choice-grid', role: 'radiogroup', 'aria-label': t( 'Notify me when' ) }, LEAD_OPTIONS.map( function ( o ) {
							var on = ( s.lead_trigger || 'any' ) === o[ 0 ];
							return el( 'button', { key: o[ 0 ], type: 'button', role: 'radio', 'aria-checked': on, className: cx( 'cr-choice', on && 'is-on' ), onClick: function () { set( 'lead_trigger', o[ 0 ] ); } },
								el( 'span', { className: 'cr-choice-icon' }, el( Icon, { name: o[ 3 ], size: 16 } ) ),
								el( 'span', { className: 'cr-choice-text' }, el( 'strong', null, o[ 1 ] ), el( 'span', null, o[ 2 ] ) ),
								el( 'span', { className: 'cr-choice-radio', 'aria-hidden': 'true' } ) );
						} ) ) ),
					el( 'div', { className: 'cr-form-grid' },
						el( Field, { label: t( 'Wait before sending' ), help: t( '0 = instantly. With a delay, the alert is skipped if the shopper places the order in the meantime.' ) },
							el( TextInput, { type: 'number', min: 0, max: 1440, value: s.lead_delay_minutes, suffix: t( 'minutes' ), onChange: function ( v ) { set( 'lead_delay_minutes', v ); } } ) ),
						el( Field, { label: t( 'Valid phone number length' ), help: t( 'Digits required before a phone counts (Bangladesh mobile = 11).' ) },
							el( TextInput, { type: 'number', min: 6, max: 15, value: s.lead_min_phone_digits, suffix: t( 'digits' ), onChange: function ( v ) { set( 'lead_min_phone_digits', v ); } } ) ) ),
					el( 'p', { className: 'cr-field-help' }, t( 'One alert per cart. Bots are never reported.' ) ) ),

				el( SettingsSection, { title: t( 'Which events go where' ), description: t( 'Fine-tune each event per channel. Greyed switches are off because the channel is disabled or its "Send notifications for" setting excludes that group.' ) },
					el( 'div', { className: 'cr-table-wrap' }, el( 'table', { className: 'cr-matrix' },
						el( 'thead', null, el( 'tr', null, el( 'th', null, t( 'Event' ) ), el( 'th', null, el( Icon, { name: 'telegram', size: 14 } ), ' Telegram' ), el( 'th', null, el( Icon, { name: 'chat', size: 14 } ), ' WhatsApp' ) ) ),
						el( 'tbody', null,
							el( 'tr', { className: 'cr-matrix-group' }, el( 'td', { colSpan: 3 }, el( Icon, { name: 'cart', size: 14 } ), t( 'Cart notifications' ) ) ),
							matrixRows( 'cart' ),
							el( 'tr', { className: 'cr-matrix-group' }, el( 'td', { colSpan: 3 }, el( Icon, { name: 'receipt', size: 14 } ), t( 'Order notifications' ) ) ),
							matrixRows( 'order' ) ) ) ),
					el( 'div', { className: 'cr-form-grid' },
						el( Field, { label: t( 'High-value threshold' ), help: t( 'Abandoned carts at or above this total use the high-value alert.' ) },
							el( TextInput, { type: 'number', min: 0, value: s.alert_high_value_threshold, suffix: CURRENCY, onChange: function ( v ) { set( 'alert_high_value_threshold', v ); } } ) ),
						el( Field, { label: t( 'Store name in messages' ), help: t( 'Leave empty to use the site title.' ) },
							el( TextInput, { value: s.notify_store_name, placeholder: CFG.site_name || '', onChange: function ( v ) { set( 'notify_store_name', v ); } } ) ) ) ),
				el( DeliveryLog, { key: logKey } ) ),
			el( SaveBar, { state: st } ) );
	}

	/* ------------------------------------------------------------------ settings */
	function SettingsView( props ) {
		var st = useSettings( props.onSettingsSaved );
		var _t = useState( '' ), testing = _t[ 0 ], setTesting = _t[ 1 ];
		if ( st.error ) return el( Card, null, el( EmptyState, { icon: 'alert', title: t( 'Could not load settings' ), description: st.error } ) );
		if ( ! st.settings ) return el( Loading, { rows: 8 } );
		var s = st.settings, set = st.set;
		function tog( key, label, desc, disabled ) { return el( Toggle, { key: key, value: s[ key ], label: label, description: desc, disabled: disabled, onChange: function ( v ) { set( key, v ); } } ); }
		function numField( key, label, suffix, help, extra ) { return el( Field, { key: key, label: label, help: help }, el( TextInput, Object.assign( { type: 'number', min: 0, value: s[ key ], suffix: suffix, onChange: function ( v ) { set( key, v ); } }, extra || {} ) ) ); }
		function textField( key, label, placeholder, type, help ) { return el( Field, { key: key, label: label, help: help }, el( TextInput, { type: type || 'text', value: s[ key ], placeholder: placeholder, onChange: function ( v ) { set( key, v ); } } ) ); }
		function testTracking( channel ) {
			setTesting( channel );
			api( '/tracking/test', { method: 'POST', data: { channel: channel, settings: s } } )
				.then( function ( r ) { toast.success( r && r.message ? r.message : t( 'Test sent' ), { duration: 8000 } ); } )
				.catch( function ( err ) { toast.error( errMsg( err ), { duration: 10000 } ); } )
				.finally( function () { setTesting( '' ); } );
		}
		var cols = s.table_columns || {};
		var steps = [ 1, 2, 3 ];
		return el( Fragment, null,
			el( PageHeader, { title: t( 'Settings' ), description: t( 'Capture rules, the recovery email sequence, coupons, tracking and data retention.' ), actions: [ el( Button, { key: 'n', variant: 'ghost', icon: 'bell', onClick: function () { props.go( 'notifications' ); } }, t( 'Telegram & WhatsApp alerts' ) ) ] } ),
			el( 'div', null,
				el( SettingsSection, { title: t( 'Capture' ), description: t( 'What is recorded, from whom, and when a cart counts as abandoned.' ) },
					tog( 'capture_enabled', t( 'Enable data capture' ), t( 'Save name, contact, address and cart as the shopper types on checkout.' ) ),
					el( Field, { label: t( 'Capture scope' ), help: t( 'Recommended: Add to cart + Checkout only, so visitors without a cart do not fill your list.' ) },
						el( Select, { value: s.capture_scope || 'cart_checkout', onChange: function ( v ) { set( 'capture_scope', v ); }, options: [ { value: 'cart_checkout', label: t( 'Add to cart + Checkout only (recommended)' ) }, { value: 'all_visitors', label: t( 'All visitors / page sessions' ) } ] } ) ),
					tog( 'capture_guests', t( 'Capture guest (not logged-in) visitors' ) ),
					tog( 'exclude_admin_ip', t( 'Ignore store managers and admins' ) ),
					tog( 'require_consent', t( 'Require consent before capturing' ), t( 'Adds a checkbox to checkout. Nothing is stored until it is ticked.' ) ),
					'yes' === s.require_consent ? textField( 'consent_text', t( 'Consent checkbox text' ), t( 'I agree to be contacted about my order and cart via email or phone.' ) ) : null,
					numField( 'abandoned_after_minutes', t( 'Abandoned after' ), t( 'minutes' ), t( 'Minutes without activity before an Active cart becomes Abandoned (minimum 5).' ), { min: 5 } ) ),

				el( SettingsSection, { title: t( 'Recovery emails' ), description: t( 'A three-step sequence. Each step is sent only while the cart is still open.' ) },
					tog( 'emails_enabled', t( 'Send automatic recovery emails' ) ),
					el( 'div', { className: 'cr-steps' }, steps.map( function ( n ) {
						return el( 'div', { className: 'cr-step', key: n },
							el( 'span', { className: 'cr-step-num' }, n ),
							el( 'div', { className: 'cr-step-body' },
								el( 'div', { className: 'cr-step-head' }, el( 'strong', null, t( 'Step' ) + ' ' + n + ( 2 === n && 'yes' === s.email_2_coupon_enabled ? ' · ' + t( 'with coupon' ) : '' ) ),
									n > 1 ? el( Toggle, { value: s[ 'email_' + n + '_enabled' ], ariaLabel: t( 'Step' ) + ' ' + n, onChange: function ( v ) { set( 'email_' + n + '_enabled', v ); } } ) : null ),
								el( Field, { label: t( 'Delay' ) }, el( TextInput, { type: 'number', min: 0, value: s[ 'email_' + n + '_delay_minutes' ], suffix: t( 'min' ), onChange: function ( v ) { set( 'email_' + n + '_delay_minutes', v ); } } ) ),
								el( Field, { label: t( 'Subject' ) }, el( TextInput, { value: s[ 'email_' + n + '_subject' ], placeholder: t( 'Default subject' ), onChange: function ( v ) { set( 'email_' + n + '_subject', v ); } } ) ) ) );
					} ) ),
					el( 'div', { className: 'cr-form-grid' }, textField( 'from_name', t( 'From name' ), CFG.site_name || '' ), textField( 'from_email', t( 'From email' ), 'hello@example.com', 'email' ) ) ),

				el( SettingsSection, { title: t( 'Smart coupons' ), description: t( 'Single-use discount codes generated per cart for email step 2 and WhatsApp messages.' ) },
					tog( 'smart_coupon_enabled', t( 'Enable smart coupons' ) ),
					tog( 'email_2_coupon_enabled', t( 'Add a coupon to email step 2' ) ),
					el( 'div', { className: 'cr-form-grid' },
						numField( 'email_2_coupon_percent', t( 'Discount' ), '%', '', { max: 100 } ),
						numField( 'smart_coupon_expiry_days', t( 'Expires after' ), t( 'days' ), '', { min: 1 } ),
						numField( 'smart_coupon_min_spend', t( 'Minimum spend' ), CURRENCY, t( '0 = no minimum' ) ),
						numField( 'smart_coupon_max_discount', t( 'Maximum discount' ), CURRENCY, t( '0 = no cap' ) ),
						textField( 'smart_coupon_prefix', t( 'Code prefix' ), 'RECOVER' ) ) ),

				el( SettingsSection, { title: t( 'WhatsApp recovery button' ), description: t( 'Opens WhatsApp with a pre-filled message to the shopper. No API needed — it uses wa.me.' ) },
					tog( 'whatsapp_enabled', t( 'Show the WhatsApp button when a phone number is available' ) ),
					tog( 'whatsapp_coupon_enabled', t( 'Add a smart coupon to the message' ) ),
					el( Field, { label: t( 'Message template' ), help: t( 'Tags: {first_name}, {cart_total}, {site_name}, {recovery_link}, {coupon_code}' ) },
						el( 'textarea', { rows: 3, value: s.whatsapp_message || '', onChange: function ( e ) { set( 'whatsapp_message', e.target.value ); } } ) ) ),

				el( SettingsSection, { title: t( 'Smart recovery rules' ), description: t( 'Skip automatic recovery emails for carts that are not worth chasing.' ) },
					tog( 'smart_rules_enabled', t( 'Enable smart recovery rules' ) ),
					el( 'div', { className: 'cr-form-grid' },
						numField( 'smart_rules_min_cart_total', t( 'Minimum cart value' ), CURRENCY ),
						numField( 'smart_rules_max_recovery_emails', t( 'Max recovery emails per cart' ), t( 'emails' ) ) ),
					textField( 'smart_rules_excluded_sources', t( 'Excluded sources' ), 'test, internal', 'text', t( 'Comma separated. Matched against UTM source / medium / campaign and referrer.' ) ),
					tog( 'smart_rules_skip_bots', t( 'Skip bot carts' ) ),
					tog( 'smart_rules_skip_duplicates', t( 'Skip duplicate carts' ) ) ),

				el( SettingsSection, { title: t( 'Dashboard alerts' ), description: t( 'The bell in the dashboard header. For phone alerts use Notifications.' ) },
					tog( 'alerts_enabled', t( 'Enable dashboard alerts' ) ),
					tog( 'alert_new_abandoned', t( 'New abandoned cart' ) ),
					tog( 'alert_high_value', t( 'High-value cart' ) ),
					tog( 'alert_live_recovery', t( 'Cart recovered by admin' ) ),
					el( 'div', { className: 'cr-form-grid' },
						numField( 'alert_high_value_threshold', t( 'High-value threshold' ), CURRENCY ),
						numField( 'live_alert_min_total', t( 'Minimum cart value for live alerts' ), CURRENCY ) ),
					tog( 'live_alerts_enabled', t( 'Alert about new high-value live carts' ) ) ),

				el( SettingsSection, { id: 'wccr-conversion-tracking', title: t( 'Conversion tracking' ), description: t( 'When you convert a cart into an order the customer never sees the thank-you page, so your Pixel / GA4 tag never records the sale. These server-side events credit the ad that brought the customer.' ) },
					el( Field, { label: t( 'Send the Purchase event' ) }, el( Select, { value: s.tracking_trigger || 'created', onChange: function ( v ) { set( 'tracking_trigger', v ); }, options: [ { value: 'created', label: t( 'Immediately when the order is created' ) }, { value: 'paid', label: t( 'Only when the order is Processing / Completed' ) } ] } ) ),
					el( 'div', { className: 'cr-integration' },
						el( 'div', { className: 'cr-integration-head' }, tog( 'meta_capi_enabled', t( 'Meta Conversions API' ), t( 'Facebook / Instagram ads' ) ), el( Button, { size: 'sm', icon: 'send', loading: 'meta' === testing, onClick: function () { testTracking( 'meta' ); } }, t( 'Send test event' ) ) ),
						'yes' === s.meta_capi_enabled ? el( Fragment, null,
							el( 'div', { className: 'cr-form-grid' },
								textField( 'meta_pixel_id', t( 'Pixel / Dataset ID' ), '123456789012345' ),
								textField( 'meta_access_token', t( 'Access token' ), 'EAAG…', 'password' ),
								textField( 'meta_test_event_code', t( 'Test event code (optional)' ), 'TEST12345' ),
								el( Field, { label: t( 'Action source' ) }, el( Select, { value: s.meta_action_source || 'website', onChange: function ( v ) { set( 'meta_action_source', v ); }, options: [ { value: 'website', label: t( 'Website (best ad attribution)' ) }, { value: 'phone_call', label: t( 'Phone call' ) }, { value: 'chat', label: t( 'Chat / WhatsApp' ) }, { value: 'system_generated', label: t( 'System generated' ) }, { value: 'other', label: t( 'Other' ) } ] } ) ),
								el( Field, { label: t( 'Product ID format' ), help: t( 'Must match your catalog.' ) }, el( Select, { value: s.meta_content_id || 'id', onChange: function ( v ) { set( 'meta_content_id', v ); }, options: [ { value: 'id', label: t( 'WooCommerce product ID' ) }, { value: 'sku', label: 'SKU' }, { value: 'wc_post_id', label: t( 'Facebook for WooCommerce format' ) } ] } ) ) ),
							tog( 'meta_use_test_code', t( 'Send real events with the test code too' ), t( 'Debug mode — turn off when done.' ) ) ) : null ),
					el( 'div', { className: 'cr-integration' },
						el( 'div', { className: 'cr-integration-head' }, tog( 'ga4_enabled', t( 'Google Analytics 4' ), t( 'Measurement Protocol' ) ), el( Button, { size: 'sm', icon: 'send', loading: 'ga4' === testing, onClick: function () { testTracking( 'ga4' ); } }, t( 'Validate' ) ) ),
						'yes' === s.ga4_enabled ? el( 'div', { className: 'cr-form-grid' }, textField( 'ga4_measurement_id', t( 'Measurement ID' ), 'G-XXXXXXXXXX' ), textField( 'ga4_api_secret', t( 'API secret' ), '', 'password', t( 'GA4 Admin → Data streams → Measurement Protocol API secrets.' ) ) ) : null ),
					el( 'div', { className: 'cr-integration' },
						el( 'div', { className: 'cr-integration-head' }, tog( 'webhook_enabled', t( 'Webhook' ), t( 'Zapier, Make, n8n, Google Sheets, CRM…' ) ), el( Button, { size: 'sm', icon: 'send', loading: 'webhook' === testing, onClick: function () { testTracking( 'webhook' ); } }, t( 'Send test' ) ) ),
						'yes' === s.webhook_enabled ? el( 'div', { className: 'cr-form-grid' }, textField( 'webhook_url', t( 'Webhook URL' ), 'https://hooks.zapier.com/…', 'url' ), textField( 'webhook_secret', t( 'Signing secret (optional)' ), '', 'password', t( 'Adds an X-WCCR-Signature HMAC-SHA256 header.' ) ) ) : null ) ),

				el( SettingsSection, { title: t( 'Dashboard' ), description: t( 'Default analytics period and the columns of the carts table.' ) },
					el( Field, { label: t( 'Default analytics period' ) }, el( Select, { value: String( s.analytics_days || 30 ), onChange: function ( v ) { set( 'analytics_days', v ); }, options: [ { value: '7', label: t( '7 days' ) }, { value: '30', label: t( '30 days' ) }, { value: '90', label: t( '90 days' ) }, { value: '365', label: t( '12 months' ) } ] } ) ),
					el( Field, { label: t( 'Carts table columns' ) }, el( 'div', { className: 'cr-cols cr-cols--grid' }, [ [ 'id', t( 'Cart ID' ) ], [ 'customer', t( 'Customer' ) ], [ 'address', t( 'Address' ) ], [ 'products', t( 'Cart products' ) ], [ 'total', t( 'Total' ) ], [ 'source', t( 'Source' ) ], [ 'times', t( 'Entry / exit time' ) ], [ 'live', t( 'Last seen' ) ], [ 'risk', t( 'Risk badge' ) ] ].map( function ( c ) {
						return el( 'label', { key: c[ 0 ], className: 'cr-check' }, el( 'input', { type: 'checkbox', checked: false !== cols[ c[ 0 ] ], onChange: function ( e ) { var n = Object.assign( {}, cols ); n[ c[ 0 ] ] = e.target.checked; set( 'table_columns', n ); } } ), c[ 1 ] );
					} ) ) ) ),

				el( SettingsSection, { title: t( 'Data retention' ), description: t( 'Keep the database lean and stay GDPR-friendly.' ) },
					numField( 'data_retention_days', t( 'Delete un-recovered carts after' ), t( 'days' ), t( '0 keeps them forever.' ) ),
					tog( 'delete_data_on_uninstall', t( 'Delete all Fixo Cart data when the plugin is deleted' ) ) ) ),
			el( SaveBar, { state: st } ) );
	}

	/* ------------------------------------------------------------------ navigation + app */
	var NAV = [
		{ label: t( 'Recover' ), items: [
			{ key: 'carts', label: t( 'Abandoned carts' ), icon: 'cart' },
			{ key: 'live', label: t( 'Live customers' ), icon: 'pulse' },
			{ key: 'recovered', label: t( 'Recovered orders' ), icon: 'undo' },
			{ key: 'journey', label: t( 'Customer journey' ), icon: 'route' } ] },
		{ label: t( 'Sales' ), items: [
			{ key: 'orders', label: t( 'Orders' ), icon: 'receipt' } ] },
		{ label: t( 'Analyze' ), items: [
			{ key: 'analytics', label: t( 'Overview' ), icon: 'grid' },
			{ key: 'products', label: t( 'Products' ), icon: 'box' },
			{ key: 'utm', label: t( 'UTM campaigns' ), icon: 'megaphone' },
			{ key: 'revenue', label: t( 'Revenue' ), icon: 'coins' },
			{ key: 'segments', label: t( 'Segments' ), icon: 'users' } ] },
		{ label: t( 'Configure' ), items: [
			{ key: 'notifications', label: t( 'Notifications' ), icon: 'bell' },
			{ key: 'settings', label: t( 'Settings' ), icon: 'sliders' } ] }
	];
	var VIEWS = {};
	NAV.forEach( function ( g ) { g.items.forEach( function ( i ) { VIEWS[ i.key ] = i; } ); } );

	function SideNav( props ) {
		var collapsed = props.collapsed;
		return el( 'nav', { className: cx( 'cr-nav', collapsed && 'is-collapsed' ), 'aria-label': 'Fixo Cart' },
			el( 'div', { className: 'cr-nav-brand' },
				el( 'span', { className: 'cr-nav-glyph', 'aria-hidden': 'true' }, el( Icon, { name: 'cart', size: 16, strokeWidth: 2 } ) ),
				el( 'div', { className: 'cr-nav-brand-text' }, el( 'div', { className: 'cr-nav-name' }, 'Fixo Cart' ), el( 'div', { className: 'cr-nav-ver' }, 'v' + ( CFG.version || '' ) + ' · bongdevs.com' ) ),
				el( 'button', { type: 'button', className: 'cr-nav-collapse', onClick: props.onCollapse, 'aria-expanded': ! collapsed,
					title: collapsed ? t( 'Expand menu' ) : t( 'Collapse menu' ), 'aria-label': collapsed ? t( 'Expand menu' ) : t( 'Collapse menu' ) },
					el( Icon, { name: collapsed ? 'sidebarOpen' : 'sidebar', size: 16 } ) ) ),
			NAV.map( function ( g ) {
				return el( 'div', { className: 'cr-nav-group', key: g.label },
					el( 'div', { className: 'cr-nav-label' }, g.label ),
					g.items.map( function ( it ) {
						var on = it.key === props.active;
						var n = props.counts[ it.key ];
						return el( 'a', { key: it.key, href: '?page=' + ( CFG.page_slug || 'fixo-cart' ) + '&view=' + it.key, className: cx( 'cr-nav-item', on && 'is-on' ), 'aria-current': on ? 'page' : undefined,
							title: collapsed ? it.label + ( n ? ' (' + n + ')' : '' ) : undefined,
							onClick: function ( e ) { if ( e.metaKey || e.ctrlKey ) return; e.preventDefault(); props.onSelect( it.key ); } },
							el( Icon, { name: it.icon, size: 16 } ), el( 'span', { className: 'cr-nav-text' }, it.label ),
							n ? el( 'span', { className: cx( 'cr-nav-count', 'live' === it.key && 'is-live' ) }, 'live' === it.key ? el( 'i', { 'aria-hidden': 'true' } ) : null, n > 99 ? '99+' : n ) : null );
					} ) );
			} ),
			el( 'div', { className: 'cr-nav-foot' },
				el( Button, { variant: 'ghost', size: 'sm', icon: 'dark' === props.theme ? 'sun' : 'moon', onClick: props.onTheme, title: collapsed ? ( 'dark' === props.theme ? t( 'Light mode' ) : t( 'Dark mode' ) ) : undefined },
					collapsed ? null : ( 'dark' === props.theme ? t( 'Light mode' ) : t( 'Dark mode' ) ) ) ) );
	}

	function readUrl() {
		var p;
		try { p = new URLSearchParams( window.location.search ); } catch ( e ) { return {}; }
		var view = p.get( 'view' ) || p.get( 'tab' ) || 'carts';
		return { view: VIEWS[ view ] ? view : 'carts', cart: parseInt( p.get( 'cart' ) || '0', 10 ) || 0, order: parseInt( p.get( 'order' ) || '0', 10 ) || 0 };
	}
	function writeUrl( view ) {
		try {
			var url = new URL( window.location.href );
			url.searchParams.set( 'view', view );
			url.searchParams.delete( 'tab' ); url.searchParams.delete( 'cart' ); url.searchParams.delete( 'order' );
			window.history.replaceState( null, '', url.toString() );
		} catch ( e ) {}
	}

	function App() {
		var initial = readUrl();
		var _v = useState( initial.view ), view = _v[ 0 ], setView = _v[ 1 ];
		var _d = useState( 30 ), days = _d[ 0 ], setDays = _d[ 1 ];
		var _st = useState( { totals: {} } ), stats = _st[ 0 ], setStats = _st[ 1 ];
		var _sl = useState( true ), statsLoading = _sl[ 0 ], setStatsLoading = _sl[ 1 ];
		var _s = useState( {} ), settings = _s[ 0 ], setSettings = _s[ 1 ];
		var _c = useState( initial.cart ? { id: initial.cart } : null ), drawer = _c[ 0 ], setDrawer = _c[ 1 ];
		var _o = useState( initial.order || 0 ), orderId = _o[ 0 ], setOrderId = _o[ 1 ];
		var _k = useState( 0 ), reloadKey = _k[ 0 ], setReloadKey = _k[ 1 ];
		var _th = useState( storageGet( 'fixo_cart_theme', 'light' ) ), theme = _th[ 0 ], setTheme = _th[ 1 ];
		var _nc = useState( 'yes' === storageGet( 'fixo_cart_nav_collapsed', 'no' ) ), navCollapsed = _nc[ 0 ], setNavCollapsed = _nc[ 1 ];
		function toggleNav() { var n = ! navCollapsed; setNavCollapsed( n ); storageSet( 'fixo_cart_nav_collapsed', n ? 'yes' : 'no' ); }

		function loadStats() {
			api( '/stats?days=' + days ).then( function ( s ) { setStats( s ); } ).catch( function () {} ).finally( function () { setStatsLoading( false ); } );
		}
		useEffect( function () {
			api( '/settings' ).then( function ( s ) { setSettings( s || {} ); if ( s && s.analytics_days ) setDays( parseInt( s.analytics_days, 10 ) || 30 ); } ).catch( function () {} );
		}, [] );
		useEffect( loadStats, [ days ] );
		useEffect( function () { var timer = setInterval( loadStats, 60000 ); return function () { clearInterval( timer ); }; }, [ days ] );

		function go( v ) { setView( v ); writeUrl( v ); window.scrollTo( 0, 0 ); }
		function openCart( c, tab ) { setOrderId( 0 ); setDrawer( Object.assign( {}, c, { _tab: tab } ) ); }
		function openCartId( id ) { if ( id ) openCart( { id: id } ); }
		function saveColumns( cols ) {
			setSettings( Object.assign( {}, settings, { table_columns: cols } ) );
			api( '/settings', { method: 'POST', data: { table_columns: cols } } ).catch( function ( err ) { toast.error( errMsg( err ) ); } );
		}
		function toggleTheme() { var n = 'dark' === theme ? 'light' : 'dark'; setTheme( n ); storageSet( 'fixo_cart_theme', n ); }

		var last = parseInt( stats.last_cron_run || 0, 10 );
		var cronWarning = ( stats.wp_cron_disabled || ( last && Date.now() / 1000 - last > 3600 ) ) ? el( 'div', { className: 'cr-banner' }, el( Icon, { name: 'alert', size: 16 } ),
			el( 'span', null, t( 'Scheduled tasks have not run for over an hour, so carts may not be marked abandoned and emails may be late. Ask your host to set up a real server cron for wp-cron.php.' ) ) ) : null;

		var common = { days: days, onDays: setDays, stats: stats, statsLoading: statsLoading, settings: settings, onOpenCart: openCart, onOpenCartId: openCartId, onOpenOrder: setOrderId, go: go, cronWarning: cronWarning, onRefreshStats: loadStats, onSettingsSaved: function ( s ) { setSettings( s ); } };
		var content;
		switch ( view ) {
			case 'live': content = el( LiveView, common ); break;
			case 'journey': content = el( JourneyView, common ); break;
			case 'recovered': content = el( RecoveredView, common ); break;
			case 'orders': content = el( OrdersView, common ); break;
			case 'analytics': content = el( AnalyticsView, common ); break;
			case 'products': content = el( ProductsView, common ); break;
			case 'utm': content = el( UtmView, common ); break;
			case 'revenue': content = el( RevenueView, common ); break;
			case 'segments': content = el( SegmentsView, common ); break;
			case 'notifications': content = el( NotificationsView, common ); break;
			case 'settings': content = el( SettingsView, common ); break;
			default: content = el( CartsView, Object.assign( {}, common, { columns: settings.table_columns, onColumns: saveColumns, reloadKey: reloadKey } ) );
		}
		var tt = stats.totals || {};
		return el( 'div', { className: 'cr-app', 'data-theme': theme },
			el( 'div', { className: cx( 'cr-shell', navCollapsed && 'is-nav-collapsed' ) },
				el( SideNav, { active: view, onSelect: go, counts: { carts: parseInt( tt.abandoned || 0, 10 ), live: parseInt( stats.live_count || 0, 10 ) }, theme: theme, onTheme: toggleTheme, collapsed: navCollapsed, onCollapse: toggleNav } ),
				el( 'main', { className: 'cr-main' }, content ) ),
			drawer ? el( CartDrawer, { key: drawer.id, cart: drawer, tab: drawer._tab, settings: settings, onClose: function () { setDrawer( null ); },
				onUpdated: function () { setReloadKey( reloadKey + 1 ); }, onDeleted: function () { setReloadKey( reloadKey + 1 ); loadStats(); }, onChanged: function () { setReloadKey( reloadKey + 1 ); loadStats(); } } ) : null,
			orderId ? el( OrderDrawer, { key: 'o' + orderId, id: orderId, onClose: function () { setOrderId( 0 ); }, onOpenCart: function ( id ) { setOrderId( 0 ); openCartId( id ); } } ) : null,
			el( ConfirmHost ),
			el( Toaster ) );
	}

	function mount() {
		var root = document.getElementById( 'wccr-dashboard-root' );
		if ( ! root ) return;
		if ( wp.element.createRoot ) wp.element.createRoot( root ).render( el( App ) );
		else wp.element.render( el( App ), root );
	}
	if ( 'loading' === document.readyState ) document.addEventListener( 'DOMContentLoaded', mount );
	else mount();
}( window.wp ) );
