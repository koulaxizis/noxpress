/* Fast Pages: lazy CSS backgrounds and YouTube on click (inline in cached pages only). */
(function () {
	if (window.fpgFront) { return; }
	window.fpgFront = 1;
	var d = document, root = d.documentElement;
	function show(el) { el.classList.remove('fpg-lazy-bg'); }
	function bgs() {
		var els = d.querySelectorAll('.fpg-lazy-bg');
		if (!els.length) { return; }
		if (!('IntersectionObserver' in window)) {
			root.className = root.className.replace(/\bfpg-js\b/, '');
			return;
		}
		var io = new IntersectionObserver(function (list) {
			list.forEach(function (e) {
				if (e.isIntersecting) { show(e.target); io.unobserve(e.target); }
			});
		}, { rootMargin: '300px 0px' });
		for (var i = 0; i < els.length; i++) { io.observe(els[i]); }
	}
	d.addEventListener('click', function (ev) {
		var b = ev.target && ev.target.closest ? ev.target.closest('button.fpg-yt') : null;
		if (!b || !b.parentNode) { return; }
		var src = b.getAttribute('data-src') || '';
		if (!/^https:\/\/www\.youtube-nocookie\.com\/embed\//.test(src)) { return; }
		var f = d.createElement('iframe');
		f.src = src;
		f.title = b.getAttribute('data-title') || '';
		f.className = 'fpg-yt-frame';
		f.allow = 'accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share';
		f.allowFullscreen = true;
		f.setAttribute('referrerpolicy', 'strict-origin-when-cross-origin');
		if (b.getAttribute('style')) { f.setAttribute('style', b.getAttribute('style')); }
		b.parentNode.replaceChild(f, b);
		f.focus();
	});
	if (d.readyState === 'loading') { d.addEventListener('DOMContentLoaded', bgs); } else { bgs(); }
})();
