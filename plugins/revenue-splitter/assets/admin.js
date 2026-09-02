/**
 * Revenue Splitter — admin JS (vanilla, zero dependencies).
 *  1. Live add/remove γραμμών δικαιούχων
 *  2. Ζωντανό Σ=100% validation (χρώμα)
 *  3. Toggle «global defaults» στο metabox
 *  4. Period presets στο dashboard
 */
(function () {
	'use strict';

	/* ------------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------------ */

	/** Τοπική (όχι UTC) ημερομηνία σε 'YYYY-MM-DD'. */
	function isoLocal(date) {
		var y = date.getFullYear();
		var m = String(date.getMonth() + 1).padStart(2, '0');
		var d = String(date.getDate()).padStart(2, '0');
		return y + '-' + m + '-' + d;
	}

	/* ------------------------------------------------------------------
	 * 1 + 2: Beneficiary rows — live validation
	 * ------------------------------------------------------------------ */

	function recalcTable(table) {
		var total = 0;
		var inputs = table.querySelectorAll('.rs-ben-pct');

		for (var i = 0; i < inputs.length; i++) {
			var v = parseFloat(String(inputs[i].value).replace(',', '.'));
			if (!isNaN(v)) total += v;
		}
		total = Math.round(total * 100) / 100;

		var wrap = table.closest('.rs-rows');
		var el = wrap ? wrap.querySelector('.rs-total') : null;
		if (!el) return { ok: true, total: total };

		el.textContent = String(total);

		var ok = Math.abs(total - 100) <= 0.05;
		el.classList.toggle('ok', ok);
		el.classList.toggle('bad', !ok); // ΄matches το .rs-total.bad στο CSS

		return { ok: ok, total: total };
	}

	function removeRow(e, table) {
		var rows = table.querySelectorAll('tbody tr');
		if (rows.length > 1) {
			e.target.closest('tr').remove();
			recalcTable(table);
		}
	}

	function addRow(table) {
		var tbody = table.querySelector('tbody');
		var firstRow = tbody ? tbody.querySelector('tr') : null;
		if (!firstRow) return;

		var clone = firstRow.cloneNode(true);

		// Καθαρό παιχνίδι inputs στο clone.
		var inputs = clone.querySelectorAll('input');
		for (var i = 0; i < inputs.length; i++) {
			inputs[i].value = '';
		}

		tbody.appendChild(clone);
		recalcTable(table);
	}

	function bindRows(root) {
		var tables = root.querySelectorAll('table.rs-split-table');

		for (var i = 0; i < tables.length; i++) {
			(function (table) {

				recalcTable(table);

				table.addEventListener('input', function (e) {
					if (e.target.classList.contains('rs-ben-pct')) {
						recalcTable(table);
					}
				});

				table.addEventListener('click', function (e) {
					if (e.target.classList.contains('rs-remove-row')) {
						removeRow(e, table);
					}
				});

				var container = table.closest('.rs-rows');
				if (container) {
					container.addEventListener('click', function (e) {
						if (e.target.classList.contains('rs-add-row')) {
							addRow(table);
						}
					});
				}
			})(tables[i]);
		}
	}

	/* ------------------------------------------------------------------
	 * 3: Fallback toggle στο product metabox
	 * ------------------------------------------------------------------ */

	document.addEventListener('change', function (e) {
		if (!e.target.matches('input[name="rs_use_fallback"]')) return;

		var wrap = e.target.closest('.rs-split-wrap');
		var rows = wrap ? wrap.querySelector('.rs-rows') : null;
		if (rows) {
			rows.classList.toggle('rs-hidden', e.target.checked);
		}
	});

	/* ------------------------------------------------------------------
	 * 4: Period presets (dashboard)
	 * ------------------------------------------------------------------ */

	function bindPresets() {
		var presetSel = document.getElementById('rs-period-preset');
		if (!presetSel) return;

		var form = presetSel.closest('form');
		if (!form) return;

		var start = form.querySelector('[name="rs_date_start"]');
		var end = form.querySelector('[name="rs_date_end"]');
		if (!start || !end) return;

		presetSel.addEventListener('change', function () {
			var today = new Date();

			switch (presetSel.value) {
				case '7': {
					var d = new Date(today.getTime() - 6 * 864e5);
					start.value = isoLocal(d);
					end.value = isoLocal(today);
					break;
				}
				case '30': {
					var d30 = new Date(today.getTime() - 29 * 864e5);
					start.value = isoLocal(d30);
					end.value = isoLocal(today);
					break;
				}
				case 'month':
					start.value = isoLocal(new Date(today.getFullYear(), today.getMonth(), 1));
					end.value = isoLocal(today);
					break;
				case 'year':
					start.value = today.getFullYear() + '-01-01';
					end.value = isoLocal(today);
					break;
				// 'custom': τα date inputs μένουν ως έχουν.
			}
		});
	}

	/* ------------------------------------------------------------------
	 * Init
	 * ------------------------------------------------------------------ */

	document.addEventListener('DOMContentLoaded', function () {
		bindRows(document);
		bindPresets();
	});
})();