/**
 * Revenue Splitter — admin JS (vanilla, zero dependencies) — v1.3.1.
 *  1. Live add/remove γραμμών δικαιούχων
 *  2. Ζωντανό Σ=100% validation (χρώμα, comma-tolerant)
 *  3. Toggle «global defaults» στο metabox
 *
 * v1.3.1 FIX (#4): ΑΦΑΙΡΕΘΗΚΕ η ενότητα 4 (Period presets / bindPresets)
 * μαζί με τον helper isoLocal() — στοχεύε selectors (#rs-period-preset,
 * [name="rs_date_start"/"rs_date_end"]) που δεν υπάρχουν σε ΚΑΝΕΝΑ
 * v1.3.1 markup: το φίλτρο περιόδου του dashboard είναι πλέον αμιγώς
 * server-side (select[name="rs_period"] + rs_start/rs_end σε GET submit).
 * Ο κώδικας δεν εκτελούνταν ποτέ — καθαρό orphan.
 */
(function () {
	'use strict';

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
		if (!el) return;

		el.textContent = String(total);

		// Matches το .rs-total.ok / .rs-total.bad στο admin.css
		var ok = Math.abs(total - 100) <= 0.05;
		el.classList.toggle('ok', ok);
		el.classList.toggle('bad', !ok);
	}

	function removeRow(e, table) {
		var rows = table.querySelectorAll('tbody tr');
		if (rows.length > 1) {
			e.preventDefault();
			e.target.closest('tr').remove();
			recalcTable(table);
		}
	}

	function addRow(table) {
		var tbody = table.querySelector('tbody');
		var firstRow = tbody ? tbody.querySelector('tr') : null;
		if (!firstRow) return;

		var clone = firstRow.cloneNode(true);

		// Καθαρά inputs στο clone.
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
				// closest(): ανοσία σε μελλοντικό wrap (icon/span μέσα στο button).
				if (e.target.closest('.rs-remove-row')) {
					removeRow(e, table);
				}
			});

				var container = table.closest('.rs-rows');
				if (container) {
					container.addEventListener('click', function (e) {
						if (e.target.classList.contains('rs-add-row')) {
							e.preventDefault();
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
	 * v1.3.8 (#2): Φίλτρο αναζήτησης στο multi-select προϊόντων του
	 * Dashboard (#rs-prod-search → #rs-prod-multi). Delegated — δεν
	 * χρειάζεται init hook, τρέχει όπου υπάρχει το markup.
	 * ------------------------------------------------------------------ */

	document.addEventListener('input', function (e) {
		if (!e.target.matches('#rs-prod-search')) return;

		var sel = document.getElementById('rs-prod-multi');
		if (!sel) return;

		var q = String(e.target.value || '').trim().toLowerCase();

		for (var i = 0; i < sel.options.length; i++) {
			var t = sel.options[i].textContent.toLowerCase();
			sel.options[i].hidden = (q !== '' && t.indexOf(q) === -1);
		}
	});


	/* ------------------------------------------------------------------
	 * Init
	 * ------------------------------------------------------------------ */

	document.addEventListener('DOMContentLoaded', function () {
		bindRows(document);
		// v1.3.1 (#4): κανένα bindPresets() εδώ — δες κεφαλίδα.
	});
})();