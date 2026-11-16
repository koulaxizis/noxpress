/*
 * Product Tabs — click-to-load player.
 *
 * The page holds a poster button (data-src, data-title); a click swaps it
 * for the iframe, so YouTube / Vimeo load only after the visitor asks.
 * One delegated listener; window.PTB_PLAYER guards against a second copy.
 * Only https addresses of the two players are accepted.
 */
( function () {
	if ( window.PTB_PLAYER ) {
		return;
	}
	window.PTB_PLAYER = true;

	var ok = /^https:\/\/(www\.youtube-nocookie\.com\/embed\/|player\.vimeo\.com\/video\/)/;

	document.addEventListener( 'click', function ( e ) {
		var b = e.target && e.target.closest ? e.target.closest( 'button.ptb-play' ) : null;
		if ( ! b || ! b.parentNode ) {
			return;
		}
		var src = b.getAttribute( 'data-src' ) || '';
		if ( ! ok.test( src ) ) {
			return;
		}
		e.preventDefault();
		var f = document.createElement( 'iframe' );
		f.src = src;
		f.title = b.getAttribute( 'data-title' ) || '';
		f.allow = 'accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; fullscreen';
		f.allowFullscreen = true;
		f.setAttribute( 'referrerpolicy', 'strict-origin-when-cross-origin' );
		b.parentNode.replaceChild( f, b );
		f.focus();
	} );
}() );
