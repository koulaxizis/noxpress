/* Visitor Language — geo.js (Bible §23).
 * Shows the hidden EL / EN switch to a visitor outside Greece. The country
 * comes from the site's own Cloudflare (/cdn-cgi/trace, same origin), so
 * nothing goes to a third party; only the two-letter code is kept, in
 * sessionStorage, for the rest of the visit. No Cloudflare: nothing shows. */
(function () {
	'use strict';
	var nav = document.querySelector('.vlg-switch[data-vlg-geo]');
	if (!nav || !window.fetch) {
		return;
	}
	var KEY = 'vlg_loc';
	function show(loc) {
		if (/^[A-Z][A-Z0-9]$/.test(loc) && loc !== 'GR' && loc !== 'XX') {
			nav.hidden = false;
		}
	}
	var loc = null;
	try {
		loc = window.sessionStorage.getItem(KEY);
	} catch (e) {}
	if (loc !== null) {
		show(loc);
		return;
	}
	fetch('/cdn-cgi/trace', { credentials: 'omit', cache: 'no-store' })
		.then(function (r) { return r.ok ? r.text() : ''; })
		.then(function (t) {
			var m = /(?:^|\n)loc=([A-Z0-9]{2})/.exec(t);
			loc = m ? m[1] : '';
			try {
				window.sessionStorage.setItem(KEY, loc);
			} catch (e) {}
			show(loc);
		})
		.catch(function () {});
}());
