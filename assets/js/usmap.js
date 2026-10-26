/* global window, document, fetch, d3, topojson, WooPulseCharts */
/**
 * US state choropleth. Uses D3 + us-atlas (loaded from a CDN) when available and falls back to a
 * self-contained tile-grid cartogram when the libraries or the atlas cannot be loaded.
 */
(function () {
	'use strict';

	var C = window.WooPulseCharts;

	var FIPS = {
		'01': 'AL', '02': 'AK', '04': 'AZ', '05': 'AR', '06': 'CA', '08': 'CO', '09': 'CT', '10': 'DE', '11': 'DC', '12': 'FL',
		'13': 'GA', '15': 'HI', '16': 'ID', '17': 'IL', '18': 'IN', '19': 'IA', '20': 'KS', '21': 'KY', '22': 'LA', '23': 'ME',
		'24': 'MD', '25': 'MA', '26': 'MI', '27': 'MN', '28': 'MS', '29': 'MO', '30': 'MT', '31': 'NE', '32': 'NV', '33': 'NH',
		'34': 'NJ', '35': 'NM', '36': 'NY', '37': 'NC', '38': 'ND', '39': 'OH', '40': 'OK', '41': 'OR', '42': 'PA', '44': 'RI',
		'45': 'SC', '46': 'SD', '47': 'TN', '48': 'TX', '49': 'UT', '50': 'VT', '51': 'VA', '53': 'WA', '54': 'WV', '55': 'WI',
		'56': 'WY', '72': 'PR'
	};

	var NAMES = {
		AL: 'Alabama', AK: 'Alaska', AZ: 'Arizona', AR: 'Arkansas', CA: 'California', CO: 'Colorado', CT: 'Connecticut', DE: 'Delaware',
		DC: 'District of Columbia', FL: 'Florida', GA: 'Georgia', HI: 'Hawaii', ID: 'Idaho', IL: 'Illinois', IN: 'Indiana', IA: 'Iowa',
		KS: 'Kansas', KY: 'Kentucky', LA: 'Louisiana', ME: 'Maine', MD: 'Maryland', MA: 'Massachusetts', MI: 'Michigan', MN: 'Minnesota',
		MS: 'Mississippi', MO: 'Missouri', MT: 'Montana', NE: 'Nebraska', NV: 'Nevada', NH: 'New Hampshire', NJ: 'New Jersey',
		NM: 'New Mexico', NY: 'New York', NC: 'North Carolina', ND: 'North Dakota', OH: 'Ohio', OK: 'Oklahoma', OR: 'Oregon',
		PA: 'Pennsylvania', RI: 'Rhode Island', SC: 'South Carolina', SD: 'South Dakota', TN: 'Tennessee', TX: 'Texas', UT: 'Utah',
		VT: 'Vermont', VA: 'Virginia', WA: 'Washington', WV: 'West Virginia', WI: 'Wisconsin', WY: 'Wyoming', PR: 'Puerto Rico',
		AA: 'Armed Forces Americas', AE: 'Armed Forces Europe', AP: 'Armed Forces Pacific', AS: 'American Samoa', GU: 'Guam',
		MP: 'Northern Mariana Islands', UM: 'US Minor Outlying Islands', VI: 'US Virgin Islands'
	};

	// Tile-grid layout (column, row), in the style of the NPR state grid.
	var GRID = {
		AK: [ 0, 0 ], ME: [ 10, 0 ],
		VT: [ 9, 1 ], NH: [ 10, 1 ],
		WA: [ 0, 2 ], ID: [ 1, 2 ], MT: [ 2, 2 ], ND: [ 3, 2 ], MN: [ 4, 2 ], IL: [ 5, 2 ], WI: [ 6, 2 ], MI: [ 7, 2 ], NY: [ 8, 2 ], RI: [ 9, 2 ], MA: [ 10, 2 ],
		OR: [ 0, 3 ], NV: [ 1, 3 ], WY: [ 2, 3 ], SD: [ 3, 3 ], IA: [ 4, 3 ], IN: [ 5, 3 ], OH: [ 6, 3 ], PA: [ 7, 3 ], NJ: [ 8, 3 ], CT: [ 9, 3 ],
		CA: [ 0, 4 ], UT: [ 1, 4 ], CO: [ 2, 4 ], NE: [ 3, 4 ], MO: [ 4, 4 ], KY: [ 5, 4 ], WV: [ 6, 4 ], VA: [ 7, 4 ], MD: [ 8, 4 ], DE: [ 9, 4 ],
		AZ: [ 1, 5 ], NM: [ 2, 5 ], KS: [ 3, 5 ], AR: [ 4, 5 ], TN: [ 5, 5 ], NC: [ 6, 5 ], SC: [ 7, 5 ], DC: [ 8, 5 ],
		OK: [ 3, 6 ], LA: [ 4, 6 ], MS: [ 5, 6 ], AL: [ 6, 6 ], GA: [ 7, 6 ],
		HI: [ 0, 7 ], TX: [ 3, 7 ], FL: [ 8, 7 ], PR: [ 10, 7 ]
	};

	var atlasPromise = null;
	function loadAtlas( url ) {
		if ( atlasPromise ) {
			return atlasPromise;
		}
		if ( typeof d3 === 'undefined' || typeof topojson === 'undefined' || typeof fetch === 'undefined' ) {
			atlasPromise = Promise.reject( new Error( 'map libraries unavailable' ) );
			return atlasPromise;
		}
		atlasPromise = fetch( url, { mode: 'cors', credentials: 'omit' } ).then( function ( r ) {
			if ( ! r.ok ) {
				throw new Error( 'atlas ' + r.status );
			}
			return r.json();
		} ).then( function ( us ) {
			return topojson.feature( us, us.objects.states ).features;
		} );
		atlasPromise.catch( function () { /* fall back silently */ } );
		return atlasPromise;
	}

	/* ---------------- colour scale ---------------- */

	// Continuous scales: the fill darkens in proportion to the value, so a large gap between the top state and
	// the rest is visible instead of being flattened into the same bucket.
	var SEQ_LIGHT = [ 224, 236, 254 ]; // #e0ecfe
	var SEQ_MID = [ 59, 130, 246 ];    // #3b82f6, keeps the middle of the range a real blue instead of grey
	var SEQ_DARK = [ 23, 37, 84 ];     // #172554
	var NEG_DARK = [ 153, 27, 27 ];    // #991b1b
	var POS_DARK = [ 20, 83, 45 ];     // #14532d
	var MID = [ 243, 244, 246 ];       // #f3f4f6
	var EMPTY = '#f3f4f6';

	function mix( a, b, t ) {
		t = Math.max( 0, Math.min( 1, t ) );
		var r = Math.round( a[ 0 ] + ( b[ 0 ] - a[ 0 ] ) * t );
		var g = Math.round( a[ 1 ] + ( b[ 1 ] - a[ 1 ] ) * t );
		var bl = Math.round( a[ 2 ] + ( b[ 2 ] - a[ 2 ] ) * t );
		return 'rgb(' + r + ',' + g + ',' + bl + ')';
	}
	function rgb( a ) { return 'rgb(' + a.join( ',' ) + ')'; }

	function makeScale( values, diverging ) {
		var nums = values.filter( function ( v ) { return typeof v === 'number' && ! isNaN( v ); } );
		if ( ! nums.length ) {
			return { color: function () { return EMPTY; }, dark: function () { return false; }, kind: 'none' };
		}
		if ( diverging ) {
			var mag = Math.max.apply( null, nums.map( Math.abs ) ) || 1;
			return {
				kind: 'diverging',
				mag: mag,
				color: function ( v ) {
					if ( typeof v !== 'number' || isNaN( v ) ) { return EMPTY; }
					if ( Math.abs( v ) < 1e-9 ) { return rgb( MID ); }
					var t = Math.abs( v ) / mag;
					return mix( MID, v < 0 ? NEG_DARK : POS_DARK, 0.15 + 0.85 * t );
				},
				dark: function ( v ) { return typeof v === 'number' && Math.abs( v ) / mag > 0.55; }
			};
		}
		var pos = nums.filter( function ( v ) { return v > 0; } );
		if ( ! pos.length ) {
			return { color: function () { return EMPTY; }, dark: function () { return false; }, kind: 'none' };
		}
		var min = Math.min.apply( null, pos ), max = Math.max.apply( null, pos );
		var t = function ( v ) { return max > min ? ( v - min ) / ( max - min ) : 1; };
		return {
			kind: 'sequential',
			min: min,
			max: max,
			color: function ( v ) {
				if ( typeof v !== 'number' || isNaN( v ) || v <= 0 ) { return EMPTY; }
				var x = t( v );
				return x < 0.5 ? mix( SEQ_LIGHT, SEQ_MID, x * 2 ) : mix( SEQ_MID, SEQ_DARK, ( x - 0.5 ) * 2 );
			},
			dark: function ( v ) { return typeof v === 'number' && v > 0 && t( v ) > 0.5; }
		};
	}

	/**
	 * opts: { data: { CA: { value: number, tip: html } }, diverging, format, atlasUrl, onSelect(code), selected, legendFormat }
	 * Returns a promise resolved with 'geo' or 'grid'.
	 */
	function render( el, opts ) {
		var values = Object.keys( opts.data ).map( function ( k ) { return opts.data[ k ].value; } );
		var scale = makeScale( values, opts.diverging );
		el.innerHTML = '';
		el.classList.add( 'wpulse-map' );

		function legend( container ) {
			var lg = C.h( 'div', 'wpulse-map-legend' );
			var fmt = opts.format || function ( v ) { return String( v ); };
			if ( scale.kind === 'sequential' ) {
				lg.innerHTML = '<span class="wpulse-map-legend-min">' + C.esc( fmt( scale.min ) ) + '</span>' +
					'<span class="wpulse-map-gradient" style="background:linear-gradient(90deg,' + rgb( SEQ_LIGHT ) + ',' + rgb( SEQ_MID ) + ',' + rgb( SEQ_DARK ) + ')"></span>' +
					'<span class="wpulse-map-legend-max">' + C.esc( fmt( scale.max ) ) + '</span>' +
					'<span class="wpulse-map-legend-item"><i style="background:' + EMPTY + '"></i>no orders</span>';
			} else if ( scale.kind === 'diverging' ) {
				lg.innerHTML = '<span class="wpulse-map-legend-min">' + C.esc( fmt( -scale.mag ) ) + '</span>' +
					'<span class="wpulse-map-gradient" style="background:linear-gradient(90deg,' + rgb( NEG_DARK ) + ',' + rgb( MID ) + ',' + rgb( POS_DARK ) + ')"></span>' +
					'<span class="wpulse-map-legend-max">' + C.esc( fmt( scale.mag ) ) + '</span>' +
					'<span class="wpulse-map-legend-item"><i style="background:' + EMPTY + '"></i>no data</span>';
			}
			container.appendChild( lg );
		}

		function bind( node, code ) {
			var d = opts.data[ code ];
			node.addEventListener( 'mousemove', function ( ev ) {
				var html = '<div class="wpulse-tip-title">' + C.esc( NAMES[ code ] || code ) + '</div>' + ( d && d.tip ? d.tip : '<div class="wpulse-tip-row">No orders in this range</div>' );
				C.showTip( html, ev.pageX, ev.pageY );
			} );
			node.addEventListener( 'mouseleave', C.hideTip );
			if ( opts.onSelect ) {
				node.style.cursor = 'pointer';
				node.addEventListener( 'click', function () { opts.onSelect( code ); } );
			}
			if ( opts.selected === code ) {
				node.classList.add( 'is-selected' );
			}
		}

		function drawGrid() {
			var grid = C.h( 'div', 'wpulse-map-grid' );
			Object.keys( GRID ).forEach( function ( code ) {
				var pos = GRID[ code ];
				var cell = C.h( 'div', 'wpulse-map-cell' );
				cell.style.gridColumn = ( pos[ 0 ] + 1 );
				cell.style.gridRow = ( pos[ 1 ] + 1 );
				var d = opts.data[ code ];
				var val = d ? d.value : null;
				cell.style.background = scale.color( val );
				cell.style.color = scale.dark( val ) ? '#fff' : '#111827';
				cell.textContent = code;
				bind( cell, code );
				grid.appendChild( cell );
			} );
			el.appendChild( grid );
			legend( el );
			el.appendChild( C.h( 'div', 'wpulse-map-note', 'Tile view: each square is a state. The geographic map could not be loaded.' ) );
			return 'grid';
		}

		function drawGeo( features ) {
			var W = Math.max( 320, el.clientWidth || 700 ), H = Math.round( W * 0.62 );
			var holder = C.h( 'div', 'wpulse-map-geo' );
			var root = C.svg( 'svg', { viewBox: '0 0 ' + W + ' ' + H, width: '100%', height: 'auto', 'class': 'wpulse-map-svg' }, holder );
			var projection = d3.geoAlbersUsa().fitExtent( [ [ 8, 8 ], [ W - 8, H - 8 ] ], { type: 'FeatureCollection', features: features } );
			var path = d3.geoPath( projection );
			features.forEach( function ( f ) {
				var code = FIPS[ String( f.id ).padStart( 2, '0' ) ];
				if ( ! code ) { return; }
				var dAttr = path( f );
				if ( ! dAttr ) { return; }
				var d = opts.data[ code ];
				var p = C.svg( 'path', { d: dAttr, fill: scale.color( d ? d.value : null ), 'class': 'wpulse-map-state' }, root );
				bind( p, code );
			} );
			el.appendChild( holder );
			legend( el );
			return 'geo';
		}

		return loadAtlas( opts.atlasUrl ).then( function ( features ) {
			try {
				return drawGeo( features );
			} catch ( e ) {
				el.innerHTML = '';
				return drawGrid();
			}
		}, function () {
			return drawGrid();
		} );
	}

	window.WooPulseMap = {
		render: render,
		NAMES: NAMES,
		FIPS: FIPS
	};
})();
