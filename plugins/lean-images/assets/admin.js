/**
 * Lean Images — admin JS (vanilla, zero dependencies).
 *  data-lim-confirm="<question>" on a form: asks before it is submitted.
 * Without JavaScript every form still works (the enable form also needs
 * its "I agree" checkbox, checked on the server).
 */
(function () {
	'use strict';

	document.addEventListener('submit', function (e) {
		var form = e.target;
		if (!form || !form.getAttribute) {
			return;
		}
		var ask = form.getAttribute('data-lim-confirm');
		if (ask && !window.confirm(ask)) {
			e.preventDefault();
		}
	});
})();
