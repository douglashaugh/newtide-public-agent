/* NewTide Public Agent — charts in agent replies (0.16.0).
 *
 * The agent writes a fenced code block whose language is `chart`, holding a
 * small JSON description; the server renders it as escaped text inside
 * <code class="language-chart">. This file turns that into an SVG chart.
 *
 *   { "type": "line" | "bar", "title", "subtitle", "unit", "prefix", "suffix",
 *     "labels": [ ... ], "series": [ { "name", "values": [ ... ] } ] }
 *
 * Line charts for change over time, horizontal bars for comparing categories
 * (horizontal because category names fit a chat panel's width that way). At
 * most four series: past that the palette stops being reliably distinct for
 * colour-blind readers, so the data is shown as a table instead.
 *
 * The specs follow the data-viz method the plugin is built to: 2px lines,
 * bars no thicker than 24px with a 4px rounded data end, hairline solid grid,
 * a legend only for two or more series, labels used sparingly, a hover and
 * keyboard readout that every value is also reachable without (the data
 * table). Colours come from custom properties in the stylesheet, so a theme
 * switch repaints a drawn chart without redrawing it.
 *
 * Everything the agent sends is untrusted: names and labels only ever go in
 * through textContent, never innerHTML.
 */
( function () {
	'use strict';

	var SVG_NS = 'http://www.w3.org/2000/svg';
	var MAX_SERIES = 4;
	var MAX_BARS = 15;
	var MAX_POINTS = 200;

	/* Looked up on use, not on load: this file loads before the widget script,
	   and WordPress prints the widget's strings just ahead of that one. */
	function t( key, fallback ) {
		var i18n = ( window.NPA_WIDGET && window.NPA_WIDGET.i18n ) || {};
		return i18n[ key ] || fallback;
	}

	function svg( tag, attrs ) {
		var node = document.createElementNS( SVG_NS, tag );
		if ( attrs ) {
			Object.keys( attrs ).forEach( function ( k ) {
				node.setAttribute( k, attrs[ k ] );
			} );
		}
		return node;
	}

	function html( tag, cls, text ) {
		var node = document.createElement( tag );
		if ( cls ) {
			node.className = cls;
		}
		if ( null !== text && undefined !== text ) {
			node.textContent = text;
		}
		return node;
	}

	function clamp( v, lo, hi ) {
		return Math.min( hi, Math.max( lo, v ) );
	}

	function seriesColour( i ) {
		return 'var(--npa-viz-' + ( i + 1 ) + ')';
	}

	/* -----------------------------------------------------------------
	   Parsing. Forgiving about shape, strict about values: a number that
	   is not a number becomes a gap, never a guess.
	   ----------------------------------------------------------------- */

	function toNumber( v ) {
		if ( 'number' === typeof v && isFinite( v ) ) {
			return v;
		}
		if ( 'string' === typeof v && '' !== v.trim() ) {
			var n = Number( v.replace( /[,\s]/g, '' ) );
			return isFinite( n ) ? n : null;
		}
		return null;
	}

	function text( v ) {
		return null === v || undefined === v ? '' : String( v ).slice( 0, 200 );
	}

	/* Returns { spec, drawable } or null when this is not chart data at all. */
	function parse( raw ) {
		var data;
		try {
			data = JSON.parse( raw );
		} catch ( e ) {
			return null;
		}
		if ( ! data || 'object' !== typeof data || ! Array.isArray( data.labels ) || ! Array.isArray( data.series ) ) {
			return null;
		}

		var type = String( data.type || 'line' ).toLowerCase();
		type = ( 'bar' === type || 'bars' === type || 'column' === type || 'columns' === type ) ? 'bar' : 'line';

		var labels = data.labels.map( text );
		var series = data.series.filter( function ( s ) {
			return s && 'object' === typeof s;
		} ).map( function ( s, i ) {
			var vals = Array.isArray( s.values ) ? s.values : ( Array.isArray( s.data ) ? s.data : [] );
			var out = [];
			for ( var j = 0; j < labels.length; j++ ) {
				out.push( j < vals.length ? toNumber( vals[ j ] ) : null );
			}
			return { name: text( s.name ) || ( t( 'chartSeries', 'Series' ) + ' ' + ( i + 1 ) ), values: out, decimals: decimalsOf( out ) };
		} );

		if ( ! labels.length || ! series.length ) {
			return null;
		}

		var spec = {
			type: type,
			title: text( data.title ),
			subtitle: text( data.subtitle ),
			unit: text( data.unit ),
			prefix: text( data.prefix ).slice( 0, 4 ),
			suffix: text( data.suffix ).slice( 0, 6 ),
			labels: labels,
			series: series
		};

		var any = series.some( function ( s ) {
			return s.values.some( function ( v ) {
				return null !== v;
			} );
		} );
		var drawable = any
			&& series.length <= MAX_SERIES
			&& ( 'bar' === type ? labels.length <= MAX_BARS : labels.length >= 2 && labels.length <= MAX_POINTS );

		return { spec: spec, drawable: drawable };
	}

	/* -----------------------------------------------------------------
	   Numbers.
	   ----------------------------------------------------------------- */

	function niceStep( range, count ) {
		var rough = range / Math.max( 1, count );
		var mag = Math.pow( 10, Math.floor( Math.log( rough ) / Math.LN10 ) );
		var norm = rough / mag;
		var step = norm < 1.5 ? 1 : norm < 3 ? 2 : norm < 7 ? 5 : 10;
		return step * mag;
	}

	function niceDomain( lo, hi, count ) {
		if ( lo === hi ) {
			var pad = Math.abs( lo ) || 1;
			lo -= pad / 2;
			hi += pad / 2;
		}
		var step = niceStep( hi - lo, count );
		var d0 = Math.floor( lo / step ) * step;
		var d1 = Math.ceil( hi / step ) * step;
		var ticks = [];
		for ( var v = d0; v <= d1 + step / 2; v += step ) {
			ticks.push( Math.abs( v ) < step / 1e6 ? 0 : v );
		}
		return { d0: d0, d1: d1, step: step, ticks: ticks };
	}

	function decimalsFor( step ) {
		return step >= 1 ? 0 : clamp( Math.ceil( -Math.log( step ) / Math.LN10 ), 0, 4 );
	}

	/* Axis ticks: compact from 1,000 up (0 / 1K / 2K). */
	function fmtTick( spec, v, step ) {
		var abs = Math.abs( v );
		var s;
		if ( abs >= 1000 ) {
			try {
				s = new Intl.NumberFormat( undefined, { notation: 'compact', maximumFractionDigits: 1 } ).format( v );
			} catch ( e ) {
				s = abs >= 1e6 ? ( v / 1e6 ).toFixed( 1 ) + 'M' : ( v / 1e3 ).toFixed( 1 ) + 'K';
			}
		} else {
			s = v.toLocaleString( undefined, { maximumFractionDigits: decimalsFor( step ), minimumFractionDigits: 0 } );
		}
		return withAffixes( spec, s );
	}

	/* Decimal places a series is written to: the most any of its values uses,
	   up to two. One count per series, so a column reads 84.0 beside 81.2
	   rather than 84 — numbers that line up are numbers that compare. */
	function decimalsOf( values ) {
		var most = 0;
		values.forEach( function ( v ) {
			if ( null === v ) {
				return;
			}
			var s = String( v );
			var dot = s.indexOf( '.' );
			if ( dot >= 0 && s.indexOf( 'e' ) < 0 ) {
				most = Math.max( most, s.length - dot - 1 );
			}
		} );
		return Math.min( 2, most );
	}

	/* Values in labels, the readout and the table. */
	function fmtValue( spec, v, decimals ) {
		if ( null === v ) {
			return '—';
		}
		var d = 'number' === typeof decimals ? decimals : 2;
		var opts = 'number' === typeof decimals
			? { minimumFractionDigits: d, maximumFractionDigits: d }
			: { maximumFractionDigits: d };
		return withAffixes( spec, v.toLocaleString( undefined, opts ) );
	}

	function withAffixes( spec, s ) {
		if ( spec.prefix && '-' === s.charAt( 0 ) ) {
			return '-' + spec.prefix + s.slice( 1 ) + spec.suffix;
		}
		return spec.prefix + s + spec.suffix;
	}

	/* -----------------------------------------------------------------
	   Text measurement, so labels are placed rather than clipped.
	   ----------------------------------------------------------------- */

	var measureCtx = null;

	function measurer( font ) {
		if ( ! measureCtx ) {
			measureCtx = document.createElement( 'canvas' ).getContext( '2d' );
		}
		return function ( s ) {
			measureCtx.font = font;
			return measureCtx.measureText( s ).width;
		};
	}

	function truncate( s, max, width ) {
		if ( width( s ) <= max ) {
			return s;
		}
		var lo = 0;
		var hi = s.length;
		while ( lo < hi ) {
			var mid = Math.ceil( ( lo + hi ) / 2 );
			if ( width( s.slice( 0, mid ) + '…' ) <= max ) {
				lo = mid;
			} else {
				hi = mid - 1;
			}
		}
		return s.slice( 0, lo ) + '…';
	}

	/* -----------------------------------------------------------------
	   Table view: the accessible route to every value, the "Show data"
	   disclosure, and what a copied reply pastes as.
	   ----------------------------------------------------------------- */

	function toTable( spec ) {
		var table = html( 'table', 'newtide-public-agent__chart-table' );
		var head = table.appendChild( html( 'thead' ) ).appendChild( html( 'tr' ) );
		head.appendChild( html( 'th', null, '' ) );
		spec.series.forEach( function ( s ) {
			head.appendChild( html( 'th', null, s.name + ( spec.unit ? ' (' + spec.unit + ')' : '' ) ) );
		} );
		var body = table.appendChild( html( 'tbody' ) );
		spec.labels.forEach( function ( label, i ) {
			var row = body.appendChild( html( 'tr' ) );
			row.appendChild( html( 'th', null, label ) ).setAttribute( 'scope', 'row' );
			spec.series.forEach( function ( s ) {
				row.appendChild( html( 'td', null, fmtValue( spec, s.values[ i ], s.decimals ) ) );
			} );
		} );
		return table;
	}

	function toText( spec ) {
		var lines = [];
		if ( spec.title ) {
			lines.push( spec.title + ( spec.subtitle ? ' — ' + spec.subtitle : '' ) );
		}
		lines.push( [ '' ].concat( spec.series.map( function ( s ) {
			return s.name + ( spec.unit ? ' (' + spec.unit + ')' : '' );
		} ) ).join( '\t' ) );
		spec.labels.forEach( function ( label, i ) {
			lines.push( [ label ].concat( spec.series.map( function ( s ) {
				return fmtValue( spec, s.values[ i ], s.decimals );
			} ) ).join( '\t' ) );
		} );
		return lines.join( '\n' );
	}

	/* -----------------------------------------------------------------
	   The readout. Values lead, names follow; line keys, not boxes.
	   ----------------------------------------------------------------- */

	function fillTip( tip, spec, i ) {
		tip.textContent = '';
		tip.appendChild( html( 'div', 'newtide-public-agent__chart-tip-head', spec.labels[ i ] ) );
		spec.series.forEach( function ( s, k ) {
			var row = tip.appendChild( html( 'div', 'newtide-public-agent__chart-tip-row' ) );
			var key = row.appendChild( html( 'span', 'newtide-public-agent__chart-key newtide-public-agent__chart-key--line' ) );
			key.style.background = seriesColour( k );
			row.appendChild( html( 'strong', null, fmtValue( spec, s.values[ i ], s.decimals ) ) );
			if ( spec.series.length > 1 ) {
				row.appendChild( html( 'span', 'newtide-public-agent__chart-tip-name', s.name ) );
			}
		} );
	}

	function placeTip( tip, plot, x, y ) {
		tip.hidden = false;
		var w = tip.offsetWidth;
		var h = tip.offsetHeight;
		var left = clamp( x - w / 2, 0, plot.clientWidth - w );
		var top = y - h - 12;
		if ( top < 0 ) {
			top = y + 14;
		}
		tip.style.left = left + 'px';
		tip.style.top = top + 'px';
	}

	/* -----------------------------------------------------------------
	   Line chart.
	   ----------------------------------------------------------------- */

	function drawLine( spec, plot, W, fs, width ) {
		var n = spec.labels.length;
		var all = [];
		spec.series.forEach( function ( s ) {
			s.values.forEach( function ( v ) {
				if ( null !== v ) {
					all.push( v );
				}
			} );
		} );
		var lo = Math.min.apply( null, all );
		var hi = Math.max.apply( null, all );
		// Start at zero when the data sits well above it; otherwise fit the data,
		// since a trend squashed against a distant zero hides the trend.
		if ( lo >= 0 && lo < hi * 0.6 ) {
			lo = 0;
		}
		var dom = niceDomain( lo, hi, 4 );
		var single = 1 === spec.series.length;

		var tickLabels = dom.ticks.map( function ( v ) {
			return fmtTick( spec, v, dom.step );
		} );
		var L = Math.ceil( Math.max.apply( null, tickLabels.map( width ) ) ) + 8;
		var lastIdx = -1;
		var lastVal = null;
		if ( single ) {
			for ( var q = n - 1; q >= 0; q-- ) {
				if ( null !== spec.series[ 0 ].values[ q ] ) {
					lastIdx = q;
					lastVal = spec.series[ 0 ].values[ q ];
					break;
				}
			}
		}
		var endLabel = single && lastIdx === n - 1 ? fmtValue( spec, lastVal, spec.series[ 0 ].decimals ) : '';
		var R = endLabel ? Math.ceil( width( endLabel ) ) + 12 : 8;
		var T = 8;
		var B = fs + 12;
		var H = clamp( Math.round( W * 0.5 ), 170, 250 );
		var pw = Math.max( 40, W - L - R );
		var ph = H - T - B;

		var x = function ( i ) {
			return L + ( 1 === n ? pw / 2 : ( i * pw ) / ( n - 1 ) );
		};
		var y = function ( v ) {
			return T + ( 1 - ( v - dom.d0 ) / ( dom.d1 - dom.d0 ) ) * ph;
		};

		var root = svg( 'svg', { width: W, height: H, viewBox: '0 0 ' + W + ' ' + H, 'aria-hidden': 'true', focusable: 'false' } );

		// Grid and y ticks. The lowest line is the baseline, one step darker.
		dom.ticks.forEach( function ( v, k ) {
			var yy = Math.round( y( v ) ) + 0.5;
			root.appendChild( svg( 'line', {
				x1: L, x2: L + pw, y1: yy, y2: yy,
				style: 'stroke:' + ( 0 === k ? 'var(--npa-viz-axis)' : 'var(--npa-viz-grid)' ) + ';stroke-width:1'
			} ) );
			var tl = svg( 'text', { x: L - 8, y: yy, 'text-anchor': 'end', 'dominant-baseline': 'middle', 'font-size': fs, style: 'fill:var(--npa-viz-muted)' } );
			tl.textContent = tickLabels[ k ];
			root.appendChild( tl );
		} );

		// X labels: as many as fit without touching; first and last anchored in.
		var widest = Math.max.apply( null, spec.labels.map( width ) );
		var slots = Math.max( 1, Math.floor( pw / ( widest + 14 ) ) );
		var step = Math.max( 1, Math.ceil( n / slots ) );
		var shown = [];
		for ( var i = 0; i < n; i += step ) {
			shown.push( i );
		}
		if ( shown[ shown.length - 1 ] !== n - 1 && x( n - 1 ) - x( shown[ shown.length - 1 ] ) >= widest + 14 ) {
			shown.push( n - 1 );
		}
		shown.forEach( function ( idx ) {
			var anchor = 0 === idx ? 'start' : ( n - 1 === idx ? 'end' : 'middle' );
			var xl = svg( 'text', { x: x( idx ), y: H - 4, 'text-anchor': anchor, 'font-size': fs, style: 'fill:var(--npa-viz-muted)' } );
			xl.textContent = spec.labels[ idx ];
			root.appendChild( xl );
		} );

		// Series: a wash under a lone line, then the 2px lines, breaking at gaps.
		spec.series.forEach( function ( s, k ) {
			var segs = [];
			var cur = [];
			s.values.forEach( function ( v, idx ) {
				if ( null === v ) {
					if ( cur.length ) {
						segs.push( cur );
					}
					cur = [];
				} else {
					cur.push( [ x( idx ), y( v ) ] );
				}
			} );
			if ( cur.length ) {
				segs.push( cur );
			}

			segs.forEach( function ( pts ) {
				var d = pts.map( function ( p, j ) {
					return ( j ? 'L' : 'M' ) + p[ 0 ].toFixed( 1 ) + ' ' + p[ 1 ].toFixed( 1 );
				} ).join( '' );
				if ( single && pts.length > 1 ) {
					var base = y( dom.d0 ).toFixed( 1 );
					root.appendChild( svg( 'path', {
						d: d + 'L' + pts[ pts.length - 1 ][ 0 ].toFixed( 1 ) + ' ' + base + 'L' + pts[ 0 ][ 0 ].toFixed( 1 ) + ' ' + base + 'Z',
						style: 'fill:' + seriesColour( k ) + ';fill-opacity:0.1;stroke:none'
					} ) );
				}
				root.appendChild( svg( 'path', {
					d: d,
					style: 'fill:none;stroke:' + seriesColour( k ) + ';stroke-width:2;stroke-linejoin:round;stroke-linecap:round'
				} ) );
			} );

			// End dot, ringed in the surface colour so it reads over the line.
			for ( var e = n - 1; e >= 0; e-- ) {
				if ( null !== s.values[ e ] ) {
					root.appendChild( svg( 'circle', { cx: x( e ), cy: y( s.values[ e ] ), r: 6, style: 'fill:var(--npa-surface)' } ) );
					root.appendChild( svg( 'circle', { cx: x( e ), cy: y( s.values[ e ] ), r: 4, style: 'fill:' + seriesColour( k ) } ) );
					break;
				}
			}
		} );

		// One value label: the latest, for a single series.
		if ( endLabel ) {
			var el = svg( 'text', { x: x( n - 1 ) + 9, y: y( lastVal ), 'dominant-baseline': 'middle', 'font-size': fs, 'font-weight': 600, style: 'fill:var(--npa-viz-ink2)' } );
			el.textContent = endLabel;
			root.appendChild( el );
		}

		// Hover layer: a crosshair that snaps to the nearest point.
		var cross = svg( 'line', { y1: T, y2: T + ph, style: 'stroke:var(--npa-viz-axis);stroke-width:1;display:none' } );
		root.appendChild( cross );
		var dots = spec.series.map( function ( s, k ) {
			var g = svg( 'g', { style: 'display:none' } );
			g.appendChild( svg( 'circle', { r: 6, style: 'fill:var(--npa-surface)' } ) );
			g.appendChild( svg( 'circle', { r: 4, style: 'fill:' + seriesColour( k ) } ) );
			root.appendChild( g );
			return g;
		} );
		var hit = svg( 'rect', { x: L, y: 0, width: pw, height: H, style: 'fill:transparent' } );
		root.appendChild( hit );

		return {
			svg: root,
			count: n,
			pointFor: function ( px ) {
				return clamp( Math.round( ( ( px - L ) / pw ) * ( n - 1 ) ), 0, n - 1 );
			},
			show: function ( i ) {
				var xx = Math.round( x( i ) ) + 0.5;
				cross.setAttribute( 'x1', xx );
				cross.setAttribute( 'x2', xx );
				cross.style.display = '';
				var topY = T + ph;
				spec.series.forEach( function ( s, k ) {
					var v = s.values[ i ];
					if ( null === v ) {
						dots[ k ].style.display = 'none';
						return;
					}
					dots[ k ].style.display = '';
					Array.prototype.forEach.call( dots[ k ].childNodes, function ( c ) {
						c.setAttribute( 'cx', x( i ) );
						c.setAttribute( 'cy', y( v ) );
					} );
					topY = Math.min( topY, y( v ) );
				} );
				return { x: x( i ), y: topY };
			},
			hide: function () {
				cross.style.display = 'none';
				dots.forEach( function ( g ) {
					g.style.display = 'none';
				} );
			}
		};
	}

	/* -----------------------------------------------------------------
	   Horizontal bar chart.
	   ----------------------------------------------------------------- */

	function barPath( zx, vx, y, h ) {
		var len = Math.abs( vx - zx );
		if ( len < 0.5 ) {
			return '';
		}
		var r = Math.min( 4, h / 2, len );
		var dir = vx >= zx ? 1 : -1;
		var tip = vx - dir * r;
		return 'M' + zx + ' ' + y
			+ 'H' + tip
			+ 'Q' + vx + ' ' + y + ' ' + vx + ' ' + ( y + r )
			+ 'V' + ( y + h - r )
			+ 'Q' + vx + ' ' + ( y + h ) + ' ' + tip + ' ' + ( y + h )
			+ 'H' + zx + 'Z';
	}

	function drawBar( spec, plot, W, fs, width ) {
		var n = spec.labels.length;
		var k = spec.series.length;
		var single = 1 === k;
		var all = [ 0 ];
		spec.series.forEach( function ( s ) {
			s.values.forEach( function ( v ) {
				if ( null !== v ) {
					all.push( v );
				}
			} );
		} );
		var dom = niceDomain( Math.min.apply( null, all ), Math.max.apply( null, all ), 4 );
		var hasNeg = dom.d0 < 0;

		var barH = single ? 18 : 12;
		var rowH = k * barH + ( k - 1 ) * 2 + 12;
		var catW = Math.min( Math.ceil( Math.max.apply( null, spec.labels.map( width ) ) ), Math.round( W * 0.36 ) );
		var valLabels = single ? spec.series[ 0 ].values.map( function ( v ) {
			return null === v ? '' : fmtValue( spec, v, spec.series[ 0 ].decimals );
		} ) : [];
		var valW = single ? Math.ceil( Math.max.apply( null, valLabels.map( width ).concat( [ 0 ] ) ) ) + 8 : 0;

		var T = 4;
		var B = single ? 4 : fs + 12;
		var x0 = catW + 10 + ( hasNeg ? valW : 0 );
		var x1 = W - ( single ? valW : 8 );
		var pw = Math.max( 40, x1 - x0 );
		var H = T + n * rowH + B;

		var x = function ( v ) {
			return x0 + ( ( v - dom.d0 ) / ( dom.d1 - dom.d0 ) ) * pw;
		};
		var zx = Math.round( x( 0 ) );

		var root = svg( 'svg', { width: W, height: H, viewBox: '0 0 ' + W + ' ' + H, 'aria-hidden': 'true', focusable: 'false' } );

		// Several series need an axis to read against; one series is labelled.
		if ( ! single ) {
			dom.ticks.forEach( function ( v ) {
				var xx = Math.round( x( v ) ) + 0.5;
				root.appendChild( svg( 'line', { x1: xx, x2: xx, y1: T, y2: T + n * rowH, style: 'stroke:var(--npa-viz-grid);stroke-width:1' } ) );
				var tl = svg( 'text', { x: xx, y: H - 4, 'text-anchor': 'middle', 'font-size': fs, style: 'fill:var(--npa-viz-muted)' } );
				tl.textContent = fmtTick( spec, v, dom.step );
				root.appendChild( tl );
			} );
		}

		var shade = svg( 'rect', { x: 0, width: W, height: rowH - 4, rx: 4, style: 'fill:var(--npa-muted);display:none' } );
		root.appendChild( shade );

		spec.labels.forEach( function ( label, i ) {
			var top = T + i * rowH + 6;
			var cl = svg( 'text', { x: catW, y: top + ( k * barH + ( k - 1 ) * 2 ) / 2, 'text-anchor': 'end', 'dominant-baseline': 'middle', 'font-size': fs, style: 'fill:var(--npa-viz-ink2)' } );
			cl.textContent = truncate( label, catW, width );
			root.appendChild( cl );

			spec.series.forEach( function ( s, j ) {
				var v = s.values[ i ];
				if ( null === v ) {
					return;
				}
				var by = top + j * ( barH + 2 );
				var d = barPath( zx, Math.round( x( v ) ), by, barH );
				if ( d ) {
					root.appendChild( svg( 'path', { d: d, style: 'fill:' + seriesColour( j ) } ) );
				}
				if ( single ) {
					var vx = x( v );
					var vl = svg( 'text', {
						x: v < 0 ? vx - 6 : vx + 6,
						y: by + barH / 2,
						'text-anchor': v < 0 ? 'end' : 'start',
						'dominant-baseline': 'middle',
						'font-size': fs,
						style: 'fill:var(--npa-viz-ink2)'
					} );
					vl.textContent = valLabels[ i ];
					root.appendChild( vl );
				}
			} );
		} );

		// The zero line, over the bar ends it anchors.
		root.appendChild( svg( 'line', { x1: zx + 0.5, x2: zx + 0.5, y1: T, y2: T + n * rowH, style: 'stroke:var(--npa-viz-axis);stroke-width:1' } ) );

		var hit = svg( 'rect', { x: 0, y: T, width: W, height: n * rowH, style: 'fill:transparent' } );
		root.appendChild( hit );

		return {
			svg: root,
			count: n,
			vertical: true,
			pointFor: function ( px, py ) {
				return clamp( Math.floor( ( py - T ) / rowH ), 0, n - 1 );
			},
			show: function ( i ) {
				shade.setAttribute( 'y', T + i * rowH + 2 );
				shade.style.display = '';
				var far = zx;
				spec.series.forEach( function ( s ) {
					if ( null !== s.values[ i ] ) {
						far = Math.max( far, x( s.values[ i ] ) );
					}
				} );
				return { x: Math.min( far, x1 ), y: T + i * rowH + 4 };
			},
			hide: function () {
				shade.style.display = 'none';
			}
		};
	}

	/* -----------------------------------------------------------------
	   Assembly.
	   ----------------------------------------------------------------- */

	function legend( spec ) {
		var wrap = html( 'div', 'newtide-public-agent__chart-legend' );
		spec.series.forEach( function ( s, k ) {
			var item = wrap.appendChild( html( 'span', 'newtide-public-agent__chart-legend-item' ) );
			var key = item.appendChild( html( 'span', 'newtide-public-agent__chart-key newtide-public-agent__chart-key--' + ( 'bar' === spec.type ? 'bar' : 'line' ) ) );
			key.style.background = seriesColour( k );
			item.appendChild( document.createTextNode( s.name ) );
		} );
		return wrap;
	}

	function describe( spec ) {
		var kind = 'bar' === spec.type ? t( 'chartBar', 'Bar chart' ) : t( 'chartLine', 'Line chart' );
		var parts = [ spec.title || kind ];
		if ( spec.title ) {
			parts.push( kind );
		}
		parts.push( t( 'chartKeys', 'Use the arrow keys to read each value, or open Show data for the table.' ) );
		return parts.join( '. ' );
	}

	function head( spec, figure ) {
		if ( ! spec.title && ! spec.subtitle && ! spec.unit ) {
			return;
		}
		var cap = figure.appendChild( html( 'figcaption', 'newtide-public-agent__chart-head' ) );
		if ( spec.title ) {
			cap.appendChild( html( 'div', 'newtide-public-agent__chart-title', spec.title ) );
		}
		var sub = [ spec.subtitle, spec.unit ].filter( Boolean ).join( ' · ' );
		if ( sub ) {
			cap.appendChild( html( 'div', 'newtide-public-agent__chart-sub', sub ) );
		}
	}

	function dataDisclosure( spec, open ) {
		var details = html( 'details', 'newtide-public-agent__chart-data' );
		if ( open ) {
			details.open = true;
		}
		details.appendChild( html( 'summary', null, t( 'chartData', 'Show data' ) ) );
		details.appendChild( toTable( spec ) );
		return details;
	}

	/* Replace one <pre><code class="language-chart"> with its chart. Returns
	   the figure, or null when the block is not chart data. */
	function draw( pre, options ) {
		var code = pre.querySelector( 'code' );
		var parsed = parse( code ? code.textContent : pre.textContent );
		var figure;

		if ( ! parsed ) {
			// Not something we can read: keep it, but out of the way.
			var keep = html( 'details', 'newtide-public-agent__chart-failed' );
			keep.appendChild( html( 'summary', null, t( 'chartFailed', 'Chart data (could not be drawn)' ) ) );
			pre.parentNode.replaceChild( keep, pre );
			keep.appendChild( pre );
			return null;
		}

		var spec = parsed.spec;
		figure = html( 'figure', 'newtide-public-agent__chart' );
		figure.npaSpec = spec;
		head( spec, figure );

		// Charts switched off, too many series, or too many bars: the table.
		if ( ! parsed.drawable || ( options && false === options.draw ) ) {
			figure.appendChild( toTable( spec ) );
			pre.parentNode.replaceChild( figure, pre );
			return figure;
		}

		if ( spec.series.length > 1 ) {
			figure.appendChild( legend( spec ) );
		}

		var plot = figure.appendChild( html( 'div', 'newtide-public-agent__chart-plot' ) );
		plot.setAttribute( 'tabindex', '0' );
		plot.setAttribute( 'role', 'group' );
		plot.setAttribute( 'aria-label', describe( spec ) );
		var tip = html( 'div', 'newtide-public-agent__chart-tip' );
		tip.hidden = true;
		tip.setAttribute( 'aria-live', 'polite' );

		figure.appendChild( dataDisclosure( spec, false ) );
		pre.parentNode.replaceChild( figure, pre );

		var state = { drawn: null, width: 0, index: -1 };

		function render() {
			var W = Math.floor( plot.clientWidth );
			if ( W < 120 || W === state.width ) {
				return;
			}
			state.width = W;
			var fs = Math.round( parseFloat( window.getComputedStyle( plot ).fontSize ) * 0.8 );
			var font = fs + 'px ' + window.getComputedStyle( plot ).fontFamily;
			var width = measurer( font );
			var drawn = 'bar' === spec.type ? drawBar( spec, plot, W, fs, width ) : drawLine( spec, plot, W, fs, width );
			if ( state.drawn ) {
				plot.replaceChild( drawn.svg, state.drawn.svg );
			} else {
				plot.insertBefore( drawn.svg, plot.firstChild );
			}
			state.drawn = drawn;
			if ( state.index >= 0 ) {
				show( state.index );
			}
		}

		function show( i ) {
			state.index = i;
			fillTip( tip, spec, i );
			var at = state.drawn.show( i );
			placeTip( tip, plot, at.x, at.y );
		}

		function hide() {
			state.index = -1;
			tip.hidden = true;
			if ( state.drawn ) {
				state.drawn.hide();
			}
		}

		plot.appendChild( tip );

		plot.addEventListener( 'pointermove', function ( e ) {
			if ( ! state.drawn ) {
				return;
			}
			var r = plot.getBoundingClientRect();
			show( state.drawn.pointFor( e.clientX - r.left, e.clientY - r.top ) );
		} );
		plot.addEventListener( 'pointerleave', function () {
			if ( document.activeElement !== plot ) {
				hide();
			}
		} );
		plot.addEventListener( 'focus', function () {
			if ( state.drawn ) {
				show( state.index >= 0 ? state.index : ( state.drawn.vertical ? 0 : state.drawn.count - 1 ) );
			}
		} );
		plot.addEventListener( 'blur', hide );
		plot.addEventListener( 'keydown', function ( e ) {
			if ( ! state.drawn ) {
				return;
			}
			var back = state.drawn.vertical ? 'ArrowUp' : 'ArrowLeft';
			var fwd = state.drawn.vertical ? 'ArrowDown' : 'ArrowRight';
			var i = state.index < 0 ? 0 : state.index;
			if ( e.key === back ) {
				i -= 1;
			} else if ( e.key === fwd ) {
				i += 1;
			} else if ( 'Home' === e.key ) {
				i = 0;
			} else if ( 'End' === e.key ) {
				i = state.drawn.count - 1;
			} else if ( 'Escape' === e.key && state.index >= 0 ) {
				// Close the readout first; a second Escape reaches the panel.
				e.stopPropagation();
				hide();
				return;
			} else {
				return;
			}
			e.preventDefault();
			show( clamp( i, 0, state.drawn.count - 1 ) );
		} );

		// Redraw at the new width when auto-grow or a drag resizes the panel.
		if ( window.ResizeObserver ) {
			var pending = false;
			new window.ResizeObserver( function () {
				if ( pending ) {
					return;
				}
				pending = true;
				window.requestAnimationFrame( function () {
					pending = false;
					render();
				} );
			} ).observe( plot );
		} else {
			window.addEventListener( 'resize', render );
		}

		render();
		return figure;
	}

	window.NPAChart = {
		parse: parse,
		draw: draw,
		toTable: toTable,
		toText: toText
	};
}() );
