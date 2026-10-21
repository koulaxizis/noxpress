/**
 * Site Bridge — QR code for pairing the Android app (vanilla, zero
 * dependencies). Byte mode, error correction M, versions 1–20: enough for
 * the pairing code (about 200 characters). Draws every
 * <canvas data-sbr-qr="text"> on the page.
 *
 * Follows ISO/IEC 18004 the way Project Nayuki's reference encoder does;
 * the mask is picked with penalty rules 1, 2 and 4 (rule 3 only tunes
 * readability, any mask is valid).
 */
(function (root) {
	'use strict';

	// Error correction M, index = version.
	var ECC_PER_BLOCK = [-1, 10, 16, 26, 18, 24, 16, 18, 22, 22, 26, 30, 22, 22, 24, 24, 28, 28, 26, 26, 26];
	var NUM_BLOCKS = [-1, 1, 1, 1, 2, 2, 4, 4, 4, 5, 5, 5, 8, 9, 9, 10, 10, 11, 13, 14, 16];
	var MAX_VERSION = 20;

	function rawModules(ver) {
		var r = (16 * ver + 128) * ver + 64;
		if (ver >= 2) {
			var n = Math.floor(ver / 7) + 2;
			r -= (25 * n - 10) * n - 55;
			if (ver >= 7) r -= 36;
		}
		return r;
	}

	function dataCodewords(ver) {
		return Math.floor(rawModules(ver) / 8) - ECC_PER_BLOCK[ver] * NUM_BLOCKS[ver];
	}

	function gfMul(x, y) {
		var z = 0;
		for (var i = 7; i >= 0; i--) {
			z = (z << 1) ^ ((z >>> 7) * 0x11D);
			z ^= ((y >>> i) & 1) * x;
		}
		return z & 0xFF;
	}

	function rsDivisor(degree) {
		var res = [];
		for (var i = 0; i < degree - 1; i++) res.push(0);
		res.push(1);
		var rootv = 1;
		for (i = 0; i < degree; i++) {
			for (var j = 0; j < res.length; j++) {
				res[j] = gfMul(res[j], rootv);
				if (j + 1 < res.length) res[j] ^= res[j + 1];
			}
			rootv = gfMul(rootv, 0x02);
		}
		return res;
	}

	function rsRemainder(data, div) {
		var res = div.map(function () { return 0; });
		data.forEach(function (b) {
			var factor = b ^ res.shift();
			res.push(0);
			div.forEach(function (coef, i) { res[i] ^= gfMul(coef, factor); });
		});
		return res;
	}

	function utf8(text) {
		var out = [];
		var s = unescape(encodeURIComponent(text));
		for (var i = 0; i < s.length; i++) out.push(s.charCodeAt(i));
		return out;
	}

	/** The QR matrix (array of rows of booleans, true = dark) or null when too long. */
	function encode(text) {
		var bytes = utf8(text);
		var ver;
		for (ver = 1; ver <= MAX_VERSION; ver++) {
			var ccBits = ver <= 9 ? 8 : 16;
			if (4 + ccBits + 8 * bytes.length <= dataCodewords(ver) * 8) break;
		}
		if (ver > MAX_VERSION) return null;

		// Bit stream: mode, count, data, terminator, padding.
		var bits = [];
		function put(val, len) { for (var i = len - 1; i >= 0; i--) bits.push((val >>> i) & 1); }
		put(4, 4);
		put(bytes.length, ver <= 9 ? 8 : 16);
		bytes.forEach(function (b) { put(b, 8); });
		var cap = dataCodewords(ver) * 8;
		put(0, Math.min(4, cap - bits.length));
		put(0, (8 - bits.length % 8) % 8);
		for (var pad = 0xEC; bits.length < cap; pad ^= 0xEC ^ 0x11) put(pad, 8);
		var data = [];
		for (var i = 0; i < bits.length; i += 8) {
			var v = 0;
			for (var j = 0; j < 8; j++) v = (v << 1) | bits[i + j];
			data.push(v);
		}

		// Blocks, error correction, interleaving.
		var nb = NUM_BLOCKS[ver], el = ECC_PER_BLOCK[ver];
		var raw = Math.floor(rawModules(ver) / 8);
		var nShort = nb - raw % nb, shortLen = Math.floor(raw / nb);
		var div = rsDivisor(el), blocks = [], k = 0;
		for (i = 0; i < nb; i++) {
			var dat = data.slice(k, k + shortLen - el + (i < nShort ? 0 : 1));
			k += dat.length;
			var ecc = rsRemainder(dat, div);
			if (i < nShort) dat.push(0);
			blocks.push(dat.concat(ecc));
		}
		var cw = [];
		for (i = 0; i < blocks[0].length; i++) {
			for (j = 0; j < blocks.length; j++) {
				if (i !== shortLen - el || j >= nShort) cw.push(blocks[j][i]);
			}
		}

		var size = ver * 4 + 17;
		var mod = [], fn = [];
		for (i = 0; i < size; i++) {
			mod.push(new Array(size).fill(false));
			fn.push(new Array(size).fill(false));
		}
		function setF(x, y, dark) { mod[y][x] = dark; fn[y][x] = true; }

		// Function patterns.
		for (i = 0; i < size; i++) {
			setF(6, i, i % 2 === 0);
			setF(i, 6, i % 2 === 0);
		}
		[[3, 3], [size - 4, 3], [3, size - 4]].forEach(function (c) {
			for (var dy = -4; dy <= 4; dy++) {
				for (var dx = -4; dx <= 4; dx++) {
					var d = Math.max(Math.abs(dx), Math.abs(dy)), xx = c[0] + dx, yy = c[1] + dy;
					if (xx >= 0 && xx < size && yy >= 0 && yy < size) setF(xx, yy, d !== 2 && d !== 4);
				}
			}
		});
		var align = [];
		if (ver > 1) {
			var na = Math.floor(ver / 7) + 2;
			var step = Math.ceil((ver * 4 + 4) / (na * 2 - 2)) * 2;
			align = [6];
			for (var pos = size - 7; align.length < na; pos -= step) align.splice(1, 0, pos);
		}
		align.forEach(function (ax, ai) {
			align.forEach(function (ay, aj) {
				var last = align.length - 1;
				if ((ai === 0 && aj === 0) || (ai === 0 && aj === last) || (ai === last && aj === 0)) return;
				for (var dy = -2; dy <= 2; dy++) {
					for (var dx = -2; dx <= 2; dx++) setF(ax + dx, ay + dy, Math.max(Math.abs(dx), Math.abs(dy)) !== 1);
				}
			});
		});
		function drawFormat(mask) {
			var d = (0 << 3) | mask, rem = d; // M = 0b00
			for (var t = 0; t < 10; t++) rem = (rem << 1) ^ ((rem >>> 9) * 0x537);
			var b = ((d << 10) | rem) ^ 0x5412;
			function bit(n) { return ((b >>> n) & 1) !== 0; }
			for (var t2 = 0; t2 <= 5; t2++) setF(8, t2, bit(t2));
			setF(8, 7, bit(6));
			setF(8, 8, bit(7));
			setF(7, 8, bit(8));
			for (t2 = 9; t2 < 15; t2++) setF(14 - t2, 8, bit(t2));
			for (t2 = 0; t2 < 8; t2++) setF(size - 1 - t2, 8, bit(t2));
			for (t2 = 8; t2 < 15; t2++) setF(8, size - 15 + t2, bit(t2));
			setF(8, size - 8, true);
		}
		drawFormat(0);
		if (ver >= 7) {
			var rem = ver;
			for (i = 0; i < 12; i++) rem = (rem << 1) ^ ((rem >>> 11) * 0x1F25);
			var vb = (ver << 12) | rem;
			for (i = 0; i < 18; i++) {
				var bt = ((vb >>> i) & 1) !== 0, a = size - 11 + i % 3, bb = Math.floor(i / 3);
				setF(a, bb, bt);
				setF(bb, a, bt);
			}
		}

		// Data in the zigzag.
		var bi = 0;
		for (var right = size - 1; right >= 1; right -= 2) {
			if (right === 6) right = 5;
			for (var vert = 0; vert < size; vert++) {
				for (j = 0; j < 2; j++) {
					var x = right - j, up = ((right + 1) & 2) === 0, y = up ? size - 1 - vert : vert;
					if (!fn[y][x] && bi < cw.length * 8) {
						mod[y][x] = ((cw[bi >>> 3] >>> (7 - (bi & 7))) & 1) !== 0;
						bi++;
					}
				}
			}
		}

		function maskAt(m, x, y) {
			switch (m) {
				case 0: return (x + y) % 2 === 0;
				case 1: return y % 2 === 0;
				case 2: return x % 3 === 0;
				case 3: return (x + y) % 3 === 0;
				case 4: return (Math.floor(x / 3) + Math.floor(y / 2)) % 2 === 0;
				case 5: return x * y % 2 + x * y % 3 === 0;
				case 6: return (x * y % 2 + x * y % 3) % 2 === 0;
				default: return ((x + y) % 2 + x * y % 3) % 2 === 0;
			}
		}
		function applyMask(m) {
			for (var yy = 0; yy < size; yy++) {
				for (var xx = 0; xx < size; xx++) {
					if (!fn[yy][xx] && maskAt(m, xx, yy)) mod[yy][xx] = !mod[yy][xx];
				}
			}
		}
		function penalty() {
			var p = 0, dark = 0, yy, xx;
			for (yy = 0; yy < size; yy++) {
				var runR = 1, runC = 1;
				for (xx = 0; xx < size; xx++) {
					if (mod[yy][xx]) dark++;
					if (xx > 0) {
						if (mod[yy][xx] === mod[yy][xx - 1]) { runR++; if (runR === 5) p += 3; else if (runR > 5) p++; } else runR = 1;
						if (mod[xx][yy] === mod[xx - 1][yy]) { runC++; if (runC === 5) p += 3; else if (runC > 5) p++; } else runC = 1;
					}
					if (xx > 0 && yy > 0) {
						var c = mod[yy][xx];
						if (c === mod[yy][xx - 1] && c === mod[yy - 1][xx] && c === mod[yy - 1][xx - 1]) p += 3;
					}
				}
			}
			var total = size * size;
			p += (Math.ceil(Math.abs(dark * 20 - total * 10) / total) - 1) * 10;
			return p;
		}
		var best = 0, bestP = Infinity;
		for (var m = 0; m < 8; m++) {
			applyMask(m);
			drawFormat(m);
			var pp = penalty();
			if (pp < bestP) { bestP = pp; best = m; }
			applyMask(m); // XOR again: back to unmasked.
		}
		applyMask(best);
		drawFormat(best);
		return mod;
	}

	function draw(canvas) {
		var m = encode(canvas.getAttribute('data-sbr-qr') || '');
		if (!m) return;
		var quiet = 4, n = m.length + 2 * quiet;
		var scale = Math.max(3, Math.floor(280 / n));
		canvas.width = canvas.height = n * scale;
		var ctx = canvas.getContext('2d');
		ctx.fillStyle = '#ffffff';
		ctx.fillRect(0, 0, canvas.width, canvas.height);
		ctx.fillStyle = '#000000';
		for (var y = 0; y < m.length; y++) {
			for (var x = 0; x < m.length; x++) {
				if (m[y][x]) ctx.fillRect((x + quiet) * scale, (y + quiet) * scale, scale, scale);
			}
		}
	}

	root.sbrQr = encode;
	if (typeof document !== 'undefined') {
		var run = function () {
			var list = document.querySelectorAll('canvas[data-sbr-qr]');
			for (var i = 0; i < list.length; i++) draw(list[i]);
		};
		if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', run);
		else run();
	}
})(typeof window !== 'undefined' ? window : globalThis);
