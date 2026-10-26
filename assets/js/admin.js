/* global window, document, fetch, WooPulseConfig, WooPulseCharts, WooPulseMap, Blob, URL */
/**
 * Woo Pulse admin app. Vanilla JS, no build step.
 * State lives in one object; every tab is a render function that rebuilds its DOM from the latest report.
 */
(function () {
	'use strict';

	var cfg = window.WooPulseConfig || {};
	var C = window.WooPulseCharts;
	var MAP = window.WooPulseMap;
	var esc = C.esc;
	var h = C.h;

	/* ======================================================================
	 * Formatting
	 * ==================================================================== */

	var locale = cfg.locale || 'en-US';
	// Amounts follow WooCommerce > Settings > General exactly: symbol position, thousand and decimal separators, decimals.
	var CUR = {
		symbol: cfg.currencySymbol || '$',
		format: cfg.priceFormat || '%1$s%2$s',
		thousand: ( typeof cfg.thousandSep === 'string' ) ? cfg.thousandSep : ',',
		decimal: ( typeof cfg.decimalSep === 'string' && cfg.decimalSep !== '' ) ? cfg.decimalSep : '.',
		decimals: ( typeof cfg.decimals === 'number' ) ? cfg.decimals : 2
	};

	function isNil( v ) { return v === null || v === undefined || ( typeof v === 'number' && isNaN( v ) ); }

	/** Unsigned number with WooCommerce separators, e.g. 1234.5 -> "1,234.50". */
	function groupDigits( v, decimals ) {
		var fixed = Math.abs( Number( v ) ).toFixed( decimals );
		var parts = fixed.split( '.' );
		var whole = parts[ 0 ].replace( /\B(?=(\d{3})+(?!\d))/g, CUR.thousand );
		return whole + ( decimals > 0 ? CUR.decimal + parts[ 1 ] : '' );
	}
	function withSymbol( body, negative ) {
		return ( negative ? '-' : '' ) + CUR.format.replace( '%1$s', CUR.symbol ).replace( '%2$s', body );
	}

	// Formatters may be called as table cell formatters with (value, row): only honour a second argument of the right type.
	function money( v, axis ) {
		if ( isNil( v ) ) { return 'n/a'; }
		v = Number( v );
		var neg = v < 0, a = Math.abs( v ), body;
		if ( axis === true ) {
			// Chart axes: short labels.
			if ( a >= 1000000 ) { body = groupDigits( a / 1000000, 1 ) + 'M'; } else if ( a >= 10000 ) { body = groupDigits( a / 1000, 1 ) + 'K'; } else { body = groupDigits( a, 0 ); }
		} else {
			body = groupDigits( a, CUR.decimals );
		}
		return withSymbol( body, neg );
	}
	function num( v, d ) {
		if ( isNil( v ) ) { return 'n/a'; }
		d = ( typeof d === 'number' && d >= 0 && d <= 6 ) ? d : 0;
		v = Number( v );
		return ( v < 0 ? '-' : '' ) + groupDigits( d ? v : Math.round( v ), d );
	}
	function pct( v, d ) {
		if ( isNil( v ) ) { return 'n/a'; }
		d = ( typeof d === 'number' && d >= 0 && d <= 6 ) ? d : 1;
		return ( Number( v ) * 100 ).toFixed( d ) + '%';
	}
	function ratio( v ) {
		if ( isNil( v ) ) { return 'n/a'; }
		return Number( v ).toFixed( 2 ) + 'x';
	}
	var dfShort = new Intl.DateTimeFormat( locale, { month: 'short', day: 'numeric', timeZone: 'UTC' } );
	var dfMonth = new Intl.DateTimeFormat( locale, { month: 'short', year: 'numeric', timeZone: 'UTC' } );
	var dfFull = new Intl.DateTimeFormat( locale, { month: 'short', day: 'numeric', year: 'numeric', timeZone: 'UTC' } );
	function ymdToDate( s ) {
		var p = String( s ).split( '-' ).map( Number );
		return new Date( Date.UTC( p[ 0 ], ( p[ 1 ] || 1 ) - 1, p[ 2 ] || 1 ) );
	}
	function dateToYmd( d ) { return d.toISOString().slice( 0, 10 ); }
	function addDays( s, n ) { var d = ymdToDate( s ); d.setUTCDate( d.getUTCDate() + n ); return dateToYmd( d ); }
	function fmtDate( s, full ) { return ( full ? dfFull : dfShort ).format( ymdToDate( s ) ); }
	function bucketLabel( key, gran, full ) {
		if ( gran === 'month' ) { return dfMonth.format( ymdToDate( key ) ); }
		if ( gran === 'week' ) { return ( full ? 'Week of ' : '' ) + fmtDate( key, full ); }
		return fmtDate( key, full );
	}
	function monthLabel( ym ) { return dfMonth.format( ymdToDate( ym + '-01' ) ); }

	/* ======================================================================
	 * Definitions shown as tooltips, in the quiz summary and in Settings
	 * ==================================================================== */

	var DEF = {
		revenue: 'Net sales = order totals minus tax, shipping and refunds. Gross sales = order totals as charged, minus refunds. Which one is used is chosen in the setup quiz.',
		gross: 'Sum of order totals as charged, including tax and shipping, for orders with a counted status.',
		net: 'Gross sales minus tax, shipping and refunds.',
		aov: 'Gross sales divided by orders.',
		new_customers: 'Customers whose first counted order ever falls inside the range. Identified by billing email, otherwise account id.',
		returning: 'Customers in the range who had at least one counted order before the range started.',
		refunds: 'Refunds dated inside the range whose parent order has a counted status.',
		blended_roas: 'Revenue divided by ad spend from the ledger. Paid media only, no fixed costs.',
		new_customer_roas: 'Revenue from customers acquired in the range divided by ad spend. Also called aMER.',
		mer: 'Marketing Efficiency Ratio: revenue divided by all marketing cost (ad spend + prorated fixed costs). Shown as a ratio, or as spend as a percentage of revenue.',
		cac: 'All marketing cost divided by new customers.',
		cost_per_order: 'All marketing cost divided by orders.',
		marketing_share: 'All marketing cost as a share of revenue.',
		break_even: 'The blended ROAS at which ad spend exactly consumes gross margin: 1 / margin fraction.',
		contribution: 'Revenue × gross margin − all marketing cost. What is left after product cost and marketing.',
		profit: 'Net sales − COGS − estimated payment fees. COGS comes from orders when at least half of them record it; otherwise net sales × the margin % from the quiz.',
		platform: 'Ad platforms each claim credit for conversions. The sum of their reported revenue divided by your actual revenue shows how much they over-claim.',
		repeat_rate: 'Customers with two or more orders inside the range, divided by customers in the range.',
		ltv: 'Lifetime revenue per customer across all counted orders ever, refunds subtracted. Average and median.',
		active: 'Customers with a counted order in the last N days (set under Settings).',
		second_order: 'Share of all customers who ever placed a second order.',
		cohorts: 'Customers grouped by the month of their first order. Each cell is the share of that cohort with at least one order N months later. Built from the last 24 months of orders.',
		heatmap: 'Orders by day of week and hour, in the site timezone.',
		status: 'Every order created in the range by status, including statuses that are not counted as sales.',
		fees: 'Estimated payment processing fees: percent of order total plus a fixed amount per order, from the quiz.',
		channels: 'From the attribution WooCommerce stores on each order (last touch before checkout). It will not match what ad platforms report.'
	};

	/* ======================================================================
	 * State
	 * ==================================================================== */

	var settings = cfg.settings || {};
	var state = {
		preset: 'last30',
		start: null,
		end: null,
		compare: settings.compare_default === 'year' ? 'year' : 'previous',
		showCompare: true,
		tab: ( window.location.hash || '' ).replace( '#', '' ) || 'overview',
		report: null,
		loading: false,
		error: null,
		geo: { country: null, metric: 'revenue', selected: null, view: 'map' },
		overview: { series: 'revenue', heat: 'orders' },
		customers: { split: 'orders' },
		channelDim: 'source_type',
		ledgerEdit: null,
		importPreview: null,
		diag: null
	};
	var reportCache = {};
	var TABS = [
		[ 'overview', 'Overview' ],
		[ 'geography', 'Geography' ],
		[ 'marketing', 'Marketing' ],
		[ 'customers', 'Customers' ],
		[ 'products', 'Products' ],
		[ 'settings', 'Settings' ]
	];
	if ( ! TABS.some( function ( t ) { return t[ 0 ] === state.tab; } ) ) {
		state.tab = 'overview';
	}

	/* ======================================================================
	 * API
	 * ==================================================================== */

	function api( path, opts ) {
		opts = opts || {};
		var init = {
			method: opts.method || 'GET',
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': cfg.nonce, 'Accept': 'application/json' }
		};
		if ( opts.body !== undefined ) {
			init.headers[ 'Content-Type' ] = 'application/json';
			init.body = JSON.stringify( opts.body );
		}
		return fetch( cfg.restUrl + path, init ).then( function ( r ) {
			return r.text().then( function ( txt ) {
				var data = null;
				try { data = txt ? JSON.parse( txt ) : null; } catch ( e ) { data = null; }
				if ( ! r.ok ) {
					var msg = ( data && data.message ) ? data.message : ( 'Request failed (' + r.status + ')' );
					throw new Error( msg );
				}
				return data;
			} );
		} );
	}

	function toast( msg, isError ) {
		var t = h( 'div', 'wpulse-toast' + ( isError ? ' error' : '' ), esc( msg ) );
		document.body.appendChild( t );
		setTimeout( function () { t.remove(); }, isError ? 5000 : 2800 );
	}

	/* ======================================================================
	 * Date presets
	 * ==================================================================== */

	var PRESETS = [
		[ 'today', 'Today' ], [ 'yesterday', 'Yesterday' ], [ 'last7', 'Last 7 days' ], [ 'last30', 'Last 30 days' ], [ 'last90', 'Last 90 days' ],
		[ 'this_month', 'This month' ], [ 'last_month', 'Last month' ], [ 'this_quarter', 'This quarter' ], [ 'last_quarter', 'Last quarter' ],
		[ 'ytd', 'Year to date' ], [ 'last12m', 'Last 12 months' ], [ 'custom', 'Custom' ]
	];

	function presetRange( preset ) {
		var today = cfg.today || dateToYmd( new Date() );
		var d = ymdToDate( today );
		var y = d.getUTCFullYear(), m = d.getUTCMonth();
		var q = Math.floor( m / 3 );
		function ymd( yy, mm, dd ) { return dateToYmd( new Date( Date.UTC( yy, mm, dd ) ) ); }
		switch ( preset ) {
			case 'today': return { start: today, end: today };
			case 'yesterday': return { start: addDays( today, -1 ), end: addDays( today, -1 ) };
			case 'last7': return { start: addDays( today, -6 ), end: today };
			case 'last90': return { start: addDays( today, -89 ), end: today };
			case 'this_month': return { start: ymd( y, m, 1 ), end: today };
			case 'last_month': return { start: ymd( y, m - 1, 1 ), end: ymd( y, m, 0 ) };
			case 'this_quarter': return { start: ymd( y, q * 3, 1 ), end: today };
			case 'last_quarter': return { start: ymd( y, q * 3 - 3, 1 ), end: ymd( y, q * 3, 0 ) };
			case 'ytd': return { start: ymd( y, 0, 1 ), end: today };
			case 'last12m': return { start: addDays( ymd( y - 1, m, d.getUTCDate() ), 1 ), end: today };
			case 'last30':
			default: return { start: addDays( today, -29 ), end: today };
		}
	}
	var initial = presetRange( state.preset );
	state.start = initial.start;
	state.end = initial.end;

	/* ======================================================================
	 * Shell
	 * ==================================================================== */

	var root = document.getElementById( 'woo-pulse-app' );
	var els = {};

	function renderShell() {
		root.innerHTML = '';
		var header = h( 'div', 'wpulse-header' );
		header.innerHTML = '<div class="wpulse-title"><span class="wpulse-muted">' + esc( 'Snapshot analytics for ' + ( cfg.storeName || 'this store' ) ) + '</span></div>';
		var controls = h( 'div', 'wpulse-controls' );

		var presetSel = h( 'select' );
		PRESETS.forEach( function ( p ) {
			var o = h( 'option', null, esc( p[ 1 ] ) );
			o.value = p[ 0 ];
			if ( p[ 0 ] === state.preset ) { o.selected = true; }
			presetSel.appendChild( o );
		} );
		presetSel.addEventListener( 'change', function () {
			state.preset = presetSel.value;
			if ( state.preset !== 'custom' ) {
				var r = presetRange( state.preset );
				state.start = r.start;
				state.end = r.end;
				syncDateInputs();
				loadReport();
			} else {
				syncDateInputs();
			}
		} );
		controls.appendChild( presetSel );

		var startIn = h( 'input' ); startIn.type = 'date';
		var endIn = h( 'input' ); endIn.type = 'date';
		var applyBtn = h( 'button', 'wpulse-btn small', 'Apply' );
		applyBtn.addEventListener( 'click', function () {
			if ( ! startIn.value || ! endIn.value ) { return; }
			state.start = startIn.value;
			state.end = endIn.value;
			if ( state.end < state.start ) { var t = state.start; state.start = state.end; state.end = t; }
			loadReport();
		} );
		var toSpan = h( 'span', 'wpulse-muted', 'to' );
		controls.appendChild( startIn );
		controls.appendChild( toSpan );
		controls.appendChild( endIn );
		controls.appendChild( applyBtn );
		els.startIn = startIn; els.endIn = endIn; els.applyBtn = applyBtn; els.toSpan = toSpan;

		controls.appendChild( h( 'span', 'wpulse-sep' ) );
		var cmpSel = h( 'select' );
		[ [ 'previous', 'vs previous period' ], [ 'year', 'vs same period last year' ], [ 'none', 'no comparison' ] ].forEach( function ( p ) {
			var o = h( 'option', null, esc( p[ 1 ] ) );
			o.value = p[ 0 ];
			if ( ( state.showCompare ? state.compare : 'none' ) === p[ 0 ] ) { o.selected = true; }
			cmpSel.appendChild( o );
		} );
		cmpSel.addEventListener( 'change', function () {
			if ( cmpSel.value === 'none' ) {
				state.showCompare = false;
				renderTab();
			} else {
				state.showCompare = true;
				state.compare = cmpSel.value;
				loadReport();
			}
		} );
		controls.appendChild( cmpSel );

		var refresh = h( 'button', 'wpulse-btn', '&#8635; Refresh' );
		refresh.title = 'Recompute now, bypassing the 10 minute cache';
		refresh.addEventListener( 'click', function () { loadReport( true ); } );
		controls.appendChild( refresh );
		els.refresh = refresh;

		header.appendChild( controls );
		root.appendChild( header );

		var tabs = h( 'div', 'wpulse-tabs' );
		TABS.forEach( function ( t ) {
			var b = h( 'button', 'wpulse-tab' + ( t[ 0 ] === state.tab ? ' is-active' : '' ), esc( t[ 1 ] ) );
			b.dataset.tab = t[ 0 ];
			b.addEventListener( 'click', function () { setTab( t[ 0 ] ); } );
			tabs.appendChild( b );
		} );
		root.appendChild( tabs );
		els.tabs = tabs;

		els.content = h( 'div', 'wpulse-content' );
		root.appendChild( els.content );
		els.foot = h( 'div', 'wpulse-muted', '' );
		els.foot.style.cssText = 'font-size:11px;margin:6px 0 20px;';
		root.appendChild( els.foot );
		syncDateInputs();
	}

	function syncDateInputs() {
		els.startIn.value = state.start;
		els.endIn.value = state.end;
		var custom = state.preset === 'custom';
		els.startIn.style.display = custom ? '' : 'none';
		els.endIn.style.display = custom ? '' : 'none';
		els.applyBtn.style.display = custom ? '' : 'none';
		els.toSpan.style.display = custom ? '' : 'none';
	}

	function setTab( tab ) {
		state.tab = tab;
		try { window.history.replaceState( null, '', '#' + tab ); } catch ( e ) { /* ignore */ }
		Array.prototype.forEach.call( els.tabs.children, function ( b ) {
			b.classList.toggle( 'is-active', b.dataset.tab === tab );
		} );
		renderTab();
	}

	function loadReport( force ) {
		var key = state.start + '|' + state.end + '|' + state.compare;
		if ( ! force && reportCache[ key ] ) {
			state.report = reportCache[ key ];
			state.error = null;
			state.geo.selected = null;
			renderTab();
			return Promise.resolve();
		}
		state.loading = true;
		state.error = null;
		els.content.classList.add( 'wpulse-loading' );
		els.refresh.disabled = true;
		if ( ! state.report ) {
			els.content.innerHTML = '<div class="wpulse-card"><div class="wpulse-skeleton" style="width:40%"></div><div class="wpulse-skeleton"></div><div class="wpulse-skeleton" style="width:70%"></div></div>';
		}
		var q = 'report?start=' + state.start + '&end=' + state.end + '&compare=' + state.compare + ( force ? '&nocache=1' : '' );
		return api( q ).then( function ( data ) {
			reportCache[ key ] = data;
			state.report = data;
			state.geo.selected = null;
		} ).catch( function ( e ) {
			state.error = e.message || String( e );
		} ).then( function () {
			state.loading = false;
			els.content.classList.remove( 'wpulse-loading' );
			els.refresh.disabled = false;
			renderTab();
		} );
	}

	function renderTab() {
		var el = els.content;
		el.innerHTML = '';
		if ( state.error ) {
			el.appendChild( h( 'div', 'wpulse-error', '<b>Could not load the report.</b> ' + esc( state.error ) ) );
		}
		if ( state.tab === 'settings' ) {
			renderSettings( el );
			return;
		}
		if ( ! state.report ) {
			if ( ! state.error ) {
				el.innerHTML = '<div class="wpulse-card"><div class="wpulse-skeleton" style="width:40%"></div><div class="wpulse-skeleton"></div><div class="wpulse-skeleton" style="width:70%"></div></div>';
			}
			return;
		}
		var r = state.report;
		if ( r.errors && r.errors.length ) {
			el.appendChild( h( 'div', 'wpulse-error', '<b>Some database queries failed, so parts of this report are incomplete.</b><br>' + r.errors.map( esc ).join( '<br>' ) ) );
		}
		var rangeNote = fmtDate( r.range.start, true ) + ' to ' + fmtDate( r.range.end, true ) + ' (' + r.range.days + ' days, ' + r.range.timezone + ')';
		if ( state.showCompare ) {
			rangeNote += ' · compared with ' + fmtDate( r.range.cmp_start, true ) + ' to ' + fmtDate( r.range.cmp_end, true );
		}
		rangeNote += ' · ' + ( r.cached ? 'cached' : 'computed' ) + ' ' + new Date( r.generated_at ).toLocaleTimeString( locale ) + ' · storage: ' + r.storage;
		els.foot.textContent = rangeNote;

		switch ( state.tab ) {
			case 'geography': renderGeography( el, r ); break;
			case 'marketing': renderMarketing( el, r ); break;
			case 'customers': renderCustomers( el, r ); break;
			case 'products': renderProducts( el, r ); break;
			default: renderOverview( el, r );
		}
	}

	/* ======================================================================
	 * Components
	 * ==================================================================== */

	function help( key ) {
		return DEF[ key ] ? '<span class="wpulse-help" data-tip="' + esc( DEF[ key ] ) + '" tabindex="0" role="button" aria-label="What is this?">?</span>' : '';
	}

	// One delegated handler shows the definition tooltip for every ? icon: hover, focus, or tap.
	function bindHelpTips() {
		var pinned = null;
		function show( el, ev ) {
			var r = el.getBoundingClientRect();
			var x = ev && ev.pageX ? ev.pageX : r.left + window.scrollX + r.width / 2;
			var y = ev && ev.pageY ? ev.pageY : r.top + window.scrollY + r.height;
			C.showTip( '<div class="wpulse-tip-def">' + esc( el.getAttribute( 'data-tip' ) ) + '</div>', x, y );
		}
		root.addEventListener( 'mouseover', function ( ev ) {
			var el = ev.target.closest && ev.target.closest( '.wpulse-help' );
			if ( el ) { show( el, ev ); }
		} );
		root.addEventListener( 'mousemove', function ( ev ) {
			var el = ev.target.closest && ev.target.closest( '.wpulse-help' );
			if ( el && ! pinned ) { show( el, ev ); }
		} );
		root.addEventListener( 'mouseout', function ( ev ) {
			var el = ev.target.closest && ev.target.closest( '.wpulse-help' );
			if ( el && pinned !== el ) { C.hideTip(); }
		} );
		root.addEventListener( 'click', function ( ev ) {
			var el = ev.target.closest && ev.target.closest( '.wpulse-help' );
			if ( el ) {
				ev.preventDefault();
				if ( pinned === el ) { pinned = null; C.hideTip(); } else { pinned = el; show( el, ev ); }
				return;
			}
			if ( pinned ) { pinned = null; C.hideTip(); }
		} );
		root.addEventListener( 'focusin', function ( ev ) {
			var el = ev.target.closest && ev.target.closest( '.wpulse-help' );
			if ( el ) { show( el ); }
		} );
		root.addEventListener( 'focusout', function ( ev ) {
			var el = ev.target.closest && ev.target.closest( '.wpulse-help' );
			if ( el && pinned !== el ) { C.hideTip(); }
		} );
	}

	function deltaInfo( cur, prev ) {
		if ( ! state.showCompare || isNil( cur ) || isNil( prev ) ) { return null; }
		if ( Number( prev ) === 0 ) { return Number( cur ) === 0 ? { pct: 0, dir: 'flat' } : null; }
		var p = ( cur - prev ) / Math.abs( prev );
		return { pct: p, dir: Math.abs( p ) < 0.0005 ? 'flat' : ( p > 0 ? 'up' : 'down' ) };
	}

	function deltaHtml( cur, prev, inverse, prevFormatted ) {
		var d = deltaInfo( cur, prev );
		if ( ! d ) {
			return state.showCompare && ! isNil( prev ) ? '<span class="wpulse-kpi-delta flat">prev ' + esc( prevFormatted || '' ) + '</span>' : '';
		}
		if ( d.dir === 'flat' ) {
			return '<span class="wpulse-kpi-delta flat">no change</span>';
		}
		var arrow = d.dir === 'up' ? '&#9650;' : '&#9660;';
		return '<span class="wpulse-kpi-delta ' + d.dir + ( inverse ? ' inverse' : '' ) + '">' + arrow + ' ' + Math.abs( d.pct * 100 ).toFixed( 1 ) + '%' + ( prevFormatted ? ' <span class="wpulse-muted">vs ' + esc( prevFormatted ) + '</span>' : '' ) + '</span>';
	}

	/** o: { label, raw, prev, fmt, inverse, help, sub, status } */
	function kpi( o ) {
		var fmt = o.fmt || num;
		var cls = 'wpulse-kpi' + ( o.status ? ' ' + o.status : '' );
		return '<div class="' + cls + '"><div class="wpulse-kpi-label">' + esc( o.label ) + ( o.help ? help( o.help ) : '' ) + '</div>' +
			'<div class="wpulse-kpi-value" title="' + esc( fmt( o.raw ) ) + '">' + esc( fmt( o.raw ) ) + '</div>' +
			deltaHtml( o.raw, o.prev, o.inverse, isNil( o.prev ) ? '' : fmt( o.prev ) ) +
			( o.sub ? '<div class="wpulse-kpi-sub">' + o.sub + '</div>' : '' ) + '</div>';
	}

	function card( title, opts ) {
		opts = opts || {};
		var c = h( 'div', 'wpulse-card' + ( opts.col ? ' wpulse-col-' + opts.col : '' ) );
		if ( title ) {
			var head = h( 'div', 'wpulse-card-head' );
			head.innerHTML = '<h3>' + esc( title ) + ( opts.help ? ' ' + help( opts.help ) : '' ) + '</h3>';
			if ( opts.actions ) {
				var a = h( 'div', 'wpulse-actions' );
				opts.actions.forEach( function ( x ) { a.appendChild( x ); } );
				head.appendChild( a );
			}
			c.appendChild( head );
		}
		if ( opts.sub ) {
			c.appendChild( h( 'div', 'wpulse-card-sub', opts.sub ) );
		}
		var body = h( 'div', 'wpulse-card-body' );
		if ( typeof opts.body === 'string' ) { body.innerHTML = opts.body; } else if ( opts.body ) { body.appendChild( opts.body ); }
		c.appendChild( body );
		c.body = body;
		return c;
	}

	function toggleGroup( options, current, onChange ) {
		var g = h( 'div', 'wpulse-toggle-group' );
		options.forEach( function ( o ) {
			var b = h( 'button', o[ 0 ] === current ? 'is-active' : '', esc( o[ 1 ] ) );
			b.type = 'button';
			b.addEventListener( 'click', function () { onChange( o[ 0 ] ); } );
			g.appendChild( b );
		} );
		return g;
	}

	function button( label, cls, onClick ) {
		var b = h( 'button', 'wpulse-btn ' + ( cls || '' ), label );
		b.type = 'button';
		if ( onClick ) { b.addEventListener( 'click', onClick ); }
		return b;
	}

	function downloadCsv( name, columns, rows ) {
		var lines = [ columns.map( function ( c ) { return '"' + String( c.label ).replace( /"/g, '""' ) + '"'; } ).join( ',' ) ];
		rows.forEach( function ( r ) {
			lines.push( columns.map( function ( c ) {
				var v = c.raw ? c.raw( r ) : r[ c.key ];
				if ( isNil( v ) ) { v = ''; }
				return '"' + String( v ).replace( /"/g, '""' ) + '"';
			} ).join( ',' ) );
		} );
		var blob = new Blob( [ '﻿' + lines.join( '\n' ) ], { type: 'text/csv;charset=utf-8' } );
		var a = document.createElement( 'a' );
		a.href = URL.createObjectURL( blob );
		a.download = name + '.csv';
		document.body.appendChild( a );
		a.click();
		setTimeout( function () { URL.revokeObjectURL( a.href ); a.remove(); }, 500 );
	}

	/**
	 * Sortable table. opts: { columns: [ { key, label, num, format(v,row), sort(bool), raw(row) } ], rows, sort: { key, dir }, limit, onRow(row, tr), isSelected(row), empty }
	 */
	function table( opts ) {
		var wrap = h( 'div', 'wpulse-table-wrap' );
		var sort = opts.sort ? { key: opts.sort.key, dir: opts.sort.dir || 'desc' } : null;
		function draw() {
			wrap.innerHTML = '';
			var rows = opts.rows.slice();
			if ( sort ) {
				rows.sort( function ( a, b ) {
					var av = a[ sort.key ], bv = b[ sort.key ];
					if ( isNil( av ) ) { av = -Infinity; }
					if ( isNil( bv ) ) { bv = -Infinity; }
					if ( typeof av === 'string' || typeof bv === 'string' ) {
						av = String( av ).toLowerCase(); bv = String( bv ).toLowerCase();
						return sort.dir === 'asc' ? av.localeCompare( bv ) : bv.localeCompare( av );
					}
					return sort.dir === 'asc' ? av - bv : bv - av;
				} );
			}
			if ( opts.limit ) { rows = rows.slice( 0, opts.limit ); }
			if ( ! rows.length ) {
				wrap.appendChild( h( 'div', 'wpulse-empty', esc( opts.empty || 'Nothing to show for this range.' ) ) );
				return;
			}
			var t = h( 'table', 'wpulse-table' + ( opts.cls ? ' ' + opts.cls : '' ) );
			var thead = h( 'thead' ), tr = h( 'tr' );
			opts.columns.forEach( function ( c ) {
				var th = h( 'th', ( c.num ? 'num' : '' ) + ( sort && sort.key === c.key ? ' sorted-' + sort.dir : '' ), esc( c.label ) );
				if ( c.sort !== false ) {
					th.dataset.sort = c.key;
					th.addEventListener( 'click', function () {
						if ( sort && sort.key === c.key ) { sort.dir = sort.dir === 'asc' ? 'desc' : 'asc'; } else { sort = { key: c.key, dir: c.num ? 'desc' : 'asc' }; }
						draw();
					} );
				}
				tr.appendChild( th );
			} );
			thead.appendChild( tr );
			t.appendChild( thead );
			var tbody = h( 'tbody' );
			rows.forEach( function ( r ) {
				var trr = h( 'tr', opts.isSelected && opts.isSelected( r ) ? 'is-selected' : '' );
				opts.columns.forEach( function ( c ) {
					var v = r[ c.key ];
					var td = h( 'td', ( c.num ? 'num' : '' ) + ( c.cls ? ' ' + c.cls : '' ) );
					td.innerHTML = c.format ? c.format( v, r ) : esc( isNil( v ) ? '' : v );
					trr.appendChild( td );
				} );
				if ( opts.onRow ) {
					trr.style.cursor = 'pointer';
					trr.addEventListener( 'click', function () { opts.onRow( r, trr ); } );
				}
				tbody.appendChild( trr );
			} );
			t.appendChild( tbody );
			wrap.appendChild( t );
		}
		draw();
		wrap.exportCsv = function ( name ) { downloadCsv( name, opts.columns, opts.rows ); };
		return wrap;
	}

	function exportButton( tbl, name ) {
		return button( 'Export CSV', 'small', function () { tbl.exportCsv( name ); } );
	}

	function stateName( country, code ) {
		if ( code === '??' || ! code ) { return 'Unknown'; }
		if ( cfg.states && cfg.states[ country ] && cfg.states[ country ][ code ] ) { return cfg.states[ country ][ code ]; }
		if ( country === 'US' && MAP.NAMES[ code ] ) { return MAP.NAMES[ code ]; }
		return code;
	}
	function countryName( code ) {
		if ( code === '??' || ! code ) { return 'Unknown'; }
		return ( cfg.countries && cfg.countries[ code ] ) ? cfg.countries[ code ] : code;
	}

	var SOURCE_TYPE_LABELS = {
		utm: 'Paid / UTM-tagged', organic: 'Organic search', referral: 'Referral', typein: 'Direct', admin: 'Admin (manual order)',
		mobile_app: 'Mobile app', unknown: 'Unknown', '(no data)': 'No attribution data'
	};

	function statusLabel( s ) {
		return ( cfg.statuses && cfg.statuses[ s ] ) ? cfg.statuses[ s ] : s.replace( /^wc-/, '' );
	}

	function merValue( m ) {
		return settings.mer_display === 'percent' ? m.mer_percent : m.mer_ratio;
	}
	function merFmt( v ) {
		return settings.mer_display === 'percent' ? ( isNil( v ) ? 'n/a' : Number( v ).toFixed( 1 ) + '%' ) : ratio( v );
	}
	function targetStatus( value, target, lowerIsBetter ) {
		if ( ! target || isNil( value ) ) { return ''; }
		if ( lowerIsBetter ) {
			return value <= target ? 'good' : value <= target * 1.15 ? 'warn' : 'bad';
		}
		return value >= target ? 'good' : value >= target * 0.85 ? 'warn' : 'bad';
	}

	function alignPrev( cur, prev ) {
		var out = [];
		for ( var i = 0; i < cur.length; i++ ) { out.push( prev && i < prev.length ? prev[ i ] : null ); }
		return out;
	}

	/* ======================================================================
	 * Overview
	 * ==================================================================== */

	function renderOverview( el, r ) {
		var c = r.current, p = r.previous, t = c.totals, pt = p.totals, m = c.marketing, pm = p.marketing;
		var basisLabel = settings.revenue_basis === 'gross' ? 'Revenue (gross, after refunds)' : 'Revenue (net sales)';

		if ( ! settings.setup_complete ) {
			var note = h( 'div', 'wpulse-note info' );
			note.style.marginBottom = '14px';
			note.innerHTML = '<b>Finish setup to unlock ROAS, MER and profit.</b> A short quiz defines what counts as revenue, which statuses count, and your marketing costs. ';
			var b = button( 'Start setup', 'small primary', openQuiz );
			note.appendChild( b );
			el.appendChild( note );
		}

		var profitSub = t.profit_method === 'cogs' ? 'from recorded COGS' : t.profit_method === 'margin' ? 'from ' + settings.gross_margin + '% margin' : 'set a margin in the quiz';
		var kpis = h( 'div', 'wpulse-kpis' );
		kpis.innerHTML = [
			kpi( { label: basisLabel, raw: t.revenue, prev: pt.revenue, fmt: money, help: 'revenue' } ),
			kpi( { label: 'Gross sales', raw: t.gross, prev: pt.gross, fmt: money, help: 'gross' } ),
			kpi( { label: 'Orders', raw: t.orders, prev: pt.orders, fmt: num } ),
			kpi( { label: 'Average order value', raw: t.aov, prev: pt.aov, fmt: money, help: 'aov' } ),
			kpi( { label: 'Items per order', raw: t.items_per_order, prev: pt.items_per_order, fmt: function ( v ) { return num( v, 2 ); } } ),
			kpi( { label: 'New customers', raw: t.new_customers, prev: pt.new_customers, fmt: num, help: 'new_customers' } ),
			kpi( { label: 'Returning customers', raw: t.returning_customers, prev: pt.returning_customers, fmt: num, help: 'returning' } ),
			kpi( { label: 'Refunds', raw: t.refunds, prev: pt.refunds, fmt: money, inverse: true, help: 'refunds', sub: num( t.refund_count ) + ' refund' + ( t.refund_count === 1 ? '' : 's' ) + ' · ' + pct( t.refund_rate ) + ' of gross' } ),
			kpi( { label: 'Blended ROAS', raw: m.blended_roas, prev: pm.blended_roas, fmt: ratio, help: 'blended_roas', status: targetStatus( m.blended_roas, settings.target_roas ), sub: m.ad_spend > 0 ? 'on ' + money( m.ad_spend ) + ' ad spend' : 'no ad spend logged' } ),
			kpi( { label: 'MER', raw: merValue( m ), prev: merValue( pm ), fmt: merFmt, inverse: settings.mer_display === 'percent', help: 'mer', status: targetStatus( m.mer_ratio, settings.target_mer ), sub: 'all marketing: ' + money( m.total_marketing ) } ),
			kpi( { label: 'CAC', raw: m.cac, prev: pm.cac, fmt: money, inverse: true, help: 'cac' } ),
			kpi( { label: 'Est. gross profit', raw: t.gross_profit, prev: pt.gross_profit, fmt: money, help: 'profit', sub: profitSub } )
		].join( '' );
		el.appendChild( kpis );

		// Reconciliation strip.
		var strip = h( 'div', 'wpulse-strip' );
		var items = [
			[ 'Gross sales', money( t.gross ) ], [ 'Discounts', money( t.discounts ) ], [ 'Refunds', '−' + money( t.refunds ) ],
			[ 'Tax', money( t.tax ) ], [ 'Shipping', money( t.shipping ) ], [ 'Net sales', money( t.net ), 'total' ],
			[ 'Est. fees', '−' + money( t.fees ) ], [ 'COGS', isNil( t.cogs ) ? 'n/a' : '−' + money( t.cogs ) ], [ 'Est. profit', money( t.gross_profit ), 'total' ]
		];
		strip.innerHTML = items.map( function ( it ) {
			return '<div class="wpulse-strip-item' + ( it[ 2 ] ? ' ' + it[ 2 ] : '' ) + '"><div class="l">' + esc( it[ 0 ] ) + '</div><div class="v">' + esc( it[ 1 ] ) + '</div></div>';
		} ).join( '' );
		el.appendChild( strip );

		var grid = h( 'div', 'wpulse-grid' );

		// Trend.
		var trendToggle = toggleGroup( [ [ 'revenue', 'Revenue' ], [ 'orders', 'Orders' ], [ 'new_customers', 'New customers' ] ], state.overview.series, function ( v ) {
			state.overview.series = v; renderTab();
		} );
		var trend = card( 'Trend', { col: 8, actions: [ trendToggle ] } );
		var chartEl = h( 'div', 'wpulse-chart' );
		trend.body.appendChild( chartEl );
		grid.appendChild( trend );
		var sKey = state.overview.series;
		var isMoney = sKey === 'revenue';
		var series = [ { label: sKey === 'revenue' ? 'Revenue' : sKey === 'orders' ? 'Orders' : 'New customers', values: c.series[ sKey ], color: C.colors.primary, area: true } ];
		if ( state.showCompare ) {
			series.push( { label: state.compare === 'year' ? 'Last year' : 'Previous period', values: alignPrev( c.series[ sKey ], p.series[ sKey ] ), color: C.colors.compare, dashed: true } );
		}
		C.line( chartEl, {
			labels: c.series.buckets, series: series,
			format: isMoney ? money : num,
			xFormat: function ( k, i, full ) { return bucketLabel( k, r.range.granularity, full ); }
		} );

		// Status breakdown.
		var statusCard = card( 'Orders by status', { col: 4, help: 'status', sub: 'Every order created in the range. Statuses not counted as sales are marked.' } );
		var sRows = Object.keys( c.status_counts ).map( function ( s ) {
			var counted = ( settings.statuses || [] ).indexOf( s ) >= 0;
			return { label: statusLabel( s ), sub: counted ? '' : '(not counted)', value: c.status_counts[ s ], color: counted ? C.colors.primary : C.colors.compare };
		} ).sort( function ( a, b ) { return b.value - a.value; } );
		var sEl = h( 'div' );
		C.hbars( sEl, { rows: sRows, format: num, empty: 'No orders in this range.' } );
		statusCard.body.appendChild( sEl );
		grid.appendChild( statusCard );

		// Heatmap.
		var heatToggle = toggleGroup( [ [ 'orders', 'Orders' ], [ 'revenue', 'Revenue' ] ], state.overview.heat, function ( v ) { state.overview.heat = v; renderTab(); } );
		var heat = card( 'When people buy', { col: 8, help: 'heatmap', actions: [ heatToggle ], sub: 'Day of week by hour of day, site timezone.' } );
		var heatEl = h( 'div' );
		var hours = [];
		for ( var hh = 0; hh < 24; hh++ ) { hours.push( hh === 0 ? '12a' : hh < 12 ? hh + 'a' : hh === 12 ? '12p' : ( hh - 12 ) + 'p' ); }
		C.heatmap( heatEl, { rows: [ 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun' ], cols: hours, values: c.heatmap[ state.overview.heat ], format: state.overview.heat === 'revenue' ? money : num, colLabelEvery: 3 } );
		heat.body.appendChild( heatEl );
		grid.appendChild( heat );

		// Payment methods.
		var pay = card( 'Payment methods', { col: 4 } );
		var payEl = h( 'div' );
		C.donut( payEl, { slices: c.payment_methods.map( function ( x, i ) { return { label: x.name, value: x.orders, color: C.palette[ i % C.palette.length ] }; } ), format: num, center: { value: num( t.orders ), label: 'orders' } } );
		pay.body.appendChild( payEl );
		grid.appendChild( pay );

		// Mini lists.
		function miniList( title, rows, col, onMore ) {
			var cd = card( title, { col: col, actions: [ button( 'See all', 'link small', onMore ) ] } );
			if ( ! rows.length ) { cd.body.innerHTML = '<div class="wpulse-empty">Nothing yet.</div>'; return cd; }
			var ul = h( 'ul', 'wpulse-mini-list' );
			rows.forEach( function ( x ) { ul.appendChild( h( 'li', null, '<span title="' + esc( x[ 0 ] ) + '">' + esc( x[ 0 ] ) + '</span><b>' + esc( x[ 1 ] ) + '</b>' ) ); } );
			cd.body.appendChild( ul );
			return cd;
		}
		grid.appendChild( miniList( 'Top states / regions', c.geo.regions.slice( 0, 5 ).map( function ( g ) { return [ stateName( g.country, g.state ) + ( g.country !== cfg.baseCountry ? ' (' + g.country + ')' : '' ), money( g.revenue ) ]; } ), 4, function () { setTab( 'geography' ); } ) );
		grid.appendChild( miniList( 'Top products', ( c.products || [] ).slice( 0, 5 ).map( function ( x ) { return [ x.name, money( x.revenue ) ]; } ), 4, function () { setTab( 'products' ); } ) );
		grid.appendChild( miniList( 'Top channels', ( c.channels.source_type || [] ).slice( 0, 5 ).map( function ( x ) { return [ SOURCE_TYPE_LABELS[ x.name ] || x.name, money( x.revenue ) ]; } ), 4, function () { setTab( 'marketing' ); } ) );

		el.appendChild( grid );
	}

	/* ======================================================================
	 * Geography
	 * ==================================================================== */

	var GEO_METRICS = [
		[ 'revenue', 'Revenue' ], [ 'orders', 'Orders' ], [ 'aov', 'Average order value' ], [ 'customers', 'Customers' ],
		[ 'new_customer_share', 'New-customer share' ], [ 'change', 'Change vs comparison' ]
	];

	function renderGeography( el, r ) {
		var c = r.current, p = r.previous;
		var regions = c.geo.regions;
		var countries = c.geo.countries;
		if ( ! regions.length ) {
			el.appendChild( card( 'Geography', { body: '<div class="wpulse-empty">No orders in this range.</div>' } ) );
			return;
		}
		var countryCodes = countries.map( function ( x ) { return x.country; } );
		if ( ! state.geo.country || countryCodes.indexOf( state.geo.country ) < 0 ) {
			state.geo.country = countryCodes.indexOf( cfg.baseCountry ) >= 0 ? cfg.baseCountry : countryCodes[ 0 ];
		}
		var country = state.geo.country;
		var isUS = country === 'US';
		var rows = regions.filter( function ( g ) { return g.country === country; } );
		var prevMap = {};
		p.geo.regions.forEach( function ( g ) { prevMap[ g.country + ':' + g.state ] = g; } );
		rows = rows.map( function ( g ) {
			var pg = prevMap[ g.country + ':' + g.state ];
			var d = pg ? deltaInfo( g.revenue, pg.revenue ) : null;
			return Object.assign( {}, g, { name: stateName( g.country, g.state ), prev_revenue: pg ? pg.revenue : null, change: d ? d.pct : null } );
		} );

		// Controls.
		var countrySel = h( 'select' );
		countries.forEach( function ( x ) {
			var o = h( 'option', null, esc( countryName( x.country ) + ' · ' + money( x.revenue, true ) ) );
			o.value = x.country;
			if ( x.country === country ) { o.selected = true; }
			countrySel.appendChild( o );
		} );
		countrySel.addEventListener( 'change', function () { state.geo.country = countrySel.value; state.geo.selected = null; renderTab(); } );
		var metricSel = h( 'select' );
		GEO_METRICS.forEach( function ( mm ) {
			var o = h( 'option', null, esc( mm[ 1 ] ) );
			o.value = mm[ 0 ];
			if ( mm[ 0 ] === state.geo.metric ) { o.selected = true; }
			metricSel.appendChild( o );
		} );
		metricSel.addEventListener( 'change', function () { state.geo.metric = metricSel.value; renderTab(); } );
		var viewToggle = toggleGroup( [ [ 'map', isUS ? 'Map' : 'Table' ], [ 'cities', 'By city' ], [ 'months', 'By month' ], [ 'quarters', 'By quarter' ] ], state.geo.view, function ( v ) { state.geo.view = v; renderTab(); } );

		var grid = h( 'div', 'wpulse-grid' );
		var metric = state.geo.metric;
		var metricFmt = metric === 'revenue' || metric === 'aov' ? money : metric === 'new_customer_share' ? pct : metric === 'change' ? function ( v ) { return isNil( v ) ? 'n/a' : ( v > 0 ? '+' : '' ) + ( v * 100 ).toFixed( 1 ) + '%'; } : num;

		function tipFor( g ) {
			return '<div class="wpulse-tip-row">Revenue <b>' + esc( money( g.revenue ) ) + '</b></div>' +
				'<div class="wpulse-tip-row">Orders <b>' + esc( num( g.orders ) ) + '</b></div>' +
				'<div class="wpulse-tip-row">AOV <b>' + esc( money( g.aov ) ) + '</b></div>' +
				'<div class="wpulse-tip-row">Customers <b>' + esc( num( g.customers ) ) + ' (' + esc( num( g.new_customers ) ) + ' new)</b></div>' +
				'<div class="wpulse-tip-row">Share <b>' + esc( pct( g.share ) ) + '</b></div>' +
				( state.showCompare ? '<div class="wpulse-tip-row">vs comparison <b>' + esc( isNil( g.change ) ? 'n/a' : ( g.change > 0 ? '+' : '' ) + ( g.change * 100 ).toFixed( 1 ) + '%' ) + '</b></div>' : '' );
		}

		if ( state.geo.view === 'map' && isUS ) {
			var mapCard = card( 'Sales by state', { col: 7, actions: [ countrySel, metricSel, viewToggle ] } );
			{
				var mapEl = h( 'div' );
				mapEl.style.minHeight = '320px';
				mapCard.body.appendChild( mapEl );
				var data = {};
				rows.forEach( function ( g ) { data[ g.state ] = { value: g[ metric ], tip: tipFor( g ) }; } );
				MAP.render( mapEl, {
					data: data, diverging: metric === 'change', format: metricFmt, atlasUrl: cfg.atlasUrl, selected: state.geo.selected,
					onSelect: function ( code ) { state.geo.selected = state.geo.selected === code ? null : code; renderTab(); }
				} );
				var unknown = rows.filter( function ( g ) { return g.state === '??' || ! MAP.NAMES[ g.state ]; } );
				if ( unknown.length ) {
					var uSum = unknown.reduce( function ( a, g ) { return a + g.revenue; }, 0 );
					mapCard.body.appendChild( h( 'div', 'wpulse-map-note', esc( 'Not on the map: ' + unknown.map( function ( g ) { return g.state === '??' ? 'no state' : g.state; } ).join( ', ' ) + ' · ' + money( uSum ) ) ) );
				}
				grid.appendChild( mapCard );

				// Side panel: selected state or top list.
				var side = card( state.geo.selected ? stateName( 'US', state.geo.selected ) : 'Top states by ' + GEO_METRICS.filter( function ( x ) { return x[ 0 ] === metric; } )[ 0 ][ 1 ].toLowerCase(), { col: 5, actions: state.geo.selected ? [ button( 'Clear', 'small', function () { state.geo.selected = null; renderTab(); } ) ] : [] } );
				if ( state.geo.selected ) {
					var g = rows.filter( function ( x ) { return x.state === state.geo.selected; } )[ 0 ];
					if ( g ) {
						var k2 = h( 'div', 'wpulse-kpis' );
						k2.style.gridTemplateColumns = 'repeat(2, minmax(0,1fr))';
						k2.innerHTML = [
							kpi( { label: 'Revenue', raw: g.revenue, prev: g.prev_revenue, fmt: money } ),
							kpi( { label: 'Orders', raw: g.orders, fmt: num } ),
							kpi( { label: 'AOV', raw: g.aov, fmt: money } ),
							kpi( { label: 'Customers', raw: g.customers, fmt: num, sub: num( g.new_customers ) + ' new (' + pct( g.new_customer_share ) + ')' } ),
							kpi( { label: 'Refunds', raw: g.refunds, fmt: money } ),
							kpi( { label: 'Tax collected', raw: g.tax, fmt: money } )
						].join( '' );
						side.body.appendChild( k2 );
						var cities = ( c.geo.cities[ 'US:' + g.state ] || [] );
						side.body.appendChild( h( 'div', 'wpulse-card-sub', 'Top cities' ) );
						var cEl = h( 'div' );
						C.hbars( cEl, { rows: cities.map( function ( x ) { return { label: x.city, sub: num( x.orders ) + ' orders', value: x.revenue }; } ), format: money, empty: 'No city data.' } );
						side.body.appendChild( cEl );
					} else {
						side.body.innerHTML = '<div class="wpulse-empty">No orders from ' + esc( stateName( 'US', state.geo.selected ) ) + ' in this range.</div>';
					}
				} else {
					var topEl = h( 'div' );
					var top = rows.slice().sort( function ( a, b ) { return ( b[ metric ] || 0 ) - ( a[ metric ] || 0 ); } ).slice( 0, 12 );
					C.hbars( topEl, { rows: top.map( function ( x ) { return { label: x.name, value: x[ metric ], sub: metric === 'revenue' ? pct( x.share ) : '', color: metric === 'change' && x.change < 0 ? C.colors.red : C.colors.primary }; } ), format: metricFmt } );
					side.body.appendChild( topEl );
				}
				grid.appendChild( side );
			}
		}

		// Region table (full width).
		var tbl;
		var tCard;
		if ( state.geo.view === 'map' ) {
			tbl = table( {
				rows: rows, sort: { key: 'revenue', dir: 'desc' },
				isSelected: function ( g ) { return g.state === state.geo.selected; },
				onRow: isUS ? function ( g ) { state.geo.selected = state.geo.selected === g.state ? null : g.state; renderTab(); } : null,
				columns: [
					{ key: 'name', label: isUS ? 'State' : 'Region', format: function ( v, g ) { return esc( v ) + ( g.state !== '??' ? ' <span class="wpulse-muted">' + esc( g.state ) + '</span>' : '' ); } },
					{ key: 'revenue', label: 'Revenue', num: true, format: money },
					{ key: 'share', label: 'Share', num: true, format: pct },
					{ key: 'orders', label: 'Orders', num: true, format: num },
					{ key: 'aov', label: 'AOV', num: true, format: money },
					{ key: 'customers', label: 'Customers', num: true, format: num },
					{ key: 'new_customers', label: 'New', num: true, format: num },
					{ key: 'refunds', label: 'Refunds', num: true, format: money },
					{ key: 'tax', label: 'Tax', num: true, format: money },
					{ key: 'change', label: 'vs comparison', num: true, format: function ( v ) { return isNil( v ) ? '<span class="wpulse-muted">n/a</span>' : '<span class="wpulse-kpi-delta ' + ( v > 0 ? 'up' : v < 0 ? 'down' : 'flat' ) + '">' + ( v > 0 ? '+' : '' ) + ( v * 100 ).toFixed( 1 ) + '%</span>'; } }
				]
			} );
			var tActions = isUS ? [] : [ countrySel, viewToggle ];
			tActions.push( exportButton( tbl, 'pulse-' + country.toLowerCase() + '-regions-' + r.range.start + '-' + r.range.end ) );
			tCard = card( ( isUS ? 'All states' : 'All regions' ) + ' · ' + countryName( country ), { col: 12, actions: tActions } );
		} else if ( state.geo.view === 'cities' ) {
			var cityRows = ( ( c.geo.top_cities && c.geo.top_cities[ country ] ) || [] ).map( function ( x ) {
				return Object.assign( {}, x, { state_name: stateName( x.country, x.state ) } );
			} );
			tbl = table( {
				rows: cityRows, sort: { key: 'revenue', dir: 'desc' }, empty: 'No city data for this country in the range.',
				columns: [
					{ key: 'city', label: 'City' },
					{ key: 'state_name', label: isUS ? 'State' : 'Region', format: function ( v, row ) { return esc( v ) + ( row.state !== '??' ? ' <span class="wpulse-muted">' + esc( row.state ) + '</span>' : '' ); } },
					{ key: 'revenue', label: 'Revenue', num: true, format: money },
					{ key: 'share', label: 'Share', num: true, format: pct },
					{ key: 'orders', label: 'Orders', num: true, format: num },
					{ key: 'aov', label: 'AOV', num: true, format: money },
					{ key: 'customers', label: 'Customers', num: true, format: num },
					{ key: 'new_customers', label: 'New', num: true, format: num },
					{ key: 'new_customer_share', label: 'New share', num: true, format: pct }
				]
			} );
			tCard = card( 'Cities · ' + countryName( country ), { col: 12, sub: 'Top 150 cities by revenue, from the order address. Share is of the period\'s total revenue.', actions: [ countrySel, viewToggle, exportButton( tbl, 'pulse-' + country.toLowerCase() + '-cities-' + r.range.start + '-' + r.range.end ) ] } );
		} else {
			var periodKey = state.geo.view; // months | quarters
			var periods = r.periods[ periodKey ];
			var cols = [ { key: 'name', label: isUS ? 'State' : 'Region' } ];
			periods.forEach( function ( pk ) {
				cols.push( { key: 'p_' + pk, label: periodKey === 'months' ? monthLabel( pk ) : pk, num: true, format: money } );
			} );
			cols.push( { key: 'revenue', label: 'Total', num: true, format: money } );
			var prow = rows.map( function ( g ) {
				var o = { name: g.name, state: g.state, revenue: g.revenue };
				periods.forEach( function ( pk ) { o[ 'p_' + pk ] = ( g[ periodKey ] && g[ periodKey ][ pk ] ) ? g[ periodKey ][ pk ] : 0; } );
				return o;
			} );
			tbl = table( { rows: prow, sort: { key: 'revenue', dir: 'desc' }, columns: cols } );
			tCard = card( 'Revenue by ' + ( periodKey === 'months' ? 'month' : 'quarter' ) + ' · ' + countryName( country ), { col: 12, sub: 'Revenue as defined in setup, refunds subtracted by refund date. Quarters are calendar quarters (the US estimated-tax periods).', actions: [ countrySel, viewToggle, exportButton( tbl, 'pulse-' + country.toLowerCase() + '-by-' + periodKey + '-' + r.range.start + '-' + r.range.end ) ] } );
		}
		tCard.body.appendChild( tbl );
		grid.appendChild( tCard );

		// Countries.
		if ( countries.length > 1 ) {
			var cRows = countries.map( function ( g ) { return Object.assign( {}, g, { name: countryName( g.country ) } ); } );
			var cTbl = table( {
				rows: cRows, sort: { key: 'revenue', dir: 'desc' },
				onRow: function ( g ) { state.geo.country = g.country; state.geo.selected = null; renderTab(); },
				columns: [
					{ key: 'name', label: 'Country', format: function ( v, g ) { return esc( v ) + ' <span class="wpulse-muted">' + esc( g.country ) + '</span>'; } },
					{ key: 'revenue', label: 'Revenue', num: true, format: money },
					{ key: 'share', label: 'Share', num: true, format: pct },
					{ key: 'orders', label: 'Orders', num: true, format: num },
					{ key: 'aov', label: 'AOV', num: true, format: money },
					{ key: 'customers', label: 'Customers', num: true, format: num },
					{ key: 'new_customers', label: 'New', num: true, format: num },
					{ key: 'refunds', label: 'Refunds', num: true, format: money }
				]
			} );
			var cCard = card( 'Countries', { col: 12, actions: [ exportButton( cTbl, 'pulse-countries-' + r.range.start + '-' + r.range.end ) ] } );
			cCard.body.appendChild( cTbl );
			grid.appendChild( cCard );
		}
		el.appendChild( grid );
	}

	/* ======================================================================
	 * Marketing
	 * ==================================================================== */

	function weeklyMer( r ) {
		var c = r.current, m = c.marketing, gran = r.range.granularity;
		var groups = {}, order = [];
		c.series.buckets.forEach( function ( b, i ) {
			var key = b;
			if ( gran === 'day' ) {
				var d = ymdToDate( b );
				var dow = ( d.getUTCDay() + 6 ) % 7;
				d.setUTCDate( d.getUTCDate() - dow );
				key = dateToYmd( d );
			}
			if ( ! groups[ key ] ) { groups[ key ] = { rev: 0, spend: 0, fixed: 0 }; order.push( key ); }
			groups[ key ].rev += c.series.revenue[ i ] || 0;
			groups[ key ].spend += m.series.spend[ i ] || 0;
			groups[ key ].fixed += m.series.fixed[ i ] || 0;
		} );
		var labels = [], mer = [], roas = [];
		order.forEach( function ( k ) {
			var g = groups[ k ];
			labels.push( k );
			mer.push( ( g.spend + g.fixed ) > 0 ? g.rev / ( g.spend + g.fixed ) : null );
			roas.push( g.spend > 0 ? g.rev / g.spend : null );
		} );
		var vals = mer.filter( function ( v ) { return v !== null; } );
		var declining = vals.length >= 4 && vals[ vals.length - 1 ] < vals[ vals.length - 2 ] && vals[ vals.length - 2 ] < vals[ vals.length - 3 ] && vals[ vals.length - 3 ] < vals[ vals.length - 4 ];
		return { labels: labels, mer: mer, roas: roas, declining: declining, gran: gran === 'day' ? 'week' : gran };
	}

	function renderMarketing( el, r ) {
		var c = r.current, p = r.previous, t = c.totals, m = c.marketing, pm = p.marketing;

		if ( ! settings.setup_complete ) {
			var setup = card( 'Set up your measurement', { body: '<p>Blended ROAS and MER only mean something when everyone agrees on the inputs. A two-minute quiz settles what counts as revenue, which order statuses count as a sale, which paid channels you run, and what your marketing costs beyond ad spend are.</p>' } );
			setup.body.appendChild( button( 'Start the setup quiz', 'primary', openQuiz ) );
			el.appendChild( setup );
		}

		var kpis = h( 'div', 'wpulse-kpis' );
		kpis.innerHTML = [
			kpi( { label: 'Blended ROAS', raw: m.blended_roas, prev: pm.blended_roas, fmt: ratio, help: 'blended_roas', status: targetStatus( m.blended_roas, settings.target_roas ), sub: settings.target_roas ? 'target ' + ratio( settings.target_roas ) : '' } ),
			kpi( { label: 'MER', raw: merValue( m ), prev: merValue( pm ), fmt: merFmt, inverse: settings.mer_display === 'percent', help: 'mer', status: targetStatus( m.mer_ratio, settings.target_mer ), sub: settings.target_mer ? 'target ' + ratio( settings.target_mer ) : '' } ),
			kpi( { label: 'New-customer ROAS', raw: m.new_customer_roas, prev: pm.new_customer_roas, fmt: ratio, help: 'new_customer_roas' } ),
			kpi( { label: 'Total marketing cost', raw: m.total_marketing, prev: pm.total_marketing, fmt: money, inverse: true } ),
			kpi( { label: 'Ad spend', raw: m.ad_spend, prev: pm.ad_spend, fmt: money, inverse: true } ),
			kpi( { label: 'Fixed costs (prorated)', raw: m.fixed_costs, prev: pm.fixed_costs, fmt: money, inverse: true, sub: m.fixed_monthly ? money( m.fixed_monthly ) + ' / month' : 'none set' } ),
			kpi( { label: 'CAC', raw: m.cac, prev: pm.cac, fmt: money, inverse: true, help: 'cac', sub: num( t.new_customers ) + ' new customers' } ),
			kpi( { label: 'Cost per order', raw: m.cost_per_order, prev: pm.cost_per_order, fmt: money, inverse: true, help: 'cost_per_order' } ),
			kpi( { label: 'Marketing % of revenue', raw: m.marketing_share, prev: pm.marketing_share, fmt: pct, inverse: true, help: 'marketing_share' } ),
			kpi( { label: 'Break-even ROAS', raw: m.break_even_roas, fmt: ratio, help: 'break_even', sub: isNil( m.break_even_roas ) ? 'needs a margin %' : '' } ),
			kpi( { label: 'Contribution after marketing', raw: m.contribution, prev: pm.contribution, fmt: money, help: 'contribution' } )
		].join( '' );
		el.appendChild( kpis );

		var grid = h( 'div', 'wpulse-grid' );

		// Revenue vs spend.
		var rvs = card( 'Revenue vs ad spend', { col: 8 } );
		var rvsEl = h( 'div', 'wpulse-chart' );
		var roasSeries = c.series.revenue.map( function ( v, i ) { var s = m.series.spend[ i ]; return s > 0 ? v / s : null; } );
		C.line( rvsEl, {
			labels: c.series.buckets,
			series: [
				{ label: 'Revenue', values: c.series.revenue, color: C.colors.primary, area: true },
				{ label: 'Ad spend', values: m.series.spend, color: C.colors.spend },
				{ label: 'Blended ROAS', values: roasSeries, color: C.colors.green, dashed: true, axis: 'right' }
			],
			format: money, formatRight: ratio,
			xFormat: function ( k, i, full ) { return bucketLabel( k, r.range.granularity, full ); }
		} );
		rvs.body.appendChild( rvsEl );
		grid.appendChild( rvs );

		// MER trend.
		var wk = weeklyMer( r );
		var merCard = card( 'MER trend', { col: 4, sub: wk.gran === 'week' ? 'Weekly. Operators act when MER falls three periods in a row.' : 'Per ' + wk.gran + '.' } );
		if ( wk.declining ) { merCard.body.appendChild( h( 'div', 'wpulse-note warn', '<b>MER has declined for 3+ consecutive periods.</b> Check spend creep, creative fatigue and discounting.' ) ); }
		var merEl = h( 'div', 'wpulse-chart' );
		C.line( merEl, {
			labels: wk.labels, height: 200,
			series: [ { label: 'MER', values: wk.mer, color: C.colors.purple, area: true }, { label: 'Blended ROAS', values: wk.roas, color: C.colors.green, dashed: true } ],
			format: ratio,
			xFormat: function ( k, i, full ) { return bucketLabel( k, wk.gran, full ); }
		} );
		merCard.body.appendChild( merEl );
		grid.appendChild( merCard );

		// Spend by channel.
		var chanRows = m.by_channel.map( function ( x ) { return Object.assign( {}, x ); } );
		var chanTbl = table( {
			rows: chanRows, sort: { key: 'amount', dir: 'desc' }, empty: 'No spend logged for this range. Add entries below.',
			columns: [
				{ key: 'channel', label: 'Channel' },
				{ key: 'amount', label: 'Spend', num: true, format: money },
				{ key: 'share', label: 'Share', num: true, format: pct },
				{ key: 'reported_revenue', label: 'Platform-reported revenue', num: true, format: function ( v ) { return v > 0 ? money( v ) : '<span class="wpulse-muted">—</span>'; } },
				{ key: 'reported_roas', label: 'Platform ROAS', num: true, format: function ( v ) { return isNil( v ) || v === 0 ? '<span class="wpulse-muted">—</span>' : ratio( v ); } }
			]
		} );
		var chanCard = card( 'Spend by channel', { col: 6, actions: [ exportButton( chanTbl, 'pulse-spend-by-channel-' + r.range.start + '-' + r.range.end ) ] } );
		chanCard.body.appendChild( chanTbl );
		grid.appendChild( chanCard );

		// Platform reality check.
		var check = card( 'Platform-reported vs blended', { col: 6, help: 'platform' } );
		if ( m.platform_reported_revenue > 0 ) {
			var over = m.platform_overclaim;
			check.body.innerHTML = '<div class="wpulse-kpis" style="grid-template-columns:repeat(3,minmax(0,1fr))">' +
				kpi( { label: 'Platforms claim', raw: m.platform_reported_revenue, fmt: money } ) +
				kpi( { label: 'Actual revenue', raw: t.revenue, fmt: money } ) +
				kpi( { label: 'Over-claim', raw: over, fmt: function ( v ) { return isNil( v ) ? 'n/a' : ( v * 100 ).toFixed( 0 ) + '%'; }, status: isNil( over ) ? '' : over <= 1.1 ? 'good' : over <= 1.6 ? 'warn' : 'bad', sub: 'of actual revenue' } ) +
				'</div><div class="wpulse-note">Platform ROAS ' + esc( ratio( m.platform_roas ) ) + ' vs blended ROAS ' + esc( ratio( m.blended_roas ) ) + '. Platforms count view-through and overlapping conversions; blended uses what the store actually took. Use platform ROAS for in-account decisions and blended ROAS for budget decisions.</div>';
		} else {
			check.body.innerHTML = '<div class="wpulse-note">Add the revenue each ad platform reports when you log spend and this panel will show how much they over-claim compared with real store revenue.</div>';
		}
		grid.appendChild( check );

		// Channels (attribution).
		var dims = [ [ 'source_type', 'Source type' ], [ 'source_medium', 'Source / medium' ], [ 'campaign', 'Campaign' ], [ 'device', 'Device' ] ];
		var dimToggle = toggleGroup( dims, state.channelDim, function ( v ) { state.channelDim = v; renderTab(); } );
		var chRows = ( c.channels[ state.channelDim ] || [] ).map( function ( x ) { return Object.assign( {}, x, { label: state.channelDim === 'source_type' ? ( SOURCE_TYPE_LABELS[ x.name ] || x.name ) : x.name } ); } );
		var chTbl = table( {
			rows: chRows, sort: { key: 'revenue', dir: 'desc' }, empty: cfg.hasAttribution ? 'No attributed orders in this range.' : 'No attribution data found. WooCommerce 8.5+ records it automatically when order attribution is enabled under WooCommerce > Settings > Advanced > Features.',
			columns: [
				{ key: 'label', label: dims.filter( function ( d ) { return d[ 0 ] === state.channelDim; } )[ 0 ][ 1 ], cls: 'name' },
				{ key: 'orders', label: 'Orders', num: true, format: num },
				{ key: 'revenue', label: 'Revenue', num: true, format: money },
				{ key: 'share', label: 'Share', num: true, format: pct },
				{ key: 'aov', label: 'AOV', num: true, format: money },
				{ key: 'new_customers', label: 'New customers', num: true, format: num },
				{ key: 'new_customer_share', label: 'New share', num: true, format: pct }
			]
		} );
		var chCard = card( 'Where orders come from', { col: 12, help: 'channels', sub: 'Last-touch attribution recorded by WooCommerce at checkout. Expect it to differ from ad platform numbers.', actions: [ dimToggle, exportButton( chTbl, 'pulse-channels-' + state.channelDim + '-' + r.range.start + '-' + r.range.end ) ] } );
		chCard.body.appendChild( chTbl );
		grid.appendChild( chCard );

		el.appendChild( grid );

		// Ad-platform connections, then the ledger and import.
		el.appendChild( renderConnections() );
		el.appendChild( renderLedger( r ) );
	}

	/* ---------------- Connections (Woo Pulse Connect service) ---------------- */

	var SOURCE_LABELS = { manual: 'Manual', csv: 'CSV', meta: 'Meta', google: 'Google Ads', ping: 'Ping' };
	function sourceBadge( s ) {
		var cls = s === 'meta' || s === 'google' ? 'green' : '';
		return '<span class="wpulse-badge ' + cls + '">' + esc( SOURCE_LABELS[ s ] || s ) + '</span>';
	}

	function renderConnections() {
		var cardEl = card( 'Ad platform connections', { sub: 'Pull daily spend and platform-reported revenue automatically instead of logging it by hand.' } );
		var body = cardEl.body;
		body.innerHTML = '<div class="wpulse-skeleton" style="width:50%"></div>';

		function draw( st ) {
			body.innerHTML = '';
			if ( ! st.configured ) {
				body.appendChild( h( 'div', 'wpulse-note', 'Connections run through the Woo Pulse Connect service. Enter its URL under <b>Settings &rsaquo; Connectors</b> to enable them. Until then, log spend manually or import a CSV below.' ) );
				return;
			}
			if ( st.error ) {
				body.appendChild( h( 'div', 'wpulse-error', '<b>Could not reach the connector service.</b> ' + esc( st.error ) ) );
				return;
			}
			if ( ! st.registered ) {
				var note = h( 'div', 'wpulse-note info', 'This store is not registered with the connector service yet. Registration sends the site URL, currency and timezone, nothing else. ' );
				var reg = button( 'Enable connectors', 'small primary', function () {
					reg.disabled = true;
					api( 'connector/register', { method: 'POST', body: {} } ).then( function () { toast( 'Store registered with the connector service.' ); refresh(); } ).catch( function ( e ) { reg.disabled = false; toast( e.message, true ); } );
				} );
				note.appendChild( reg );
				body.appendChild( note );
				return;
			}
			var svc = st.service || {};
			var conns = svc.connections || [];
			var platforms = svc.platforms || {};
			var tw = h( 'div', 'wpulse-table-wrap' );
			var t = h( 'table', 'wpulse-table' );
			t.innerHTML = '<thead><tr><th>Platform</th><th>Account</th><th>Status</th><th>Last sync</th><th></th></tr></thead>';
			var tb = h( 'tbody' );
			Object.keys( platforms ).forEach( function ( p ) {
				var info = platforms[ p ];
				var mine = conns.filter( function ( c ) { return c.platform === p; } );
				if ( ! mine.length ) {
					var tr = h( 'tr' );
					tr.innerHTML = '<td>' + esc( info.label ) + '</td><td class="wpulse-muted">Not connected</td><td>' + ( info.enabled ? '<span class="wpulse-badge">available</span>' : '<span class="wpulse-badge amber">coming soon</span>' ) + '</td><td></td>';
					var td = h( 'td', 'num' );
					var cb = button( 'Connect', 'small primary', function () {
						cb.disabled = true;
						api( 'connector/connect', { method: 'POST', body: { platform: p } } ).then( function ( res ) {
							cb.disabled = false;
							if ( res && res.url ) { window.location.href = res.url; } else { toast( 'No connect URL returned.', true ); }
						} ).catch( function ( e ) { cb.disabled = false; toast( e.message, true ); } );
					} );
					if ( ! info.enabled ) { cb.disabled = true; cb.title = 'This platform is not enabled on the connector service yet.'; }
					td.appendChild( cb );
					tr.appendChild( td );
					tb.appendChild( tr );
				}
				mine.forEach( function ( c ) {
					var tr = h( 'tr' );
					var statusHtml = c.status === 'active' ? '<span class="wpulse-badge green">active</span>' : c.status === 'needs_reauth' ? '<span class="wpulse-badge red">reconnect needed</span>' : '<span class="wpulse-badge">' + esc( c.status ) + '</span>';
					if ( c.last_error ) { statusHtml += '<div class="wpulse-muted" style="font-size:11px;max-width:320px;white-space:normal">' + esc( c.last_error ) + '</div>'; }
					tr.innerHTML = '<td>' + esc( info.label ) + '</td><td>' + esc( c.account_name || c.external_account_id ) + '<div class="wpulse-muted">' + esc( c.external_account_id ) + ( c.account_currency ? ' · ' + esc( c.account_currency ) : '' ) + '</div></td><td>' + statusHtml + '</td><td>' + ( c.last_sync_at ? esc( new Date( c.last_sync_at ).toLocaleString( locale ) ) : '<span class="wpulse-muted">never</span>' ) + '</td>';
					var td = h( 'td', 'num' );
					var rs = button( 'Resync 30 days', 'small', function () {
						rs.disabled = true;
						api( 'connector/connections/' + encodeURIComponent( c.id ) + '/resync', { method: 'POST', body: { days: 30 } } ).then( function ( res ) {
							rs.disabled = false;
							toast( res.ok ? 'Synced ' + res.rows + ' day' + ( res.rows === 1 ? '' : 's' ) + '.' : ( res.error || 'Sync failed' ), ! res.ok );
							reportCache = {}; refresh(); loadReport( true );
						} ).catch( function ( e ) { rs.disabled = false; toast( e.message, true ); refresh(); } );
					} );
					var dc = button( 'Disconnect', 'small danger', function () {
						if ( ! window.confirm( 'Disconnect ' + ( c.account_name || c.external_account_id ) + '? ' + ( settings.keep_synced_on_disconnect ? 'Synced spend rows will be kept.' : 'Synced spend rows will be deleted.' ) ) ) { return; }
						dc.disabled = true;
						api( 'connector/connections/' + encodeURIComponent( c.id ) + '?source=' + encodeURIComponent( p ) + '&external_id=' + encodeURIComponent( c.external_account_id ), { method: 'DELETE' } ).then( function () { toast( 'Disconnected.' ); reportCache = {}; refresh(); loadReport( true ); } ).catch( function ( e ) { dc.disabled = false; toast( e.message, true ); } );
					} );
					td.appendChild( rs ); td.appendChild( document.createTextNode( ' ' ) ); td.appendChild( dc );
					tr.appendChild( td );
					tb.appendChild( tr );
				} );
			} );
			t.appendChild( tb );
			tw.appendChild( t );
			body.appendChild( tw );
			var foot = h( 'div', 'wpulse-form-actions' );
			foot.style.marginTop = '10px';
			var ping = button( 'Test connection', 'small', function () {
				ping.disabled = true;
				api( 'connector/ping', { method: 'POST', body: {} } ).then( function ( res ) { ping.disabled = false; toast( res.ok ? 'The service can reach this store and its signature was accepted.' : ( res.message || 'Test failed' ), ! res.ok ); } ).catch( function ( e ) { ping.disabled = false; toast( e.message, true ); } );
			} );
			foot.appendChild( ping );
			foot.appendChild( h( 'span', 'wpulse-muted', 'Store id ' + esc( st.store_id || '' ) + ' · service ' + esc( st.url ) ) );
			body.appendChild( foot );
		}
		function refresh() {
			api( 'connector/status' ).then( draw ).catch( function ( e ) { body.innerHTML = '<div class="wpulse-error">' + esc( e.message ) + '</div>'; } );
		}
		refresh();
		return cardEl;
	}

	function renderLedger( r ) {
		var grid = h( 'div', 'wpulse-grid' );
		var channels = ( settings.channels || [] ).slice();
		var edit = state.ledgerEdit;

		// Entry form.
		var formCard = card( edit ? 'Edit spend entry' : 'Log ad spend', { col: 5, sub: 'One entry per channel per day works best. Totals per week or month are fine too: date it on the first day.' } );
		var form = h( 'div', 'wpulse-form' );
		var row1 = h( 'div', 'wpulse-form-row' );
		var dateF = field( 'Date', 'date', edit ? edit.spend_date : r.range.end );
		var chanSel = h( 'select' );
		channels.concat( [ '__other' ] ).forEach( function ( ch ) {
			var o = h( 'option', null, esc( ch === '__other' ? 'Other…' : ch ) );
			o.value = ch;
			chanSel.appendChild( o );
		} );
		if ( edit ) { chanSel.value = channels.indexOf( edit.channel ) >= 0 ? edit.channel : '__other'; }
		var chanWrap = h( 'div', 'wpulse-field', '<label>Channel</label>' );
		chanWrap.appendChild( chanSel );
		var otherF = field( 'Channel name', 'text', edit && channels.indexOf( edit.channel ) < 0 ? edit.channel : '' );
		otherF.style.display = chanSel.value === '__other' ? '' : 'none';
		chanSel.addEventListener( 'change', function () { otherF.style.display = chanSel.value === '__other' ? '' : 'none'; } );
		row1.appendChild( dateF ); row1.appendChild( chanWrap ); row1.appendChild( otherF );
		form.appendChild( row1 );
		var row2 = h( 'div', 'wpulse-form-row' );
		var amountF = field( 'Amount (' + ( cfg.currency || '' ) + ')', 'number', edit ? edit.amount : '' );
		var repF = field( 'Platform-reported revenue (optional)', 'number', edit && ! isNil( edit.reported_revenue ) ? edit.reported_revenue : '' );
		var noteF = field( 'Note (optional)', 'text', edit ? edit.note : '' );
		row2.appendChild( amountF ); row2.appendChild( repF ); row2.appendChild( noteF );
		form.appendChild( row2 );
		var actions = h( 'div' );
		actions.style.display = 'flex'; actions.style.gap = '8px';
		var saveBtn = button( edit ? 'Update entry' : 'Add entry', 'primary', function () {
			var body = {
				spend_date: dateF.input.value,
				channel: chanSel.value === '__other' ? otherF.input.value : chanSel.value,
				amount: amountF.input.value,
				reported_revenue: repF.input.value === '' ? null : repF.input.value,
				note: noteF.input.value
			};
			saveBtn.disabled = true;
			var req = edit ? api( 'spend/' + edit.id, { method: 'PUT', body: body } ) : api( 'spend', { method: 'POST', body: body } );
			req.then( function () {
				toast( edit ? 'Entry updated.' : 'Entry added.' );
				state.ledgerEdit = null;
				if ( chanSel.value === '__other' && body.channel && channels.indexOf( body.channel ) < 0 ) {
					settings.channels = channels.concat( [ body.channel ] );
					api( 'settings', { method: 'POST', body: { channels: settings.channels } } ).catch( function () {} );
				}
				reportCache = {};
				return loadReport( true );
			} ).catch( function ( e ) { toast( e.message, true ); saveBtn.disabled = false; } );
		} );
		actions.appendChild( saveBtn );
		if ( edit ) { actions.appendChild( button( 'Cancel', '', function () { state.ledgerEdit = null; renderTab(); } ) ); }
		form.appendChild( actions );
		formCard.body.appendChild( form );
		grid.appendChild( formCard );

		// Entries table.
		var entries = [];
		var entriesCard = card( 'Spend entries in this range', { col: 7 } );
		var holder = h( 'div', null, '<div class="wpulse-skeleton"></div>' );
		entriesCard.body.appendChild( holder );
		api( 'spend?start=' + r.range.start + '&end=' + r.range.end ).then( function ( rows ) {
			entries = rows || [];
			holder.innerHTML = '';
			var sources = [];
			entries.forEach( function ( x ) { if ( sources.indexOf( x.source ) < 0 ) { sources.push( x.source ); } } );
			if ( sources.length > 1 ) {
				var filt = toggleGroup( [ [ 'all', 'All sources' ] ].concat( sources.map( function ( s ) { return [ s, SOURCE_LABELS[ s ] || s ]; } ) ), state.ledgerSource || 'all', function ( v ) { state.ledgerSource = v; renderTab(); } );
				filt.style.marginBottom = '8px';
				holder.appendChild( filt );
			}
			var shown = ( state.ledgerSource && state.ledgerSource !== 'all' ) ? entries.filter( function ( x ) { return x.source === state.ledgerSource; } ) : entries;
			var tbl = table( {
				rows: shown, sort: { key: 'spend_date', dir: 'desc' }, empty: 'No entries for this range yet.',
				columns: [
					{ key: 'spend_date', label: 'Date', format: function ( v ) { return esc( fmtDate( v, true ) ); } },
					{ key: 'channel', label: 'Channel' },
					{ key: 'source', label: 'Source', format: function ( v, row ) { return sourceBadge( v ) + ( row.currency && row.currency !== cfg.currency ? ' <span class="wpulse-muted">' + esc( row.currency ) + '</span>' : '' ); } },
					{ key: 'amount', label: 'Spend', num: true, format: money },
					{ key: 'reported_revenue', label: 'Reported rev.', num: true, format: function ( v ) { return isNil( v ) ? '<span class="wpulse-muted">—</span>' : money( v ); } },
					{ key: 'note', label: 'Note', cls: 'name', format: function ( v ) { return '<span class="wpulse-muted">' + esc( v ) + '</span>'; } },
					{ key: 'id', label: '', sort: false, format: function ( v, row ) { return row.readonly ? '<span class="wpulse-muted" title="Synced rows are managed by the connector">synced</span>' : '<button type="button" class="wpulse-btn small" data-edit="' + v + '">Edit</button> <button type="button" class="wpulse-btn small danger" data-del="' + v + '">Delete</button>'; } }
				]
			} );
			tbl.addEventListener( 'click', function ( ev ) {
				var b = ev.target.closest( 'button' );
				if ( ! b ) { return; }
				if ( b.dataset.edit ) {
					state.ledgerEdit = entries.filter( function ( x ) { return String( x.id ) === b.dataset.edit; } )[ 0 ];
					renderTab();
					window.scrollTo( { top: formCard.offsetTop - 40, behavior: 'smooth' } );
				} else if ( b.dataset.del ) {
					if ( ! window.confirm( 'Delete this spend entry?' ) ) { return; }
					api( 'spend/' + b.dataset.del, { method: 'DELETE' } ).then( function () {
						toast( 'Entry deleted.' );
						reportCache = {};
						return loadReport( true );
					} ).catch( function ( e ) { toast( e.message, true ); } );
				}
			} );
			var exp = exportButton( tbl, 'pulse-spend-' + r.range.start + '-' + r.range.end );
			entriesCard.querySelector( '.wpulse-card-head' ).appendChild( h( 'div', 'wpulse-actions' ) ).appendChild( exp );
			holder.appendChild( tbl );
		} ).catch( function ( e ) { holder.innerHTML = '<div class="wpulse-error">' + esc( e.message ) + '</div>'; } );
		grid.appendChild( entriesCard );

		// Import.
		var imp = card( 'Import spend from CSV', { col: 12, sub: 'Columns: date, channel, amount, reported_revenue (optional), note (optional). A header row is detected automatically. Dates accept YYYY-MM-DD, MM/DD/YYYY and DD.MM.YYYY; amounts may include currency symbols.' } );
		var impForm = h( 'div', 'wpulse-form' );
		var ta = h( 'div', 'wpulse-field', '<label>Paste CSV</label>' );
		var textarea = h( 'textarea' );
		textarea.placeholder = 'date,channel,amount,reported_revenue,note\n2026-10-01,Meta,412.50,1890.00,Prospecting\n2026-10-01,Google,230.00,,Brand';
		ta.appendChild( textarea );
		impForm.appendChild( ta );
		var fileRow = h( 'div', 'wpulse-form-row' );
		var fileF = h( 'div', 'wpulse-field', '<label>…or choose a .csv file</label>' );
		var fileIn = h( 'input' ); fileIn.type = 'file'; fileIn.accept = '.csv,text/csv';
		fileIn.addEventListener( 'change', function () {
			var f = fileIn.files && fileIn.files[ 0 ];
			if ( ! f ) { return; }
			var reader = new window.FileReader();
			reader.onload = function () { textarea.value = String( reader.result || '' ); };
			reader.readAsText( f );
		} );
		fileF.appendChild( fileIn );
		fileRow.appendChild( fileF );
		var replaceWrap = h( 'label', 'wpulse-check' );
		var replaceCb = h( 'input' ); replaceCb.type = 'checkbox';
		replaceWrap.appendChild( replaceCb );
		replaceWrap.appendChild( document.createTextNode( ' Replace existing entries for the same date and channel' ) );
		fileRow.appendChild( replaceWrap );
		impForm.appendChild( fileRow );
		var impActions = h( 'div' );
		impActions.style.display = 'flex'; impActions.style.gap = '8px';
		var previewEl = h( 'div' );
		var importBtn = button( 'Import', 'primary', null );
		importBtn.disabled = true;
		var previewBtn = button( 'Preview', '', function () {
			if ( ! textarea.value.trim() ) { toast( 'Paste or choose a CSV first.', true ); return; }
			previewBtn.disabled = true;
			api( 'spend/bulk', { method: 'POST', body: { csv: textarea.value, preview: true } } ).then( function ( res ) {
				previewBtn.disabled = false;
				previewEl.innerHTML = '';
				if ( res.errors && res.errors.length ) {
					previewEl.appendChild( h( 'div', 'wpulse-error', res.errors.map( esc ).join( '<br>' ) ) );
				}
				var bad = res.rows.filter( function ( x ) { return ! x.ok; } ).length;
				previewEl.appendChild( h( 'div', 'wpulse-note' + ( bad ? ' warn' : ' info' ), '<b>' + res.valid + '</b> valid row' + ( res.valid === 1 ? '' : 's' ) + ( bad ? ', <b>' + bad + '</b> with errors (they will be skipped)' : '' ) + '.' ) );
				previewEl.appendChild( table( {
					rows: res.rows, limit: 200,
					columns: [
						{ key: 'line', label: 'Line', num: true },
						{ key: 'ok', label: 'Status', format: function ( v, row ) { return v ? '<span class="wpulse-badge green">ok</span>' : '<span class="wpulse-badge red" title="' + esc( row.error ) + '">' + esc( row.error ) + '</span>'; } },
						{ key: 'spend_date', label: 'Date', format: function ( v, row ) { return esc( v || ( row.input && row.input.spend_date ) || '' ); } },
						{ key: 'channel', label: 'Channel', format: function ( v, row ) { return esc( v || ( row.input && row.input.channel ) || '' ); } },
						{ key: 'amount', label: 'Amount', num: true, format: function ( v, row ) { return isNil( v ) ? esc( row.input && row.input.amount ) : money( v ); } },
						{ key: 'reported_revenue', label: 'Reported rev.', num: true, format: function ( v ) { return isNil( v ) ? '—' : money( v ); } },
						{ key: 'note', label: 'Note' }
					]
				} ) );
				importBtn.disabled = res.valid === 0;
			} ).catch( function ( e ) { previewBtn.disabled = false; toast( e.message, true ); } );
		} );
		importBtn.addEventListener( 'click', function () {
			importBtn.disabled = true;
			api( 'spend/bulk', { method: 'POST', body: { csv: textarea.value, replace: replaceCb.checked } } ).then( function ( res ) {
				toast( 'Imported ' + res.inserted + ' row' + ( res.inserted === 1 ? '' : 's' ) + ( res.replaced ? ', replaced ' + res.replaced : '' ) + ( res.skipped ? ', skipped ' + res.skipped : '' ) + '.' );
				textarea.value = '';
				previewEl.innerHTML = '';
				reportCache = {};
				return loadReport( true );
			} ).catch( function ( e ) { importBtn.disabled = false; toast( e.message, true ); } );
		} );
		impActions.appendChild( previewBtn );
		impActions.appendChild( importBtn );
		impForm.appendChild( impActions );
		impForm.appendChild( previewEl );
		imp.body.appendChild( impForm );
		grid.appendChild( imp );

		return grid;
	}

	function field( label, type, value ) {
		var f = h( 'div', 'wpulse-field', '<label>' + esc( label ) + '</label>' );
		var input = h( 'input' );
		input.type = type;
		if ( type === 'number' ) { input.step = 'any'; input.min = '0'; }
		if ( value !== undefined && value !== null ) { input.value = value; }
		f.appendChild( input );
		f.input = input;
		return f;
	}

	/* ======================================================================
	 * Customers
	 * ==================================================================== */

	function renderCustomers( el, r ) {
		var c = r.current, p = r.previous, t = c.totals, pt = p.totals, L = r.lifetime, S = L.stats, seq = L.sequence;

		var head = h( 'div', 'wpulse-card-sub', 'In this range' );
		head.style.margin = '0 0 6px';
		el.appendChild( head );
		var kpis = h( 'div', 'wpulse-kpis' );
		kpis.innerHTML = [
			kpi( { label: 'Customers', raw: t.customers, prev: pt.customers, fmt: num } ),
			kpi( { label: 'New', raw: t.new_customers, prev: pt.new_customers, fmt: num, help: 'new_customers' } ),
			kpi( { label: 'Returning', raw: t.returning_customers, prev: pt.returning_customers, fmt: num, help: 'returning' } ),
			kpi( { label: 'Repeat purchase rate', raw: t.repeat_rate, prev: pt.repeat_rate, fmt: pct, help: 'repeat_rate' } ),
			kpi( { label: 'Orders per customer', raw: t.orders_per_customer, prev: pt.orders_per_customer, fmt: function ( v ) { return num( v, 2 ); } } ),
			kpi( { label: 'Revenue per customer', raw: t.revenue_per_customer, prev: pt.revenue_per_customer, fmt: money } ),
			kpi( { label: 'Guest checkout share', raw: t.guest_share, prev: pt.guest_share, fmt: pct } )
		].join( '' );
		el.appendChild( kpis );

		var head2 = h( 'div', 'wpulse-card-sub', 'All time (every counted order)' );
		head2.style.margin = '0 0 6px';
		el.appendChild( head2 );
		var k2 = h( 'div', 'wpulse-kpis' );
		k2.innerHTML = [
			kpi( { label: 'Total customers', raw: S.customers, fmt: num } ),
			kpi( { label: 'Average LTV', raw: S.avg_ltv, fmt: money, help: 'ltv' } ),
			kpi( { label: 'Median LTV', raw: S.median_ltv, fmt: money, help: 'ltv' } ),
			kpi( { label: 'Orders per customer', raw: S.avg_orders, fmt: function ( v ) { return num( v, 2 ); } } ),
			kpi( { label: 'Active customers', raw: S.active, fmt: num, help: 'active', sub: 'ordered in last ' + L.active_days + ' days' } ),
			kpi( { label: 'Second-order rate', raw: S.customers ? S.reached_2 / S.customers : null, fmt: pct, help: 'second_order' } ),
			kpi( { label: 'Median days to 2nd order', raw: seq.median_days_to_second, fmt: function ( v ) { return isNil( v ) ? 'n/a' : num( v ) + ' days'; }, sub: 'customers acquired in the last 12 months' } )
		].join( '' );
		el.appendChild( k2 );

		var grid = h( 'div', 'wpulse-grid' );

		// New vs returning.
		var splitToggle = toggleGroup( [ [ 'orders', 'Orders' ], [ 'revenue', 'Revenue' ] ], state.customers.split, function ( v ) { state.customers.split = v; renderTab(); } );
		var nvr = card( 'New vs returning', { col: 4, actions: [ splitToggle ] } );
		var nvrEl = h( 'div' );
		var isRev = state.customers.split === 'revenue';
		C.donut( nvrEl, {
			slices: [
				{ label: 'New', value: isRev ? t.new_customer_revenue : t.new_customer_orders, color: C.colors.primary },
				{ label: 'Returning', value: isRev ? t.returning_revenue : ( t.orders - t.new_customer_orders ), color: C.colors.orders }
			],
			format: isRev ? money : num, center: { value: pct( t.orders ? ( isRev ? ( t.revenue > 0 ? t.returning_revenue / ( t.new_customer_revenue + t.returning_revenue ) : 0 ) : ( t.orders - t.new_customer_orders ) / t.orders ) : 0, 0 ), label: 'returning' }
		} );
		nvr.body.appendChild( nvrEl );
		nvr.body.appendChild( h( 'div', 'wpulse-note', 'Orders and revenue from customers acquired in this range vs customers who ordered before it.' ) );
		grid.appendChild( nvr );

		// New customers trend.
		var trend = card( 'New customers over time', { col: 8 } );
		var trEl = h( 'div', 'wpulse-chart' );
		var ser = [ { label: 'New customers', values: c.series.new_customers, color: C.colors.primary, area: true } ];
		if ( state.showCompare ) { ser.push( { label: 'Comparison', values: alignPrev( c.series.new_customers, p.series.new_customers ), color: C.colors.compare, dashed: true } ); }
		C.line( trEl, { labels: c.series.buckets, series: ser, format: num, xFormat: function ( k, i, full ) { return bucketLabel( k, r.range.granularity, full ); } } );
		trend.body.appendChild( trEl );
		grid.appendChild( trend );

		// Cohorts.
		var coh = card( 'Cohort retention', { col: 8, help: 'cohorts', sub: 'Share of each first-order-month cohort that ordered again N months later. Blank cells have not happened yet.' } );
		var cohorts = L.cohorts || [];
		if ( ! cohorts.some( function ( x ) { return x.size > 0; } ) ) {
			coh.body.innerHTML = '<div class="wpulse-empty">Not enough history yet. Cohorts fill in as customers come back.</div>';
		} else {
			var tw = h( 'div', 'wpulse-table-wrap' );
			var tbl = h( 'table', 'wpulse-table wpulse-cohort' );
			var hdr = '<tr><th>Cohort</th><th class="num">Customers</th>';
			for ( var k = 1; k <= 12; k++ ) { hdr += '<th class="num">M' + k + '</th>'; }
			hdr += '</tr>';
			var body = '';
			cohorts.forEach( function ( co ) {
				body += '<tr><td>' + esc( monthLabel( co.month ) ) + '</td><td class="num">' + esc( num( co.size ) ) + '</td>';
				co.rates.forEach( function ( rate ) {
					if ( rate === null || co.size === 0 ) { body += '<td class="cell na">·</td>'; return; }
					var a = Math.min( 1, rate * 2.5 );
					body += '<td class="cell" style="background:rgba(37,99,235,' + ( 0.06 + a * 0.6 ).toFixed( 2 ) + ');color:' + ( a > 0.55 ? '#fff' : '#111827' ) + '">' + ( rate * 100 ).toFixed( 0 ) + '%</td>';
				} );
				body += '</tr>';
			} );
			tbl.innerHTML = '<thead>' + hdr + '</thead><tbody>' + body + '</tbody>';
			tw.appendChild( tbl );
			coh.body.appendChild( tw );
		}
		grid.appendChild( coh );

		// Order sequence.
		var seqCard = card( 'Order sequence', { col: 4, sub: 'How many customers ever reach their 2nd, 3rd and 4th order.' } );
		var seqEl = h( 'div' );
		C.hbars( seqEl, {
			rows: [
				{ label: '1st order', value: seq.customers, sub: '100%' },
				{ label: '2nd order', value: seq.reached_2, sub: pct( seq.customers ? seq.reached_2 / seq.customers : 0 ) },
				{ label: '3rd order', value: seq.reached_3, sub: pct( seq.customers ? seq.reached_3 / seq.customers : 0 ) },
				{ label: '4th order', value: seq.reached_4, sub: pct( seq.customers ? seq.reached_4 / seq.customers : 0 ) }
			], format: num
		} );
		seqCard.body.appendChild( seqEl );
		if ( ! isNil( seq.avg_days_to_second ) ) {
			seqCard.body.appendChild( h( 'div', 'wpulse-note', 'Median <b>' + esc( num( seq.median_days_to_second ) ) + '</b> days and average <b>' + esc( num( seq.avg_days_to_second ) ) + '</b> days between first and second order (' + esc( num( seq.second_order_customers ) ) + ' customers acquired in the last 12 months who came back).' ) );
		}
		grid.appendChild( seqCard );

		// Top customers.
		var topRows = ( L.top_customers || [] ).map( function ( x ) { return Object.assign( {}, x, { display: x.name || x.email || x.key } ); } );
		var topTbl = table( {
			rows: topRows, sort: { key: 'revenue', dir: 'desc' },
			columns: [
				{ key: 'display', label: 'Customer', cls: 'name', format: function ( v, row ) {
					var url = cfg.ordersUrl + ( cfg.ordersUrl.indexOf( '?' ) >= 0 ? '&' : '?' ) + 's=' + encodeURIComponent( row.email || row.name );
					return '<a href="' + esc( url ) + '" target="_blank" rel="noopener">' + esc( v ) + '</a>' + ( row.email && row.name ? '<div class="wpulse-muted">' + esc( row.email ) + '</div>' : '' );
				} },
				{ key: 'orders', label: 'Orders', num: true, format: num },
				{ key: 'revenue', label: 'Lifetime revenue', num: true, format: money },
				{ key: 'first_order', label: 'First order', format: function ( v ) { return esc( v ? fmtDate( v.slice( 0, 10 ), true ) : '' ); } },
				{ key: 'last_order', label: 'Last order', format: function ( v ) { return esc( v ? fmtDate( v.slice( 0, 10 ), true ) : '' ); } },
				{ key: 'state', label: 'Location', format: function ( v, row ) { return esc( [ stateName( row.country, v ) !== 'Unknown' ? stateName( row.country, v ) : '', row.country ].filter( Boolean ).join( ', ' ) ); } }
			]
		} );
		var topCard = card( 'Top customers (lifetime)', { col: 12, actions: [ exportButton( topTbl, 'pulse-top-customers' ) ] } );
		topCard.body.appendChild( topTbl );
		grid.appendChild( topCard );

		el.appendChild( grid );
	}

	/* ======================================================================
	 * Products
	 * ==================================================================== */

	function renderProducts( el, r ) {
		var c = r.current, p = r.previous, t = c.totals, pt = p.totals;
		var kpis = h( 'div', 'wpulse-kpis' );
		kpis.innerHTML = [
			kpi( { label: 'Items sold', raw: t.items, prev: pt.items, fmt: num } ),
			kpi( { label: 'Items per order', raw: t.items_per_order, prev: pt.items_per_order, fmt: function ( v ) { return num( v, 2 ); } } ),
			kpi( { label: 'Discounts given', raw: t.discounts, prev: pt.discounts, fmt: money, inverse: true } ),
			kpi( { label: 'Discount rate', raw: t.discount_rate, prev: pt.discount_rate, fmt: pct, inverse: true, sub: 'discounts / (gross + discounts)' } ),
			kpi( { label: 'Refund rate', raw: t.refund_rate, prev: pt.refund_rate, fmt: pct, inverse: true, sub: 'refunds / gross sales' } )
		].join( '' );
		el.appendChild( kpis );

		var grid = h( 'div', 'wpulse-grid' );

		var prodRows = ( c.products || [] ).map( function ( x ) { return Object.assign( {}, x, { refund_rate: x.revenue > 0 ? x.refunded / x.revenue : 0, net: x.revenue - x.refunded } ); } );
		var prodTbl = table( {
			rows: prodRows, sort: { key: 'revenue', dir: 'desc' },
			columns: [
				{ key: 'name', label: 'Product', cls: 'name', format: function ( v, row ) { return esc( v ) + ( row.variation_id ? ' <span class="wpulse-muted">#' + row.variation_id + '</span>' : '' ); } },
				{ key: 'qty', label: 'Qty', num: true, format: num },
				{ key: 'orders', label: 'Orders', num: true, format: num },
				{ key: 'revenue', label: 'Revenue', num: true, format: money },
				{ key: 'refunded', label: 'Refunded', num: true, format: money },
				{ key: 'refund_rate', label: 'Refund rate', num: true, format: pct },
				{ key: 'net', label: 'Net', num: true, format: money }
			]
		} );
		var prodCard = card( 'Top products', { col: 12, sub: 'Line revenue excludes tax and is after line discounts. Refunded amounts come from refund line items dated in the range.', actions: [ exportButton( prodTbl, 'pulse-products-' + r.range.start + '-' + r.range.end ) ] } );
		prodCard.body.appendChild( prodTbl );
		grid.appendChild( prodCard );

		var catCard = card( 'Revenue by category', { col: 6, sub: 'Products in several categories count in each.' } );
		var catEl = h( 'div' );
		C.hbars( catEl, { rows: ( c.categories || [] ).slice( 0, 15 ).map( function ( x ) { return { label: x.name, value: x.revenue, sub: num( x.qty ) + ' items' }; } ), format: money, empty: 'No category data.' } );
		catCard.body.appendChild( catEl );
		grid.appendChild( catCard );

		var brandCard = card( 'Revenue by brand', { col: 6 } );
		var brandEl = h( 'div' );
		C.hbars( brandEl, { rows: ( c.brands || [] ).slice( 0, 15 ).map( function ( x ) { return { label: x.name, value: x.revenue, sub: num( x.qty ) + ' items' }; } ), format: money, empty: 'No brand data. Brands appear when products use the Brands taxonomy (WooCommerce 9.6+).' } );
		brandCard.body.appendChild( brandEl );
		grid.appendChild( brandCard );

		var coupTbl = table( {
			rows: ( c.coupons || [] ), sort: { key: 'discount', dir: 'desc' }, empty: 'No coupons used in this range.',
			columns: [
				{ key: 'code', label: 'Coupon' },
				{ key: 'orders', label: 'Orders', num: true, format: num },
				{ key: 'discount', label: 'Discount given', num: true, format: money },
				{ key: 'revenue', label: 'Order revenue', num: true, format: money },
				{ key: 'cost_share', label: 'Discount / revenue', num: true, sort: false, format: function ( v, row ) { return row.revenue > 0 ? pct( row.discount / row.revenue ) : 'n/a'; } }
			]
		} );
		var coupCard = card( 'Coupons', { col: 12, actions: [ exportButton( coupTbl, 'pulse-coupons-' + r.range.start + '-' + r.range.end ) ] } );
		coupCard.body.appendChild( coupTbl );
		grid.appendChild( coupCard );

		var payTbl = table( {
			rows: c.payment_methods, sort: { key: 'revenue', dir: 'desc' },
			columns: [ { key: 'name', label: 'Payment method' }, { key: 'orders', label: 'Orders', num: true, format: num }, { key: 'revenue', label: 'Revenue', num: true, format: money }, { key: 'share', label: 'Share', num: true, format: pct }, { key: 'aov', label: 'AOV', num: true, format: money } ]
		} );
		var payCard = card( 'Payment methods', { col: 6 } );
		payCard.body.appendChild( payTbl );
		grid.appendChild( payCard );

		var brandRows = c.payment_brands || [];
		if ( brandRows.length && ( brandRows.length !== c.payment_methods.length || brandRows.some( function ( b, i ) { return b.name !== c.payment_methods[ i ].name; } ) ) ) {
			var brandTbl = table( {
				rows: brandRows, sort: { key: 'revenue', dir: 'desc' },
				columns: [ { key: 'name', label: 'Card brand / wallet' }, { key: 'orders', label: 'Orders', num: true, format: num }, { key: 'revenue', label: 'Revenue', num: true, format: money }, { key: 'share', label: 'Share', num: true, format: pct }, { key: 'aov', label: 'AOV', num: true, format: money } ]
			} );
			var brandCard = card( 'Card brands and wallets', { col: 6, sub: 'Derived from the payment title on each order.' } );
			brandCard.body.appendChild( brandTbl );
			grid.appendChild( brandCard );
		}

		var shipRows = ( c.shipping_methods || [] ).map( function ( x ) { return Object.assign( {}, x, { share: t.orders > 0 ? x.orders / t.orders : 0 } ); } );
		var shipTbl = table( {
			rows: shipRows, sort: { key: 'orders', dir: 'desc' }, empty: 'No shipping lines in this range.',
			columns: [ { key: 'name', label: 'Shipping method' }, { key: 'orders', label: 'Orders', num: true, format: num }, { key: 'share', label: 'Share', num: true, format: pct }, { key: 'cost', label: 'Shipping charged', num: true, format: money } ]
		} );
		var shipCard = card( 'Shipping methods', { col: 6 } );
		shipCard.body.appendChild( shipTbl );
		grid.appendChild( shipCard );

		el.appendChild( grid );
	}

	/* ======================================================================
	 * Settings
	 * ==================================================================== */

	function renderSettings( el ) {
		var wrap = h( 'div' );
		el.appendChild( wrap );
		wrap.innerHTML = '<div class="wpulse-card"><div class="wpulse-skeleton" style="width:40%"></div><div class="wpulse-skeleton"></div></div>';
		var load = state.diag ? Promise.resolve( state.diag ) : api( 'settings' ).then( function ( d ) { state.diag = d.diagnostics; settings = d.settings; cfg.settings = d.settings; return d.diagnostics; } );
		load.then( function ( diag ) {
			wrap.innerHTML = '';
			var grid = h( 'div', 'wpulse-grid' );
			var s = settings;

			// Measurement.
			var measSub = s.setup_complete
				? 'These answers come from the setup quiz. Change them here or run the quiz again.'
				: 'The setup quiz is a two-minute walkthrough that defines what counts as revenue, which order statuses count as a sale, your paid channels, fixed marketing costs, margin and targets. It has not been run yet, so the defaults below apply.';
			var meas = card( 'Measurement', { col: 6, sub: measSub, actions: [ button( s.setup_complete ? 'Run the setup quiz again' : 'Run the setup quiz', s.setup_complete ? 'small' : 'small primary', openQuiz ) ] } );
			var f = h( 'div', 'wpulse-form' );
			var basisSel = selectField( 'Revenue basis', [ [ 'net', 'Net sales (totals minus tax, shipping, refunds)' ], [ 'gross', 'Gross sales (totals minus refunds)' ] ], s.revenue_basis );
			f.appendChild( basisSel );
			var stWrap = h( 'div', 'wpulse-field', '<label>Order statuses that count as a sale</label>' );
			var chips = h( 'div', 'wpulse-chips' );
			var chosen = ( s.statuses || [] ).slice();
			Object.keys( diag.statuses || {} ).forEach( function ( code ) {
				var chip = h( 'button', 'wpulse-chip' + ( chosen.indexOf( code ) >= 0 ? ' is-active' : '' ), esc( diag.statuses[ code ] ) );
				chip.type = 'button';
				chip.addEventListener( 'click', function () {
					var i = chosen.indexOf( code );
					if ( i >= 0 ) { chosen.splice( i, 1 ); } else { chosen.push( code ); }
					chip.classList.toggle( 'is-active', chosen.indexOf( code ) >= 0 );
				} );
				chips.appendChild( chip );
			} );
			stWrap.appendChild( chips );
			f.appendChild( stWrap );
			var locSel = selectField( 'Location source for states and countries', [ [ 'shipping', 'Shipping address (billing as fallback)' ], [ 'billing', 'Billing address' ] ], s.location_source );
			f.appendChild( locSel );
			var row = h( 'div', 'wpulse-form-row' );
			var marginF = field( 'Gross margin % (after COGS)', 'number', s.gross_margin || '' );
			var feePctF = field( 'Payment fee %', 'number', s.fee_percent || '' );
			var feeFixF = field( 'Payment fee fixed per order', 'number', s.fee_fixed || '' );
			row.appendChild( marginF ); row.appendChild( feePctF ); row.appendChild( feeFixF );
			f.appendChild( row );
			var row2 = h( 'div', 'wpulse-form-row' );
			var merSel = selectField( 'Show MER as', [ [ 'ratio', 'Ratio (4.2x)' ], [ 'percent', 'Spend % of revenue (23.8%)' ] ], s.mer_display );
			var tMer = field( 'Target MER (ratio)', 'number', s.target_mer || '' );
			var tRoas = field( 'Target blended ROAS', 'number', s.target_roas || '' );
			row2.appendChild( merSel ); row2.appendChild( tMer ); row2.appendChild( tRoas );
			f.appendChild( row2 );
			var row3 = h( 'div', 'wpulse-form-row' );
			var activeF = field( 'Active customer window (days)', 'number', s.active_days );
			var cmpSel = selectField( 'Default comparison', [ [ 'previous', 'Previous period' ], [ 'year', 'Same period last year' ] ], s.compare_default );
			row3.appendChild( activeF ); row3.appendChild( cmpSel );
			f.appendChild( row3 );
			var chanWrap = h( 'div', 'wpulse-field', '<label>Paid channels (comma separated)</label>' );
			var chanIn = h( 'input' ); chanIn.type = 'text'; chanIn.value = ( s.channels || [] ).join( ', ' );
			chanWrap.appendChild( chanIn );
			f.appendChild( chanWrap );
			var fixedWrap = h( 'div', 'wpulse-field', '<label>Fixed monthly marketing costs</label>' );
			var fixedRows = fixedCostEditor( ( s.fixed_costs || [] ).slice() );
			fixedWrap.appendChild( fixedRows );
			f.appendChild( fixedWrap );
			var saveMeas = button( 'Save measurement settings', 'primary', function () {
				saveSettings( {
					revenue_basis: basisSel.select.value,
					statuses: chosen,
					location_source: locSel.select.value,
					gross_margin: marginF.input.value,
					fee_percent: feePctF.input.value,
					fee_fixed: feeFixF.input.value,
					mer_display: merSel.select.value,
					target_mer: tMer.input.value,
					target_roas: tRoas.input.value,
					active_days: activeF.input.value,
					compare_default: cmpSel.select.value,
					channels: chanIn.value.split( ',' ).map( function ( x ) { return x.trim(); } ).filter( Boolean ),
					fixed_costs: fixedRows.collect()
				}, saveMeas );
			} );
			f.appendChild( saveMeas );
			meas.body.appendChild( f );
			grid.appendChild( meas );

			// Access + digest + data.
			var right = h( 'div', 'wpulse-col-6' );
			var access = card( 'Who can view', { sub: 'Administrators and Shop Managers always can.' } );
			var roleOpts = [ [ '', 'No extra role' ] ].concat( Object.keys( diag.roles || {} ).map( function ( k ) { return [ k, diag.roles[ k ] ]; } ) );
			var roleSel = selectField( 'Additional role', roleOpts, s.extra_role || '' );
			var saveRole = button( 'Save', 'primary', function () { saveSettings( { extra_role: roleSel.select.value }, saveRole ); } );
			var accRow = h( 'div', 'wpulse-form-row inline' );
			accRow.appendChild( roleSel );
			accRow.appendChild( saveRole );
			access.body.appendChild( accRow );
			right.appendChild( access );

			var dig = card( 'Weekly snapshot email', { sub: 'Every Monday at 07:00 site time: last 7 days vs the 7 before.' + ( diag.digest_next_run ? ' Next run: ' + diag.digest_next_run + '.' : '' ) } );
			var digForm = h( 'div', 'wpulse-form' );
			var enWrap = h( 'label', 'wpulse-check' );
			var enCb = h( 'input' ); enCb.type = 'checkbox'; enCb.checked = ! ! s.digest_enabled;
			enWrap.appendChild( enCb ); enWrap.appendChild( document.createTextNode( ' Send the weekly snapshot' ) );
			digForm.appendChild( enWrap );
			var recF = field( 'Recipients (comma separated)', 'text', ( s.digest_recipients || [] ).join( ', ' ) );
			digForm.appendChild( recF );
			var digActions = h( 'div', 'wpulse-form-actions' );
			var saveDig = button( 'Save', 'primary', function () { saveSettings( { digest_enabled: enCb.checked, digest_recipients: recF.input.value }, saveDig, true ); } );
			var testDig = button( 'Send me a test', '', function () {
				testDig.disabled = true;
				api( 'digest/test', { method: 'POST', body: {} } ).then( function ( res ) { testDig.disabled = false; toast( res.ok ? 'Test sent to ' + res.to.join( ', ' ) : 'wp_mail() reported a failure. Check your mail configuration.', ! res.ok ); } ).catch( function ( e ) { testDig.disabled = false; toast( e.message, true ); } );
			} );
			digActions.appendChild( saveDig ); digActions.appendChild( testDig );
			digForm.appendChild( digActions );
			dig.body.appendChild( digForm );
			right.appendChild( dig );
			dig.style.marginTop = '14px';

			var data = card( 'Data' );
			var dForm = h( 'div', 'wpulse-form' );
			var clearBtn = button( '&#8635; Clear cached reports', '', function () {
				clearBtn.disabled = true;
				api( 'cache/clear', { method: 'POST', body: {} } ).then( function () { reportCache = {}; state.report = null; clearBtn.disabled = false; toast( 'Cache cleared.' ); } ).catch( function ( e ) { clearBtn.disabled = false; toast( e.message, true ); } );
			} );
			var clearRow = h( 'div', 'wpulse-form-actions' );
			clearRow.appendChild( clearBtn );
			clearRow.appendChild( h( 'span', 'wpulse-muted', 'Reports recompute on the next load.' ) );
			dForm.appendChild( clearRow );
			var rmWrap = h( 'label', 'wpulse-check' );
			var rmCb = h( 'input' ); rmCb.type = 'checkbox'; rmCb.checked = ! ! s.remove_data_on_uninstall;
			rmCb.addEventListener( 'change', function () { saveSettings( { remove_data_on_uninstall: rmCb.checked }, null, true ); } );
			rmWrap.appendChild( rmCb ); rmWrap.appendChild( document.createTextNode( ' Remove settings and the spend ledger when the plugin is deleted' ) );
			dForm.appendChild( rmWrap );
			data.body.appendChild( dForm );
			data.style.marginTop = '14px';
			right.appendChild( data );

			var conn = card( 'Connectors', { sub: 'The Woo Pulse Connect service pulls spend from ad platforms into the ledger. Leave the URL empty if you do not run one.' } );
			var cForm = h( 'div', 'wpulse-form' );
			var urlF = field( 'Service URL (https)', 'text', s.connector_url || '' );
			urlF.input.placeholder = 'https://connect.example.com';
			cForm.appendChild( urlF );
			var keyRow = h( 'div', 'wpulse-form-row inline' );
			var keyF = field( 'Store key', 'text', s.store_key_masked || '(generated on first use)' );
			keyF.input.readOnly = true;
			keyRow.appendChild( keyF );
			var regen = button( 'Regenerate', 'small', function () {
				if ( ! window.confirm( 'Regenerate the store key? The service will stop accepting this store until you register again from the Marketing tab.' ) ) { return; }
				regen.disabled = true;
				api( 'connector/regenerate-key', { method: 'POST', body: {} } ).then( function ( res ) { regen.disabled = false; keyF.input.value = res.store_key_masked; toast( 'New store key generated. Re-register under Marketing.' ); } ).catch( function ( e ) { regen.disabled = false; toast( e.message, true ); } );
			} );
			keyRow.appendChild( regen );
			cForm.appendChild( keyRow );
			var keepWrap = h( 'label', 'wpulse-check' );
			var keepCb = h( 'input' ); keepCb.type = 'checkbox'; keepCb.checked = s.keep_synced_on_disconnect !== false;
			keepWrap.appendChild( keepCb ); keepWrap.appendChild( document.createTextNode( ' Keep synced spend rows when a platform is disconnected' ) );
			cForm.appendChild( keepWrap );
			var saveConn = button( 'Save connector settings', 'primary', function () { saveSettings( { connector_url: urlF.input.value, keep_synced_on_disconnect: keepCb.checked }, saveConn, true ); } );
			cForm.appendChild( saveConn );
			conn.body.appendChild( cForm );
			conn.style.marginTop = '14px';
			right.appendChild( conn );

			var dg = card( 'Diagnostics' );
			var dgEl = h( 'div', 'wpulse-diag' );
			dgEl.innerHTML = [
				[ 'Order storage', diag.storage === 'hpos' ? 'High-Performance Order Storage' : 'Posts (legacy)' ],
				[ 'Timezone', diag.timezone ], [ 'Currency', diag.currency ],
				[ 'Orders in last 30 days', num( diag.orders_last_30 ) ],
				[ 'Order attribution data', diag.has_attribution ? 'found' : 'not found' ],
				[ 'COGS on orders', diag.has_cogs ? 'found' : 'not found' ],
				[ 'WooCommerce', diag.woocommerce || '?' ], [ 'Woo Pulse', diag.plugin_version ]
			].map( function ( x ) { return '<div>' + esc( x[ 0 ] ) + '<b>' + esc( x[ 1 ] ) + '</b></div>'; } ).join( '' );
			dg.body.appendChild( dgEl );
			dg.style.marginTop = '14px';
			right.appendChild( dg );
			grid.appendChild( right );

			wrap.appendChild( grid );
		} ).catch( function ( e ) {
			wrap.innerHTML = '<div class="wpulse-error">' + esc( e.message ) + '</div>';
		} );
	}

	function selectField( label, options, value ) {
		var f = h( 'div', 'wpulse-field', '<label>' + esc( label ) + '</label>' );
		var sel = h( 'select' );
		options.forEach( function ( o ) {
			var op = h( 'option', null, esc( o[ 1 ] ) );
			op.value = o[ 0 ];
			if ( o[ 0 ] === value ) { op.selected = true; }
			sel.appendChild( op );
		} );
		f.appendChild( sel );
		f.select = sel;
		return f;
	}

	// A drawn cross centres exactly inside the round button; the × text glyph sits low on its baseline.
	var ICON_X = '<svg viewBox="0 0 10 10" width="10" height="10" aria-hidden="true" focusable="false"><path d="M1.5 1.5l7 7M8.5 1.5l-7 7" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" fill="none"/></svg>';

	function fixedCostEditor( rows ) {
		var wrap = h( 'div', 'wpulse-rows' );
		function draw() {
			wrap.innerHTML = '';
			rows.forEach( function ( r, i ) {
				var row = h( 'div', 'row' );
				var l = h( 'input' ); l.type = 'text'; l.placeholder = 'e.g. Agency retainer'; l.value = r.label || '';
				var a = h( 'input' ); a.type = 'number'; a.step = 'any'; a.min = '0'; a.placeholder = 'per month'; a.value = r.amount || '';
				l.addEventListener( 'input', function () { r.label = l.value; } );
				a.addEventListener( 'input', function () { r.amount = a.value; } );
				var x = button( ICON_X, 'round', function () { rows.splice( i, 1 ); draw(); } );
				x.title = 'Remove';
				row.appendChild( l ); row.appendChild( a ); row.appendChild( x );
				wrap.appendChild( row );
			} );
			var add = button( '+ Add cost', 'small link', function () { rows.push( { label: '', amount: '' } ); draw(); } );
			wrap.appendChild( add );
		}
		draw();
		wrap.collect = function () { return rows.filter( function ( r ) { return r.label && Number( r.amount ) > 0; } ).map( function ( r ) { return { label: r.label, amount: Number( r.amount ) }; } ); };
		return wrap;
	}

	function saveSettings( body, btn, quiet ) {
		if ( btn ) { btn.disabled = true; }
		return api( 'settings', { method: 'POST', body: body } ).then( function ( res ) {
			settings = res.settings;
			cfg.settings = res.settings;
			state.diag = res.diagnostics;
			reportCache = {};
			if ( btn ) { btn.disabled = false; }
			toast( 'Settings saved.' );
			if ( ! quiet ) { state.report = null; }
			return res;
		} ).catch( function ( e ) { if ( btn ) { btn.disabled = false; } toast( e.message, true ); } );
	}

	/* ======================================================================
	 * Setup quiz
	 * ==================================================================== */

	function openQuiz() {
		var draft = JSON.parse( JSON.stringify( settings ) );
		if ( ! draft.channels || ! draft.channels.length ) { draft.channels = [ 'Meta', 'Google' ]; }
		if ( ! draft.fixed_costs ) { draft.fixed_costs = []; }
		var statusesAll = ( state.diag && state.diag.statuses ) || cfg.statuses || {};
		var step = 0;
		var overlay = h( 'div', 'wpulse-modal' );
		var box = h( 'div', 'wpulse-modal-box' );
		overlay.appendChild( box );
		document.body.appendChild( overlay );
		var collect = null;

		var steps = [
			{
				title: 'What counts as revenue?',
				sub: 'Everything on the dashboard, including ROAS and MER, uses this number.',
				render: function ( body ) {
					var ch = choice( [
						[ 'net', 'Net sales (recommended)', 'Order totals minus tax, shipping and refunds. What you actually keep for product. Most MER guides use this.' ],
						[ 'gross', 'Gross sales', 'Order totals as charged, minus refunds. Matches what the payment processor deposits, before tax and shipping are passed through.' ]
					], draft.revenue_basis );
					body.appendChild( ch );
					collect = function () { draft.revenue_basis = ch.value(); };
				}
			},
			{
				title: 'Which order statuses count as a sale?',
				sub: 'Refunded orders are usually included so that gross sales and refunds reconcile. Pending, failed and cancelled are usually excluded.',
				render: function ( body ) {
					var chips = h( 'div', 'wpulse-chips' );
					var chosen = ( draft.statuses || [] ).slice();
					Object.keys( statusesAll ).forEach( function ( code ) {
						var chip = h( 'button', 'wpulse-chip' + ( chosen.indexOf( code ) >= 0 ? ' is-active' : '' ), esc( statusesAll[ code ] ) );
						chip.type = 'button';
						chip.addEventListener( 'click', function () {
							var i = chosen.indexOf( code );
							if ( i >= 0 ) { chosen.splice( i, 1 ); } else { chosen.push( code ); }
							chip.classList.toggle( 'is-active', chosen.indexOf( code ) >= 0 );
						} );
						chips.appendChild( chip );
					} );
					body.appendChild( chips );
					collect = function () { if ( chosen.length ) { draft.statuses = chosen; } };
				}
			},
			{
				title: 'Which paid channels do you run?',
				sub: 'These become the options in the spend ledger. You can add more later.',
				render: function ( body ) {
					var chips = h( 'div', 'wpulse-chips' );
					var chosen = draft.channels.slice();
					var all = ( cfg.channelPresets || [] ).slice();
					chosen.forEach( function ( c ) { if ( all.indexOf( c ) < 0 ) { all.push( c ); } } );
					function drawChips() {
						chips.innerHTML = '';
						all.forEach( function ( c ) {
							var chip = h( 'button', 'wpulse-chip' + ( chosen.indexOf( c ) >= 0 ? ' is-active' : '' ), esc( c ) );
							chip.type = 'button';
							chip.addEventListener( 'click', function () {
								var i = chosen.indexOf( c );
								if ( i >= 0 ) { chosen.splice( i, 1 ); } else { chosen.push( c ); }
								chip.classList.toggle( 'is-active', chosen.indexOf( c ) >= 0 );
							} );
							chips.appendChild( chip );
						} );
					}
					drawChips();
					body.appendChild( chips );
					var custom = field( 'Add another channel', 'text', '' );
					custom.input.placeholder = 'e.g. Reddit, Podcasts';
					custom.input.addEventListener( 'keydown', function ( ev ) {
						if ( ev.key === 'Enter' && custom.input.value.trim() ) {
							ev.preventDefault();
							var v = custom.input.value.trim();
							if ( all.indexOf( v ) < 0 ) { all.push( v ); }
							if ( chosen.indexOf( v ) < 0 ) { chosen.push( v ); }
							custom.input.value = '';
							drawChips();
						}
					} );
					custom.style.marginTop = '12px';
					body.appendChild( custom );
					collect = function () {
						if ( custom.input.value.trim() && chosen.indexOf( custom.input.value.trim() ) < 0 ) { chosen.push( custom.input.value.trim() ); }
						draft.channels = chosen;
					};
				}
			},
			{
				title: 'What else do you spend on marketing each month?',
				sub: 'MER includes these; blended ROAS does not. Enter monthly amounts and they are prorated per day. Leave empty if none.',
				render: function ( body ) {
					if ( ! draft.fixed_costs.length ) {
						draft.fixed_costs = [ { label: 'Agency / freelancers', amount: '' }, { label: 'Software (email, SMS, analytics)', amount: '' }, { label: 'Creative production', amount: '' } ];
					}
					var ed = fixedCostEditor( draft.fixed_costs );
					body.appendChild( ed );
					collect = function () { draft.fixed_costs = ed.collect(); };
				}
			},
			{
				title: 'Do you know your blended gross margin?',
				sub: cfg.hasCogs ? 'This store records cost of goods on orders, so profit uses real COGS. A margin % here is only a fallback for orders without it.' : 'Average margin after cost of goods, before marketing and overhead. Optional, but it unlocks break-even ROAS, contribution and estimated profit.',
				render: function ( body ) {
					var row = h( 'div', 'wpulse-form-row' );
					var m = field( 'Gross margin %', 'number', draft.gross_margin || '' );
					m.input.placeholder = 'e.g. 55';
					row.appendChild( m );
					body.appendChild( row );
					body.appendChild( h( 'div', 'wpulse-note', 'A 55% margin means break-even blended ROAS is 1 / 0.55 = 1.82x. Below that, every ad dollar loses money before fixed costs.' ) );
					collect = function () { draft.gross_margin = m.input.value; };
				}
			},
			{
				title: 'Payment processing fees',
				sub: 'Optional. Lets the profit estimate subtract card fees. Stripe and PayPal are typically 2.9% + 0.30.',
				render: function ( body ) {
					var row = h( 'div', 'wpulse-form-row' );
					var p1 = field( 'Percent of order total', 'number', draft.fee_percent || '' ); p1.input.placeholder = '2.9';
					var p2 = field( 'Fixed per order', 'number', draft.fee_fixed || '' ); p2.input.placeholder = '0.30';
					row.appendChild( p1 ); row.appendChild( p2 );
					body.appendChild( row );
					collect = function () { draft.fee_percent = p1.input.value; draft.fee_fixed = p2.input.value; };
				}
			},
			{
				title: 'How do you like MER shown?',
				sub: 'Same number, two conventions.',
				render: function ( body ) {
					var ch = choice( [
						[ 'ratio', 'Ratio', 'Revenue ÷ total marketing cost, e.g. 4.2x. Higher is better.' ],
						[ 'percent', 'Percent of revenue', 'Total marketing cost ÷ revenue, e.g. 23.8%. Lower is better. Common in finance-led teams.' ]
					], draft.mer_display );
					body.appendChild( ch );
					collect = function () { draft.mer_display = ch.value(); };
				}
			},
			{
				title: 'Targets',
				sub: 'Optional. Tiles turn green, amber or red against these. Leave empty to skip.',
				render: function ( body ) {
					var row = h( 'div', 'wpulse-form-row' );
					var t1 = field( 'Target MER (ratio)', 'number', draft.target_mer || '' ); t1.input.placeholder = 'e.g. 4';
					var t2 = field( 'Target blended ROAS', 'number', draft.target_roas || '' ); t2.input.placeholder = 'e.g. 3';
					row.appendChild( t1 ); row.appendChild( t2 );
					body.appendChild( row );
					collect = function () { draft.target_mer = t1.input.value; draft.target_roas = t2.input.value; };
				}
			}
		];

		function choice( options, value ) {
			var wrap = h( 'div', 'wpulse-choice' );
			var current = value;
			options.forEach( function ( o ) {
				var lab = h( 'label', o[ 0 ] === current ? 'is-active' : '' );
				var inp = h( 'input' ); inp.type = 'radio'; inp.name = 'wpulse-choice'; inp.value = o[ 0 ]; inp.checked = o[ 0 ] === current;
				inp.addEventListener( 'change', function () {
					current = o[ 0 ];
					Array.prototype.forEach.call( wrap.children, function ( c ) { c.classList.toggle( 'is-active', c.querySelector( 'input' ).value === current ); } );
				} );
				lab.appendChild( inp );
				lab.appendChild( h( 'div', null, '<div class="t">' + esc( o[ 1 ] ) + '</div><div class="d">' + esc( o[ 2 ] ) + '</div>' ) );
				wrap.appendChild( lab );
			} );
			wrap.value = function () { return current; };
			return wrap;
		}

		function drawStep() {
			box.innerHTML = '';
			var s = steps[ step ];
			var prog = h( 'div', 'wpulse-steps' );
			steps.forEach( function ( x, i ) { prog.appendChild( h( 'i', i <= step ? 'done' : '' ) ); } );
			box.appendChild( prog );
			box.appendChild( h( 'h2', null, esc( s.title ) ) );
			box.appendChild( h( 'div', 'wpulse-step-sub', esc( s.sub ) ) );
			var body = h( 'div' );
			s.render( body );
			box.appendChild( body );
			var foot = h( 'div', 'wpulse-modal-foot' );
			foot.appendChild( button( 'Cancel', 'link', function () { overlay.remove(); } ) );
			var right = h( 'div', 'right' );
			if ( step > 0 ) { right.appendChild( button( 'Back', '', function () { collect(); step--; drawStep(); } ) ); }
			var isLast = step === steps.length - 1;
			var next = button( isLast ? 'Save and finish' : 'Next', 'primary', function () {
				collect();
				if ( ! isLast ) { step++; drawStep(); return; }
				next.disabled = true;
				draft.setup_complete = true;
				api( 'settings', { method: 'POST', body: draft } ).then( function ( res ) {
					settings = res.settings;
					cfg.settings = res.settings;
					state.diag = res.diagnostics;
					overlay.remove();
					toast( 'Setup saved. Log ad spend on the Marketing tab to fill in ROAS and MER.' );
					reportCache = {};
					return loadReport( true );
				} ).catch( function ( e ) { next.disabled = false; toast( e.message, true ); } );
			} );
			right.appendChild( next );
			foot.appendChild( right );
			box.appendChild( foot );
		}
		drawStep();
	}

	/* ======================================================================
	 * Boot
	 * ==================================================================== */

	if ( ! root ) { return; }
	renderShell();
	bindHelpTips();
	// Returning from an OAuth flow on the connector service.
	var connectedParam = ( window.location.search.match( /[?&]connected=([a-z]+)/ ) || [] )[ 1 ];
	if ( connectedParam ) {
		toast( ( SOURCE_LABELS[ connectedParam ] || connectedParam ) + ' connected. The first sync is running.' );
		try { window.history.replaceState( null, '', window.location.pathname + '?page=woo-pulse#marketing' ); } catch ( e ) { /* ignore */ }
		state.tab = 'marketing';
	}
	window.addEventListener( 'hashchange', function () {
		var t = ( window.location.hash || '' ).replace( '#', '' );
		if ( TABS.some( function ( x ) { return x[ 0 ] === t; } ) && t !== state.tab ) { setTab( t ); }
	} );
	loadReport();
})();
