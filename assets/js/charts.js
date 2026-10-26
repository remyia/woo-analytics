/* global window, document */
/**
 * Woo Pulse chart helpers: dependency-free SVG line charts, donuts, horizontal bars and heatmaps.
 * Every renderer takes a container element and an options object and replaces the container's content.
 */
(function () {
	'use strict';

	var SVG_NS = 'http://www.w3.org/2000/svg';

	var colors = {
		primary: '#2563eb',
		compare: '#9ca3af',
		orders: '#f59e0b',
		green: '#16a34a',
		red: '#dc2626',
		purple: '#7c3aed',
		teal: '#0d9488',
		pink: '#db2777',
		slate: '#475569',
		spend: '#ef4444'
	};
	var palette = [ '#2563eb', '#f59e0b', '#16a34a', '#7c3aed', '#0d9488', '#db2777', '#dc2626', '#475569', '#84cc16', '#06b6d4', '#a855f7', '#f97316' ];

	function svg( tag, attrs, parent ) {
		var el = document.createElementNS( SVG_NS, tag );
		if ( attrs ) {
			Object.keys( attrs ).forEach( function ( k ) {
				el.setAttribute( k, attrs[ k ] );
			} );
		}
		if ( parent ) {
			parent.appendChild( el );
		}
		return el;
	}

	function h( tag, cls, html ) {
		var el = document.createElement( tag );
		if ( cls ) {
			el.className = cls;
		}
		if ( html !== undefined && html !== null ) {
			el.innerHTML = html;
		}
		return el;
	}

	function esc( s ) {
		return String( s === null || s === undefined ? '' : s )
			.replace( /&/g, '&amp;' ).replace( /</g, '&lt;' ).replace( />/g, '&gt;' ).replace( /"/g, '&quot;' );
	}

	/* ---------------- tooltip ---------------- */

	var tipEl = null;
	function tip() {
		if ( ! tipEl ) {
			tipEl = document.createElement( 'div' );
			tipEl.className = 'wpulse-tip';
			tipEl.style.display = 'none';
			document.body.appendChild( tipEl );
		}
		return tipEl;
	}
	function showTip( html, x, y ) {
		var t = tip();
		t.innerHTML = html;
		t.style.display = 'block';
		var w = t.offsetWidth, hh = t.offsetHeight;
		var left = x + 14, top = y + 14;
		if ( left + w > window.innerWidth + window.scrollX - 8 ) {
			left = x - w - 14;
		}
		if ( top + hh > window.innerHeight + window.scrollY - 8 ) {
			top = y - hh - 14;
		}
		t.style.left = left + 'px';
		t.style.top = top + 'px';
	}
	function hideTip() {
		if ( tipEl ) {
			tipEl.style.display = 'none';
		}
	}

	/* ---------------- scales ---------------- */

	function niceStep( x ) {
		if ( ! ( x > 0 ) ) {
			return 1;
		}
		var p = Math.pow( 10, Math.floor( Math.log( x ) / Math.LN10 ) );
		var f = x / p;
		var n = f <= 1 ? 1 : f <= 2 ? 2 : f <= 2.5 ? 2.5 : f <= 5 ? 5 : 10;
		return n * p;
	}
	function ticks( max, count ) {
		count = count || 4;
		if ( ! ( max > 0 ) ) {
			max = 1;
		}
		var step = niceStep( max / count );
		var out = [];
		for ( var v = 0; v <= max + step * 0.001; v += step ) {
			out.push( +v.toFixed( 10 ) );
		}
		if ( out[ out.length - 1 ] < max ) {
			out.push( out[ out.length - 1 ] + step );
		}
		return out;
	}

	function observe( el, fn ) {
		if ( el.__wpulseRO ) {
			el.__wpulseRO.disconnect();
			el.__wpulseRO = null;
		}
		if ( typeof window.ResizeObserver === 'undefined' ) {
			return;
		}
		var last = el.clientWidth, timer = null;
		var ro = new window.ResizeObserver( function () {
			if ( Math.abs( el.clientWidth - last ) < 4 ) {
				return;
			}
			last = el.clientWidth;
			clearTimeout( timer );
			timer = setTimeout( fn, 120 );
		} );
		ro.observe( el );
		el.__wpulseRO = ro;
	}

	function fmtDefault( v ) {
		return v === null || v === undefined ? 'n/a' : String( Math.round( v * 100 ) / 100 );
	}

	/* ---------------- line chart ---------------- */

	/**
	 * opts: { labels: [], series: [ { label, values: [], color, dashed, area, axis: 'left'|'right' } ],
	 *         format, formatRight, xFormat(label, i), height }
	 */
	function line( el, opts ) {
		function draw() {
			el.innerHTML = '';
			var W = Math.max( 280, el.clientWidth || 600 );
			var H = opts.height || 260;
			var hasRight = opts.series.some( function ( s ) { return s.axis === 'right'; } );
			var padL = 58, padR = hasRight ? 58 : 18, padT = 14, padB = 30;
			var iw = W - padL - padR, ih = H - padT - padB;
			var n = opts.labels.length;
			var fmt = opts.format || fmtDefault;
			var fmtR = opts.formatRight || fmt;

			var maxL = 0, maxR = 0;
			opts.series.forEach( function ( s ) {
				s.values.forEach( function ( v ) {
					if ( v === null || v === undefined || isNaN( v ) ) {
						return;
					}
					if ( s.axis === 'right' ) {
						maxR = Math.max( maxR, v );
					} else {
						maxL = Math.max( maxL, v );
					}
				} );
			} );
			var tL = ticks( maxL ), tR = ticks( maxR );
			var topL = tL[ tL.length - 1 ], topR = tR[ tR.length - 1 ];

			var root = svg( 'svg', { width: W, height: H, viewBox: '0 0 ' + W + ' ' + H, 'class': 'wpulse-svg' }, el );
			var xAt = function ( i ) {
				return n <= 1 ? padL + iw / 2 : padL + ( i / ( n - 1 ) ) * iw;
			};
			var yL = function ( v ) { return padT + ih - ( v / topL ) * ih; };
			var yR = function ( v ) { return padT + ih - ( v / topR ) * ih; };

			// grid + left axis
			tL.forEach( function ( t ) {
				var y = yL( t );
				svg( 'line', { x1: padL, x2: W - padR, y1: y, y2: y, 'class': 'wpulse-grid' }, root );
				var txt = svg( 'text', { x: padL - 8, y: y + 4, 'class': 'wpulse-axis', 'text-anchor': 'end' }, root );
				txt.textContent = fmt( t, true );
			} );
			if ( hasRight ) {
				tR.forEach( function ( t ) {
					var y = yR( t );
					var txt = svg( 'text', { x: W - padR + 8, y: y + 4, 'class': 'wpulse-axis', 'text-anchor': 'start' }, root );
					txt.textContent = fmtR( t, true );
				} );
			}
			// x labels
			var every = Math.max( 1, Math.ceil( n / Math.max( 2, Math.floor( iw / 90 ) ) ) );
			for ( var i = 0; i < n; i += every ) {
				var tx = svg( 'text', { x: xAt( i ), y: H - 8, 'class': 'wpulse-axis', 'text-anchor': i === 0 ? 'start' : ( i >= n - every ? 'end' : 'middle' ) }, root );
				tx.textContent = opts.xFormat ? opts.xFormat( opts.labels[ i ], i ) : opts.labels[ i ];
			}

			// series
			opts.series.forEach( function ( s, si ) {
				var color = s.color || palette[ si % palette.length ];
				var yf = s.axis === 'right' ? yR : yL;
				var d = '', started = false, areaD = '';
				s.values.forEach( function ( v, i ) {
					if ( v === null || v === undefined || isNaN( v ) ) {
						started = false;
						return;
					}
					var x = xAt( i ), y = yf( v );
					d += ( started ? ' L' : ' M' ) + x.toFixed( 1 ) + ' ' + y.toFixed( 1 );
					started = true;
				} );
				if ( s.area && n > 0 ) {
					var firstIdx = -1, lastIdx = -1;
					s.values.forEach( function ( v, i ) {
						if ( v !== null && v !== undefined && ! isNaN( v ) ) {
							if ( firstIdx < 0 ) { firstIdx = i; }
							lastIdx = i;
						}
					} );
					if ( firstIdx >= 0 ) {
						areaD = 'M' + xAt( firstIdx ).toFixed( 1 ) + ' ' + ( padT + ih ).toFixed( 1 );
						s.values.forEach( function ( v, i ) {
							if ( v === null || v === undefined || isNaN( v ) ) { return; }
							areaD += ' L' + xAt( i ).toFixed( 1 ) + ' ' + yf( v ).toFixed( 1 );
						} );
						areaD += ' L' + xAt( lastIdx ).toFixed( 1 ) + ' ' + ( padT + ih ).toFixed( 1 ) + ' Z';
						svg( 'path', { d: areaD, fill: color, 'fill-opacity': 0.08, stroke: 'none' }, root );
					}
				}
				if ( d ) {
					svg( 'path', { d: d.trim(), fill: 'none', stroke: color, 'stroke-width': s.dashed ? 1.5 : 2.25, 'stroke-dasharray': s.dashed ? '4 4' : 'none', 'stroke-linejoin': 'round', 'stroke-linecap': 'round' }, root );
				}
				if ( n === 1 ) {
					var v0 = s.values[ 0 ];
					if ( v0 !== null && v0 !== undefined ) {
						svg( 'circle', { cx: xAt( 0 ), cy: yf( v0 ), r: 4, fill: color }, root );
					}
				}
			} );

			// hover
			var guide = svg( 'line', { x1: 0, x2: 0, y1: padT, y2: padT + ih, 'class': 'wpulse-guide', style: 'display:none' }, root );
			var dots = opts.series.map( function ( s, si ) {
				return svg( 'circle', { r: 4, fill: s.color || palette[ si % palette.length ], stroke: '#fff', 'stroke-width': 1.5, style: 'display:none' }, root );
			} );
			var overlay = svg( 'rect', { x: padL, y: padT, width: iw, height: ih, fill: 'transparent' }, root );
			overlay.addEventListener( 'mousemove', function ( ev ) {
				var rect = root.getBoundingClientRect();
				var x = ev.clientX - rect.left;
				var idx = n <= 1 ? 0 : Math.round( ( x - padL ) / iw * ( n - 1 ) );
				idx = Math.max( 0, Math.min( n - 1, idx ) );
				var gx = xAt( idx );
				guide.setAttribute( 'x1', gx );
				guide.setAttribute( 'x2', gx );
				guide.style.display = '';
				var html = '<div class="wpulse-tip-title">' + esc( opts.xFormat ? opts.xFormat( opts.labels[ idx ], idx, true ) : opts.labels[ idx ] ) + '</div>';
				opts.series.forEach( function ( s, si ) {
					var v = s.values[ idx ];
					var color = s.color || palette[ si % palette.length ];
					if ( v === null || v === undefined || isNaN( v ) ) {
						dots[ si ].style.display = 'none';
					} else {
						dots[ si ].style.display = '';
						dots[ si ].setAttribute( 'cx', gx );
						dots[ si ].setAttribute( 'cy', ( s.axis === 'right' ? yR : yL )( v ) );
					}
					html += '<div class="wpulse-tip-row"><span class="wpulse-tip-swatch" style="background:' + color + '"></span>' + esc( s.label ) + ' <b>' + esc( ( s.axis === 'right' ? fmtR : fmt )( v ) ) + '</b></div>';
				} );
				showTip( html, ev.pageX, ev.pageY );
			} );
			overlay.addEventListener( 'mouseleave', function () {
				guide.style.display = 'none';
				dots.forEach( function ( d ) { d.style.display = 'none'; } );
				hideTip();
			} );

			// legend
			if ( opts.series.length > 1 || opts.legend ) {
				var lg = h( 'div', 'wpulse-legend' );
				opts.series.forEach( function ( s, si ) {
					var color = s.color || palette[ si % palette.length ];
					lg.appendChild( h( 'span', 'wpulse-legend-item', '<span class="wpulse-legend-swatch" style="background:' + color + ( s.dashed ? ';opacity:.6' : '' ) + '"></span>' + esc( s.label ) ) );
				} );
				el.appendChild( lg );
			}
		}
		draw();
		observe( el, draw );
	}

	/* ---------------- donut ---------------- */

	/** opts: { slices: [ { label, value, color } ], format, center } */
	function donut( el, opts ) {
		el.innerHTML = '';
		var total = opts.slices.reduce( function ( a, s ) { return a + ( s.value > 0 ? s.value : 0 ); }, 0 );
		var fmt = opts.format || fmtDefault;
		var wrap = h( 'div', 'wpulse-donut' );
		var size = opts.size || 150, r = size / 2, ri = r * 0.62, cx = r, cy = r;
		var root = svg( 'svg', { width: size, height: size, viewBox: '0 0 ' + size + ' ' + size }, wrap );
		if ( total <= 0 ) {
			svg( 'circle', { cx: cx, cy: cy, r: ( r + ri ) / 2, fill: 'none', stroke: '#e5e7eb', 'stroke-width': r - ri }, root );
		} else {
			var a0 = -Math.PI / 2;
			opts.slices.forEach( function ( s, i ) {
				if ( ! ( s.value > 0 ) ) { return; }
				var frac = s.value / total;
				var a1 = a0 + frac * Math.PI * 2;
				var large = frac > 0.5 ? 1 : 0;
				var p = function ( rad, a ) { return [ cx + rad * Math.cos( a ), cy + rad * Math.sin( a ) ]; };
				var d;
				if ( frac >= 0.9999 ) {
					d = 'M' + p( r, a0 ).join( ' ' ) + ' A' + r + ' ' + r + ' 0 1 1 ' + p( r, a0 + Math.PI ).join( ' ' ) + ' A' + r + ' ' + r + ' 0 1 1 ' + p( r, a0 ).join( ' ' ) +
						' M' + p( ri, a0 ).join( ' ' ) + ' A' + ri + ' ' + ri + ' 0 1 0 ' + p( ri, a0 + Math.PI ).join( ' ' ) + ' A' + ri + ' ' + ri + ' 0 1 0 ' + p( ri, a0 ).join( ' ' ) + ' Z';
				} else {
					d = 'M' + p( r, a0 ).join( ' ' ) + ' A' + r + ' ' + r + ' 0 ' + large + ' 1 ' + p( r, a1 ).join( ' ' ) +
						' L' + p( ri, a1 ).join( ' ' ) + ' A' + ri + ' ' + ri + ' 0 ' + large + ' 0 ' + p( ri, a0 ).join( ' ' ) + ' Z';
				}
				var path = svg( 'path', { d: d, fill: s.color || palette[ i % palette.length ], 'fill-rule': 'evenodd' }, root );
				path.addEventListener( 'mousemove', function ( ev ) {
					showTip( '<div class="wpulse-tip-title">' + esc( s.label ) + '</div><div class="wpulse-tip-row"><b>' + esc( fmt( s.value ) ) + '</b> &middot; ' + ( frac * 100 ).toFixed( 1 ) + '%</div>', ev.pageX, ev.pageY );
				} );
				path.addEventListener( 'mouseleave', hideTip );
				a0 = a1;
			} );
		}
		if ( opts.center ) {
			var t1 = svg( 'text', { x: cx, y: cy - 4, 'text-anchor': 'middle', 'class': 'wpulse-donut-center' }, root );
			t1.textContent = opts.center.value;
			var t2 = svg( 'text', { x: cx, y: cy + 14, 'text-anchor': 'middle', 'class': 'wpulse-donut-sub' }, root );
			t2.textContent = opts.center.label;
		}
		var lg = h( 'div', 'wpulse-donut-legend' );
		opts.slices.forEach( function ( s, i ) {
			var pct = total > 0 && s.value > 0 ? ( s.value / total * 100 ).toFixed( 1 ) + '%' : '0%';
			lg.appendChild( h( 'div', 'wpulse-legend-row', '<span class="wpulse-legend-swatch" style="background:' + ( s.color || palette[ i % palette.length ] ) + '"></span><span class="wpulse-legend-label">' + esc( s.label ) + '</span><b>' + esc( fmt( s.value ) ) + '</b><span class="wpulse-muted">' + pct + '</span>' ) );
		} );
		wrap.appendChild( lg );
		el.appendChild( wrap );
	}

	/* ---------------- horizontal bars ---------------- */

	/** opts: { rows: [ { label, value, sub, color } ], format, max } */
	function hbars( el, opts ) {
		el.innerHTML = '';
		var fmt = opts.format || fmtDefault;
		var max = opts.max || opts.rows.reduce( function ( a, r ) { return Math.max( a, r.value || 0 ); }, 0 );
		if ( ! opts.rows.length ) {
			el.appendChild( h( 'div', 'wpulse-empty', esc( opts.empty || 'Nothing to show for this range.' ) ) );
			return;
		}
		var list = h( 'div', 'wpulse-hbars' );
		opts.rows.forEach( function ( r, i ) {
			var pct = max > 0 ? Math.max( 0, ( r.value || 0 ) / max * 100 ) : 0;
			var row = h( 'div', 'wpulse-hbar' );
			row.innerHTML = '<div class="wpulse-hbar-label" title="' + esc( r.label ) + '">' + esc( r.label ) + ( r.sub ? '<span class="wpulse-muted"> ' + esc( r.sub ) + '</span>' : '' ) + '</div>' +
				'<div class="wpulse-hbar-track"><div class="wpulse-hbar-fill" style="width:' + pct.toFixed( 1 ) + '%;background:' + ( r.color || colors.primary ) + '"></div></div>' +
				'<div class="wpulse-hbar-value">' + esc( fmt( r.value ) ) + '</div>';
			if ( r.title ) {
				row.title = r.title;
			}
			list.appendChild( row );
		} );
		el.appendChild( list );
	}

	/* ---------------- heatmap ---------------- */

	/** opts: { rows: [labels], cols: [labels], values: [[...]], format, colLabelEvery } */
	function heatmap( el, opts ) {
		el.innerHTML = '';
		var fmt = opts.format || fmtDefault;
		var max = 0;
		opts.values.forEach( function ( row ) { row.forEach( function ( v ) { max = Math.max( max, v || 0 ); } ); } );
		var grid = h( 'div', 'wpulse-heatmap' );
		grid.style.gridTemplateColumns = '44px repeat(' + opts.cols.length + ', minmax(0, 1fr))';
		grid.appendChild( h( 'div', 'wpulse-heat-corner', '' ) );
		var every = opts.colLabelEvery || 1;
		opts.cols.forEach( function ( c, i ) {
			grid.appendChild( h( 'div', 'wpulse-heat-col', i % every === 0 ? esc( c ) : '' ) );
		} );
		opts.rows.forEach( function ( rl, ri ) {
			grid.appendChild( h( 'div', 'wpulse-heat-row', esc( rl ) ) );
			opts.values[ ri ].forEach( function ( v, ci ) {
				var cell = h( 'div', 'wpulse-heat-cell' );
				var a = max > 0 ? ( v || 0 ) / max : 0;
				cell.style.background = v > 0 ? 'rgba(37, 99, 235, ' + ( 0.12 + a * 0.85 ).toFixed( 3 ) + ')' : '#f3f4f6';
				cell.addEventListener( 'mousemove', function ( ev ) {
					showTip( '<div class="wpulse-tip-title">' + esc( rl ) + ' ' + esc( opts.cols[ ci ] ) + '</div><div class="wpulse-tip-row"><b>' + esc( fmt( v || 0 ) ) + '</b></div>', ev.pageX, ev.pageY );
				} );
				cell.addEventListener( 'mouseleave', hideTip );
				grid.appendChild( cell );
			} );
		} );
		el.appendChild( grid );
	}

	window.WooPulseCharts = {
		colors: colors,
		palette: palette,
		line: line,
		donut: donut,
		hbars: hbars,
		heatmap: heatmap,
		showTip: showTip,
		hideTip: hideTip,
		esc: esc,
		h: h,
		svg: svg,
		ticks: ticks,
		observe: observe
	};
})();
