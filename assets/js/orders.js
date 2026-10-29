/* Product Glide — Orders dashboard */
(function () {
	'use strict';
	var A = window.PGA, cfg = A.cfg, esc = A.esc;
	var root = document.getElementById('pg-orders');
	if (!root) { return; }

	var state = { status: 'processing', q: '', page: 1, per: 25, orders: {}, order: [], selected: new Set(), open: new Set(), loading: false, req: 0 };
	try {
		var saved = JSON.parse(localStorage.getItem('pg_orders_state') || '{}');
		if (saved.status) { state.status = saved.status; }
		if (saved.per) { state.per = saved.per; }
	} catch (e) {}

	var $list = document.getElementById('pg-list');
	var $tabs = document.getElementById('pg-status-tabs');
	var $pager = document.getElementById('pg-pager');
	var $q = document.getElementById('pg-q');
	var $per = document.getElementById('pg-per-page');
	var $bar = document.getElementById('pg-bottom-bar');
	var $selbar = document.getElementById('pg-selectbar');
	$per.value = String(state.per);
	var urlQ = new URLSearchParams(window.location.search).get('q');
	if (urlQ) { state.q = urlQ; state.status = 'all'; $q.value = urlQ; }

	function persist() {
		try { localStorage.setItem('pg_orders_state', JSON.stringify({ status: state.status, per: state.per })); } catch (e) {}
	}

	/* ---------- Loading ---------- */

	function load(keepScroll) {
		var my = ++state.req;
		state.loading = true;
		if (!keepScroll) {
			$list.innerHTML = '<div class="pg-skel"></div><div class="pg-skel"></div><div class="pg-skel"></div>';
		}
		return A.ajax('pg_orders_list', { status: state.status, q: state.q, page: state.page, per_page: state.per })
			.then(function (d) {
				if (my !== state.req) { return; }
				state.orders = {};
				state.order = [];
				d.orders.forEach(function (o) { state.orders[o.id] = o; state.order.push(o.id); });
				renderTabs(d.counts);
				renderKpis(d.kpis);
				renderList();
				renderPager(d.page, d.pages, d.total);
				if (!keepScroll) { window.scrollTo({ top: 0 }); }
			})
			.catch(function (e) {
				if (my !== state.req) { return; }
				$list.innerHTML = '<div class="pg-empty">' + esc(e.message) + '</div>';
			})
			.finally(function () { state.loading = false; });
	}

	function renderTabs(counts) {
		var html = '';
		Object.keys(cfg.tabs).forEach(function (k) {
			var c = counts && counts[k] !== undefined ? counts[k] : '';
			html += '<button type="button" class="pg-status-tab' + (k === state.status ? ' is-active' : '') + '" data-tab="' + esc(k) + '">' +
				esc(cfg.tabs[k]) + (c !== '' ? '<span class="c">' + c + '</span>' : '') + '</button>';
		});
		$tabs.innerHTML = html;
	}

	function renderKpis(k) {
		if (!k) { return; }
		document.querySelectorAll('#pg-kpis [data-k]').forEach(function (el) {
			el.textContent = k[el.getAttribute('data-k')];
		});
	}

	function renderPager(page, pages, total) {
		if (pages <= 1) {
			$pager.innerHTML = '<span class="pg-muted">' + total + ' orders</span>';
			return;
		}
		$pager.innerHTML =
			'<button class="pg-btn pg-btn-sm" data-page="' + (page - 1) + '"' + (page <= 1 ? ' disabled' : '') + '>‹ Prev</button>' +
			'<span class="pg-muted">Page ' + page + ' / ' + pages + ' · ' + total + ' orders</span>' +
			'<button class="pg-btn pg-btn-sm" data-page="' + (page + 1) + '"' + (page >= pages ? ' disabled' : '') + '>Next ›</button>';
	}

	function renderList() {
		if (!state.order.length) {
			$list.innerHTML = '<div class="pg-empty">No orders here.</div>';
			$selbar.hidden = true;
			updateBar();
			return;
		}
		$list.innerHTML = state.order.map(function (id) { return cardHtml(state.orders[id]); }).join('');
		$selbar.hidden = false;
		autoFraud();
		state.open.forEach(function (id) {
			if (state.orders[id]) { openMore(id, true); }
		});
		updateBar();
	}

	/* ---------- Card ---------- */

	/* Both addresses saved by BD Smart Address: what the customer typed + the cleaned-up one. */
	function addrBoth(o) {
		if (!o.addr_typed && !o.addr_clean) { return ''; }
		var zones = { inside: 'Inside Dhaka city', suburb: 'Sub-urban Dhaka', outside: 'Outside Dhaka' };
		var row = function (label, v) {
			return v ? '<div class="pg-addr2-row"><b>' + label + ':</b> ' + esc(v) + ' <button class="pg-chipbtn" data-copy="' + esc(v) + '">Copy</button></div>' : '';
		};
		return '<div class="pg-addr2">' + row('Customer typed', o.addr_typed) + row('System cleaned', o.addr_clean) +
			(o.addr_zone && zones[o.addr_zone] ? '<div class="pg-addr2-row"><b>Zone:</b> ' + zones[o.addr_zone] + '</div>' : '') + '</div>';
	}

	/* Extra order details — only the ones this order actually has. */
	function infoHtml(o) {
		var row = function (label, v, extra) {
			return v ? '<span class="pg-info-item"><b>' + label + '</b> ' + esc(v) + (extra || '') + '</span>' : '';
		};
		var html = '';
		if (o.email) {
			html += '<span class="pg-info-item"><b>Email</b> <a href="mailto:' + esc(o.email) + '">' + esc(o.email) + '</a> <button class="pg-chipbtn" data-copy="' + esc(o.email) + '">Copy</button></span>';
		}
		html += row('Company', o.company) + row('Payment', o.payment) + row('Txn ID', o.txn_id) +
			row('Shipping', o.ship_method) + row('Coupon', o.coupons && o.coupons.length ? o.coupons.join(', ') : '') +
			row('Source', o.source) + row('Account', o.account);
		return html ? '<div class="pg-info">' + html + '</div>' : '';
	}

	function statusPill(s, label) {
		return '<span class="pg-st pg-st-' + esc(s) + '">' + esc(label || cfg.statuses[s] || s) + '</span>';
	}

	/* ---------- Courier report: "should we send a parcel?" ---------- */

	var RISK_ICON = { safe: '✓', caution: '!', risky: '✕', 'new': '★' };
	var VOLUME_BN = { none: 'কোনো রেকর্ড নেই', low: 'কম', medium: 'মাঝারি', high: 'বেশি', very_high: 'অনেক বেশি' };
	var SKIP_AUTO = ['completed', 'cancelled', 'refunded', 'failed', 'trash'];

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

	function courierHtml(o) {
		var c = o.courier;
		if (!c) { return ''; }
		return '<div class="pg-courier"><b>Steadfast</b>' +
			'<span>Status: <b>' + esc(c.status || 'in_review') + '</b></span>' +
			(c.tracking ? '<span>Tracking: <a href="' + esc(c.url) + '" target="_blank" rel="noopener">' + esc(c.tracking) + '</a> <button class="pg-chipbtn" data-copy="' + esc(c.tracking) + '">Copy</button></span>' : '') +
			'<span>CID: ' + esc(c.cid) + '</span><span>COD: ' + A.money(c.cod) + '</span>' +
			(c.message ? '<span class="pg-muted">' + esc(c.message) + '</span>' : '') + '</div>';
	}

	function cardHtml(o) {
		var sel = state.selected.has(o.id);
		var dup = '';
		if (o.dup) {
			dup = '<button type="button" class="pg-badge ' + (o.dup.type === 'duplicate' ? 'pg-badge-dup' : 'pg-badge-repeat') + '" data-act="history" title="Show customer history">' +
				(o.dup.type === 'duplicate' ? 'Duplicate' : 'Repeat') + ' · ' + o.dup.count + ' orders</button>';
		}
		var items = o.items.map(function (it) {
			return '<li><span>' + esc(it.name) + ' <b>×' + it.qty + '</b></span><span>' + A.money(it.subtotal) + '</span></li>';
		}).join('');
		var phone = o.phone_bd || o.phone;
		var waPhone = o.phone_bd ? '88' + o.phone_bd : '';

		var sfBtn;
		if (o.courier && o.courier.status !== 'cancelled') {
			sfBtn = '<button class="pg-btn" data-act="sf-refresh" title="Fetch the latest status from Steadfast">↻ Refresh status</button>' +
				'<button class="pg-btn pg-btn-bad" data-act="sf-void" title="Use after cancelling the parcel in the Steadfast portal">Void / Resend</button>';
		} else {
			sfBtn = '<button class="pg-btn pg-btn-primary" data-act="sf-send">' + (o.courier ? 'Resend SF' : 'Send to SF') + '</button>';
		}
		var statusOpts = Object.keys(cfg.statuses).map(function (s) {
			return '<option value="' + esc(s) + '"' + (s === o.status ? ' selected' : '') + '>' + esc(cfg.statuses[s]) + '</option>';
		}).join('');

		return '<article class="pg-order' + (sel ? ' is-selected' : '') + '" data-id="' + o.id + '">' +
			'<div class="pg-order-top">' +
				'<label class="pg-check"><input type="checkbox" data-act="select"' + (sel ? ' checked' : '') + ' aria-label="Select order ' + esc(o.number) + '"></label>' +
				'<div class="pg-order-main">' +
					'<div class="pg-order-line1"><span class="pg-order-no">#' + esc(o.number) + '</span>' + statusPill(o.status, o.status_label) + dup +
						'<span class="pg-order-date">' + esc(o.date) + '</span><span class="pg-order-total">' + A.money(o.total) + '</span></div>' +
					'<div class="pg-cust"><div class="pg-cust-name">' + esc(o.name || '—') + '</div>' +
						'<div class="pg-cust-row"><a class="pg-phone" href="tel:' + esc(phone) + '">' + esc(phone) + '</a>' +
							'<a class="pg-chipbtn call" href="tel:' + esc(phone) + '" aria-label="Call">Call</a>' +
							'<button class="pg-chipbtn" data-copy="' + esc(phone) + '">Copy</button>' +
							(waPhone ? '<a class="pg-chipbtn" href="https://wa.me/' + esc(waPhone) + '" target="_blank" rel="noopener">WhatsApp</a>' : '') +
							(o.alt_phone && o.alt_phone !== o.phone ? '<a class="pg-chipbtn" href="tel:' + esc(o.alt_phone) + '">Alt: ' + esc(o.alt_phone) + '</a>' : '') +
						'</div>' +
						'<div class="pg-cust-addr">' + esc(o.address) + ' <button class="pg-chipbtn" data-copy="' + esc(o.address) + '">Copy address</button></div>' +
						addrBoth(o) +
						infoHtml(o) +
					'</div>' +
					'<ul class="pg-items">' + items + (o.discount ? '<li><span class="pg-muted">Discount</span><span>-' + A.money(o.discount) + '</span></li>' : '') + (o.shipping ? '<li><span class="pg-muted">Delivery</span><span>' + A.money(o.shipping) + '</span></li>' : '') + '</ul>' +
					(o.customer_note ? '<div class="pg-custnote"><b>Customer note</b>' + esc(o.customer_note) + '</div>' : '') +
					courierHtml(o) +
					'<div class="pg-fraud" data-fraud>' + fraudHtml(o.fraud, o) + '</div>' +
				'</div>' +
			'</div>' +
			'<div class="pg-order-actions">' + sfBtn +
				'<button class="pg-btn" data-act="edit">Edit</button>' +
				'<button class="pg-btn" data-act="more" aria-expanded="false">Notes ▾</button>' +
				'<button class="pg-btn" data-act="jpg">Invoice JPG</button>' +
				'<button class="pg-btn" data-act="print">Print</button>' +
				(cfg.waReady ? '<button class="pg-btn" data-act="wa">Send WA</button>' : '') +
				'<select data-act="status" aria-label="Change status">' + statusOpts + '</select>' +
			'</div>' +
			'<div class="pg-order-more" hidden></div>' +
		'</article>';
	}

	function replaceCard(o) {
		state.orders[o.id] = Object.assign({}, state.orders[o.id] || {}, o, { fraud: o.fraud || (state.orders[o.id] && state.orders[o.id].fraud) });
		var el = $list.querySelector('.pg-order[data-id="' + o.id + '"]');
		if (!el) { return; }
		var tmp = document.createElement('div');
		tmp.innerHTML = cardHtml(state.orders[o.id]);
		el.replaceWith(tmp.firstChild);
		if (state.open.has(o.id)) { openMore(o.id, true); }
	}

	/* ---------- Notes panel ---------- */

	function openMore(id, force) {
		var card = $list.querySelector('.pg-order[data-id="' + id + '"]');
		if (!card) { return; }
		var more = card.querySelector('.pg-order-more');
		var btn = card.querySelector('[data-act="more"]');
		var show = force || more.hidden;
		more.hidden = !show;
		btn.setAttribute('aria-expanded', show ? 'true' : 'false');
		btn.textContent = show ? 'Notes ▴' : 'Notes ▾';
		if (!show) { state.open.delete(id); return; }
		state.open.add(id);
		var o = state.orders[id];
		more.innerHTML =
			'<div class="pg-chips">' + cfg.quickNotes.map(function (n) {
				return '<button type="button" class="pg-chip" data-act="chip" data-note="' + esc(n) + '">' + esc(n) + '</button>';
			}).join('') + '</div>' +
			'<div class="pg-note-form"><textarea rows="1" placeholder="Write a private note…" data-note-input></textarea>' +
			'<button class="pg-btn pg-btn-primary" data-act="add-note">Save</button></div>' +
			'<label class="pg-check pg-muted" style="margin-bottom:10px"><input type="checkbox" data-note-customer> Send as note to customer (email)</label>' +
			'<div class="pg-notes" data-notes><div class="pg-muted">Loading notes…</div></div>' +
			'<div style="margin-top:10px;display:flex;gap:6px;flex-wrap:wrap">' +
				(cfg.canWoo ? '<a class="pg-btn pg-btn-sm" href="' + esc(o.edit_url) + '" target="_blank" rel="noopener">Open in WooCommerce ↗</a>' : '') +
				(o.courier ? '<button class="pg-btn pg-btn-sm" data-act="sf-return">Request return</button>' : '') +
			'</div>';
		A.ajax('pg_orders_notes', { order_id: id }).then(function (d) { renderNotes(id, d.notes); })
			.catch(function (e) { setNotesHtml(id, '<div class="pg-alert pg-alert-bad">' + esc(e.message) + '</div>'); });
	}

	function setNotesHtml(id, html) {
		var box = $list.querySelector('.pg-order[data-id="' + id + '"] [data-notes]');
		if (box) { box.innerHTML = html; }
	}

	function renderNotes(id, notes) {
		if (!notes.length) { setNotesHtml(id, '<div class="pg-muted">No notes yet.</div>'); return; }
		setNotesHtml(id, notes.map(function (n) {
			return '<div class="pg-note pg-note-' + n.type + '" data-note-id="' + n.id + '"><div class="pg-note-text">' + esc(n.content) + '</div>' +
				'<div class="pg-note-meta"><span>' + esc(n.date) + ' · ' + esc(n.by) + ' · ' + (n.type === 'customer' ? 'to customer' : n.type) + '</span>' +
				(n.editable ? '<span><button data-act="edit-note">Edit</button><button data-act="del-note">Delete</button></span>' : '') +
				'</div></div>';
		}).join(''));
	}

	function addNote(id, text, btn) {
		var card = $list.querySelector('.pg-order[data-id="' + id + '"]');
		var cust = card && card.querySelector('[data-note-customer]');
		A.busy(btn, true);
		return A.ajax('pg_orders_add_note', { order_id: id, note: text, customer: cust && cust.checked ? 1 : 0 })
			.then(function (d) {
				renderNotes(id, d.notes);
				A.toast('Note saved', 'ok');
				var inp = card && card.querySelector('[data-note-input]');
				if (inp && inp.value === text) { inp.value = ''; }
			})
			.catch(function (e) { A.toast(e.message, 'bad'); })
			.finally(function () { A.busy(btn, false); });
	}

	function paintFraud(phone) {
		Object.keys(state.orders).forEach(function (k) {
			var x = state.orders[k];
			if (x.phone_bd === phone) {
				var b = $list.querySelector('.pg-order[data-id="' + k + '"] [data-fraud]');
				if (b) { b.innerHTML = fraudHtml(x.fraud, x); }
			}
		});
	}

	function setFraud(phone, val) {
		Object.keys(state.orders).forEach(function (k) {
			if (state.orders[k].phone_bd === phone) { state.orders[k].fraud = val; }
		});
		paintFraud(phone);
	}

	function fraudCheck(id, force) {
		var o = state.orders[id];
		if (!o || !o.phone_bd) { return Promise.resolve(); }
		var phone = o.phone_bd;
		setFraud(phone, { loading: true });
		return A.ajax('pg_fraud_check', { phone: phone, force: force ? 1 : 0 })
			.then(function (d) { setFraud(phone, d.data); })
			.catch(function (e) { setFraud(phone, { error: e.message || 'error' }); });
	}

	/* Load courier reports by themselves for orders that still need a decision. */
	var fraudQueue = [], fraudRunning = 0;
	function autoFraud() {
		if (!cfg.sfReady || !cfg.fraudAuto) { return; }
		var seen = {};
		fraudQueue = [];
		state.order.forEach(function (id) {
			var o = state.orders[id];
			if (!o || o.fraud || !o.phone_bd || seen[o.phone_bd] || SKIP_AUTO.indexOf(o.status) > -1) { return; }
			seen[o.phone_bd] = true;
			fraudQueue.push(id);
			o.fraud = { loading: true };
			paintFraud(o.phone_bd);
		});
		for (var i = 0; i < 2; i++) { nextFraud(); }
	}
	function nextFraud() {
		if (fraudRunning >= 2 || !fraudQueue.length) { return; }
		var id = fraudQueue.shift();
		if (!state.orders[id]) { nextFraud(); return; }
		fraudRunning++;
		fraudCheck(id, false).then(function () { fraudRunning--; nextFraud(); });
	}

	/* ---------- History ---------- */

	function history(id) {
		var o = state.orders[id];
		var m = A.modal('Customer history · ' + (o.phone_bd || o.phone), '<div class="pg-muted">Loading…</div>');
		A.ajax('pg_orders_history', { phone: o.phone }).then(function (d) {
			m.body.innerHTML = '<p class="pg-muted">' + d.orders.length + ' orders from this number.</p>' + d.orders.map(function (h) {
				return '<div class="pg-hist"' + (h.id === o.id ? ' style="border-color:#2563eb"' : '') + '><div class="pg-hist-top"><b>#' + esc(h.number) + '</b>' + statusPill(h.status, h.status_label) +
					'<span class="pg-muted">' + esc(h.date) + '</span><span class="amt">' + A.money(h.total) + '</span></div>' +
					'<div style="margin-top:4px">' + esc(h.items) + '</div>' +
					'<div class="pg-muted">' + esc(h.name) + ' · ' + esc(h.address) + '</div>' +
					(h.courier ? '<div class="pg-muted">Courier: ' + esc(h.courier) + '</div>' : '') +
					h.notes.map(function (n) { return '<div class="pg-note pg-note-' + n.type + '">' + esc(n.content) + '<div class="pg-note-meta"><span>' + esc(n.date) + ' · ' + esc(n.by) + '</span></div></div>'; }).join('') +
					(cfg.canWoo ? '<div style="margin-top:6px"><a class="pg-btn pg-btn-sm" href="' + esc(h.edit_url) + '" target="_blank" rel="noopener">Open ↗</a></div>' : '') + '</div>';
			}).join('');
		}).catch(function (e) { m.body.innerHTML = '<div class="pg-alert pg-alert-bad">' + esc(e.message) + '</div>'; });
	}

	/* ---------- Steadfast ---------- */

	function sfSend(id) {
		var o = state.orders[id];
		if (!cfg.sfReady) { A.toast('Add Steadfast API keys in Settings first.', 'bad'); return; }
		var warn = '';
		if (o.dup && o.dup.type === 'duplicate') {
			warn = '<div class="pg-alert pg-alert-warn">This number has other recent orders (#' + o.dup.dup_ids.join(', #') + '). Check for a duplicate before sending.</div>';
		}
		if (o.fraud && o.fraud.verdict && o.fraud.verdict.level !== 'safe') {
			var lv = o.fraud.verdict.level;
			warn += '<div class="pg-alert ' + (lv === 'risky' ? 'pg-alert-bad' : (lv === 'new' ? 'pg-alert-info' : 'pg-alert-warn')) + '">' +
				esc(o.fraud.verdict.title) + ' — ' + esc(o.fraud.verdict.advice) + '</div>';
		}
		var m = A.modal('Send #' + o.number + ' to Steadfast',
			warn +
			'<div class="pg-field"><label>Invoice</label><input type="text" value="' + esc(o.next_invoice) + '" readonly></div>' +
			'<div class="pg-field"><label>Recipient</label><div>' + esc(o.name) + ' · <b>' + esc(o.phone_bd || o.phone) + '</b></div><div class="pg-muted">' + esc(o.address) + '</div></div>' +
			'<div class="pg-field"><label>COD amount (৳)</label><input type="number" min="0" step="1" data-cod value="' + Math.round(o.cod) + '"></div>' +
			'<div class="pg-field"><label>Note for rider</label><textarea data-note rows="2">' + esc(o.customer_note || '') + '</textarea></div>' +
			'<div class="pg-form-actions"><button class="pg-btn" data-close>Cancel</button><button class="pg-btn pg-btn-primary" data-go>Book parcel</button></div>',
			{ center: true });
		m.body.querySelector('[data-go]').addEventListener('click', function () {
			var btn = this;
			A.busy(btn, true);
			A.ajax('pg_sf_send', { order_id: id, cod_amount: m.body.querySelector('[data-cod]').value, note: m.body.querySelector('[data-note]').value })
				.then(function (d) { A.toast(d.message, 'ok'); m.close(); replaceCard(d.order); refreshKpis(); })
				.catch(function (e) { A.toast(e.message, 'bad'); A.busy(btn, false); });
		});
	}

	function sfVoid(id, btn) {
		var o = state.orders[id];
		var m = A.modal('Void consignment?',
			'<p>Use this after the parcel <b>' + esc(o.courier.tracking) + '</b> was cancelled in the Steadfast portal.</p>' +
			'<p class="pg-muted">The order goes back to Processing, and the next booking uses a new invoice suffix (-R1, -R2…) so Steadfast accepts it.</p>' +
			'<div class="pg-form-actions"><button class="pg-btn" data-close>Keep</button><button class="pg-btn pg-btn-primary" data-go>Void consignment</button></div>',
			{ center: true });
		m.body.querySelector('[data-go]').addEventListener('click', function () {
			var b = this;
			A.busy(b, true);
			A.ajax('pg_sf_void', { order_id: id }).then(function (d) { A.toast(d.message, 'ok'); m.close(); replaceCard(d.order); refreshKpis(); })
				.catch(function (e) { A.toast(e.message, 'bad'); A.busy(b, false); });
		});
	}

	function sfReturn(id) {
		var o = state.orders[id];
		var m = A.modal('Return request · ' + o.courier.tracking,
			'<div class="pg-field"><label>Reason</label><textarea data-reason rows="2"></textarea></div>' +
			'<div class="pg-form-actions"><button class="pg-btn" data-close>Cancel</button><button class="pg-btn pg-btn-primary" data-go>Create return request</button></div>',
			{ center: true });
		m.body.querySelector('[data-go]').addEventListener('click', function () {
			var b = this;
			A.busy(b, true);
			A.ajax('pg_sf_return_request', { consignment_id: o.courier.cid, reason: m.body.querySelector('[data-reason]').value })
				.then(function (d) { A.toast(d.message, 'ok'); m.close(); })
				.catch(function (e) { A.toast(e.message, 'bad'); A.busy(b, false); });
		});
	}

	function refreshKpis() {
		A.ajax('pg_sf_kpis', {}).then(renderKpis).catch(function () {});
	}

	function bulkSend() {
		var ids = Array.from(state.selected);
		if (!ids.length) { return; }
		if (!cfg.sfReady) { A.toast('Add Steadfast API keys in Settings first.', 'bad'); return; }
		var m = A.modal('Send ' + ids.length + ' orders to Steadfast',
			'<p class="pg-muted">Each order is validated first. Orders already booked are skipped.</p>' +
			'<div class="pg-form-actions"><button class="pg-btn" data-close>Cancel</button><button class="pg-btn pg-btn-primary" data-go>Book ' + ids.length + ' parcels</button></div><ul class="pg-bulk-res" data-res></ul>',
			{ center: true });
		m.body.querySelector('[data-go]').addEventListener('click', function () {
			var b = this;
			A.busy(b, true);
			A.ajax('pg_sf_bulk', { order_ids: ids }).then(function (d) {
				var ok = 0;
				m.body.querySelector('[data-res]').innerHTML = d.results.map(function (r) {
					var o = state.orders[r.order_id];
					if (r.ok) { ok++; }
					return '<li class="' + (r.ok ? 'ok' : 'bad') + '">#' + esc(o ? o.number : r.order_id) + ': ' +
						(r.ok ? '✓ ' + esc(r.tracking_code) : '✗ ' + esc((r.errors || []).join('; '))) + '</li>';
				}).join('');
				b.remove();
				A.toast(ok + ' booked, ' + (d.results.length - ok) + ' failed', ok ? 'ok' : 'bad');
				state.selected.clear();
				load(true);
			}).catch(function (e) { A.toast(e.message, 'bad'); A.busy(b, false); });
		});
	}

	/* ---------- Selection / bulk bar ---------- */

	function updateBar() {
		var n = state.selected.size;
		$bar.hidden = n === 0;
		document.getElementById('pg-bottom-count').textContent = n + ' selected';
		document.getElementById('pg-sel-count').textContent = n;
		var all = document.getElementById('pg-select-all');
		all.checked = state.order.length > 0 && state.order.every(function (id) { return state.selected.has(id); });
	}

	/* ---------- Events ---------- */

	$tabs.addEventListener('click', function (e) {
		var t = e.target.closest('[data-tab]');
		if (!t) { return; }
		state.status = t.getAttribute('data-tab');
		state.page = 1;
		persist();
		load();
	});

	$pager.addEventListener('click', function (e) {
		var b = e.target.closest('[data-page]');
		if (!b || b.disabled) { return; }
		state.page = parseInt(b.getAttribute('data-page'), 10);
		load();
	});

	$q.addEventListener('input', A.debounce(function () {
		state.q = $q.value.trim();
		state.page = 1;
		load();
	}, 350));

	$per.addEventListener('change', function () {
		state.per = parseInt($per.value, 10);
		state.page = 1;
		persist();
		load();
	});

	document.getElementById('pg-select-all').addEventListener('change', function () {
		var on = this.checked;
		state.order.forEach(function (id) { if (on) { state.selected.add(id); } else { state.selected.delete(id); } });
		renderList();
	});

	$bar.addEventListener('click', function (e) {
		var b = e.target.closest('[data-bulk]');
		if (!b) { return; }
		var act = b.getAttribute('data-bulk');
		if (act === 'clear') { state.selected.clear(); renderList(); }
		if (act === 'print') { window.open(A.printUrl(Array.from(state.selected)), '_blank'); }
		if (act === 'sf') { bulkSend(); }
	});

	$list.addEventListener('change', function (e) {
		var card = e.target.closest('.pg-order');
		if (!card) { return; }
		var id = parseInt(card.getAttribute('data-id'), 10);
		var act = e.target.getAttribute('data-act');
		if (act === 'select') {
			if (e.target.checked) { state.selected.add(id); } else { state.selected.delete(id); }
			card.classList.toggle('is-selected', e.target.checked);
			updateBar();
		}
		if (act === 'status') {
			var sel = e.target, val = sel.value, prev = state.orders[id].status;
			sel.disabled = true;
			A.ajax('pg_orders_set_status', { order_id: id, status: val })
				.then(function (d) { A.toast('Status → ' + cfg.statuses[val], 'ok'); replaceCard(d.order); })
				.catch(function (err) { A.toast(err.message, 'bad'); sel.value = prev; sel.disabled = false; });
		}
	});

	$list.addEventListener('click', function (e) {
		var el = e.target.closest('[data-act]');
		if (!el || el.tagName === 'SELECT' || el.type === 'checkbox') { return; }
		var card = el.closest('.pg-order');
		if (!card) { return; }
		var id = parseInt(card.getAttribute('data-id'), 10);
		var act = el.getAttribute('data-act');
		var o = state.orders[id];
		switch (act) {
			case 'more': openMore(id); break;
			case 'edit': editOrder(id); break;
			case 'history': history(id); break;
			case 'sf-send': sfSend(id); break;
			case 'sf-void': sfVoid(id, el); break;
			case 'sf-return': sfReturn(id); break;
			case 'sf-refresh':
				A.busy(el, true);
				A.ajax('pg_sf_refresh', { order_id: id }).then(function (d) { A.toast(d.message, 'ok'); replaceCard(d.order); refreshKpis(); })
					.catch(function (err) { A.toast(err.message, 'bad'); A.busy(el, false); });
				break;
			case 'print': window.open(A.printUrl([id]), '_blank'); break;
			case 'jpg': window.PGInvoiceJPG && window.PGInvoiceJPG.open(o); break;
			case 'wa':
				A.busy(el, true);
				A.ajax('pg_wa_send', { order_id: id }).then(function (d) { A.toast(d.message, 'ok'); })
					.catch(function (err) { A.toast(err.message, 'bad'); }).finally(function () { A.busy(el, false); });
				break;
			case 'chip': addNote(id, el.getAttribute('data-note'), el); break;
			case 'add-note':
				var inp = card.querySelector('[data-note-input]');
				if (inp.value.trim()) { addNote(id, inp.value.trim(), el); }
				break;
			case 'fraud-refresh': fraudCheck(id, true); break;
			case 'risk-toggle':
				var box = el.closest('.pg-risk'), det = box && box.querySelector('.pg-risk-detail');
				if (det) {
					det.hidden = !det.hidden;
					box.querySelector('.pg-risk-head').setAttribute('aria-expanded', det.hidden ? 'false' : 'true');
					box.querySelector('.pg-risk-more').textContent = det.hidden ? 'বিস্তারিত ▾' : 'বন্ধ করুন ▴';
				}
				break;
			case 'edit-note':
			case 'del-note':
				var noteEl = el.closest('[data-note-id]');
				var nid = noteEl.getAttribute('data-note-id');
				if (act === 'del-note') {
					if (!window.confirm('Delete this note?')) { return; }
					A.ajax('pg_orders_delete_note', { order_id: id, note_id: nid }).then(function (d) { renderNotes(id, d.notes); A.toast('Deleted', 'ok'); })
						.catch(function (err) { A.toast(err.message, 'bad'); });
				} else {
					var cur = noteEl.querySelector('.pg-note-text').textContent;
					noteEl.querySelector('.pg-note-text').innerHTML = '<textarea rows="2" style="width:100%">' + esc(cur) + '</textarea><div style="margin-top:6px;display:flex;gap:6px"><button class="pg-btn pg-btn-sm pg-btn-primary" data-act="save-note">Save</button></div>';
				}
				break;
			case 'save-note':
				var ne = el.closest('[data-note-id]');
				A.busy(el, true);
				A.ajax('pg_orders_edit_note', { order_id: id, note_id: ne.getAttribute('data-note-id'), note: ne.querySelector('textarea').value })
					.then(function (d) { renderNotes(id, d.notes); A.toast('Updated', 'ok'); })
					.catch(function (err) { A.toast(err.message, 'bad'); A.busy(el, false); });
				break;
		}
	});

	$list.addEventListener('keydown', function (e) {
		if (e.key === 'Enter' && !e.shiftKey && e.target.matches('[data-note-input]')) {
			e.preventDefault();
			var card = e.target.closest('.pg-order');
			card.querySelector('[data-act="add-note"]').click();
		}
	});


	/* ---------- Edit order ---------- */

	function editOrder(id) {
		var o = state.orders[id];
		var items = o.items.map(function (it) {
			return { item_id: it.item_id, product_id: it.product_id, name: it.name, qty: it.qty, price: it.unit, thumb: it.thumb };
		});
		var warn = '';
		if (o.courier && o.courier.status !== 'cancelled') {
			warn = '<div class="pg-alert pg-alert-warn">Already booked with Steadfast (COD ' + A.money(o.courier.cod) + '). If the total changes, update the COD in the Steadfast panel too.</div>';
		}
		if (o.coupons && o.coupons.length) {
			warn += '<div class="pg-alert pg-alert-warn">Coupon ' + esc(o.coupons.join(', ')) + ' will be replaced by the discount amount below.</div>';
		}
		var m = A.modal('Edit order #' + o.number,
			warn +
			'<div class="pg-edit">' +
			'<div class="pg-card"><div class="pg-card-head"><h2>Customer</h2></div>' +
				'<div class="pg-grid pg-grid-2">' +
					'<div class="pg-field"><label>Name</label><input type="text" data-c="name" value="' + esc(o.name) + '"></div>' +
					'<div class="pg-field"><label>Phone</label><input type="tel" data-c="phone" value="' + esc(o.phone_bd || o.phone) + '"></div>' +
				'</div>' +
				'<div class="pg-field"><label>Address</label><textarea rows="2" data-c="address">' + esc(o.address_1 || o.address) + '</textarea></div>' +
				'<div class="pg-grid pg-grid-2">' +
					'<div class="pg-field"><label>City / District</label><input type="text" data-c="city" value="' + esc(o.city) + '"></div>' +
					'<div class="pg-field"><label>Alternative phone</label><input type="tel" data-c="alt_phone" value="' + esc(o.alt_phone) + '"></div>' +
				'</div>' +
				'<div class="pg-field"><label>Email</label><input type="email" data-c="email" value="' + esc(o.email) + '" placeholder="optional"></div>' +
				'<div class="pg-field"><label>Customer note</label><textarea rows="2" data-c="note">' + esc(o.customer_note) + '</textarea></div>' +
			'</div>' +
			'<div class="pg-card"><div class="pg-card-head"><h2>Products</h2></div>' +
				'<div class="pg-edit-head"><span></span><span>Product</span><span>Price</span><span>Qty</span><span>Total</span><span></span></div>' +
				'<div data-items></div>' +
				'<div class="pg-grid pg-grid-2" style="margin-top:12px">' +
					'<div class="pg-field"><label>Discount (৳)</label><input type="number" min="0" step="1" data-discount value="' + Math.round(o.discount || 0) + '"></div>' +
					'<div class="pg-field"><label>Delivery charge (৳)</label><input type="number" min="0" step="1" data-delivery value="' + Math.round(o.shipping || 0) + '"></div>' +
				'</div>' +
				'<div class="pg-pkg-sum" data-sum></div>' +
			'</div>' +
			'<div class="pg-card"><div class="pg-card-head"><h2>Add products</h2></div><div data-picker></div></div>' +
			'</div>' +
			'<div class="pg-form-actions pg-edit-actions"><button type="button" class="pg-btn" data-close>Cancel</button><button type="button" class="pg-btn pg-btn-primary" data-save>Save changes</button></div>',
			{ center: true, wide: true });
		var body = m.body;

		function renderItems() {
			body.querySelector('[data-items]').innerHTML = items.length ? items.map(function (it, i) {
				return '<div class="pg-edit-row" data-i="' + i + '">' +
					(it.thumb ? '<img src="' + esc(it.thumb) + '" alt="">' : '<span class="pg-thumb-ph"></span>') +
					'<div class="nm">' + esc(it.name) + (it.item_id ? '' : ' <span class="pg-badge pg-badge-info">new</span>') + '</div>' +
					'<input type="number" min="0" step="1" data-price="' + i + '" value="' + esc(it.price) + '" aria-label="Unit price">' +
					'<input type="number" min="1" step="1" data-qty="' + i + '" value="' + esc(it.qty) + '" aria-label="Quantity">' +
					'<div class="lt" data-lt="' + i + '">' + A.money(it.price * it.qty) + '</div>' +
					'<button type="button" class="pg-btn pg-btn-sm pg-btn-bad" data-rm="' + i + '" aria-label="Remove">✕</button></div>';
			}).join('') : '<div class="pg-pp-cart-empty">No products — add at least one from the list below.</div>';
			renderSum();
			if (picker) { picker.refresh(); }
		}
		function renderSum() {
			var sub = items.reduce(function (s, it) { return s + (Number(it.price) || 0) * it.qty; }, 0);
			var disc = Math.min(Number(body.querySelector('[data-discount]').value) || 0, sub);
			var del = Number(body.querySelector('[data-delivery]').value) || 0;
			items.forEach(function (it, i) { var el = body.querySelector('[data-lt="' + i + '"]'); if (el) { el.textContent = A.money((Number(it.price) || 0) * it.qty); } });
			body.querySelector('[data-sum]').innerHTML =
				'<div><span>Subtotal</span><span>' + A.money(sub) + '</span></div>' +
				(disc ? '<div><span>Discount</span><span>-' + A.money(disc) + '</span></div>' : '') +
				'<div><span>Delivery</span><span>' + A.money(del) + '</span></div>' +
				'<div class="total"><span>New total</span><span>' + A.money(sub - disc + del) + '</span></div>' +
				(Math.abs(sub - disc + del - o.total) > 0.5 ? '<div class="pg-muted"><span>Current total</span><span>' + A.money(o.total) + '</span></div>' : '');
		}
		body.addEventListener('input', function (e) {
			var t = e.target, pi = t.getAttribute('data-price'), qi = t.getAttribute('data-qty');
			if (pi !== null) { items[pi].price = Number(t.value); renderSum(); }
			else if (qi !== null) { items[qi].qty = Math.max(1, parseInt(t.value, 10) || 1); renderSum(); }
			else if (t.hasAttribute('data-discount') || t.hasAttribute('data-delivery')) { renderSum(); }
		});
		body.addEventListener('click', function (e) {
			var rm = e.target.closest('[data-rm]');
			if (rm) { items.splice(parseInt(rm.getAttribute('data-rm'), 10), 1); renderItems(); return; }
		});
		var picker = null;
		picker = window.PGProductPicker.mount(body.querySelector('[data-picker]'), {
			onAdd: function (p) {
				var ex = items.find(function (it) { return it.product_id === p.id; });
				if (ex) { ex.qty++; } else { items.push({ item_id: 0, product_id: p.id, name: p.name, qty: 1, price: p.price, thumb: p.thumb }); }
				renderItems();
				A.toast('Added: ' + p.name, 'ok');
			},
			inOrder: function (pid) {
				return items.reduce(function (n, it) { return n + (it.product_id === pid ? it.qty : 0); }, 0);
			}
		});
		body.querySelector('[data-save]').addEventListener('click', function () {
			var b = this, cust = {};
			body.querySelectorAll('[data-c]').forEach(function (el) { cust[el.getAttribute('data-c')] = el.value.trim(); });
			if (!items.length) { A.toast('Add at least one product.', 'bad'); return; }
			A.busy(b, true);
			A.ajax('pg_orders_save', {
				order_id: id, customer: cust,
				items: items.map(function (it) { return { item_id: it.item_id, product_id: it.product_id, qty: it.qty, price: it.price }; }),
				discount: body.querySelector('[data-discount]').value, delivery: body.querySelector('[data-delivery]').value
			}).then(function (d) {
				A.toast(d.message, 'ok');
				if (d.warning) { A.toast(d.warning, 'bad'); }
				m.close();
				replaceCard(d.order);
			}).catch(function (err) { A.toast(err.message, 'bad'); A.busy(b, false); });
		});
		renderItems();
	}

	/* ---------- Courier sync ---------- */

	var syncBtn = document.getElementById('pg-sync');
	if (syncBtn) {
		syncBtn.addEventListener('click', function () {
			A.busy(syncBtn, true);
			A.ajax('pg_sf_sync', {}).then(function (d) {
				A.toast(d.message, d.data.changed ? 'ok' : '');
				var note = document.getElementById('pg-sync-note');
				if (note) { note.textContent = 'Courier status last synced: just now (auto-sync every 30 min)'; }
				renderKpis(d.kpis);
				if (d.data.changed) { load(true); }
			}).catch(function (e) { A.toast(e.message, 'bad'); }).finally(function () { A.busy(syncBtn, false); });
		});
	}

	// "New order" opens the screenshot / manual order creator in a modal.
	var newBtn = document.getElementById('pg-new-order');
	if (newBtn) {
		newBtn.addEventListener('click', function () {
			if (!window.PGOcrForm) { window.location.href = cfg.newOrderUrl; return; }
			var m = A.modal('New order', '', { center: true, wide: true });
			window.PGOcrForm.mount(m.body, { onCreated: function () { load(true); } });
		});
	}

	// Refresh when returning to the tab (another device may have changed orders).
	var lastLoad = Date.now();
	document.addEventListener('visibilitychange', function () {
		if (document.visibilityState === 'visible' && Date.now() - lastLoad > 60000 && !state.loading) {
			lastLoad = Date.now();
			load(true);
		}
	});

	load();
})();
