/**
 * Forms — proof of work (Web Worker, also loaded as a plain script for the
 * fallback without workers).
 *
 * Finds the smallest n with SHA-256( token + ":" + n ) starting with `d`
 * zero bits; the server checks it with one hash (FRM_Spam::check_pow).
 * Plain SHA-256 in JavaScript (the strings are short ASCII: base64url, a dot,
 * a colon and digits), faster than crypto.subtle for many tiny hashes.
 */
( function ( root ) {
	'use strict';

	var K = [
		0x428a2f98, 0x71374491, 0xb5c0fbcf, 0xe9b5dba5, 0x3956c25b, 0x59f111f1, 0x923f82a4, 0xab1c5ed5,
		0xd807aa98, 0x12835b01, 0x243185be, 0x550c7dc3, 0x72be5d74, 0x80deb1fe, 0x9bdc06a7, 0xc19bf174,
		0xe49b69c1, 0xefbe4786, 0x0fc19dc6, 0x240ca1cc, 0x2de92c6f, 0x4a7484aa, 0x5cb0a9dc, 0x76f988da,
		0x983e5152, 0xa831c66d, 0xb00327c8, 0xbf597fc7, 0xc6e00bf3, 0xd5a79147, 0x06ca6351, 0x14292967,
		0x27b70a85, 0x2e1b2138, 0x4d2c6dfc, 0x53380d13, 0x650a7354, 0x766a0abb, 0x81c2c92e, 0x92722c85,
		0xa2bfe8a1, 0xa81a664b, 0xc24b8b70, 0xc76c51a3, 0xd192e819, 0xd6990624, 0xf40e3585, 0x106aa070,
		0x19a4c116, 0x1e376c08, 0x2748774c, 0x34b0bcb5, 0x391c0cb3, 0x4ed8aa4a, 0x5b9cca4f, 0x682e6ff3,
		0x748f82ee, 0x78a5636f, 0x84c87814, 0x8cc70208, 0x90befffa, 0xa4506ceb, 0xbef9a3f7, 0xc67178f2
	];
	var W = new Array( 64 );

	/** The first 32-bit word of SHA-256 of an ASCII string, and the second. */
	function sha256head( s ) {
		var len = s.length;
		var blocks = ( ( len + 8 ) >> 6 ) + 1;
		var words = new Array( blocks * 16 );
		var i;
		for ( i = 0; i < words.length; i++ ) {
			words[ i ] = 0;
		}
		for ( i = 0; i < len; i++ ) {
			words[ i >> 2 ] |= ( s.charCodeAt( i ) & 0xff ) << ( 24 - ( i % 4 ) * 8 );
		}
		words[ len >> 2 ] |= 0x80 << ( 24 - ( len % 4 ) * 8 );
		words[ blocks * 16 - 1 ] = len * 8;

		var h0 = 0x6a09e667, h1 = 0xbb67ae85, h2 = 0x3c6ef372, h3 = 0xa54ff53a;
		var h4 = 0x510e527f, h5 = 0x9b05688c, h6 = 0x1f83d9ab, h7 = 0x5be0cd19;
		for ( var b = 0; b < blocks; b++ ) {
			var a = h0, bb = h1, c = h2, d = h3, e = h4, f = h5, g = h6, h = h7, t;
			for ( i = 0; i < 64; i++ ) {
				if ( i < 16 ) {
					W[ i ] = words[ b * 16 + i ] | 0;
				} else {
					var x = W[ i - 15 ], y = W[ i - 2 ];
					var s0 = ( ( x >>> 7 ) | ( x << 25 ) ) ^ ( ( x >>> 18 ) | ( x << 14 ) ) ^ ( x >>> 3 );
					var s1 = ( ( y >>> 17 ) | ( y << 15 ) ) ^ ( ( y >>> 19 ) | ( y << 13 ) ) ^ ( y >>> 10 );
					W[ i ] = ( W[ i - 16 ] + s0 + W[ i - 7 ] + s1 ) | 0;
				}
				var S1 = ( ( e >>> 6 ) | ( e << 26 ) ) ^ ( ( e >>> 11 ) | ( e << 21 ) ) ^ ( ( e >>> 25 ) | ( e << 7 ) );
				var ch = ( e & f ) ^ ( ~e & g );
				var t1 = ( h + S1 + ch + K[ i ] + W[ i ] ) | 0;
				var S0 = ( ( a >>> 2 ) | ( a << 30 ) ) ^ ( ( a >>> 13 ) | ( a << 19 ) ) ^ ( ( a >>> 22 ) | ( a << 10 ) );
				var maj = ( a & bb ) ^ ( a & c ) ^ ( bb & c );
				t = ( S0 + maj ) | 0;
				h = g; g = f; f = e; e = ( d + t1 ) | 0; d = c; c = bb; bb = a; a = ( t1 + t ) | 0;
			}
			h0 = ( h0 + a ) | 0; h1 = ( h1 + bb ) | 0; h2 = ( h2 + c ) | 0; h3 = ( h3 + d ) | 0;
			h4 = ( h4 + e ) | 0; h5 = ( h5 + f ) | 0; h6 = ( h6 + g ) | 0; h7 = ( h7 + h ) | 0;
		}
		return [ h0 >>> 0, h1 >>> 0 ];
	}

	function zeroBits( head ) {
		var n = 0;
		for ( var w = 0; w < head.length; w++ ) {
			if ( head[ w ] === 0 ) {
				n += 32;
				continue;
			}
			return n + Math.clz32( head[ w ] );
		}
		return n;
	}

	/** Search from `from` for `count` numbers; returns the answer or -1. */
	function search( token, bits, from, count ) {
		var prefix = token + ':';
		for ( var n = from; n < from + count; n++ ) {
			if ( zeroBits( sha256head( prefix + n ) ) >= bits ) {
				return n;
			}
		}
		return -1;
	}

	root.frmPow = { search: search, sha256head: sha256head };

	// As a worker: { t, d } in, { n } out.
	if ( typeof root.document === 'undefined' && typeof root.postMessage === 'function' ) {
		root.onmessage = function ( ev ) {
			var t = ev.data && ev.data.t ? String( ev.data.t ) : '';
			var d = ev.data && ev.data.d ? parseInt( ev.data.d, 10 ) : 0;
			var n = 0;
			var found = -1;
			while ( found < 0 && n < 1e9 ) {
				found = search( t, d, n, 50000 );
				n += 50000;
			}
			root.postMessage( { n: found } );
		};
	}
}( typeof self !== 'undefined' ? self : this ) );
