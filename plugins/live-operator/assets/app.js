/**
 * Live Operator — the operator app (vanilla JS, no build step).
 *
 * Talks to two REST namespaces with the logged-in cookie + wp_rest nonce:
 *   live-operator/v1        switch and push devices (this plugin)
 *   listeo/v1/live-handoff  PurioChat's own operator routes (threads,
 *                           claim, take-over, reply, return-to-ai), the same
 *                           ones its Live Chat admin page uses
 *
 * Polls only while the page is visible: the inbox every 5 s, an open
 * conversation every 3 s, the switch every 30 s. In the background the
 * notifications (service worker) take over. User text always goes in with
 * textContent, never as HTML.
 */
(function () {
	'use strict';

	var cfgEl = document.getElementById('lop-config');
	var root = document.getElementById('lop-app');
	if (!cfgEl || !root) {
		return;
	}
	var cfg = JSON.parse(cfgEl.textContent || '{}');
	var L = cfg.i18n || {};

	var state = cfg.state || {};
	var skew = state.now ? state.now * 1000 - Date.now() : 0;
	var threads = [];
	var threadsAt = 0;
	var view = { cid: '', thread: null, messages: [], lastId: 0, busy: false, els: null };
	var push = { supported: false, sub: null, busy: false, note: '' };
	var banner = '';
	var switchBusy = false;
	var timers = {};

	/* ---------- helpers ---------- */

	function t(key) {
		var s = L[key] || key;
		var args = Array.prototype.slice.call(arguments, 1);
		var i = 0;
		return s.replace(/%(\d\$)?[sd]/g, function (m, pos) {
			var v = pos ? args[parseInt(pos, 10) - 1] : args[i++];
			return v === undefined ? '' : String(v);
		});
	}

	function h(tag, attrs, kids) {
		var el = document.createElement(tag);
		attrs = attrs || {};
		Object.keys(attrs).forEach(function (k) {
			var v = attrs[k];
			if (v === null || v === undefined || v === false) {
				return;
			}
			if (k === 'class') {
				el.className = v;
			} else if (k === 'text') {
				el.textContent = v;
			} else if (k.indexOf('on') === 0) {
				el.addEventListener(k.slice(2), v);
			} else {
				el.setAttribute(k, v === true ? '' : v);
			}
		});
		(kids || []).forEach(function (c) {
			if (c === null || c === undefined || c === false) {
				return;
			}
			el.appendChild(typeof c === 'string' ? document.createTextNode(c) : c);
		});
		return el;
	}

	function now() {
		return Date.now() + skew;
	}

	function hhmm(ts) {
		var d = new Date(ts);
		return ('0' + d.getHours()).slice(-2) + ':' + ('0' + d.getMinutes()).slice(-2);
	}

	/** «Y-m-d H:i:s» in UTC (PurioChat's columns) → ms. */
	function utc(s) {
		if (!s) {
			return 0;
		}
		var ms = Date.parse(String(s).replace(' ', 'T') + 'Z');
		return isNaN(ms) ? 0 : ms;
	}

	function dur(sec) {
		sec = Math.max(0, Math.round(sec));
		if (sec < 60) {
			return sec + 's';
		}
		var m = Math.floor(sec / 60);
		if (m < 60) {
			return m + "'" + ('0' + (sec % 60)).slice(-2) + '"';
		}
		return Math.floor(m / 60) + 'h ' + (m % 60) + "'";
	}

	function excerpt(s, n) {
		s = String(s || '').replace(/\s+/g, ' ').trim();
		return s.length > n ? s.slice(0, n - 1) + '…' : s;
	}

	function safeUrl(u) {
		return /^https?:\/\//i.test(String(u || '')) ? String(u) : '';
	}

	function b64u(buf) {
		var bytes = new Uint8Array(buf);
		var s = '';
		for (var i = 0; i < bytes.length; i++) {
			s += String.fromCharCode(bytes[i]);
		}
		return btoa(s).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
	}

	function unb64u(s) {
		s = String(s).replace(/-/g, '+').replace(/_/g, '/');
		while (s.length % 4) {
			s += '=';
		}
		var raw = atob(s);
		var out = new Uint8Array(raw.length);
		for (var i = 0; i < raw.length; i++) {
			out[i] = raw.charCodeAt(i);
		}
		return out;
	}

	function uuid() {
		if (window.crypto && crypto.randomUUID) {
			return crypto.randomUUID();
		}
		return 'lop-' + Date.now().toString(36) + '-' + Math.random().toString(36).slice(2);
	}

	/* ---------- REST ---------- */

	function restUrl(path, query) {
		var base = cfg.rest || '/wp-json/';
		var sep = base.indexOf('?') >= 0 ? '&' : '?';
		return base + path + (query ? sep + query : '');
	}

	var reloading = false;

	function loggedOut() {
		if (reloading) {
			return;
		}
		reloading = true;
		setBanner(t('loggedOut'));
		setTimeout(function () {
			location.reload();
		}, 1500);
	}

	function refreshNonce() {
		return fetch(cfg.nonceUrl, { credentials: 'same-origin', cache: 'no-store' })
			.then(function (r) {
				return r.text();
			})
			.then(function (txt) {
				txt = String(txt || '').trim();
				if (!/^[a-f0-9]{10}$/i.test(txt)) {
					throw new Error('logged-out');
				}
				cfg.nonce = txt;
			});
	}

	function api(method, path, body, query, retried) {
		var opts = {
			method: method,
			credentials: 'same-origin',
			cache: 'no-store',
			headers: { 'X-WP-Nonce': cfg.nonce, Accept: 'application/json' }
		};
		if (body !== undefined) {
			opts.headers['Content-Type'] = 'application/json';
			opts.body = JSON.stringify(body);
		}
		return fetch(restUrl(path, query), opts).then(
			function (r) {
				return r.text().then(function (txt) {
					var data = null;
					try {
						data = txt ? JSON.parse(txt) : null;
					} catch (e) {
						data = null;
					}
					if (r.ok) {
						if (banner === t('netError')) {
							setBanner('');
						}
						return data;
					}
					var code = data && data.code ? data.code : '';
					if (code === 'rest_cookie_invalid_nonce' && !retried) {
						return refreshNonce().then(
							function () {
								return api(method, path, body, query, true);
							},
							function () {
								loggedOut();
								throw { status: 401, code: 'logged-out' };
							}
						);
					}
					if (r.status === 401 || (r.status === 403 && code === 'rest_forbidden')) {
						loggedOut();
					}
					throw { status: r.status, code: code, message: data && data.message ? data.message : '' };
				});
			},
			function () {
				setBanner(t('netError'));
				throw { status: 0, code: 'network' };
			}
		);
	}

	function lop(method, path, body) {
		return api(method, cfg.ns + path, body);
	}

	function purio(method, path, body, query) {
		return api(method, cfg.purio + path, body, query);
	}

	/* ---------- banner ---------- */

	var bannerEl = h('div', { class: 'lop-banner', role: 'status', hidden: true });

	function setBanner(text) {
		banner = text || '';
		bannerEl.textContent = banner;
		bannerEl.hidden = !banner;
	}

	/* ---------- switch ---------- */

	function loadState() {
		return lop('GET', '/state').then(function (s) {
			if (s) {
				state = s;
				skew = s.now ? s.now * 1000 - Date.now() : skew;
			}
			renderTop();
		}, function () {});
	}

	function setOnline(on) {
		if (switchBusy) {
			return;
		}
		switchBusy = true;
		renderTop();
		lop('POST', '/state', { online: !!on }).then(
			function (s) {
				state = s || state;
				switchBusy = false;
				renderTop();
			},
			function () {
				switchBusy = false;
				setBanner(t('failed'));
				renderTop();
			}
		);
	}

	/* ---------- push ---------- */

	function swReg() {
		return navigator.serviceWorker.register(cfg.sw, { scope: cfg.scope });
	}

	function sameKey(sub, key) {
		try {
			var k = sub.options && sub.options.applicationServerKey;
			return !k || b64u(k) === key;
		} catch (e) {
			return true;
		}
	}

	function sendSub(sub) {
		return lop('POST', '/push', {
			endpoint: sub.endpoint,
			p256dh: b64u(sub.getKey('p256dh')),
			auth: b64u(sub.getKey('auth'))
		});
	}

	/** On load: register the worker and keep the site's copy of this device in sync. */
	function initPush() {
		push.supported = 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window;
		if (!push.supported) {
			renderTop();
			return;
		}
		navigator.serviceWorker.addEventListener('message', function (e) {
			if (e.data && e.data.type === 'lop-open' && typeof e.data.url === 'string') {
				openUrl(e.data.url);
			}
		});
		swReg()
			.then(function (reg) {
				return reg.pushManager.getSubscription().then(function (sub) {
					if (!sub || Notification.permission !== 'granted' || !state.push || !state.push.available) {
						push.sub = sub && Notification.permission === 'granted' ? sub : null;
						return;
					}
					return lop('GET', '/push').then(function (p) {
						cfg.key = p && p.key ? p.key : cfg.key;
						if (!sameKey(sub, cfg.key)) {
							// The site's keys changed: subscribe again with the new one.
							return sub.unsubscribe().then(function () {
								return subscribe(reg);
							});
						}
						push.sub = sub;
						return sendSub(sub);
					});
				});
			})
			.catch(function () {})
			.then(renderTop);
	}

	function subscribe(reg) {
		return reg.pushManager
			.subscribe({ userVisibleOnly: true, applicationServerKey: unb64u(cfg.key) })
			.then(function (sub) {
				push.sub = sub;
				return sendSub(sub);
			});
	}

	function enablePush() {
		if (push.busy) {
			return;
		}
		push.busy = true;
		push.note = '';
		renderTop();
		Notification.requestPermission()
			.then(function (perm) {
				if (perm !== 'granted') {
					throw { code: 'denied' };
				}
				return Promise.all([swReg(), lop('GET', '/push')]);
			})
			.then(function (res) {
				cfg.key = res[1] && res[1].key ? res[1].key : cfg.key;
				return res[0].pushManager.getSubscription().then(function (old) {
					return old ? old.unsubscribe() : null;
				}).then(function () {
					return subscribe(res[0]);
				});
			})
			.then(function (r) {
				if (r && state.push) {
					state.push.devices = r.devices;
				}
			})
			.catch(function (e) {
				push.note = e && e.code === 'denied' ? t('pushDenied') : t('failed');
			})
			.then(function () {
				push.busy = false;
				renderTop();
			});
	}

	function disablePush() {
		var sub = push.sub;
		if (!sub || push.busy) {
			return;
		}
		push.busy = true;
		renderTop();
		lop('DELETE', '/push', { endpoint: sub.endpoint })
			.catch(function () {})
			.then(function () {
				return sub.unsubscribe();
			})
			.catch(function () {})
			.then(function () {
				push.sub = null;
				push.busy = false;
				push.note = '';
				renderTop();
			});
	}

	function testPush() {
		if (push.busy) {
			return;
		}
		push.busy = true;
		renderTop();
		lop('POST', '/push/test')
			.then(function (r) {
				push.note = r && r.sent > 0 ? t('pushTestSent') : t('pushTestFail');
			}, function () {
				push.note = t('pushTestFail');
			})
			.then(function () {
				push.busy = false;
				renderTop();
			});
	}

	/* ---------- layout ---------- */

	var topEl = h('section', { class: 'lop-top' });
	var mainEl = h('main', { class: 'lop-main' });
	root.textContent = '';
	root.appendChild(bannerEl);
	root.appendChild(topEl);
	root.appendChild(mainEl);

	function renderTop() {
		topEl.textContent = '';
		var p = state.purio || {};
		var on = !!state.online;

		var head = h('div', { class: 'lop-switch' + (on ? ' is-on' : '') }, [
			h('div', { class: 'lop-switch__state' }, [
				h('span', { class: 'lop-dot', 'aria-hidden': 'true' }),
				h('strong', { text: on ? t('online') : t('offline') }),
				on && state.until ? h('span', { class: 'lop-muted', text: ' ' + t('until', hhmm(state.until * 1000)) }) : null
			]),
			h('div', { class: 'lop-switch__btns' }, state.enabled
				? (on
					? [
						h('button', { type: 'button', class: 'lop-btn', disabled: switchBusy, onclick: function () { setOnline(true); }, text: t('extend') }),
						h('button', { type: 'button', class: 'lop-btn lop-btn--ghost', disabled: switchBusy, onclick: function () { setOnline(false); }, text: t('goOffline') })
					]
					: [h('button', { type: 'button', class: 'lop-btn lop-btn--go', disabled: switchBusy, onclick: function () { setOnline(true); }, text: t('goOnline') })])
				: [])
		]);
		topEl.appendChild(head);

		if (!state.enabled) {
			topEl.appendChild(h('p', { class: 'lop-note lop-note--warn' }, [t('switchOff') + ' ', h('a', { href: cfg.settings, text: t('settings') })]));
		}
		if (!p.live) {
			topEl.appendChild(h('p', { class: 'lop-note lop-note--warn', text: t('noPurio') }));
		} else if (p.mode && p.mode !== 'online') {
			topEl.appendChild(h('p', { class: 'lop-note lop-note--warn', text: t('modeWarn') }));
		}

		// Notifications for this device.
		var row = [];
		if (!(state.push && state.push.available)) {
			row.push(h('span', { class: 'lop-muted', text: t('pushServer') }));
		} else if (!push.supported) {
			row.push(h('span', { class: 'lop-muted', text: t('pushNone') }));
		} else if (Notification.permission === 'denied') {
			row.push(h('span', { class: 'lop-muted', text: t('pushDenied') }));
		} else if (push.sub) {
			row.push(h('span', { class: 'lop-ok', text: '✓ ' + t('pushReady') }));
			row.push(h('span', { class: 'lop-push__btns' }, [
				h('button', { type: 'button', class: 'lop-btn lop-btn--small', disabled: push.busy, onclick: testPush, text: t('pushTest') }),
				h('button', { type: 'button', class: 'lop-btn lop-btn--small lop-btn--ghost', disabled: push.busy, onclick: disablePush, text: t('pushOffBtn') })
			]));
		} else {
			row.push(h('span', { class: 'lop-muted', text: t('pushOff') }));
			row.push(h('button', { type: 'button', class: 'lop-btn lop-btn--small', disabled: push.busy, onclick: enablePush, text: t('pushOn') }));
		}
		topEl.appendChild(h('div', { class: 'lop-push' }, row));
		if (push.note) {
			topEl.appendChild(h('p', { class: 'lop-note', text: push.note }));
		}
	}

	/* ---------- inbox ---------- */

	var GROUPS = [
		['waiting', 'waiting'],
		['human', 'active'],
		['ai', 'withAi'],
		['past', 'past']
	];

	function loadThreads() {
		return purio('GET', '/threads').then(function (r) {
			threads = r && Array.isArray(r.threads) ? r.threads : [];
			threadsAt = Date.now();
			var waiting = threads.filter(function (x) { return x.inbox_group === 'waiting'; }).length;
			document.title = (waiting ? '(' + waiting + ') ' : '') + (document.title.replace(/^\(\d+\)\s*/, ''));
			if (!view.cid) {
				renderList();
			}
		}, function () {});
	}

	function waitSeconds(th) {
		return (parseInt(th.wait_seconds, 10) || 0) + (Date.now() - threadsAt) / 1000;
	}

	function renderList() {
		mainEl.textContent = '';
		var any = false;
		var timeout = (state.purio && state.purio.timeout) || 0;
		GROUPS.forEach(function (g) {
			var items = threads.filter(function (x) { return x.inbox_group === g[0]; });
			if (!items.length) {
				return;
			}
			any = true;
			var list = h('ul', { class: 'lop-list' });
			items.forEach(function (th) {
				var meta = [];
				if (g[0] === 'waiting') {
					var w = waitSeconds(th);
					meta.push(h('span', { class: 'lop-tag lop-tag--warn', 'data-wait': th.conversation_id, text: t('waitFor', dur(w)) }));
					if (timeout) {
						meta.push(h('span', { class: 'lop-muted', 'data-left': th.conversation_id, text: t('leftFor', dur(timeout - w)) }));
					}
				} else if (g[0] === 'human' && th.assigned_user_id && parseInt(th.assigned_user_id, 10) !== cfg.user) {
					meta.push(h('span', { class: 'lop-tag', text: th.assigned_name || t('assignedOther') }));
				}
				var when = utc(th.last_message_at);
				list.appendChild(h('li', {}, [
					h('a', { class: 'lop-item lop-item--' + g[0], href: '#c=' + encodeURIComponent(th.conversation_id) }, [
						h('span', { class: 'lop-item__top' }, [
							h('strong', { text: th.visitor_name || th.visitor_email || t('visitor') }),
							when ? h('span', { class: 'lop-muted', text: hhmm(when) }) : null
						]),
						h('span', { class: 'lop-item__msg', text: excerpt(th.last_message, 120) }),
						meta.length ? h('span', { class: 'lop-item__meta' }, meta) : null
					])
				]));
			});
			mainEl.appendChild(h('h2', { class: 'lop-group', text: t(g[1]) + ' · ' + items.length }));
			mainEl.appendChild(list);
		});
		if (!any) {
			mainEl.appendChild(h('p', { class: 'lop-empty', text: t('empty') }));
		}
		if (timeout) {
			mainEl.appendChild(h('p', { class: 'lop-hint', text: t('timeoutHint', timeout) }));
		}
		mainEl.appendChild(h('p', { class: 'lop-hint' }, [h('a', { href: cfg.inbox, text: t('inbox') }), ' · ', h('a', { href: cfg.settings, text: t('settings') })]));
	}

	/** Every second: the waiting counters, without a new request. */
	function tick() {
		if (view.cid || !threadsAt) {
			return;
		}
		var timeout = (state.purio && state.purio.timeout) || 0;
		threads.forEach(function (th) {
			if (th.inbox_group !== 'waiting') {
				return;
			}
			var w = waitSeconds(th);
			var a = mainEl.querySelector('[data-wait="' + cssEsc(th.conversation_id) + '"]');
			if (a) {
				a.textContent = t('waitFor', dur(w));
			}
			var b = mainEl.querySelector('[data-left="' + cssEsc(th.conversation_id) + '"]');
			if (b) {
				b.textContent = t('leftFor', dur(timeout - w));
			}
		});
		if (state.online && state.until && now() > state.until * 1000) {
			loadState();
		}
	}

	function cssEsc(s) {
		return window.CSS && CSS.escape ? CSS.escape(s) : String(s).replace(/["\\]/g, '\\$&');
	}

	/* ---------- one conversation ---------- */

	function openThread(cid) {
		view.cid = cid;
		view.thread = null;
		view.messages = [];
		view.lastId = 0;
		mainEl.textContent = '';
		var msgs = h('div', { class: 'lop-msgs', role: 'log' });
		var actions = h('div', { class: 'lop-actions' });
		var input = h('textarea', { class: 'lop-input', rows: '2', placeholder: t('placeholder'), 'aria-label': t('placeholder') });
		var send = h('button', { type: 'submit', class: 'lop-btn lop-btn--go', text: t('send') });
		var form = h('form', { class: 'lop-composer', hidden: true }, [input, send]);
		form.addEventListener('submit', function (e) {
			e.preventDefault();
			reply();
		});
		input.addEventListener('keydown', function (e) {
			if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) {
				e.preventDefault();
				reply();
			}
		});
		var title = h('strong', { class: 'lop-thread__name', text: t('visitor') });
		var sub = h('span', { class: 'lop-thread__sub lop-muted' });
		var bar = h('div', { class: 'lop-thread__bar' }, [
			h('a', { class: 'lop-btn lop-btn--ghost lop-btn--small', href: '#', text: '‹ ' + t('back') }),
			h('div', { class: 'lop-thread__who' }, [title, sub])
		]);
		mainEl.appendChild(h('section', { class: 'lop-thread' }, [bar, msgs, actions, form]));
		view.els = { msgs: msgs, actions: actions, form: form, input: input, send: send, title: title, sub: sub };
		window.scrollTo(0, 0);
		loadThread(true);
	}

	function closeThread() {
		view.cid = '';
		view.els = null;
		renderList();
		loadThreads();
	}

	function loadThread(full) {
		var cid = view.cid;
		if (!cid) {
			return Promise.resolve();
		}
		var q = full || !view.lastId ? '' : 'after_id=' + view.lastId;
		return purio('GET', '/threads/' + encodeURIComponent(cid), undefined, q).then(
			function (r) {
				if (cid !== view.cid || !r) {
					return;
				}
				view.thread = r.thread || view.thread;
				var list = Array.isArray(r.messages) ? r.messages : [];
				if (full) {
					view.messages = [];
					view.lastId = 0;
				}
				list.forEach(function (m) {
					var id = parseInt(m.id, 10) || 0;
					if (id > view.lastId) {
						view.messages.push(m);
						view.lastId = id;
					}
				});
				renderThread(full || list.length > 0);
			},
			function (e) {
				if (cid === view.cid && e && e.status === 404) {
					location.hash = '';
				}
			}
		);
	}

	function senderLabel(m) {
		switch (m.sender_type) {
			case 'visitor':
				return (view.thread && view.thread.visitor_name) || t('visitor');
			case 'agent':
				return parseInt(m.sender_user_id, 10) === cfg.user ? t('you') : (m.sender_name || t('you'));
			case 'ai':
				return t('ai');
			default:
				return '';
		}
	}

	function renderThread(newMessages) {
		var th = view.thread || {};
		var els = view.els;
		if (!els) {
			return;
		}
		els.title.textContent = th.visitor_name || th.visitor_email || t('visitor');
		els.sub.textContent = '';
		var page = safeUrl(th.page_url);
		if (th.visitor_email && th.visitor_name) {
			els.sub.appendChild(document.createTextNode(th.visitor_email));
		}
		if (page) {
			if (els.sub.firstChild) {
				els.sub.appendChild(document.createTextNode(' · '));
			}
			els.sub.appendChild(h('a', { href: page, target: '_blank', rel: 'noopener noreferrer', text: t('page') }));
		}

		if (newMessages) {
			var atBottom = window.innerHeight + window.scrollY >= document.body.scrollHeight - 80;
			els.msgs.textContent = '';
			view.messages.forEach(function (m) {
				var type = ['visitor', 'agent', 'ai'].indexOf(m.sender_type) >= 0 ? m.sender_type : 'system';
				var when = utc(m.created_at);
				els.msgs.appendChild(h('div', { class: 'lop-msg lop-msg--' + type }, [
					type !== 'system' ? h('span', { class: 'lop-msg__who', text: senderLabel(m) + (when ? ' · ' + hhmm(when) : '') }) : null,
					h('div', { class: 'lop-msg__text', text: String(m.content || '') })
				]));
			});
			if (atBottom || !els.scrolled) {
				els.scrolled = true;
				window.scrollTo(0, document.body.scrollHeight);
			}
		}

		// What the operator can do now.
		els.actions.textContent = '';
		var stateName = th.state || '';
		var mine = parseInt(th.assigned_user_id, 10) === cfg.user;
		var canWrite = false;
		if (stateName === 'waiting') {
			els.actions.appendChild(h('button', { type: 'button', class: 'lop-btn lop-btn--go', disabled: view.busy, onclick: function () { act('claim'); }, text: t('claim') }));
		} else if (stateName === 'ai') {
			els.actions.appendChild(h('p', { class: 'lop-muted', text: th.requested_at ? t('closed') : '' }));
			els.actions.appendChild(h('button', { type: 'button', class: 'lop-btn', disabled: view.busy, onclick: function () { act('take-over'); }, text: t('takeOver') }));
		} else if (stateName === 'human') {
			if (mine || !parseInt(th.assigned_user_id, 10)) {
				canWrite = true;
				els.actions.appendChild(h('button', {
					type: 'button',
					class: 'lop-btn lop-btn--ghost lop-btn--small',
					disabled: view.busy,
					onclick: function () {
						if (window.confirm(t('returnConfirm'))) {
							act('return-to-ai');
						}
					},
					text: t('returnAi')
				}));
			} else {
				els.actions.appendChild(h('p', { class: 'lop-muted', text: t('assignedOther') + (th.assigned_user_name ? ' (' + th.assigned_user_name + ')' : '') }));
			}
		} else if (stateName) {
			els.actions.appendChild(h('p', { class: 'lop-muted', text: t('closed') }));
		}
		els.form.hidden = !canWrite;
		els.send.disabled = view.busy;
	}

	function act(action) {
		if (view.busy || !view.cid) {
			return;
		}
		view.busy = true;
		renderThread(false);
		purio('POST', '/threads/' + encodeURIComponent(view.cid) + '/' + action, {})
			.catch(function (e) {
				setBanner((e && e.message) || t('failed'));
			})
			.then(function () {
				view.busy = false;
				return loadThread(true);
			})
			.then(function () {
				if (action === 'claim' || action === 'take-over') {
					if (view.els && !view.els.form.hidden) {
						view.els.input.focus();
					}
				}
			});
	}

	function reply() {
		var els = view.els;
		var text = els ? els.input.value.trim() : '';
		if (!text || view.busy) {
			return;
		}
		view.busy = true;
		els.send.disabled = true;
		purio('POST', '/threads/' + encodeURIComponent(view.cid) + '/reply', { content: text, client_message_id: uuid() })
			.then(function () {
				if (view.els === els) {
					els.input.value = '';
				}
			}, function (e) {
				setBanner((e && e.message) || t('failed'));
			})
			.then(function () {
				view.busy = false;
				if (view.els === els) {
					els.send.disabled = false;
					els.scrolled = false;
				}
				return loadThread(false);
			});
	}

	/* ---------- app update notice ---------- */

	// The official Android app opens the page with ?app=<its version>. Then,
	// at most every 12 hours, the page looks at the repository's releases for
	// a newer nox-loa-* and shows a line with the APK link. Builds for other
	// sites do not send the parameter, so they never see the notice.
	var updEl = h('p', { class: 'lop-update', hidden: true });
	root.insertBefore(updEl, topEl);

	function store(kind) {
		try {
			return window[kind];
		} catch (e) {
			return null;
		}
	}

	function sget(kind, key) {
		var st = store(kind);
		try {
			return st ? st.getItem(key) : null;
		} catch (e) {
			return null;
		}
	}

	function sset(kind, key, value) {
		var st = store(kind);
		try {
			if (st) {
				st.setItem(key, value);
			}
		} catch (e) {}
	}

	function semver(v) {
		var m = /^(\d+)\.(\d+)\.(\d+)$/.exec(v || '');
		return m ? [+m[1], +m[2], +m[3]] : null;
	}

	function newer(a, b) {
		var x = semver(a);
		var y = semver(b);
		if (!x || !y) {
			return false;
		}
		for (var i = 0; i < 3; i++) {
			if (x[i] !== y[i]) {
				return x[i] > y[i];
			}
		}
		return false;
	}

	function showUpdate(found, current) {
		if (!found || !newer(found.v, current)) {
			return;
		}
		updEl.textContent = '';
		updEl.appendChild(document.createTextNode(t('appUpdate', found.v) + ' '));
		updEl.appendChild(h('a', { href: found.url, rel: 'noopener', text: t('appGet') }));
		updEl.hidden = false;
	}

	function pickRelease(list) {
		var best = null;
		(Array.isArray(list) ? list : []).forEach(function (r) {
			var tag = (r && r.tag_name) || '';
			if (r.draft || tag.indexOf('nox-loa-') !== 0) {
				return;
			}
			var v = tag.slice(8);
			if (!semver(v) || (best && !newer(v, best.v))) {
				return;
			}
			var url = r.html_url || '';
			(r.assets || []).forEach(function (a) {
				if (/\.apk$/.test(a.name || '') && a.browser_download_url) {
					url = a.browser_download_url;
				}
			});
			best = { v: v, url: url };
		});
		return best;
	}

	function checkAppUpdate() {
		var m = /[?&]app=([0-9.]+)(?:&|$)/.exec(location.search);
		if (m && semver(m[1])) {
			sset('sessionStorage', 'lop_app', m[1]);
		}
		var current = sget('sessionStorage', 'lop_app');
		if (!current || !window.fetch) {
			return;
		}
		var cached = null;
		try {
			cached = JSON.parse(sget('localStorage', 'lop_upd') || 'null');
		} catch (e) {}
		if (cached && cached.found) {
			showUpdate(cached.found, current);
		}
		if (cached && Date.now() - (cached.at || 0) < 12 * 3600 * 1000) {
			return;
		}
		fetch('https://api.github.com/repos/koulaxizis/noxpress/releases?per_page=50', {
			credentials: 'omit',
			headers: { Accept: 'application/vnd.github+json' }
		}).then(function (r) {
			return r.ok ? r.json() : Promise.reject(r.status);
		}).then(function (list) {
			var found = pickRelease(list);
			sset('localStorage', 'lop_upd', JSON.stringify({ at: Date.now(), found: found }));
			showUpdate(found, current);
		}).catch(function () {
			// Silent: no notice is better than a wrong one.
		});
	}

	/* ---------- routing and polling ---------- */

	function route() {
		var m = /(?:^|[#&])c=([^&]+)/.exec(location.hash);
		var cid = m ? decodeURIComponent(m[1]) : '';
		if (cid && !/^[a-zA-Z0-9_-]+$/.test(cid)) {
			cid = '';
		}
		if (cid === view.cid) {
			return;
		}
		if (cid) {
			openThread(cid);
		} else {
			closeThread();
		}
	}

	/** A notification click while the app is open. */
	function openUrl(url) {
		var i = url.indexOf('#');
		var hash = i >= 0 ? url.slice(i) : '';
		if (location.hash !== hash) {
			location.hash = hash;
		} else {
			route();
		}
		refresh();
	}

	function every(name, ms, fn) {
		clearInterval(timers[name]);
		timers[name] = setInterval(function () {
			if (!document.hidden) {
				fn();
			}
		}, ms);
	}

	function refresh() {
		loadState();
		if (view.cid) {
			loadThread(false);
		} else {
			loadThreads();
		}
	}

	window.addEventListener('hashchange', route);
	document.addEventListener('visibilitychange', function () {
		if (!document.hidden) {
			refresh();
		}
	});

	every('state', 30000, loadState);
	every('threads', 5000, function () {
		if (!view.cid) {
			loadThreads();
		}
	});
	every('thread', 3000, function () {
		if (view.cid && !view.busy) {
			loadThread(false);
		}
	});
	every('tick', 1000, tick);

	renderTop();
	initPush();
	checkAppUpdate();
	if (/(?:^|[#&])c=/.test(location.hash)) {
		route();
		loadThreads();
	} else {
		renderList();
		loadThreads();
	}
})();
