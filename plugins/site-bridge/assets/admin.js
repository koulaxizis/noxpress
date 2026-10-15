/**
 * Site Bridge — admin JS (vanilla, zero dependencies).
 *  1. data-sbr-copy="<id>": copies the text of that element (code or textarea).
 *  2. data-sbr-confirm="<question>": asks before the form is submitted.
 */
(function () {
	'use strict';

	var copied = (window.sbrAdmin && window.sbrAdmin.copied) || 'Copied';

	function textOf(el) {
		return ('value' in el && el.tagName !== 'CODE') ? el.value : el.textContent;
	}

	function fallbackCopy(text) {
		var ta = document.createElement('textarea');
		ta.value = text;
		ta.setAttribute('readonly', '');
		ta.style.position = 'fixed';
		ta.style.opacity = '0';
		document.body.appendChild(ta);
		ta.select();
		try { document.execCommand('copy'); } catch (e) { /* nothing more to try */ }
		document.body.removeChild(ta);
	}

	function flash(btn) {
		var old = btn.textContent;
		btn.textContent = copied;
		btn.disabled = true;
		setTimeout(function () {
			btn.textContent = old;
			btn.disabled = false;
		}, 1500);
	}

	document.addEventListener('click', function (e) {
		var btn = e.target.closest ? e.target.closest('[data-sbr-copy]') : null;
		if (btn) {
			var el = document.getElementById(btn.getAttribute('data-sbr-copy'));
			if (!el) return;
			var text = String(textOf(el)).trim();
			if (navigator.clipboard && window.isSecureContext) {
				navigator.clipboard.writeText(text).then(function () { flash(btn); }, function () { fallbackCopy(text); flash(btn); });
			} else {
				fallbackCopy(text);
				flash(btn);
			}
			return;
		}
		var ask = e.target.closest ? e.target.closest('[data-sbr-confirm]') : null;
		if (ask && !window.confirm(ask.getAttribute('data-sbr-confirm'))) {
			e.preventDefault();
		}
	});
})();
