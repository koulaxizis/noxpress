/**
 * Smart Formatter — admin.js v1.1.0 (vanilla, delegated events, ES5+safe).
 *
 * Dependencies: SF_CFG (wp_localize_script) — ajaxUrl, nonce, i18n.
 * Φορτώνεται μόνο στη σελίδα του εργαλείου· κάθε binding είναι null-safe.
 * Καμία βιβλιοθήκη. Καμία inline εκτύπωση innerHTML από data —
 * ΠΑΝΤΑ DOM APIs / textContent (XSS-safe by construction).
 */
(function () {
	'use strict';

	var CFG = window.SF_CFG || { ajaxUrl: '', nonce: '', i18n: {} };
	var T = CFG.i18n || {};

	function $(sel, root) { return (root || document).querySelector(sel); }
	function $$(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }
	/* Null-safe binding: αν το στοιχείο δεν υπάρχει, απλώς δεν δένεται. */
	function on(sel, evt, fn) { var el = $(sel); if (el) { el.addEventListener(evt, fn); } return el; }
	/* Μικρό sprintf για τα localized %1$s / %2$s / %s. */
	function fmt(str) {
		var args = Array.prototype.slice.call(arguments, 1), seq = 0;
		return String(str || '').replace(/%(?:(\d+)\$)?[sd]/g, function (m, n) {
			var v = n ? args[parseInt(n, 10) - 1] : args[seq++];
			return v === undefined ? m : String(v);
		});
	}
	function errMsg(res) { return (res && res.data && res.data.message) || T.fail; }

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
		var pp = $('#sf-pick-products'), pt = $('#sf-pick-terms'), termsSel = $('#sf-terms');
		if (pp) { pp.classList.toggle('sf-hidden', mode !== 'products'); }
		if (pt) { pt.classList.toggle('sf-hidden', !(mode === 'categories' || mode === 'tags')); }
		if (!termsSel) { return; }

		// Κατηγορίες ↔ ετικέτες: άλλη λίστα όρων → επαναφόρτωση (και
		// καθάρισμα, ώστε IDs κατηγοριών να μη σταλούν ως ετικέτες).
		if ((mode === 'categories' || mode === 'tags') && termsSel.dataset.loaded !== mode) {
			termsSel.textContent = '';
			termsSel.dataset.loaded = '';
			post('sf_search', { type: mode }).then(function (res) {
				if (!res || !res.success || payload().sf_mode !== mode) { return; }
				termsSel.textContent = '';
				(res.data.items || []).forEach(function (t) {
					var opt = document.createElement('option');
					opt.value = String(t.id);
					opt.textContent = t.title;
					termsSel.appendChild(opt);
				});
				termsSel.dataset.loaded = mode;
			});
		}
	}

	/* ---------- Product search (από τον 3ο χαρακτήρα, debounced) ---------- */
	var searchTimer = null;
	on('#sf-prod-search', 'input', function () {
		var q = this.value.trim();
		clearTimeout(searchTimer);
		if (q.length < 3) { return; }
		searchTimer = setTimeout(function () {
			post('sf_search', { type: 'products', q: q }).then(function (res) {
				if (!res || !res.success) { return; }
				var sel = $('#sf-products');
				if (!sel) { return; }
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
		if (!tb) { return; }
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

	function showResults() { var r = $('#sf-results'); if (r) { r.classList.remove('sf-hidden'); } }
	function hideProgress() { var w = $('#sf-progress-wrap'); if (w) { w.classList.add('sf-hidden'); } }
	function setSummary(text) { var s = $('#sf-summary'); if (s) { s.textContent = text || ''; } }
	function busy(btn, state) { if (btn) { btn.disabled = !!state; } }

	/* ---------- Preview ---------- */
	on('#sf-btn-preview', 'click', function () {
		var btn = this;
		busy(btn, true);
		post('sf_preview', payload()).then(function (res) {
			busy(btn, false);
			showResults();
			hideProgress();
			if (!res || !res.success) { setSummary(errMsg(res)); return; }
			renderEntries(res.data.entries);
			setSummary(fmt(T.previewSummary, res.data.changed, res.data.applied_units));
		}).catch(function () { busy(btn, false); setSummary(T.fail); });
	});

	/* ---------- Dry run (batched: counts + μικρό δείγμα) ---------- */
	on('#sf-btn-dryrun', 'click', function () {
		var btn = this;
		var base = payload();
		var changed = 0;
		var sample = [];
		busy(btn, true);
		setSummary(T.working);
		showResults();
		hideProgress();
		renderEntries([]);

		function step(offset) {
			return post('sf_dryrun', Object.assign({ offset: offset }, base)).then(function (res) {
				if (!res || !res.success) { throw new Error(errMsg(res)); }
				changed += res.data.changed || 0;
				(res.data.entries || []).forEach(function (e) { if (sample.length < 10) { sample.push(e); } });
				setSummary(T.working + ' ' + res.data.next_offset + ' / ' + res.data.total_units);
				if (res.data.has_more && res.data.applied_units > 0) { return step(res.data.next_offset); }
				return res.data.total_units;
			});
		}

		step(0).then(function (total) {
			busy(btn, false);
			if (changed === 0) { setSummary(T.nothing); return; }
			setSummary(fmt(T.dryrunSummary, changed, total));
			renderEntries(sample);
		}).catch(function (err) {
			busy(btn, false);
			setSummary(err && err.message ? err.message : T.fail);
		});
	});

	/* ---------- Apply (batched, με progress) ---------- */
	on('#sf-btn-apply', 'click', function () {
		var barWrap = $('#sf-progress-wrap');
		var bar = $('#sf-progress-bar');
		var label = $('#sf-progress-label');
		if (!barWrap || !bar || !label) { return; }
		if (!window.confirm(T.confirmApply)) { return; }
		var btn = this;
		busy(btn, true);
		showResults();
		var base = payload();
		barWrap.classList.remove('sf-hidden');
		bar.style.width = '0%';
		label.textContent = T.working;

		var totalChanged = 0;
		var sample = [];

		post('sf_apply', Object.assign({ phase: 'start' }, base)).then(function (res) {
			if (!res || !res.success) { throw new Error(errMsg(res)); }
			var runId = res.data.run_id;

			function runBatch(offset) {
				return post('sf_apply', Object.assign({ phase: 'batch', run_id: runId, offset: offset }, base))
					.then(function (r) {
						if (!r || !r.success) { throw new Error(errMsg(r)); }
						totalChanged += r.data.changed || 0;
						(r.data.entries || []).forEach(function (e) { if (sample.length < 10) { sample.push(e); } });
						renderEntries(sample);
						var pct = r.data.total_units ? Math.round((r.data.next_offset / r.data.total_units) * 100) : 100;
						bar.style.width = pct + '%';
						label.textContent = r.data.next_offset + ' / ' + r.data.total_units;
						if (r.data.has_more && r.data.applied_units > 0) {
							return runBatch(r.data.next_offset);
						}
						return post('sf_apply', Object.assign({ phase: 'finish', run_id: runId, total_changed: totalChanged }, base));
					});
			}

			return runBatch(0);
		}).then(function (fin) {
			if (!fin || !fin.success) { throw new Error(errMsg(fin)); }
			busy(btn, false);
			bar.style.width = '100%';
			label.textContent = T.done;
			setSummary(fmt(T.applySummary, totalChanged));
			window.setTimeout(function () { window.location.reload(); }, 1500);
		}).catch(function (err) {
			busy(btn, false);
			label.textContent = err && err.message ? err.message : T.fail;
		});
	});

	/* ---------- Profiles ---------- */
	function profileMsg(text) { var m = $('#sf-profile-msg'); if (m) { m.textContent = text; } }

	on('#sf-profile-save', 'click', function () {
		var nameEl = $('#sf-profile-name');
		var name = ((nameEl && nameEl.value) || '').trim();
		var p = payload();
		var self = this;
		busy(self, true);
		post('sf_profile_save', { name: name, rules: p.sf_rules, fields: p.sf_fields }).then(function (res) {
			busy(self, false);
			if (res && res.success) {
				profileMsg(T.saved);
				window.setTimeout(function () { window.location.reload(); }, 700);
			} else {
				profileMsg(errMsg(res));
			}
		}).catch(function () { busy(self, false); profileMsg(T.fail); });
	});

	on('#sf-profile-delete', 'click', function () {
		var sel = $('#sf-profile-load');
		var name = sel ? sel.value : '';
		var self = this;
		if (!name) { profileMsg(T.pickProfile); return; }
		busy(self, true);
		post('sf_profile_delete', { name: name }).then(function (res) {
			busy(self, false);
			if (res && res.success) { window.location.reload(); }
			else { profileMsg(errMsg(res)); }
		}).catch(function () { busy(self, false); profileMsg(T.fail); });
	});

	on('#sf-profile-load', 'change', function () {
		var name = this.value;
		if (!name) { return; }
		// Τα saved rules/fields έρχονται από τα data-attributes του option.
		var opt = this.options[this.selectedIndex];
		if (!opt || !opt.dataset.rules) { return; }
		var rules = JSON.parse(opt.dataset.rules || '[]');
		var fields = JSON.parse(opt.dataset.fields || '[]');
		$$('input[name="sf_rules[]"]').forEach(function (c) { c.checked = rules.indexOf(c.value) !== -1; });
		$$('input[name="sf_fields[]"]').forEach(function (c) { c.checked = fields.indexOf(c.value) !== -1; });
	});

	/* ---------- Restore result → πραγματικό μήνυμα ---------- */
	function restoreReport(res) {
		var d = (res && res.data) || {};
		var lines = [];
		if (res && res.success) {
			lines.push(fmt(T.restoredMsg, d.restored || 0, d.skipped || 0));
		} else {
			lines.push(errMsg(res));
		}
		if (d.partial) { lines.push(T.partialMsg); }
		if (res && res.success && d.errors && d.errors.length) { lines = lines.concat(d.errors); }
		window.alert(lines.join('\n'));
	}

	/* ---------- History: units / restore / delete (delegated) ---------- */
	on('#sf-history', 'click', function (ev) {
		var btn = ev.target.closest('button');
		if (!btn) { return; }
		var tr = btn.closest('tr[data-run]');
		if (!tr) { return; }
		var runId = tr.getAttribute('data-run');

		if (btn.classList.contains('sf-btn-units')) {
			busy(btn, true);
			post('sf_units', { run_id: runId }).then(function (res) {
				busy(btn, false);
				var panel = $('#sf-units-panel');
				if (!panel) { return; }
				if (!res || !res.success) { window.alert(errMsg(res)); return; }
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
					span.textContent = (u.kind === 'term' ? T.kindTerm : T.kindProduct) + ' #' + u.id + ' — ' + (u.field_label || u.field || '');
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
					rb.textContent = T.restoreSel;
					rb.addEventListener('click', function () { restoreUnits(runId); });
					act.appendChild(rb);
					panel.appendChild(act);
				}
			}).catch(function () { busy(btn, false); window.alert(T.fail); });
		}

		if (btn.classList.contains('sf-btn-restore')) {
			var partial = tr.getAttribute('data-partial') === '1';
			if (!window.confirm(partial ? T.confirmRestoreP : T.confirmRestore)) { return; }
			busy(btn, true);
			post('sf_restore', { run_id: runId, indexes: [] }).then(function (res) {
				busy(btn, false);
				restoreReport(res);
			}).catch(function () { busy(btn, false); window.alert(T.fail); });
		}

		if (btn.classList.contains('sf-btn-delete')) {
			if (!window.confirm(T.confirmDel)) { return; }
			busy(btn, true);
			post('sf_snapshot_delete', { run_id: runId }).then(function (res) {
				if (res && res.success) { window.location.reload(); return; }
				busy(btn, false);
				window.alert(errMsg(res));
			}).catch(function () { busy(btn, false); window.alert(T.fail); });
		}
	});

	function restoreUnits(runId) {
		var idxs = $$('.sf-unit-cb').filter(function (c) { return c.checked; }).map(function (c) { return c.value; });
		if (!idxs.length) { return; }
		if (!window.confirm(T.confirmRestoreS)) { return; }
		post('sf_restore', { run_id: runId, indexes: idxs }).then(restoreReport)
			.catch(function () { window.alert(T.fail); });
	}

	/* ---------- Init ---------- */
	$$('input[name="sf_mode"]').forEach(function (r) { r.addEventListener('change', onModeChange); });
	onModeChange();
})();