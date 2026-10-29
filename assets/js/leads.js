/* Product Glide — Incomplete orders (checkout started, order not placed) */
(function () {
	'use strict';
	var A = window.PGA, cfg = A.cfg, esc = A.esc, L = window.PG_LEADS || {};
	var root = document.getElementById('pg-leads-root');
	if (!root) { return; }

	var state = { tab: 'waiting', q: '', page: 1, per: 25, leads: {}, order: [], req: 0, loading: false };
	try {
		var saved = JSON.parse(localStorage.getItem('pg_leads_state') || '{}');
		if (saved.tab && L.tabs && L.tabs[saved.tab]) { state.tab = saved.tab; }
	} catch (e) {}

	var STATUS = {
		open: ['pg-badge-warn', 'অসম্পূর্ণ'],
		hold: ['pg-badge-info', 'পরে কল'],
		cancelled: ['pg-badge-bad', 'বাতিল'],
		converted: ['pg-badge-ok', 'কনফার্ম হয়েছে'],
		ordered: ['pg-badge-ok', 'কাস্টমার নিজে অর্ডার করেছে']
	};
	var TAB_BN = {
		waiting: 'অসম্পূর্ণ', active: 'এখন checkout-এ', hold: 'পরে কল', converted: 'কনফার্ম হয়েছে',
		ordered: 'কাস্টমার নিজে অর্ডার করেছে', cancelled: 'বাতিল', all: 'সব'
	};

	root.innerHTML =
		(L.enabled ? '' : '<div class="pg-alert pg-alert-warn">অসম্পূর্ণ অর্ডার সেভ করা বন্ধ আছে। <a href="' + esc(L.settingsUrl) + '">Settings থেকে চালু করুন</a>।</div>') +
		'<p class="pg-muted pg-leads-intro">কাস্টমার checkout পেজে নাম-ফোন লিখেছে কিন্তু "Place order" চাপেনি — তাদের তালিকা। ফোন করে কনফার্ম করলে এক ক্লিকে আসল অর্ডার হবে। ' +
			'নিজে অর্ডার করে ফেললে এখান থেকে নিজে থেকেই সরে যাবে।</p>' +
		'<div class="pg-toolbar"><input type="search" data-q placeholder="নাম, ফোন বা ঠিকানা দিয়ে খুঁজুন…" autocomplete="off"></div>' +
		'<div class="pg-status-tabs" data-tabs></div>' +
		'<div class="pg-list" data-list></div>' +
		'<div class="pg-pager" data-pager></div>';
	var $list = root.querySelector('[data-list]'), $tabs = root.querySelector('[data-tabs]'), $pager = root.querySelector('[data-pager]');
	var $q = root.querySelector('[data-q]');

	function persist() { try { localStorage.setItem('pg_leads_state', JSON.stringify({ tab: state.tab })); } catch (e) {} }

	/* ---------- Loading ---------- */

	function load(keep) {
		var my = ++state.req;
		state.loading = true;
		if (!keep) { $list.innerHTML = '<div class="pg-skel"></div><div class="pg-skel"></div>'; }
		return A.ajax('pg_leads_list', { tab: state.tab, q: state.q, page: state.page, per_page: state.per }).then(function (d) {
			if (my !== state.req) { return; }
			state.leads = {}; state.order = [];
			d.leads.forEach(function (x) { state.leads[x.id] = x; state.order.push(x.id); });
			renderTabs(d.counts);
			renderList();
			renderPager(d.page, d.pages, d.total);
			if (!keep) { window.scrollTo({ top: 0 }); }
		}).catch(function (e) {
			if (my === state.req) { $list.innerHTML = '<div class="pg-empty">' + esc(e.message) + '</div>'; }
		}).finally(function () { state.loading = false; });
	}

	function renderTabs(c) {
		$tabs.innerHTML = Object.keys(L.tabs || TAB_BN).map(function (k) {
			var n = c && c[k] !== undefined ? c[k] : '';
			return '<button type="button" class="pg-status-tab' + (k === state.tab ? ' is-active' : '') + '" data-tab="' + k + '">' +
				esc(TAB_BN[k] || L.tabs[k]) + (n !== '' ? '<span class="c">' + n + '</span>' : '') + '</button>';
		}).join('');
	}

	function renderPager(page, pages, total) {
		if (pages <= 1) { $pager.innerHTML = '<span class="pg-muted">মোট ' + total + 'টি</span>'; return; }
		$pager.innerHTML =
			'<button class="pg-btn pg-btn-sm" data-page="' + (page - 1) + '"' + (page <= 1 ? ' disabled' : '') + '>‹ আগের</button>' +
			'<span class="pg-muted">পেজ ' + page + ' / ' + pages + ' · মোট ' + total + 'টি</span>' +
			'<button class="pg-btn pg-btn-sm" data-page="' + (page + 1) + '"' + (page >= pages ? ' disabled' : '') + '>পরের ›</button>';
	}

	var EMPTY = {
		waiting: 'এখন কোনো অসম্পূর্ণ অর্ডার নেই।', active: 'এই মুহূর্তে কেউ checkout পেজে লিখছে না।', hold: '"পরে কল" তালিকা খালি।',
		converted: 'এখনো কোনোটা কনফার্ম করা হয়নি।', ordered: 'এখনো কেউ ফিরে এসে নিজে অর্ডার করেনি।', cancelled: 'কোনো বাতিল নেই।', all: 'কিছু নেই।'
	};

	function renderList() {
		if (!state.order.length) { $list.innerHTML = '<div class="pg-empty">' + esc(state.q ? 'কিছু পাওয়া যায়নি।' : EMPTY[state.tab]) + '</div>'; return; }
		$list.innerHTML = state.order.map(function (id) { return cardHtml(state.leads[id]); }).join('');
		autoFraud();
	}

	/* ---------- Card ---------- */

	function ago(min) {
		min = Math.max(0, min || 0);
		if (min < 1) { return 'এইমাত্র'; }
		if (min < 60) { return min + ' মিনিট আগে'; }
		if (min < 1440) { return Math.floor(min / 60) + ' ঘণ্টা আগে'; }
		return Math.floor(min / 1440) + ' দিন আগে';
	}

	function cardHtml(x) {
		var st = STATUS[x.status] || ['', x.status];
		var phone = x.phone_bd || x.phone, wa = x.phone_bd ? '88' + x.phone_bd : '';
		var items = x.items.map(function (it) {
			return '<li><span>' + esc(it.name) + ' <b>×' + it.qty + '</b></span><span>' + A.money(it.price * it.qty) + '</span></li>';
		}).join('');
		var past = '';
		if (x.past && x.past.count) {
			past = '<div class="pg-lead-past"><b>এই নম্বরে আগে ' + x.past.count + 'টি অর্ডার:</b> ' + x.past.recent.map(function (o) {
				return '#' + o.id + ' (' + esc(o.status) + ', ' + esc(o.date) + ')';
			}).join(' · ') + '</div>';
		}
		var info = [];
		if (x.email) { info.push('<span class="pg-info-item"><b>Email</b> ' + esc(x.email) + '</span>'); }
		if (x.source === 'order-detect') { info.push('<span class="pg-info-item"><b>উৎস</b> Order Detect থেকে আনা</span>'); }
		if (x.handled_by) { info.push('<span class="pg-info-item"><b>দেখেছেন</b> ' + esc(x.handled_by) + '</span>'); }

		var done = x.status === 'converted' || x.status === 'ordered';
		var actions;
		if (done) {
			actions = x.order ? '<a class="pg-btn pg-btn-primary" href="' + esc(x.order.url) + '">অর্ডার #' + esc(x.order.number) + ' দেখুন (' + esc(x.order.status) + ')</a>' : '';
		} else {
			actions = '<button class="pg-btn pg-btn-primary" data-act="confirm">✓ কনফার্ম করে অর্ডার বানান</button>' +
				(x.status !== 'hold' ? '<button class="pg-btn" data-act="hold">⏰ পরে কল</button>' : '<button class="pg-btn" data-act="reopen">↩ অসম্পূর্ণ তালিকায় ফেরত</button>') +
				(x.status !== 'cancelled' ? '<button class="pg-btn pg-btn-bad" data-act="cancel">✕ বাতিল</button>' : '<button class="pg-btn" data-act="reopen">↩ আবার খুলুন</button>');
		}
		if (cfg.isAdmin) { actions += '<button class="pg-btn pg-btn-ghost pg-btn-bad" data-act="delete" title="মুছে ফেলুন">মুছুন</button>'; }

		return '<article class="pg-order pg-lead" data-id="' + x.id + '">' +
			'<div class="pg-order-top"><div class="pg-order-main">' +
				'<div class="pg-order-line1"><span class="pg-order-no">#' + x.id + '</span>' +
					'<span class="pg-badge ' + st[0] + '">' + esc(st[1]) + '</span>' +
					(x.live ? '<span class="pg-badge pg-lead-live"><i></i>এখন checkout-এ লিখছে</span>' : '') +
					'<span class="pg-order-date" title="' + esc(x.last) + '">' + ago(x.ago_min) + ' · ' + esc(x.last) + '</span>' +
					'<span class="pg-order-total">' + A.money(x.total) + '</span></div>' +
				'<div class="pg-cust"><div class="pg-cust-name">' + esc(x.name || '(নাম লেখেনি)') + '</div>' +
					'<div class="pg-cust-row"><a class="pg-phone" href="tel:' + esc(phone) + '">' + esc(phone) + '</a>' +
						'<a class="pg-chipbtn call" href="tel:' + esc(phone) + '">Call</a>' +
						'<button class="pg-chipbtn" data-copy="' + esc(phone) + '">Copy</button>' +
						(wa ? '<a class="pg-chipbtn" href="https://wa.me/' + esc(wa) + '" target="_blank" rel="noopener">WhatsApp</a>' : '') +
					'</div>' +
					(x.full_address ? '<div class="pg-cust-addr">' + esc(x.full_address) + ' <button class="pg-chipbtn" data-copy="' + esc(x.full_address) + '">Copy</button></div>' : '<div class="pg-cust-addr pg-muted">(ঠিকানা লেখেনি)</div>') +
					(info.length ? '<div class="pg-info">' + info.join('') + '</div>' : '') +
				'</div>' +
				'<ul class="pg-items">' + (items || '<li class="pg-muted">প্রোডাক্টের তথ্য নেই</li>') +
					(x.discount ? '<li><span class="pg-muted">ছাড়</span><span>-' + A.money(x.discount) + '</span></li>' : '') +
					(x.shipping ? '<li><span class="pg-muted">ডেলিভারি</span><span>' + A.money(x.shipping) + '</span></li>' : '') + '</ul>' +
				(x.note ? '<div class="pg-custnote"><b>কাস্টমারের নোট</b>' + esc(x.note) + '</div>' : '') +
				past +
				(done ? '' : '<div class="pg-fraud" data-fraud>' + A.fraudHtml(x.fraud, x) + '</div>') +
				notesHtml(x) +
			'</div></div>' +
			'<div class="pg-order-actions">' + actions + '</div>' +
		'</article>';
	}

	function notesHtml(x) {
		var list = (x.notes || []).slice().reverse().map(function (n) {
			return '<div class="pg-note' + (n.kind === 'status' ? ' pg-note-system' : ' pg-note-private') + '"><div>' + esc(n.text) + '</div>' +
				'<div class="pg-note-meta"><span>' + esc(n.by || '') + '</span><span>' + esc(n.at || '') + '</span></div></div>';
		}).join('');
		return '<div class="pg-lead-notes">' +
			'<div class="pg-note-form"><textarea rows="1" data-note-input placeholder="নোট লিখুন — যেমন: ফোন ধরেনি, বিকেলে কল দিতে বলেছে…" aria-label="নোট"></textarea><button class="pg-btn" data-act="note">যোগ করুন</button></div>' +
			(list ? '<div class="pg-notes">' + list + '</div>' : '') + '</div>';
	}

	function replace(x) {
		state.leads[x.id] = Object.assign({}, state.leads[x.id] || {}, x, { fraud: (state.leads[x.id] || {}).fraud, past: (state.leads[x.id] || {}).past });
		var el = $list.querySelector('.pg-lead[data-id="' + x.id + '"]');
		if (!el) { return; }
		var t = document.createElement('div');
		t.innerHTML = cardHtml(state.leads[x.id]);
		el.replaceWith(t.firstChild);
	}

	function removeCard(id) {
		var el = $list.querySelector('.pg-lead[data-id="' + id + '"]');
		if (el) { el.classList.add('is-leaving'); setTimeout(function () { el.remove(); if (!$list.children.length) { renderList(); } }, 250); }
		state.order = state.order.filter(function (i) { return i !== id; });
	}

	/* ---------- Courier report ---------- */

	function paintFraud(phone) {
		Object.keys(state.leads).forEach(function (k) {
			var x = state.leads[k];
			if (x.phone_bd !== phone) { return; }
			var b = $list.querySelector('.pg-lead[data-id="' + k + '"] [data-fraud]');
			if (b) { b.innerHTML = A.fraudHtml(x.fraud, x); }
		});
	}
	function setFraud(phone, v) {
		Object.keys(state.leads).forEach(function (k) { if (state.leads[k].phone_bd === phone) { state.leads[k].fraud = v; } });
		paintFraud(phone);
	}
	function fraudCheck(id, force) {
		var x = state.leads[id];
		if (!x || !x.phone_bd) { return Promise.resolve(); }
		setFraud(x.phone_bd, { loading: true });
		return A.ajax('pg_fraud_check', { phone: x.phone_bd, force: force ? 1 : 0 })
			.then(function (d) { setFraud(x.phone_bd, d.data); })
			.catch(function (e) { setFraud(x.phone_bd, { error: e.message || 'error' }); });
	}
	var queue = [], running = 0;
	function autoFraud() {
		if (!cfg.sfReady || !cfg.fraudAuto) { return; }
		var seen = {};
		queue = [];
		state.order.forEach(function (id) {
			var x = state.leads[id];
			if (!x || x.fraud || !x.phone_bd || seen[x.phone_bd] || x.status === 'converted' || x.status === 'ordered') { return; }
			seen[x.phone_bd] = 1;
			queue.push(id);
			x.fraud = { loading: true };
			paintFraud(x.phone_bd);
		});
		for (var i = 0; i < 2; i++) { next(); }
	}
	function next() {
		if (running >= 2 || !queue.length) { return; }
		var id = queue.shift();
		running++;
		fraudCheck(id, false).then(function () { running--; next(); });
	}

	/* ---------- Actions ---------- */

	function setStatus(id, status, note, btn) {
		A.busy(btn, true);
		return A.ajax('pg_leads_status', { id: id, status: status, note: note || '' }).then(function (d) {
			var msg = { hold: 'পরে কলের তালিকায় রাখা হলো', cancelled: 'বাতিল করা হলো', open: 'অসম্পূর্ণ তালিকায় ফেরত গেল' }[status];
			A.toast(msg, 'ok');
			renderTabs(d.counts);
			if (state.tab === 'all') { replace(d.lead); } else { removeCard(id); }
		}).catch(function (e) { A.toast(e.message, 'bad'); A.busy(btn, false); });
	}

	function askNote(title, placeholder, okText, okClass) {
		return new Promise(function (resolve) {
			var m = A.modal(title, '<div class="pg-field"><label>কারণ / নোট (ইচ্ছা হলে)</label><textarea rows="2" data-n placeholder="' + esc(placeholder) + '"></textarea></div>' +
				'<div class="pg-form-actions"><button class="pg-btn" data-close>ফিরে যান</button><button class="pg-btn ' + okClass + '" data-ok>' + esc(okText) + '</button></div>', { center: true, onClose: function () { resolve(null); } });
			var ta = m.body.querySelector('[data-n]');
			ta.focus();
			m.body.querySelector('[data-ok]').addEventListener('click', function () { var v = ta.value.trim(); resolve(v); m.close(); });
		});
	}

	$tabs.addEventListener('click', function (e) {
		var b = e.target.closest('[data-tab]');
		if (!b) { return; }
		state.tab = b.getAttribute('data-tab'); state.page = 1; persist(); load();
	});
	$pager.addEventListener('click', function (e) {
		var b = e.target.closest('[data-page]');
		if (b && !b.disabled) { state.page = parseInt(b.getAttribute('data-page'), 10); load(); }
	});
	$q.addEventListener('input', A.debounce(function () { state.q = $q.value.trim(); state.page = 1; load(); }, 350));

	$list.addEventListener('click', function (e) {
		var el = e.target.closest('[data-act]');
		if (!el) { return; }
		var card = el.closest('.pg-lead');
		if (!card) { return; }
		var id = parseInt(card.getAttribute('data-id'), 10), act = el.getAttribute('data-act');
		switch (act) {
			case 'confirm': confirmLead(id); break;
			case 'hold':
				askNote('পরে কল দেবেন', 'যেমন: রাত ৮টায় কল দিতে বলেছে', 'পরে কলের তালিকায় রাখুন', 'pg-btn-primary').then(function (n) { if (n !== null) { setStatus(id, 'hold', n, el); } });
				break;
			case 'cancel':
				askNote('বাতিল করবেন?', 'যেমন: অর্ডার করবে না / ভুল নম্বর', 'হ্যাঁ, বাতিল', 'pg-btn-bad').then(function (n) { if (n !== null) { setStatus(id, 'cancelled', n, el); } });
				break;
			case 'reopen': setStatus(id, 'open', '', el); break;
			case 'delete':
				if (!window.confirm('এই এন্ট্রিটা পুরোপুরি মুছে ফেলবেন?')) { return; }
				A.ajax('pg_leads_delete', { ids: [id] }).then(function (d) { renderTabs(d.counts); removeCard(id); A.toast('মুছে ফেলা হলো', 'ok'); })
					.catch(function (err) { A.toast(err.message, 'bad'); });
				break;
			case 'note':
				var inp = card.querySelector('[data-note-input]');
				if (!inp.value.trim()) { inp.focus(); return; }
				A.busy(el, true);
				A.ajax('pg_leads_note', { id: id, note: inp.value.trim() }).then(function (d) { replace(d.lead); A.toast('নোট যোগ হলো', 'ok'); })
					.catch(function (err) { A.toast(err.message, 'bad'); A.busy(el, false); });
				break;
			case 'fraud-refresh': fraudCheck(id, true); break;
			case 'risk-toggle': A.riskToggle(el); break;
		}
	});
	$list.addEventListener('keydown', function (e) {
		if (e.key === 'Enter' && !e.shiftKey && e.target.matches('[data-note-input]')) {
			e.preventDefault();
			e.target.closest('.pg-lead').querySelector('[data-act="note"]').click();
		}
	});

	/* ---------- Confirm → real order ---------- */

	function confirmLead(id) {
		var x = state.leads[id];
		var items = x.items.filter(function (it) { return it.product_id; }).map(function (it) {
			return { product_id: it.product_id, name: it.name, qty: it.qty, price: it.price, thumb: it.thumb };
		});
		var delivery = x.shipping > 0 ? x.shipping : (cfg.pkgDelivery || 0);
		var risk = '';
		if (x.fraud && x.fraud.verdict && x.fraud.verdict.level !== 'safe' && x.fraud.verdict.level !== 'new') {
			risk = '<div class="pg-alert pg-alert-' + (x.fraud.verdict.level === 'risky' ? 'bad' : 'warn') + '">' + esc(x.fraud.verdict.title) + ' — ' + esc(x.fraud.verdict.advice) + '</div>';
		}
		var m = A.modal('কনফার্ম করে অর্ডার বানান · #' + x.id,
			risk +
			'<div class="pg-edit">' +
			'<div class="pg-card"><div class="pg-card-head"><h2>কাস্টমার</h2></div>' +
				'<div class="pg-grid pg-grid-2">' +
					'<div class="pg-field"><label>নাম *</label><input type="text" data-c="name" value="' + esc(x.name) + '"></div>' +
					'<div class="pg-field"><label>ফোন *</label><input type="tel" data-c="phone" value="' + esc(x.phone_bd || x.phone) + '"></div>' +
				'</div>' +
				'<div class="pg-field"><label>ঠিকানা *</label><textarea rows="2" data-c="address">' + esc(x.address) + '</textarea></div>' +
				'<div class="pg-grid pg-grid-2">' +
					'<div class="pg-field"><label>শহর / জেলা</label><input type="text" data-c="city" value="' + esc([x.city, x.state].filter(Boolean).filter(function (v, i, a) { return a.indexOf(v) === i; }).join(', ')) + '"></div>' +
					'<div class="pg-field"><label>Email</label><input type="email" data-c="email" value="' + esc(x.email) + '" placeholder="ঐচ্ছিক"></div>' +
				'</div>' +
				'<div class="pg-field"><label>কাস্টমারের নোট</label><textarea rows="2" data-c="note">' + esc(x.note) + '</textarea></div>' +
			'</div>' +
			'<div class="pg-card"><div class="pg-card-head"><h2>প্রোডাক্ট</h2></div>' +
				'<div class="pg-edit-head"><span></span><span>Product</span><span>Price</span><span>Qty</span><span>Total</span><span></span></div>' +
				'<div data-items></div>' +
				'<div class="pg-grid pg-grid-2" style="margin-top:12px">' +
					'<div class="pg-field"><label>ছাড় (৳)</label><input type="number" min="0" step="1" data-discount value="' + Math.round(x.discount || 0) + '"></div>' +
					'<div class="pg-field"><label>ডেলিভারি চার্জ (৳)</label><input type="number" min="0" step="1" data-delivery value="' + Math.round(delivery) + '"></div>' +
				'</div>' +
				'<div class="pg-pkg-sum" data-sum></div>' +
			'</div>' +
			'<div class="pg-card"><div class="pg-card-head"><h2>প্রোডাক্ট যোগ করুন</h2></div><div data-picker></div></div>' +
			'<div class="pg-card pg-lead-sf"><div class="pg-card-head"><h2>কুরিয়ার</h2></div>' +
				(cfg.sfReady ?
					'<label class="pg-check"><input type="checkbox" data-sf checked> অর্ডারের সাথে সাথে Steadfast-এ পাঠান (স্ট্যাটাস হবে Courier Sent)</label>' +
					'<div class="pg-grid pg-grid-2" data-sf-box style="margin-top:10px">' +
						'<div class="pg-field"><label>COD (৳) — কাস্টমার যত টাকা দেবে</label><input type="number" min="0" step="1" data-cod></div>' +
						'<div class="pg-field"><label>রাইডারের জন্য নোট</label><input type="text" data-sf-note placeholder="ঐচ্ছিক"></div>' +
					'</div>' +
					'<p class="pg-muted">টিক তুলে দিলে অর্ডার Processing-এ থাকবে, পরে Orders পেজ থেকে পাঠাতে পারবেন।</p>'
					: '<p class="pg-muted">Steadfast সেটআপ করা নেই — অর্ডার Processing-এ যাবে।</p>') +
			'</div>' +
			'</div>' +
			'<div class="pg-form-actions pg-edit-actions"><button type="button" class="pg-btn" data-close>ফিরে যান</button><button type="button" class="pg-btn pg-btn-primary" data-save>✓ অর্ডার বানান</button></div>',
			{ center: true, wide: true });
		var body = m.body, picker = null, codTouched = false;
		var $cod = body.querySelector('[data-cod]');

		function sum() {
			var sub = items.reduce(function (s, it) { return s + (Number(it.price) || 0) * it.qty; }, 0);
			var disc = Math.min(Number(body.querySelector('[data-discount]').value) || 0, sub);
			var del = Number(body.querySelector('[data-delivery]').value) || 0;
			return { sub: sub, disc: disc, del: del, total: sub - disc + del };
		}
		function renderSum() {
			var s = sum();
			items.forEach(function (it, i) { var el = body.querySelector('[data-lt="' + i + '"]'); if (el) { el.textContent = A.money((Number(it.price) || 0) * it.qty); } });
			body.querySelector('[data-sum]').innerHTML =
				'<div><span>Subtotal</span><span>' + A.money(s.sub) + '</span></div>' +
				(s.disc ? '<div><span>ছাড়</span><span>-' + A.money(s.disc) + '</span></div>' : '') +
				'<div><span>ডেলিভারি</span><span>' + A.money(s.del) + '</span></div>' +
				'<div class="total"><span>মোট</span><span>' + A.money(s.total) + '</span></div>';
			if ($cod && !codTouched) { $cod.value = Math.round(s.total); }
		}
		function renderItems() {
			body.querySelector('[data-items]').innerHTML = items.length ? items.map(function (it, i) {
				return '<div class="pg-edit-row" data-i="' + i + '">' +
					(it.thumb ? '<img src="' + esc(it.thumb) + '" alt="">' : '<span class="pg-thumb-ph"></span>') +
					'<div class="nm">' + esc(it.name) + '</div>' +
					'<input type="number" min="0" step="1" data-price="' + i + '" value="' + esc(it.price) + '" aria-label="Unit price">' +
					'<input type="number" min="1" step="1" data-qty="' + i + '" value="' + esc(it.qty) + '" aria-label="Quantity">' +
					'<div class="lt" data-lt="' + i + '">' + A.money(it.price * it.qty) + '</div>' +
					'<button type="button" class="pg-btn pg-btn-sm pg-btn-bad" data-rm="' + i + '" aria-label="Remove">✕</button></div>';
			}).join('') : '<div class="pg-pp-cart-empty">কোনো প্রোডাক্ট নেই — নিচের তালিকা থেকে যোগ করুন।</div>';
			renderSum();
			if (picker) { picker.refresh(); }
		}
		body.addEventListener('input', function (e) {
			var t = e.target, pi = t.getAttribute('data-price'), qi = t.getAttribute('data-qty');
			if (pi !== null) { items[pi].price = Number(t.value); renderSum(); }
			else if (qi !== null) { items[qi].qty = Math.max(1, parseInt(t.value, 10) || 1); renderSum(); }
			else if (t.hasAttribute('data-discount') || t.hasAttribute('data-delivery')) { renderSum(); }
			else if (t.hasAttribute('data-cod')) { codTouched = true; }
		});
		body.addEventListener('change', function (e) {
			if (e.target.hasAttribute('data-sf')) { body.querySelector('[data-sf-box]').hidden = !e.target.checked; }
		});
		body.addEventListener('click', function (e) {
			var rm = e.target.closest('[data-rm]');
			if (rm) { items.splice(parseInt(rm.getAttribute('data-rm'), 10), 1); renderItems(); }
		});
		picker = window.PGProductPicker.mount(body.querySelector('[data-picker]'), {
			onAdd: function (p) {
				var ex = items.find(function (it) { return it.product_id === p.id; });
				if (ex) { ex.qty++; } else { items.push({ product_id: p.id, name: p.name, qty: 1, price: p.price, thumb: p.thumb }); }
				renderItems();
				A.toast('যোগ হলো: ' + p.name, 'ok');
			},
			inOrder: function (pid) { return items.reduce(function (n, it) { return n + (it.product_id === pid ? it.qty : 0); }, 0); }
		});
		body.querySelector('[data-save]').addEventListener('click', function () {
			var b = this, cust = { payment: 'cod' };
			body.querySelectorAll('[data-c]').forEach(function (el) { cust[el.getAttribute('data-c')] = el.value.trim(); });
			if (!cust.name || !cust.phone || !cust.address) { A.toast('নাম, ফোন আর ঠিকানা লাগবে।', 'bad'); return; }
			if (!items.length) { A.toast('অন্তত একটা প্রোডাክ্ট যোগ করুন।', 'bad'); return; }
			var sf = body.querySelector('[data-sf]');
			A.busy(b, true);
			A.ajax('pg_leads_confirm', {
				id: id, customer: cust,
				items: items.map(function (it) { return { product_id: it.product_id, qty: it.qty, price: it.price }; }),
				discount: body.querySelector('[data-discount]').value, delivery: body.querySelector('[data-delivery]').value,
				send_sf: sf && sf.checked ? 1 : 0, cod: $cod ? $cod.value : '', sf_note: sf && sf.checked ? body.querySelector('[data-sf-note]').value : ''
			}).then(function (d) {
				A.toast(d.message, 'ok');
				if (d.warning) { A.toast(d.warning, 'bad'); }
				m.close();
				renderTabs(d.counts);
				if (state.tab === 'all') { replace(d.lead); } else { removeCard(id); }
			}).catch(function (err) { A.toast(err.message, 'bad'); A.busy(b, false); });
		});
		renderItems();
	}

	// Fresh list when coming back to the tab.
	var last = Date.now();
	document.addEventListener('visibilitychange', function () {
		if (document.visibilityState === 'visible' && Date.now() - last > 60000 && !state.loading) { last = Date.now(); load(true); }
	});

	load();
})();
