/*
 * Home Sections — click-to-load player for [hs_video].
 *
 * The page holds a poster button (data-src, data-kind, data-title); a
 * click swaps it for the iframe, so YouTube loads only after the
 * visitor asks for it. One delegated listener; window.HMS_YT guards
 * against a second copy. A button that a page script of its own has
 * already replaced (it listens on the button, so it runs first) is no
 * longer in the document and is skipped.
 */
( function () {
	if ( window.HMS_YT ) {
		return;
	}
	window.HMS_YT = true;

	var cfg = window.HMS_YT_CFG || {};
	var cls = typeof cfg.button === 'string' && cfg.button ? cfg.button : 'glk-yt';

	function button( n ) {
		while ( n && n !== document ) {
			if ( n.nodeType === 1 && n.tagName === 'BUTTON' && n.classList.contains( cls ) && n.hasAttribute( 'data-src' ) ) {
				return n;
			}
			n = n.parentNode;
		}
		return null;
	}

	document.addEventListener( 'click', function ( e ) {
		var b = button( e.target );
		if ( ! b || ! b.parentNode || ! document.documentElement.contains( b ) ) {
			return;
		}
		var src = b.getAttribute( 'data-src' ) || '';
		if ( ! /^https:\/\//.test( src ) ) {
			return;
		}
		var sp = b.getAttribute( 'data-kind' ) === 'spotify';
		var f = document.createElement( 'iframe' );
		f.src = src;
		f.title = b.getAttribute( 'data-title' ) || '';
		f.allow = sp ? 'autoplay; clipboard-write; encrypted-media; fullscreen; picture-in-picture' : 'accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; fullscreen';
		f.allowFullscreen = true;
		var w = document.createElement( 'div' );
		w.className = b.className + ( sp ? ' ' + cls + '--spotify' : '' );
		w.appendChild( f );
		b.parentNode.replaceChild( w, b );
	} );
}() );
