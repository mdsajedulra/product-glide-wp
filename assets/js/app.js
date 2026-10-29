/* Product Glide — shared helpers (window.PGA) */
(function () {
	'use strict';

	var cfg = window.PG || {};

	function ajax(action, data) {
		var fd = new FormData();
		fd.append('action', action);
		fd.append('nonce', cfg.nonce);
		Object.keys(data || {}).forEach(function (k) {
			var v = data[k];
			if (Array.isArray(v) && v.some(function (x) { return x !== null && typeof x === 'object'; })) {
				// Arrays of objects (order items etc.) go as one JSON string.
				fd.append(k, JSON.stringify(v));
			} else if (Array.isArray(v)) {
				v.forEach(function (x) { fd.append(k + '[]', x); });
			} else if (v !== undefined && v !== null) {
				fd.append(k, typeof v === 'object' ? JSON.stringify(v) : v);
			}
		});
		return fetch(cfg.ajax, { method: 'POST', body: fd, credentials: 'same-origin' })
			.then(function (r) {
				return r.text().then(function (t) {
					var j;
					try { j = JSON.parse(t); } catch (e) {
						throw new Error(r.status === 403 ? 'Session expired — reload the page.' : 'Server error (' + r.status + ')');
					}
					if (!j || !j.success) {
						var err = new Error((j && j.data && j.data.message) || 'Request failed');
						err.data = j && j.data;
						throw err;
					}
					return j.data;
				});
			});
	}

	function esc(s) {
		return String(s === undefined || s === null ? '' : s).replace(/[&<>"']/g, function (c) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
		});
	}

	function money(n, noSymbol) {
		n = Number(n || 0);
		var s = n.toLocaleString('en-IN', { maximumFractionDigits: n % 1 ? 2 : 0 });
		return noSymbol ? s : (cfg.currency || '৳') + s;
	}

	var toastBox;
	function toast(msg, type) {
		if (!toastBox) {
			toastBox = document.createElement('div');
			toastBox.className = 'pg-toasts';
			toastBox.setAttribute('role', 'status');
			document.body.appendChild(toastBox);
		}
		var t = document.createElement('div');
		t.className = 'pg-toast' + (type ? ' pg-toast-' + type : '');
		t.textContent = msg;
		toastBox.appendChild(t);
		setTimeout(function () { t.remove(); }, type === 'bad' ? 6000 : 3200);
	}

	function modal(title, html, opts) {
		opts = opts || {};
		var back = document.createElement('div');
		back.className = 'pg-modal-back' + (opts.center ? ' pg-modal-center' : '') + (opts.wide ? ' pg-modal-wide' : '');
		back.innerHTML = '<div class="pg-modal" role="dialog" aria-modal="true"><div class="pg-modal-head"><h3>' + esc(title) +
			'</h3><button type="button" class="pg-btn pg-btn-icon" data-close aria-label="Close">✕</button></div><div class="pg-modal-body"></div></div>';
		var body = back.querySelector('.pg-modal-body');
		if (typeof html === 'string') { body.innerHTML = html; } else if (html) { body.appendChild(html); }
		function close() { back.remove(); document.removeEventListener('keydown', onKey); if (opts.onClose) { opts.onClose(); } }
		function onKey(e) { if (e.key === 'Escape') { close(); } }
		back.addEventListener('click', function (e) {
			if (e.target === back || e.target.closest('[data-close]')) { close(); }
		});
		document.addEventListener('keydown', onKey);
		document.body.appendChild(back);
		return { el: back, body: body, close: close };
	}

	function copy(text) {
		var done = function () { toast('Copied: ' + (text.length > 40 ? text.slice(0, 40) + '…' : text), 'ok'); };
		if (navigator.clipboard && window.isSecureContext) {
			navigator.clipboard.writeText(text).then(done, fallback);
		} else { fallback(); }
		function fallback() {
			var ta = document.createElement('textarea');
			ta.value = text; ta.style.position = 'fixed'; ta.style.opacity = '0';
			document.body.appendChild(ta); ta.select();
			try { document.execCommand('copy'); done(); } catch (e) { toast('Copy failed', 'bad'); }
			ta.remove();
		}
	}

	function busy(btn, on) {
		if (!btn) { return; }
		btn.classList.toggle('is-loading', !!on);
		btn.disabled = !!on;
	}

	function debounce(fn, ms) {
		var t;
		return function () {
			var a = arguments, self = this;
			clearTimeout(t);
			t = setTimeout(function () { fn.apply(self, a); }, ms);
		};
	}

	function printUrl(ids) {
		var base = String(cfg.printBase || '').replace(/&amp;/g, '&');
		var u = new URL(base, window.location.href);
		u.searchParams.set('ids', ids.join(','));
		return u.toString();
	}

	function today(offsetDays) {
		var d = new Date(Date.now() + (6 * 60 + new Date().getTimezoneOffset()) * 60000);
		if (offsetDays) { d.setDate(d.getDate() + offsetDays); }
		return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
	}

	function loadImage(src) {
		return new Promise(function (resolve) {
			if (!src) { return resolve(null); }
			var img = new Image();
			if (src.indexOf('data:') !== 0) { img.crossOrigin = 'anonymous'; }
			img.onload = function () { resolve(img); };
			img.onerror = function () { resolve(null); };
			img.src = src;
		});
	}

	function fontsReady() {
		if (!document.fonts || !document.fonts.load) { return Promise.resolve(); }
		return Promise.all([
			document.fonts.load('700 34px "Hind Siliguri"', 'অআ'),
			document.fonts.load('400 20px "Hind Siliguri"', 'অআ'),
			document.fonts.load('800 40px "Noto Sans Bengali"', 'অআ')
		]).catch(function () {});
	}

	/* Canvas helpers shared by invoice + banner. */
	function roundRect(ctx, x, y, w, h, r) {
		ctx.beginPath();
		ctx.moveTo(x + r, y);
		ctx.arcTo(x + w, y, x + w, y + h, r);
		ctx.arcTo(x + w, y + h, x, y + h, r);
		ctx.arcTo(x, y + h, x, y, r);
		ctx.arcTo(x, y, x + w, y, r);
		ctx.closePath();
	}

	function wrapText(ctx, text, maxW, maxLines) {
		var words = String(text || '').split(/\s+/), lines = [], line = '';
		words.forEach(function (w) {
			var test = line ? line + ' ' + w : w;
			if (ctx.measureText(test).width > maxW && line) { lines.push(line); line = w; } else { line = test; }
		});
		if (line) { lines.push(line); }
		if (maxLines && lines.length > maxLines) {
			lines = lines.slice(0, maxLines);
			var last = lines[maxLines - 1];
			while (last.length > 1 && ctx.measureText(last + '…').width > maxW) { last = last.slice(0, -1); }
			lines[maxLines - 1] = last + '…';
		}
		return lines;
	}

	function downloadCanvas(canvas, name) {
		canvas.toBlob(function (blob) {
			var a = document.createElement('a');
			a.href = URL.createObjectURL(blob);
			a.download = name;
			document.body.appendChild(a); a.click(); a.remove();
			setTimeout(function () { URL.revokeObjectURL(a.href); }, 4000);
		}, 'image/jpeg', 0.93);
	}

	function shareCanvas(canvas, name, text, phone) {
		canvas.toBlob(function (blob) {
			var file = new File([blob], name, { type: 'image/jpeg' });
			if (navigator.canShare && navigator.canShare({ files: [file] })) {
				navigator.share({ files: [file], text: text }).catch(function () {});
				return;
			}
			downloadCanvas(canvas, name);
			var p = String(phone || '').replace(/\D/g, '');
			if (p.length === 11 && p[0] === '0') { p = '88' + p; }
			var url = (p ? 'https://wa.me/' + p : 'https://web.whatsapp.com/send') + '?text=' + encodeURIComponent(text);
			window.open(url, '_blank', 'noopener');
			toast('Image downloaded — attach it in WhatsApp.', 'ok');
		}, 'image/jpeg', 0.93);
	}

	/* Courier report box ("should we send a parcel?") — shared by Orders and Incomplete orders. */
	var RISK_ICON = { safe: '✓', caution: '!', risky: '✕', 'new': '★' };
	var VOLUME_BN = { none: 'কোনো রেকর্ড নেই', low: 'কম', medium: 'মাঝারি', high: 'বেশি', very_high: 'অনেক বেশি' };

	function fraudHtml(f, o) {
		if (!f) {
			if (!cfg.sfReady || !o || !o.phone_bd) { return ''; }
			return '<div class="pg-risk pg-risk-idle"><span class="pg-muted">কুরিয়ার রিপোর্ট দেখা হয়নি</span>' +
				'<button type="button" class="pg-btn pg-btn-sm" data-act="fraud-refresh">রিপোর্ট দেখুন</button></div>';
		}
		if (f.loading) {
			return '<div class="pg-risk pg-risk-loading"><span class="pg-risk-spin" aria-hidden="true"></span>কুরিয়ার রিপোর্ট লোড হচ্ছে…</div>';
		}
		if (f.error) {
			return '<div class="pg-risk pg-risk-idle"><span>রিপোর্ট আনা যায়নি: ' + esc(f.error) + '</span>' +
				'<button type="button" class="pg-btn pg-btn-sm" data-act="fraud-refresh">আবার চেষ্টা</button>' +
				'<a class="pg-btn pg-btn-sm" href="' + esc(cfg.fraudPanel + (o ? o.phone_bd : '')) + '" target="_blank" rel="noopener">Steadfast-এ দেখুন ↗</a></div>';
		}
		var v = f.verdict || { level: 'new', title: '', advice: '', reasons: [] };
		var hasRatio = f.delivery_ratio !== null && f.delivery_ratio !== undefined;
		var bar = '';
		if (f.parcels) {
			bar = '<div class="pg-risk-bar" aria-hidden="true"><i class="ok" style="flex:' + (f.delivered || f.delivery_ratio || 0) + '"></i><i class="bad" style="flex:' + (f.cancelled || (100 - (f.delivery_ratio || 0))) + '"></i></div>';
		}
		var stats = [];
		if (f.parcels) {
			stats.push('<span><b>' + f.parcels + '</b> পার্সেল</span>');
			stats.push('<span class="ok"><b>' + (f.delivered || 0) + '</b> ডেলিভারি</span>');
			stats.push('<span class="' + (f.cancelled ? 'bad' : '') + '"><b>' + (f.cancelled || 0) + '</b> বাতিল</span>');
		}
		stats.push('<span class="' + (f.total_reports ? 'bad' : 'ok') + '"><b>' + (f.total_reports || 0) + '</b> ফ্রড রিপোর্ট</span>');

		var detail =
			'<ul class="pg-risk-why">' + (v.reasons || []).map(function (r) {
				return '<li class="' + esc(r[0]) + '">' + esc(r[1]) + '</li>';
			}).join('') +
			(f.volume_band && f.volume_band !== 'none' ? '<li class="info">অর্ডারের পরিমাণ: ' + esc(VOLUME_BN[f.volume_band] || f.volume_band) + (f.volume_range ? ' (' + esc(f.volume_range) + ' পার্সেল)' : '') + '</li>' : '') +
			'</ul>' +
			((f.reports && f.reports.length) ? '<div class="pg-risk-reports"><b>ফ্রড রিপোর্ট</b>' + f.reports.map(function (r) {
				return '<div class="pg-risk-report"><div>' + esc(r.details || '(বিস্তারিত নেই)') + '</div><small>' +
					[r.name, r.category, r.date].filter(Boolean).map(esc).join(' · ') + '</small></div>';
			}).join('') + '</div>' : (f.total_reports ? '<div class="pg-muted" style="margin:6px 0">রিপোর্টের বিস্তারিত Steadfast প্যানেলে দেখা যাবে।</div>' : '')) +
			'<div class="pg-risk-foot"><span class="pg-muted">চেক করা হয়েছে: ' + esc(f.checked_at || '') + '</span>' +
				'<span><button type="button" class="pg-btn pg-btn-sm" data-act="fraud-refresh">↻ আবার চেক</button>' +
				'<a class="pg-btn pg-btn-sm" href="' + esc(f.panel_url) + '" target="_blank" rel="noopener">Steadfast প্যানেল ↗</a></span></div>';

		return '<div class="pg-risk pg-risk-' + esc(v.level) + '">' +
			'<button type="button" class="pg-risk-head" data-act="risk-toggle" aria-expanded="false">' +
				'<span class="pg-risk-icon" aria-hidden="true">' + (RISK_ICON[v.level] || '?') + '</span>' +
				'<span class="pg-risk-msg"><b>' + esc(v.title) + '</b><small>' + esc(v.advice) + '</small></span>' +
				(hasRatio ? '<span class="pg-risk-pct"><b>' + f.delivery_ratio + '%</b><small>ডেলিভারি</small></span>' : '') +
			'</button>' +
			bar +
			'<div class="pg-risk-stats">' + stats.join('') + '<button type="button" class="pg-risk-more" data-act="risk-toggle">বিস্তারিত ▾</button></div>' +
			'<div class="pg-risk-detail" hidden>' + detail + '</div>' +
		'</div>';
	}

	/* Expand / collapse a report box. */
	function riskToggle(el) {
		var box = el.closest('.pg-risk'), det = box && box.querySelector('.pg-risk-detail');
		if (!det) { return; }
		det.hidden = !det.hidden;
		box.querySelector('.pg-risk-head').setAttribute('aria-expanded', det.hidden ? 'false' : 'true');
		box.querySelector('.pg-risk-more').textContent = det.hidden ? 'বিস্তারিত ▾' : 'বন্ধ করুন ▴';
	}

	// Copy buttons anywhere: <button data-copy="...">
	document.addEventListener('click', function (e) {
		var b = e.target.closest('[data-copy]');
		if (b) { e.preventDefault(); copy(b.getAttribute('data-copy')); }
	});

	window.PGA = {
		cfg: cfg, ajax: ajax, esc: esc, money: money, toast: toast, modal: modal, copy: copy, busy: busy,
		debounce: debounce, printUrl: printUrl, today: today, loadImage: loadImage, fontsReady: fontsReady,
		roundRect: roundRect, wrapText: wrapText, downloadCanvas: downloadCanvas, shareCanvas: shareCanvas,
		fraudHtml: fraudHtml, riskToggle: riskToggle
	};
})();
