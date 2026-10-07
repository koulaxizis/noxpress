/**
 * Smart Formatter — admin.js (vanilla, delegated events, ES5+safe).
 *
 * Dependencies: SF_CFG (wp_localize_script) — ajaxUrl, nonce, i18n.
 * Καμία βιβλιοθήκη. Καμία inline εκτύπωση innerHTML από data —
 * ΠΑΝΤΑ DOM APIs / textContent (XSS-safe by construction).
 */
(function () {
	'use strict';

	var CFG = window.SF_CFG || { ajaxUrl: '', nonce: '', i18n: {} };
	var T = CFG.i18n || {};

	function $(sel, root) { return (root || document).querySelector(sel); }
	function $$(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }

	/* ---------- AJAX helper (POST, form-encoded) ---------- */
	function post(action, data) {
		var body = new URLSearchParams();
		body.append('action', action);
		body.append('nonce', CFG.nonce);
		Object.keys(data || {}).forEach(function (k) {
			var v = data[k];
			if (Array.isArray(v)) {
				v.forEach(function (item) { body.append(k + '[]', item); });
			} else {
				body.append(k, v);
			}
		});
		return fetch(CFG.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: body.toString()
		}).then(function (r) { return r.json(); });
	}

	/* ---------- Form → payload ---------- */
	function payload() {
		var mode = ($$('input[name="sf_mode"]').filter(function (r) { return r.checked; })[0] || {}).value || 'all';
		var p = {
			sf_mode: mode,
			sf_fields: $$('input[name="sf_fields[]"]:checked').map(function (c) { return c.value; }),
			sf_rules: $$('input[name="sf_rules[]"]:checked').map(function (c) { return c.value; }),
			sf_exclusions_raw: [],
			sf_products: $('#sf-products') ? $$('#sf-products option:checked').map(function (o) { return o.value; }) : [],
			sf_terms: $('#sf-terms') ? $$('#sf-terms option:checked').map(function (o) { return o.value; }) : []
		};
		var exclRaw = $('#sf-exclusions') ? $('#sf-exclusions').value : '';
		exclRaw.split(/\r\n|\r|\n/).forEach(function (line) {
			line = line.trim();
			if (line) { p.sf_exclusions_raw.push(line); }
		});
		return p;
	}

	/* ---------- Mode pickers ---------- */
	function onModeChange() {
		var mode = payload().sf_mode;
		$('#sf-pick-products').classList.toggle('sf-hidden', mode !== 'products');
		$('#sf-pick-terms').classList.toggle('sf-hidden', !(mode === 'categories' || mode === 'tags'));

		var termsSel = $('#sf-terms');
		if ((mode === 'categories' || mode === 'tags') && !termsSel.dataset.loaded) {
			post('sf_search', { type: mode }).then(function (res) {
				if (!res || !res.success) { return; }
				termsSel.textContent = '';
				(res.data.items || []).forEach(function (t) {
					var opt = document.createElement('option');
					opt.value = String(t.id);
					opt.textContent = t.title;
					termsSel.appendChild(opt);
				});
				termsSel.dataset.loaded = '1';
			});
		}
	}

	/* ---------- Product search (από τον 3ο χαρακτήρα, debounced) ---------- */
	var searchTimer = null;
	$('#sf-prod-search').addEventListener('input', function () {
		var q = this.value.trim();
		clearTimeout(searchTimer);
		if (q.length < 3) { return; }
		searchTimer = setTimeout(function () {
			post('sf_search', { type: 'products', q: q }).then(function (res) {
				if (!res || !res.success) { return; }
				var sel = $('#sf-products');
				var keep = {};
				$$('#sf-products option:checked').forEach(function (o) { keep[o.value] = o.textContent; });
				sel.textContent = '';
				(res.data.items || []).forEach(function (p) {
					var opt = document.createElement('option');
					opt.value = String(p.id);
					opt.textContent = p.title;
					if (keep[opt.value]) { opt.selected = true; delete keep[opt.value]; }
					sel.appendChild(opt);
				});
				Object.keys(keep).forEach(function (v) {
					var opt = document.createElement('option');
					opt.value = v;
					opt.textContent = keep[v];
					opt.selected = true;
					sel.appendChild(opt);
				});
			});
		}, 300);
	});

	/* ---------- Results rendering (XSS-safe: textContent μόνο) ---------- */
	function renderEntries(entries) {
		var tb = $('#sf-entries');
		tb.textContent = '';
		(entries || []).forEach(function (e) {
			var tr = document.createElement('tr');

			var td1 = document.createElement('td');
			td1.textContent = e.title || '';
			tr.appendChild(td1);

			var td2 = document.createElement('td');
			td2.textContent = e.field || '';
			tr.appendChild(td2);

			var td3 = document.createElement('td');
			var b3 = document.createElement('code');
			b3.textContent = e.before || '';
			td3.appendChild(b3);
			tr.appendChild(td3);

			var td4 = document.createElement('td');
			var b4 = document.createElement('code');
			b4.textContent = e.after || '';
			td4.appendChild(b4);
			if (!e.changed) { td4.className = 'sf-muted'; }
			tr.appendChild(td4);

			if (e.error) {
				var td5 = document.createElement('td');
				var warn = document.createElement('em');
				warn.textContent = e.error;
				warn.className = 'sf-warn';
				td5.appendChild(warn);
				tr.appendChild(td5);
			}
			tb.appendChild(tr);
		});
	}

	function showResults() { $('#sf-results').classList.remove('sf-hidden'); }
	function setSummary(text) { $('#sf-summary').textContent = text || ''; }
	function busy(btn, on) { if (btn) { btn.disabled = !!on; } }

	/* ---------- Preview ---------- */
	$('#sf-btn-preview').addEventListener('click', function () {
		busy(this, true);
		post('sf_preview', payload()).then(function (res) {
			busy($('#sf-btn-preview'), false);
			showResults();
			$('#sf-progress-wrap').classList.add('sf-hidden');
			if (!res || !res.success) { setSummary((res && res.data && res.data.message) || T.fail); return; }
			renderEntries(res.data.entries);
			setSummary(res.data.changed + ' / ' + res.data.applied_units + ' (sample 5)');
		}).catch(function () { busy($('#sf-btn-preview'), false); setSummary(T.fail); });
	});

	/* ---------- Dry run ---------- */
	$('#sf-btn-dryrun').addEventListener('click', function () {
		busy(this, true);
		setSummary(T.working);
		showResults();
		$('#sf-progress-wrap').classList.add('sf-hidden');
		post('sf_dryrun', payload()).then(function (res) {
			busy($('#sf-btn-dryrun'), false);
			if (!res || !res.success) { setSummary((res && res.data && res.data.message) || T.fail); return; }
			if (res.data.changed === 0) { setSummary(T.nothing); renderEntries([]); return; }
			setSummary(res.data.changed + ' / ' + res.data.total_units);
			renderEntries([]);
		}).catch(function () { busy($('#sf-btn-dryrun'), false); setSummary(T.fail); });
	});

	/* ---------- Apply (batched, με progress) ---------- */
	$('#sf-btn-apply').addEventListener('click', function () {
		if (!window.confirm(T.confirmApply)) { return; }
		busy(this, true);
		showResults();
		var base = payload();
		var barWrap = $('#sf-progress-wrap');
		var bar = $('#sf-progress-bar');
		var label = $('#sf-progress-label');
		barWrap.classList.remove('sf-hidden');
		bar.style.width = '0%';
		label.textContent = T.working;

		var totalChanged = 0;

		post('sf_apply', Object.assign({ phase: 'start' }, base)).then(function (res) {
			if (!res || !res.success) { throw new Error((res && res.data && res.data.message) || T.fail); }
			var runId = res.data.run_id;
			var batch = res.data.batch || 20;

			function runBatch(offset) {
				return post('sf_apply', Object.assign({ phase: 'batch', run_id: runId, offset: offset }, base))
					.then(function (r) {
						if (!r || !r.success) { throw new Error((r && r.data && r.data.message) || T.fail); }
						totalChanged += r.data.changed || 0;
						renderEntries(r.data.entries.slice(0, 10));
						var pct = r.data.total_units ? Math.round((r.data.next_offset / r.data.total_units) * 100) : 100;
						bar.style.width = pct + '%';
						label.textContent = r.data.next_offset + ' / ' + r.data.total_units;
						if (r.data.has_more) {
							return runBatch(r.data.next_offset);
						}
						return post('sf_apply', Object.assign({ phase: 'finish', run_id: runId, total_changed: totalChanged }, base));
					});
			}

			return runBatch(0);
		}).then(function () {
			busy($('#sf-btn-apply'), false);
			bar.style.width = '100%';
			label.textContent = T.done;
			setSummary(totalChanged + '');
			window.setTimeout(function () { window.location.reload(); }, 1200);
		}).catch(function (err) {
			busy($('#sf-btn-apply'), false);
			label.textContent = err && err.message ? err.message : T.fail;
		});
	});

	/* ---------- Profiles ---------- */
	$('#sf-profile-save').addEventListener('click', function () {
		var name = ($('#sf-profile-name').value || '').trim();
		var p = payload();
		var self = this;
		busy(self, true);
		post('sf_profile_save', { name: name, rules: p.sf_rules, fields: p.sf_fields }).then(function (res) {
			busy(self, false);
			var msg = $('#sf-profile-msg');
			if (res && res.success) {
				msg.textContent = '✓';
				window.setTimeout(function () { window.location.reload(); }, 700);
			} else {
				msg.textContent = (res && res.data && res.data.message) || T.fail;
			}
		});
	});

	$('#sf-profile-delete').addEventListener('click', function () {
		var sel = $('#sf-profile-load');
		var name = sel.value;
		var self = this;
		if (!name) { $('#sf-profile-msg').textContent = '—'; return; }
		busy(self, true);
		post('sf_profile_delete', { name: name }).then(function (res) {
			busy(self, false);
			if (res && res.success) { window.location.reload(); }
			else { $('#sf-profile-msg').textContent = (res && res.data && res.data.message) || T.fail; }
		});
	});

	$('#sf-profile-load').addEventListener('change', function () {
		var name = this.value;
		if (!name) { return; }
		// Φόρτωση: ζητάμε τα saved rules/fields από το DOM της σελίδας
		// (rendered στα data-attributes του option από το PHP; απλούστερα:
		// ένα επιπλέον fetch δεν χρειάζεται — χρησιμοποιούμε data attrs).
		var opt = this.options[this.selectedIndex];
		if (!opt || !opt.dataset.rules) { return; }
		var rules = JSON.parse(opt.dataset.rules || '[]');
		var fields = JSON.parse(opt.dataset.fields || '[]');
		$$('input[name="sf_rules[]"]').forEach(function (c) { c.checked = rules.indexOf(c.value) !== -1; });
		$$('input[name="sf_fields[]"]').forEach(function (c) { c.checked = fields.indexOf(c.value) !== -1; });
	});

	/* ---------- History: units / restore / delete (delegated) ---------- */
	$('#sf-history').addEventListener('click', function (ev) {
		var btn = ev.target.closest('button');
		if (!btn) { return; }
		var tr = btn.closest('tr[data-run]');
		if (!tr) { return; }
		var runId = tr.getAttribute('data-run');

		if (btn.classList.contains('sf-btn-units')) {
			busy(btn, true);
			post('sf_units', { run_id: runId }).then(function (res) {
				busy(btn, false);
				if (!res || !res.success) { return; }
				var panel = $('#sf-units-panel');
				panel.classList.remove('sf-hidden');
				panel.textContent = '';

				var ul = document.createElement('ul');
				ul.className = 'sf-units-list';
				(res.data.units || []).forEach(function (u) {
					var li = document.createElement('li');
					var cb = document.createElement('input');
					cb.type = 'checkbox';
					cb.className = 'sf-unit-cb';
					cb.value = String(u.idx !== undefined ? u.idx : '');
					li.appendChild(cb);
					var span = document.createElement('span');
					span.textContent = u.kind + ' #' + u.id + ' — ' + (u.field_label || u.field || '');
					li.appendChild(span);
					ul.appendChild(li);
				});
				panel.appendChild(ul);

				if ((res.data.units || []).length) {
					var act = document.createElement('div');
					act.className = 'sf-actions';
					var rb = document.createElement('button');
					rb.type = 'button';
					rb.className = 'button';
					rb.id = 'sf-btn-restore-sel';
					rb.textContent = '↩';
					rb.addEventListener('click', function () { restoreUnits(runId); });
					act.appendChild(rb);
					panel.appendChild(act);
				}
			});
		}

		if (btn.classList.contains('sf-btn-restore')) {
			if (!window.confirm('↩ ?')) { return; }
			busy(btn, true);
			post('sf_restore', { run_id: runId, indexes: [] }).then(function (res) {
				busy(btn, false);
				window.alert(res && res.success
					? (res.data.restored + ' ✓ / ' + res.data.skipped + ' —')
					: T.fail);
			});
		}

		if (btn.classList.contains('sf-btn-delete')) {
			if (!window.confirm(T.confirmDel)) { return; }
			busy(btn, true);
			post('sf_snapshot_delete', { run_id: runId }).then(function () { window.location.reload(); });
		}
	});

	function restoreUnits(runId) {
		var idxs = $$('.sf-unit-cb').filter(function (c) { return c.checked; }).map(function (c) { return c.value; });
		if (!idxs.length) { return; }
		post('sf_restore', { run_id: runId, indexes: idxs }).then(function (res) {
			window.alert(res && res.success
				? (res.data.restored + ' ✓')
				: T.fail);
		});
	}

	/* ---------- Init ---------- */
	$$('input[name="sf_mode"]').forEach(function (r) { r.addEventListener('change', onModeChange); });
	onModeChange();
})();