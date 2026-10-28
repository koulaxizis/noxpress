/**
 * Live Operator — admin.js (v1.3.0)
 *
 * The schedule table: «Copy Monday to all weekdays» (Tue–Fri). Only
 * fills inputs; nothing is saved until the form is submitted.
 */
(function () {
	'use strict';

	var table = document.querySelector('[data-lop-sched]');
	var btn = document.querySelector('[data-lop-copy]');
	if (!table || !btn) {
		return;
	}
	btn.addEventListener('click', function () {
		var mon = table.querySelectorAll('tr[data-day="1"] input');
		for (var day = 2; day <= 5; day++) {
			var row = table.querySelectorAll('tr[data-day="' + day + '"] input');
			for (var i = 0; i < row.length && i < mon.length; i++) {
				row[i].value = mon[i].value;
			}
		}
	});
})();
