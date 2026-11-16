/**
 * Live Search — suggestions as the visitor types (v1.0.0).
 *
 * Enhances the search forms already on the page (nothing is replaced, so
 * the theme keeps its look); runs on the first focus of a search field and
 * downloads the index file only then. The text rules (norm, phon, codes,
 * near) and the scorer mirror includes/class-text.php and
 * includes/class-search.php byte for byte: a change there changes this file
 * in the same commit. WAI-ARIA combobox pattern: arrows, Enter, Escape, a
 * polite live region with the number of results. No cookies, no storage,
 * no request to anyone but this site.
 */
( function () {
	'use strict';

	var C = window.lvsConfig;
	if ( ! C || ! window.fetch || ! document.querySelector ) {
		return;
	}

	/* ================================================================
	 * Text (mirror of LVS_Text)
	 * ============================================================== */

	var G2L = [
		[ 'ου', 'ou' ], [ 'ει', 'i' ], [ 'οι', 'i' ], [ 'υι', 'i' ], [ 'αι', 'e' ], [ 'ευ', 'ev' ], [ 'αυ', 'av' ],
		[ 'μπ', 'b' ], [ 'ντ', 'd' ], [ 'γκ', 'g' ], [ 'γγ', 'g' ], [ 'γχ', 'nx' ], [ 'τσ', 'ts' ], [ 'τζ', 'tz' ],
		[ 'α', 'a' ], [ 'β', 'v' ], [ 'γ', 'g' ], [ 'δ', 'd' ], [ 'ε', 'e' ], [ 'ζ', 'z' ], [ 'η', 'i' ], [ 'θ', 'th' ],
		[ 'ι', 'i' ], [ 'κ', 'k' ], [ 'λ', 'l' ], [ 'μ', 'm' ], [ 'ν', 'n' ], [ 'ξ', 'ks' ], [ 'ο', 'o' ], [ 'π', 'p' ],
		[ 'ρ', 'r' ], [ 'σ', 's' ], [ 'τ', 't' ], [ 'υ', 'i' ], [ 'φ', 'f' ], [ 'χ', 'x' ], [ 'ψ', 'ps' ], [ 'ω', 'o' ]
	];
	var G2L_MAP = {};
	G2L.forEach( function ( p ) { G2L_MAP[ p[ 0 ] ] = p[ 1 ]; } );
	var G2L_RE = new RegExp( G2L.map( function ( p ) { return p[ 0 ]; } ).join( '|' ), 'g' );

	var STEPS = [
		[ 'th', 'T' ], [ 'ch', 'x' ], [ 'kh', 'x' ], [ 'ph', 'f' ], [ '8', 'T' ],
		[ 'ou', 'U' ], [ 'ks', 'x' ], [ 'mp', 'b' ], [ 'nt', 'd' ], [ 'gk', 'g' ], [ 'gg', 'g' ], [ 'eu', 'ev' ], [ 'au', 'av' ],
		[ 'ei', 'i' ], [ 'oi', 'i' ], [ 'ai', 'e' ], [ 'u', 'i' ], [ 'y', 'i' ], [ 'w', 'o' ], [ 'b', 'v' ], [ 'j', 'tz' ],
		[ 'c', 'k' ], [ 'q', 'k' ], [ 'U', 'u' ], [ 'T', 'th' ]
	];
	var H_AT = 5;

	var RE_MARKS = /\p{Mn}+/gu;
	var RE_NON = /[^\p{L}\p{N}]+/gu;
	var RE_LETTER = /\p{L}/u;

	function norm( s ) {
		if ( ! s ) {
			return '';
		}
		s = String( s ).toLowerCase();
		if ( s.normalize ) {
			s = s.normalize( 'NFD' ).replace( RE_MARKS, '' );
		}
		s = s.replace( /ς/g, 'σ' );
		s = s.replace( /(\d)[‐‑–-](?=[\dx])/g, '$1' );
		return s.replace( RE_NON, ' ' ).trim();
	}

	function rep( w, a, b ) {
		return w.split( a ).join( b );
	}

	function phonWord( w ) {
		if ( ! w || ! RE_LETTER.test( w ) ) {
			return w;
		}
		w = w.replace( G2L_RE, function ( m ) { return G2L_MAP[ m ]; } );
		var i;
		for ( i = 0; i < H_AT; i++ ) {
			w = rep( w, STEPS[ i ][ 0 ], STEPS[ i ][ 1 ] );
		}
		if ( 'h' === w ) {
			w = 'i';
		} else {
			w = w.replace( /([aeiouyw])h$/, '$1' );
			w = rep( w, 'h', 'i' );
		}
		for ( i = H_AT; i < STEPS.length; i++ ) {
			w = rep( w, STEPS[ i ][ 0 ], STEPS[ i ][ 1 ] );
		}
		return w.replace( /(.)\1+/gu, '$1' );
	}

	function chars( s ) {
		return Array.from ? Array.from( s ) : s.split( '' );
	}

	function near( a, b ) {
		if ( a === b ) {
			return true;
		}
		var x = chars( a ), y = chars( b ), lx = x.length, ly = y.length, i, j, t;
		if ( Math.abs( lx - ly ) > 1 ) {
			return false;
		}
		if ( lx === ly ) {
			var diff = [];
			for ( i = 0; i < lx; i++ ) {
				if ( x[ i ] !== y[ i ] ) {
					diff.push( i );
					if ( diff.length > 2 ) {
						return false;
					}
				}
			}
			if ( 1 === diff.length ) {
				return true;
			}
			return 2 === diff.length && diff[ 1 ] === diff[ 0 ] + 1 && x[ diff[ 0 ] ] === y[ diff[ 1 ] ] && x[ diff[ 1 ] ] === y[ diff[ 0 ] ];
		}
		if ( lx < ly ) {
			t = x; x = y; y = t; t = lx; lx = ly; ly = t;
		}
		i = 0; j = 0;
		var skip = false;
		while ( i < lx && j < ly ) {
			if ( x[ i ] === y[ j ] ) {
				i++; j++;
			} else if ( skip ) {
				return false;
			} else {
				skip = true; i++;
			}
		}
		return true;
	}

	function isIsbn( q ) {
		var c = String( q ).toLowerCase().replace( /[\s‐‑–-]+/g, '' );
		return /^(97[89]\d{10}|\d{9}[\dx])$/.test( c );
	}

	/* ================================================================
	 * Scorer (mirror of LVS_Search)
	 * ============================================================== */

	function tokens( q, syn ) {
		var out = [];
		norm( q ).split( ' ' ).forEach( function ( w ) {
			if ( ! w || out.length >= 8 ) {
				return;
			}
			var len = chars( w ).length, dig = /\d/.test( w );
			if ( len < 2 && ! dig ) {
				return;
			}
			out.push( { t: w, p: phonWord( w ), d: dig, l: len, alts: ( syn && Object.prototype.hasOwnProperty.call( syn, w ) ) ? syn[ w ] : [] } );
		} );
		return out;
	}

	function starts( f, t ) {
		return '' !== f && -1 !== ( ' ' + f ).indexOf( ' ' + t );
	}

	function whole( f, t ) {
		return '' !== f && -1 !== ( ' ' + f + ' ' ).indexOf( ' ' + t + ' ' );
	}

	function fuzzy( f, t, len ) {
		if ( len < 4 || '' === f ) {
			return false;
		}
		var ws = f.split( ' ' );
		for ( var i = 0; i < ws.length; i++ ) {
			var w = ws[ i ], wc = chars( w ), wl = wc.length;
			if ( wl < 3 ) {
				continue;
			}
			if ( near( t, w ) ) {
				return true;
			}
			if ( wl > len && near( t, wc.slice( 0, len ).join( '' ) ) ) {
				return true;
			}
		}
		return false;
	}

	function tokenScore( tk, r ) {
		var t = tk.t, best = 0, i, a;
		if ( tk.d && t.length >= 4 && '' !== r.k ) {
			if ( whole( r.k, t ) ) {
				return 40;
			}
			if ( t.length >= 5 && starts( r.k, t ) ) {
				best = 15;
			}
		}
		if ( starts( r.n, t ) ) {
			best = Math.max( best, whole( r.n, t ) ? 12 : 10 );
		}
		if ( best < 10 && starts( r.c, t ) ) {
			best = Math.max( best, whole( r.c, t ) ? 10 : 9 );
		}
		if ( best >= 10 ) {
			return best;
		}
		for ( i = 0; i < tk.alts.length; i++ ) {
			a = tk.alts[ i ];
			if ( starts( r.n, a ) ) {
				best = Math.max( best, 8 );
			} else if ( starts( r.c, a ) ) {
				best = Math.max( best, 7 );
			} else if ( starts( r.g, a ) ) {
				best = Math.max( best, 4 );
			}
		}
		if ( best < 6 && tk.p.length >= 2 && starts( r.o, tk.p ) ) {
			best = 6;
		}
		if ( best < 5 && tk.l >= 3 && -1 !== r.n.indexOf( t ) ) {
			best = 5;
		}
		if ( best < 4 && tk.l >= 3 && -1 !== r.c.indexOf( t ) ) {
			best = 4;
		}
		if ( best < 4 && starts( r.g, t ) ) {
			best = 4;
		}
		if ( 0 === best && ! tk.d && ( fuzzy( r.n, t, tk.l ) || fuzzy( r.c, t, tk.l ) || fuzzy( r.o, tk.p, tk.l ) ) ) {
			best = 3;
		}
		return best;
	}

	// A code typed with separators («SKU-1234»): the query as one code (LVS_Search::code_query).
	function codeQuery( q ) {
		q = String( q || '' ).trim().toLowerCase();
		if ( ! /^[a-z0-9]+(?:[\-.\/_][a-z0-9]+)+$/.test( q ) || ! /\d/.test( q ) ) {
			return '';
		}
		var c = q.replace( /[^a-z0-9]+/g, '' );
		return c.length >= 4 ? c : '';
	}

	function score( tks, qn, r, qc ) {
		if ( ! tks.length ) {
			return 0;
		}
		if ( '' !== qc && whole( r.k, qc ) ) {
			return 40;
		}
		var sum = 0;
		for ( var i = 0; i < tks.length; i++ ) {
			var s = tokenScore( tks[ i ], r );
			if ( 0 === s ) {
				return 0;
			}
			sum += s;
		}
		if ( qn === r.n ) {
			sum += 30;
		} else if ( '' !== qn && 0 === r.n.indexOf( qn ) ) {
			sum += 15;
		}
		return sum;
	}

	function sortRows( list ) {
		list.sort( function ( a, b ) {
			if ( 'last' === C.oos && a.oos !== b.oos ) {
				return a.oos - b.oos;
			}
			if ( a.sc !== b.sc ) {
				return b.sc - a.sc;
			}
			if ( a.s !== b.s ) {
				return b.s - a.s;
			}
			return a.id - b.id;
		} );
		return list;
	}

	/* ================================================================
	 * Data
	 * ============================================================== */

	var DB = null, loading = null, byId = {};

	function row( a ) {
		return { id: a[ 0 ], n: a[ 1 ] || '', c: a[ 2 ] || '', g: a[ 3 ] || '', k: a[ 4 ] || '', o: a[ 5 ] || '', w: a[ 6 ] || 0, oos: a[ 7 ] || 0, s: a[ 8 ] || 0, card: a[ 9 ] || {} };
	}

	function load() {
		if ( 'file' !== C.engine || ! C.file ) {
			return Promise.resolve( null );
		}
		if ( ! loading ) {
			loading = fetch( C.file, { credentials: 'omit' } )
				.then( function ( r ) { return r.ok ? r.json() : null; } )
				.then( function ( d ) {
					if ( ! d || ! d.items ) {
						C.engine = 'server';
						return null;
					}
					DB = { items: d.items.map( row ), works: d.works || {}, cr: d.cr || [], syn: d.syn || {} };
					DB.items.forEach( function ( r ) { byId[ r.id ] = r; } );
					return DB;
				} )
				.catch( function () {
					C.engine = 'server';
					return null;
				} );
		}
		return loading;
	}

	/** Groups the ranked rows: one entry per Product Formats work. */
	function group( ranked, works ) {
		var out = [], seen = {};
		for ( var i = 0; i < ranked.length && out.length < C.limit; i++ ) {
			var r = ranked[ i ], members = [];
			if ( C.group && r.w && works[ r.w ] ) {
				if ( seen[ r.w ] ) {
					continue;
				}
				seen[ r.w ] = true;
				works[ r.w ].forEach( function ( id ) {
					if ( byId[ id ] ) {
						members.push( byId[ id ] );
					}
				} );
			}
			out.push( { r: r, members: members } );
		}
		return out;
	}

	function searchFile( q ) {
		var tks = tokens( q, DB.syn ), qn = norm( q ), qc = codeQuery( q ), hits = [];
		if ( ! tks.length ) {
			return { list: [], cr: [], total: 0 };
		}
		DB.items.forEach( function ( r ) {
			if ( 'hide' === C.oos && r.oos ) {
				return;
			}
			var s = score( tks, qn, r, qc );
			if ( s > 0 ) {
				r.sc = s;
				hits.push( r );
			}
		} );
		sortRows( hits );
		var cr = [];
		if ( C.creators ) {
			for ( var i = 0; i < DB.cr.length && cr.length < 3; i++ ) {
				var c = DB.cr[ i ], ok = true;
				for ( var j = 0; j < tks.length; j++ ) {
					if ( ! starts( c[ 2 ], tks[ j ].t ) && ! ( tks[ j ].p.length >= 2 && starts( c[ 3 ], tks[ j ].p ) ) ) {
						ok = false;
						break;
					}
				}
				if ( ok ) {
					cr.push( c );
				}
			}
		}
		return { list: group( hits, DB.works ), cr: cr, total: hits.length, exact: exactIsbn( q, hits ) };
	}

	function exactIsbn( q, hits ) {
		if ( ! C.isbn || ! isIsbn( q ) ) {
			return null;
		}
		var c = norm( q ).replace( / /g, '' ), found = [];
		hits.forEach( function ( r ) {
			if ( whole( r.k, c ) ) {
				found.push( r );
			}
		} );
		return 1 === found.length ? found[ 0 ] : null;
	}

	var ctrl = null;

	function searchServer( q ) {
		if ( ctrl && ctrl.abort ) {
			ctrl.abort();
		}
		ctrl = window.AbortController ? new AbortController() : null;
		var url = C.rest + ( -1 === C.rest.indexOf( '?' ) ? '?' : '&' ) + 'q=' + encodeURIComponent( q.slice( 0, 100 ) );
		var opt = { credentials: C.nonce ? 'same-origin' : 'omit', signal: ctrl ? ctrl.signal : undefined, headers: {} };
		if ( C.nonce ) {
			opt.headers[ 'X-WP-Nonce' ] = C.nonce;
		}
		return fetch( url, opt )
			.then( function ( r ) { return r.ok ? r.json() : null; } )
			.then( function ( d ) {
				if ( ! d || ! d.items ) {
					return { list: [], cr: [], total: 0 };
				}
				var ranked = d.items.map( row );
				ranked.concat( ( d.more || [] ).map( row ) ).forEach( function ( r ) { byId[ r.id ] = r; } );
				return { list: group( ranked, d.works || {} ), cr: d.cr || [], total: ranked.length, exact: exactIsbn( q, ranked ) };
			} )
			.catch( function () { return null; } );
	}

	/* ================================================================
	 * Output
	 * ============================================================== */

	function esc( s ) {
		return String( null == s ? '' : s ).replace( /[&<>"']/g, function ( m ) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ m ];
		} );
	}

	function safeUrl( u ) {
		return /^(https?:)?\/\//i.test( u ) || /^\//.test( u ) ? u : '';
	}

	/** The title with the typed word starts marked. */
	function mark( raw, tks ) {
		var cs = chars( raw ), nc = cs.map( function ( ch ) {
			var n = norm( ch );
			return n ? chars( n )[ 0 ] : ' ';
		} );
		var N = nc.join( '' ), on = new Array( cs.length ), hit = false;
		var ncs = chars( N );
		tks.forEach( function ( tk ) {
			if ( tk.d ) {
				return;
			}
			var t = chars( tk.t ), L = t.length;
			for ( var i = 0; i + L <= ncs.length; i++ ) {
				if ( ( 0 === i || ' ' === ncs[ i - 1 ] ) && ncs.slice( i, i + L ).join( '' ) === tk.t ) {
					for ( var j = i; j < i + L; j++ ) {
						on[ j ] = true;
					}
					hit = true;
				}
			}
		} );
		if ( ! hit ) {
			return esc( raw );
		}
		var out = '', open = false;
		cs.forEach( function ( ch, i ) {
			if ( on[ i ] && ! open ) {
				out += '<mark>';
				open = true;
			} else if ( ! on[ i ] && open ) {
				out += '</mark>';
				open = false;
			}
			out += esc( ch );
		} );
		return out + ( open ? '</mark>' : '' );
	}

	function money( v ) {
		var cur = C.cur || {}, dec = cur.dec || 0, n = Number( v );
		if ( ! isFinite( n ) ) {
			return '';
		}
		var parts = n.toFixed( dec ).split( '.' );
		parts[ 0 ] = parts[ 0 ].replace( /\B(?=(\d{3})+(?!\d))/g, cur.ts || '' );
		var num = parts.join( cur.ds || '.' ), s = cur.s || '';
		switch ( cur.pos ) {
			case 'right': return num + s;
			case 'left_space': return s + ' ' + num;
			case 'right_space': return num + ' ' + s;
			default: return s + num;
		}
	}

	function price( card ) {
		if ( ! C.price || '' === card.p || null == card.p ) {
			return '';
		}
		if ( card.q ) {
			return esc( money( card.p ) + ' – ' + money( card.q ) );
		}
		if ( card.r ) {
			return '<del>' + esc( money( card.r ) ) + '</del> <ins>' + esc( money( card.p ) ) + '</ins>';
		}
		return esc( money( card.p ) );
	}

	/* ================================================================
	 * Combobox
	 * ============================================================== */

	var uid = 0;

	function wants( input ) {
		if ( ! input || 'INPUT' !== input.tagName || 's' !== input.name || ! input.form ) {
			return false;
		}
		if ( input.closest && input.closest( '#wpadminbar' ) ) {
			return false;
		}
		if ( 'all' === C.forms ) {
			return true;
		}
		var pt = input.form.querySelector( 'input[name="post_type"]' );
		return !! ( pt && 'product' === pt.value ) || input.form.classList.contains( 'woocommerce-product-search' );
	}

	function Box( input ) {
		var self = this, form = input.form;
		uid++;
		this.input = input;
		this.form = form;
		this.id = 'lvs-list-' + uid;
		this.opts = [];
		this.active = -1;
		this.q = '';
		this.res = null;
		this.timer = 0;

		if ( 'all' === C.forms && ! form.querySelector( 'input[name="post_type"]' ) ) {
			var h = document.createElement( 'input' );
			h.type = 'hidden';
			h.name = 'post_type';
			h.value = 'product';
			form.appendChild( h );
		}
		if ( C.t.placeholder ) {
			input.setAttribute( 'placeholder', C.t.placeholder );
		}
		input.setAttribute( 'role', 'combobox' );
		input.setAttribute( 'aria-autocomplete', 'list' );
		input.setAttribute( 'aria-expanded', 'false' );
		input.setAttribute( 'aria-controls', this.id );
		input.setAttribute( 'autocomplete', 'off' );

		this.panel = document.createElement( 'div' );
		this.panel.className = 'lvs-panel';
		this.panel.id = this.id;
		this.panel.setAttribute( 'role', 'listbox' );
		this.panel.setAttribute( 'aria-label', C.t.products );
		this.panel.hidden = true;
		document.body.appendChild( this.panel );

		this.live = document.createElement( 'span' );
		this.live.className = 'lvs-sr';
		this.live.setAttribute( 'aria-live', 'polite' );
		document.body.appendChild( this.live );

		input.addEventListener( 'input', function () { self.typed(); } );
		input.addEventListener( 'keydown', function ( e ) { self.key( e ); } );
		input.addEventListener( 'focus', function () {
			if ( self.res && self.q === input.value.trim() ) {
				self.open();
			}
		} );
		input.addEventListener( 'blur', function () {
			setTimeout( function () {
				if ( ! self.panel.contains( document.activeElement ) ) {
					self.close();
				}
			}, 180 );
		} );
		this.panel.addEventListener( 'mousedown', function ( e ) {
			// Keep the focus in the field while a suggestion is clicked.
			if ( ! e.target.closest( 'a' ) ) {
				e.preventDefault();
			}
		} );
		this.panel.addEventListener( 'click', function ( e ) {
			var a = e.target.closest( 'a' );
			if ( a ) {
				self.logPick();
				return;
			}
			var o = e.target.closest( '[role="option"]' );
			if ( o ) {
				self.go( o );
			}
		} );
		form.addEventListener( 'submit', function ( e ) {
			var q = input.value.trim();
			if ( self.res && self.res.exact && self.q === q ) {
				e.preventDefault();
				self.logPick();
				window.location.href = self.res.exact.card.u;
			}
		} );
		window.addEventListener( 'resize', function () { self.place(); } );
		window.addEventListener( 'scroll', function () { self.place(); }, { passive: true } );

		load();
		if ( input.value.trim() ) {
			this.typed();
		}
	}

	Box.prototype.typed = function () {
		var self = this, q = this.input.value.trim();
		clearTimeout( this.timer );
		if ( chars( q ).length < C.min && ! isIsbn( q ) ) {
			this.q = q;
			this.res = null;
			this.close();
			return;
		}
		this.timer = setTimeout( function () { self.run( q ); }, 'file' === C.engine ? 60 : 220 );
	};

	Box.prototype.run = function ( q ) {
		var self = this;
		if ( 'file' === C.engine && ! DB && ! self.res ) {
			// The first keystroke waits for the file: say so.
			self.panel.innerHTML = '<div class="lvs-note">' + esc( C.t.loading ) + '</div>';
			self.open();
		}
		load().then( function () {
			if ( q !== self.input.value.trim() ) {
				return;
			}
			if ( 'file' === C.engine && DB ) {
				self.show( q, searchFile( q ) );
				return;
			}
			if ( ! self.res ) {
				self.panel.innerHTML = '<div class="lvs-note">' + esc( C.t.loading ) + '</div>';
				self.open();
			}
			searchServer( q ).then( function ( res ) {
				if ( res && q === self.input.value.trim() ) {
					self.show( q, res );
				}
			} );
		} );
	};

	Box.prototype.show = function ( q, res ) {
		var self = this, tks = tokens( q, DB ? DB.syn : {} ), html = '', n = 0;
		this.q = q;
		this.res = res;
		this.opts = [];
		this.active = -1;
		this.input.removeAttribute( 'aria-activedescendant' );

		if ( ! res.list.length && ! res.cr.length ) {
			this.panel.innerHTML = '<div class="lvs-note">' + esc( C.t.none ) + '</div>';
			this.say( C.t.none );
			this.open();
			return;
		}
		if ( res.list.length ) {
			html += '<div class="lvs-head" role="presentation">' + esc( C.t.products ) + '</div>';
			res.list.forEach( function ( g ) {
				var r = g.r, c = r.card || {}, id = self.id + '-' + ( n++ ), url = safeUrl( c.u );
				html += '<div class="lvs-opt" role="option" id="' + id + '" aria-selected="false" data-href="' + esc( url ) + '">';
				html += '<a class="lvs-link" tabindex="-1" href="' + esc( url ) + '">';
				if ( C.img ) {
					html += c.i ? '<img class="lvs-img" src="' + esc( safeUrl( c.i ) ) + '" alt="" width="48" height="48" loading="lazy" decoding="async">' : '<span class="lvs-img lvs-noimg" aria-hidden="true"></span>';
				}
				html += '<span class="lvs-body"><span class="lvs-title">' + mark( c.t || '', tks ) + '</span>';
				if ( C.byline && c.b ) {
					html += '<span class="lvs-by">' + esc( c.b ) + '</span>';
				}
				var badges = '';
				if ( c.o ) {
					badges += '<span class="lvs-badge lvs-pre">' + esc( C.t.preorder ) + '</span>';
				}
				if ( r.oos ) {
					badges += '<span class="lvs-badge lvs-out">' + esc( C.t.out ) + '</span>';
				}
				var pr = price( c );
				if ( pr || badges ) {
					html += '<span class="lvs-meta">' + ( pr ? '<span class="lvs-price">' + pr + '</span>' : '' ) + badges + '</span>';
				}
				html += '</span></a>';
				if ( g.members.length > 1 ) {
					html += '<span class="lvs-formats">';
					g.members.forEach( function ( m ) {
						var mc = m.card || {}, label = mc.l || mc.f || '';
						if ( label ) {
							html += '<a class="lvs-fmt' + ( m.id === r.id ? ' lvs-cur' : '' ) + '" tabindex="-1" href="' + esc( safeUrl( mc.u ) ) + '">' + esc( label ) + '</a>';
						}
					} );
					html += '</span>';
				}
				html += '</div>';
			} );
		}
		if ( res.cr.length ) {
			html += '<div class="lvs-head" role="presentation">' + esc( C.t.creators ) + '</div>';
			res.cr.forEach( function ( c ) {
				var id = self.id + '-' + ( n++ ), url = safeUrl( c[ 1 ] );
				html += '<div class="lvs-opt lvs-creator" role="option" id="' + id + '" aria-selected="false" data-href="' + esc( url ) + '"><a class="lvs-link" tabindex="-1" href="' + esc( url ) + '"><span class="lvs-body"><span class="lvs-title">' + mark( c[ 0 ], tks ) + '</span></span></a></div>';
			} );
		}
		if ( res.list.length ) {
			var id = self.id + '-' + ( n++ );
			html += '<div class="lvs-opt lvs-all" role="option" id="' + id + '" aria-selected="false" data-submit="1">' + esc( C.t.all ) + ( res.total ? ' <span class="lvs-count">(' + res.total + ')</span>' : '' ) + '</div>';
		}
		this.panel.innerHTML = html;
		this.opts = Array.prototype.slice.call( this.panel.querySelectorAll( '[role="option"]' ) );
		this.say( 1 === res.total ? C.t.count1 : String( C.t.countN ).replace( '%d', res.total ) );
		this.open();
	};

	Box.prototype.say = function ( text ) {
		var live = this.live;
		live.textContent = '';
		setTimeout( function () { live.textContent = text; }, 60 );
	};

	Box.prototype.open = function () {
		this.panel.hidden = false;
		this.input.setAttribute( 'aria-expanded', 'true' );
		this.place();
	};

	Box.prototype.close = function () {
		this.panel.hidden = true;
		this.input.setAttribute( 'aria-expanded', 'false' );
		this.input.removeAttribute( 'aria-activedescendant' );
		this.setActive( -1 );
	};

	Box.prototype.place = function () {
		if ( this.panel.hidden ) {
			return;
		}
		var r = this.input.getBoundingClientRect(), vw = document.documentElement.clientWidth || window.innerWidth;
		if ( ! r.width && ! r.height ) {
			this.close();
			return;
		}
		var left, width;
		if ( vw < 600 ) {
			left = 8;
			width = vw - 16;
		} else {
			width = Math.min( Math.max( r.width, 340 ), 560, vw - 16 );
			left = Math.min( Math.max( 8, r.left ), vw - 8 - width );
		}
		this.panel.style.top = ( r.bottom + window.pageYOffset + 4 ) + 'px';
		this.panel.style.left = ( left + window.pageXOffset ) + 'px';
		this.panel.style.width = width + 'px';
	};

	Box.prototype.setActive = function ( i ) {
		if ( this.active >= 0 && this.opts[ this.active ] ) {
			this.opts[ this.active ].setAttribute( 'aria-selected', 'false' );
			this.opts[ this.active ].classList.remove( 'lvs-active' );
		}
		this.active = i;
		if ( i >= 0 && this.opts[ i ] ) {
			var o = this.opts[ i ];
			o.setAttribute( 'aria-selected', 'true' );
			o.classList.add( 'lvs-active' );
			this.input.setAttribute( 'aria-activedescendant', o.id );
			if ( o.scrollIntoView ) {
				o.scrollIntoView( { block: 'nearest' } );
			}
		} else {
			this.input.removeAttribute( 'aria-activedescendant' );
		}
	};

	Box.prototype.key = function ( e ) {
		var n = this.opts.length;
		if ( 'ArrowDown' === e.key || 'Down' === e.key ) {
			if ( this.panel.hidden && this.res ) {
				this.open();
			}
			if ( n ) {
				e.preventDefault();
				this.setActive( ( this.active + 1 ) % n );
			}
		} else if ( 'ArrowUp' === e.key || 'Up' === e.key ) {
			if ( n ) {
				e.preventDefault();
				this.setActive( this.active <= 0 ? n - 1 : this.active - 1 );
			}
		} else if ( 'Escape' === e.key || 'Esc' === e.key ) {
			if ( ! this.panel.hidden ) {
				e.preventDefault();
				this.close();
			}
		} else if ( 'Enter' === e.key ) {
			if ( ! this.panel.hidden && this.active >= 0 && this.opts[ this.active ] ) {
				e.preventDefault();
				this.go( this.opts[ this.active ] );
			}
		}
	};

	Box.prototype.go = function ( o ) {
		if ( o.getAttribute( 'data-submit' ) ) {
			this.close();
			if ( this.form.requestSubmit ) {
				this.form.requestSubmit();
			} else {
				this.form.submit();
			}
			return;
		}
		var href = o.getAttribute( 'data-href' );
		if ( href ) {
			this.logPick();
			window.location.href = href;
		}
	};

	Box.prototype.logPick = function () {
		if ( ! C.log || ! navigator.sendBeacon || ! this.q ) {
			return;
		}
		try {
			navigator.sendBeacon( C.log, new Blob( [ JSON.stringify( { q: this.q.slice( 0, 100 ), n: this.res ? this.res.total : 0 } ) ], { type: 'application/json' } ) );
		} catch ( err ) {
			// Statistics never stop a visitor.
		}
	};

	function init( input ) {
		if ( input.getAttribute( 'data-lvs' ) || ! wants( input ) ) {
			return;
		}
		input.setAttribute( 'data-lvs', '1' );
		try {
			new Box( input ); // eslint-disable-line no-new
		} catch ( err ) {
			// The theme's form keeps working as before.
		}
	}

	document.addEventListener( 'focusin', function ( e ) { init( e.target ); }, true );
	if ( document.activeElement ) {
		init( document.activeElement );
	}
	// Forms that should look ready: the store's placeholder from the start.
	if ( C.t.placeholder ) {
		var ready = function () {
			Array.prototype.forEach.call( document.querySelectorAll( 'form input[name="s"]' ), function ( i ) {
				if ( wants( i ) ) {
					i.setAttribute( 'placeholder', C.t.placeholder );
				}
			} );
		};
		if ( 'loading' === document.readyState ) {
			document.addEventListener( 'DOMContentLoaded', ready );
		} else {
			ready();
		}
	}

	// For tests and for other scripts: the same text rules as the server.
	window.lvsText = { norm: norm, codeQuery: codeQuery, phon: function ( s ) { return norm( s ).split( ' ' ).map( phonWord ).join( ' ' ); }, near: near };
}() );
