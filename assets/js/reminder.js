/* Product Glide — follow-up reminders for rescheduled orders (all dashboard screens) */
(function () {
	'use strict';
	var A = window.PGA;
	if (!A) { return; }
	var esc = A.esc, cfg = A.cfg;
	var POLL = 60 * 1000, QUIET = 30 * 60 * 1000; // re-show a closed popup after 30 min
	var list = [], total = 0, popup = null, pill = null, known = {}, baseTitle = document.title, first = true;

	function store(k, v) {
		try {
			if (v === undefined) { return JSON.parse(sessionStorage.getItem(k) || '{}'); }
			sessionStorage.setItem(k, JSON.stringify(v));
		} catch (e) { return {}; }
	}
	function orderUrl(n) {
		var u = cfg.ordersUrl || '';
		return u + (u.indexOf('?') > -1 ? '&' : '?') + 'q=' + encodeURIComponent(n);
	}

	function poll() {
		if (document.hidden && !first) { return; }
		A.ajax('pg_rs_due', {}).then(function (d) {
			list = d.due || [];
			total = d.total || 0;
			paintPill();
			var fresh = list.filter(function (o) { return !known[o.id]; });
			list.forEach(function (o) { known[o.id] = 1; });
			if (popup) { paintPopup(); return; }
			var closed = store('pg_rs_closed'), now = Date.now();
			var show = list.some(function (o) { return !closed[o.id] || now - closed[o.id] > QUIET; });
			if (show) {
				openPopup();
				if (fresh.length && !first) { alertNew(fresh); }
			}
			first = false;
		}).catch(function () { first = false; });
	}

	/* ---------- Floating pill ---------- */
	function paintPill() {
		document.title = (list.length ? '(' + list.length + ') ' : '') + baseTitle.replace(/^\(\d+\)\s*/, '');
		if (!list.length) { if (pill) { pill.remove(); pill = null; } return; }
		if (!pill) {
			pill = document.createElement('button');
			pill.type = 'button';
			pill.className = 'pg-rs-pill';
			pill.addEventListener('click', function () { openPopup(true); });
			document.body.appendChild(pill);
		}
		pill.innerHTML = '<span aria-hidden="true">&#128276;</span> ' + list.length + ' follow-up' + (list.length > 1 ? 's' : '') + ' due';
	}

	/* ---------- Sound + desktop notification ---------- */
	function beep() {
		try {
			var C = window.AudioContext || window.webkitAudioContext, ctx = new C();
			[0, 0.22].forEach(function (t) {
				var o = ctx.createOscillator(), g = ctx.createGain();
				o.frequency.value = 880; o.connect(g); g.connect(ctx.destination);
				g.gain.setValueAtTime(0.0001, ctx.currentTime + t);
				g.gain.exponentialRampToValueAtTime(0.25, ctx.currentTime + t + 0.02);
				g.gain.exponentialRampToValueAtTime(0.0001, ctx.currentTime + t + 0.18);
				o.start(ctx.currentTime + t); o.stop(ctx.currentTime + t + 0.2);
			});
		} catch (e) {}
	}
	function alertNew(fresh) {
		beep();
		if (window.Notification && Notification.permission === 'granted') {
			try {
				var n = new Notification('Follow-up due: ' + fresh.length + ' rescheduled order' + (fresh.length > 1 ? 's' : ''), {
					body: fresh.slice(0, 3).map(function (o) { return '#' + o.number + ' ' + o.name + ' · ' + o.phone; }).join('\n'),
					tag: 'pg-rs'
				});
				n.onclick = function () { window.focus(); openPopup(true); n.close(); };
			} catch (e) {}
		}
	}

	/* ---------- Popup ---------- */
	function openPopup(force) {
		if (popup) { return; }
		if (!list.length) { if (force) { A.toast('No follow-ups due right now.', 'ok'); } return; }
		popup = A.modal('Follow-up due', '<div data-rs-list></div>', {
			center: true,
			onClose: function () {
				var closed = store('pg_rs_closed'), now = Date.now();
				list.forEach(function (o) { closed[o.id] = now; });
				store('pg_rs_closed', closed);
				popup = null;
			}
		});
		popup.el.classList.add('pg-rs-modal');
		popup.body.addEventListener('click', onClick);
		paintPopup();
	}

	function paintPopup() {
		if (!popup) { return; }
		if (!list.length) { popup.close(); return; }
		var askNotif = window.Notification && Notification.permission === 'default';
		popup.el.querySelector('.pg-modal-head h3').textContent = 'Follow-up due (' + list.length + ')';
		popup.body.querySelector('[data-rs-list]').innerHTML =
			'<p class="pg-muted">These customers asked you to send the order later. Call them now and then ship, snooze or pick a new date.</p>' +
			list.map(function (o) {
				var overdue = /overdue/.test(o.relative);
				return '<div class="pg-rs-item' + (overdue ? ' is-overdue' : '') + '" data-id="' + o.id + '">' +
					'<div class="pg-rs-item-top"><a href="' + esc(orderUrl(o.number)) + '" class="pg-rs-no">#' + esc(o.number) + '</a>' +
						'<b>' + esc(o.name || '—') + '</b><span class="pg-rs-total">' + A.money(o.total) + '</span></div>' +
					'<div class="pg-rs-meta">' + (o.label ? '<span class="pg-badge ' + (overdue ? 'pg-badge-bad' : 'pg-badge-info') + '">' + esc(o.relative) + '</span> ' + esc(o.label) : '<span class="pg-badge pg-badge-info">No date set</span>') +
						(o.count > 1 ? ' · rescheduled ' + o.count + '×' : '') + '</div>' +
					(o.note ? '<div class="pg-rs-note">' + esc(o.note) + '</div>' : '') +
					(o.items ? '<div class="pg-rs-items">' + esc(o.items) + '</div>' : '') +
					'<div class="pg-rs-btns">' +
						'<a class="pg-btn pg-btn-sm pg-btn-ok" href="tel:' + esc(o.phone) + '">&#128222; Call ' + esc(o.phone) + '</a>' +
						'<button class="pg-btn pg-btn-sm pg-btn-primary" data-rs="done">Ready to ship</button>' +
						'<button class="pg-btn pg-btn-sm" data-rs="again">New date</button>' +
						'<button class="pg-btn pg-btn-sm" data-rs="snooze" data-min="60">Remind in 1 h</button>' +
						'<button class="pg-btn pg-btn-sm" data-rs="snooze" data-min="180">3 h</button>' +
						'<a class="pg-btn pg-btn-sm pg-btn-ghost" href="' + esc(orderUrl(o.number)) + '">Open order</a>' +
					'</div></div>';
			}).join('') +
			(total > list.length ? '<p class="pg-muted">' + (total - list.length) + ' more are snoozed.</p>' : '') +
			(askNotif ? '<div class="pg-rs-notif"><button class="pg-btn pg-btn-sm" data-rs="notif">Turn on desktop alerts</button> <span class="pg-muted">Get a pop-up even when this tab is in the background.</span></div>' : '');
	}

	function drop(id) {
		list = list.filter(function (o) { return o.id !== id; });
		total = Math.max(0, total - 1);
		paintPill();
		paintPopup();
	}

	function onClick(e) {
		var b = e.target.closest('[data-rs]');
		if (!b) { return; }
		var act = b.getAttribute('data-rs');
		if (act === 'notif') {
			Notification.requestPermission().then(function () { paintPopup(); });
			return;
		}
		var item = b.closest('.pg-rs-item'), id = parseInt(item.getAttribute('data-id'), 10);
		var o = list.filter(function (x) { return x.id === id; })[0];
		if (!o) { return; }
		if (act === 'done') {
			A.busy(b, true);
			A.ajax('pg_rs_done', { order_id: id, status: 'processing' }).then(function (d) {
				A.toast('#' + o.number + ': ' + d.message, 'ok'); drop(id);
				document.dispatchEvent(new CustomEvent('pg:rs-order', { detail: d.order }));
			}).catch(function (err) { A.toast(err.message, 'bad'); A.busy(b, false); });
		} else if (act === 'snooze') {
			A.busy(b, true);
			A.ajax('pg_rs_snooze', { order_id: id, minutes: b.getAttribute('data-min') }).then(function (d) { A.toast('#' + o.number + ': ' + d.message, 'ok'); drop(id); })
				.catch(function (err) { A.toast(err.message, 'bad'); A.busy(b, false); });
		} else if (act === 'again') {
			A.reschedule({ id: o.id, number: o.number, name: o.name, resched: { date: '', time: o.time, note: o.note } }, function () { drop(id); });
		}
	}

	document.addEventListener('pg:rs-changed', function () { setTimeout(poll, 300); });
	document.addEventListener('pg:rescheduled', function () { setTimeout(poll, 300); });
	document.addEventListener('visibilitychange', function () { if (!document.hidden) { poll(); } });
	setTimeout(poll, 1200);
	setInterval(poll, POLL);
})();
