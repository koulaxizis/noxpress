/**
 * Events — check-in page (1.1.0). Typed code or the camera (BarcodeDetector,
 * where the browser has it). Every check goes to admin-ajax with the nonce;
 * the server decides and marks the ticket used.
 */
(function () {
	'use strict';
	var cfg = window.evtCheckin || {};
	var box = document.querySelector('.evt-ci-box');
	if (!box) return;
	var form = box.querySelector('.evt-ci-form');
	var input = box.querySelector('#evt-ci-code');
	var out = box.querySelector('.evt-ci-result');
	var counts = box.querySelector('.evt-ci-counts');
	var camBtn = box.querySelector('.evt-ci-cam');
	var video = box.querySelector('.evt-ci-video');
	var busy = false, last = '', lastAt = 0, stream = null, detector = null, timer = null;

	function show(state, msg, name) {
		out.className = 'evt-ci-result evt-ci-result--' + state;
		out.textContent = '';
		var p = document.createElement('p');
		p.className = 'evt-ci-msg';
		p.textContent = msg;
		out.appendChild(p);
		if (name) {
			var n = document.createElement('p');
			n.className = 'evt-ci-name';
			n.textContent = name;
			out.appendChild(n);
		}
		if (navigator.vibrate) navigator.vibrate(state === 'ok' ? 80 : [60, 60, 60]);
	}

	function check(code, fromCamera) {
		code = String(code || '').trim();
		if (!code || busy) return; // A typed code stays in the field until the previous check answers.
		var now = Date.now();
		if (fromCamera && code === last && now - lastAt < 3000) return; // The same QR still in front of the camera.
		last = code; lastAt = now; busy = true;
		var body = new URLSearchParams();
		body.append('action', 'evt_checkin');
		body.append('nonce', cfg.nonce || '');
		body.append('code', code);
		body.append('event', box.getAttribute('data-event') || '0');
		fetch(cfg.ajax, { method: 'POST', credentials: 'same-origin', body: body })
			.then(function (r) { return r.json(); })
			.then(function (j) {
				var d = (j && j.data) || {};
				if (j && j.success) {
					show(d.state || 'invalid', d.msg || '', d.name || '');
					if (d.counts) counts.textContent = d.counts;
				} else {
					show('invalid', d.msg || cfg.error || '');
				}
			})
			.catch(function () { show('invalid', cfg.error || ''); })
			.then(function () {
				busy = false;
				if (input.value.trim() === code) input.value = '';
				input.focus();
			});
	}

	form.addEventListener('submit', function (ev) { ev.preventDefault(); check(input.value); });

	function stop() {
		if (timer) { clearInterval(timer); timer = null; }
		if (stream) { stream.getTracks().forEach(function (t) { t.stop(); }); stream = null; }
		video.hidden = true;
		camBtn.textContent = cfg.camera || 'Camera';
	}

	if ('BarcodeDetector' in window && navigator.mediaDevices && navigator.mediaDevices.getUserMedia) {
		camBtn.hidden = false;
		camBtn.addEventListener('click', function () {
			if (stream) { stop(); return; }
			try { detector = new window.BarcodeDetector({ formats: ['qr_code'] }); } catch (e) { show('invalid', cfg.nocam || ''); return; }
			navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' }, audio: false }).then(function (s) {
				stream = s; video.srcObject = s; video.hidden = false; video.play();
				camBtn.textContent = cfg.stop || 'Stop';
				timer = setInterval(function () {
					if (busy || video.readyState < 2) return;
					detector.detect(video).then(function (codes) {
						if (codes && codes.length) check(codes[0].rawValue, true);
					}).catch(function () {});
				}, 350);
			}).catch(function () { show('invalid', cfg.nocam || ''); });
		});
	}
	input.focus();
})();
