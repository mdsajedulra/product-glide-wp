/* Product Glide — WhatsApp inbox: conversations, replies, templates, quick replies, customer panel. */
(function () {
	'use strict';
	var A = window.PGA, W = window.PG_WA || {};
	var root = document.getElementById('pg-wa-root');
	if (!A || !root) { return; }
	var esc = A.esc, cfg = A.cfg;

	if (!W.ready) {
		root.innerHTML = '<div class="pg-card pg-wa-setup"><h2>Connect your WhatsApp number</h2>' +
			'<p>Open Settings → WhatsApp and use Quick connect. After that every automatic message and every customer reply appears here, and you can answer from this page.</p>' +
			(cfg.isAdmin ? '<a class="pg-btn pg-btn-primary" href="' + esc(W.settingsUrl) + '">Open WhatsApp settings</a>' : '<p class="pg-muted">Ask an Administrator to finish the setup.</p>') + '</div>';
		return;
	}

	var S = {
		filter: 'all', q: '', offset: 0, more: false, loadingMore: false,
		contacts: [], counts: {}, phone: '', lastId: 0, lastDay: '',
		window: 0, maps: [], orders: [], profile: null, quick: [], contact: null, sending: false, unseen: 0
	};

	/* ------------------------------------------------------------------ */
	/* Layout                                                              */
	/* ------------------------------------------------------------------ */

	root.innerHTML =
		'<div class="wa" data-wa>' +
			'<aside class="wa-list">' +
				'<div class="wa-list-head">' +
					'<div class="wa-search"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M15.5 14h-.8l-.3-.3A6.5 6.5 0 1 0 14 15.5l.3.3v.8l5 5 1.5-1.5-5-5zm-6 0a4.5 4.5 0 1 1 0-9 4.5 4.5 0 0 1 0 9z"/></svg>' +
						'<input type="search" data-q placeholder="Search name or phone" aria-label="Search conversations"></div>' +
					'<div class="wa-filters" role="tablist">' +
						['all:All', 'unread:Unread', 'open:Can reply', 'nowa:No WhatsApp'].map(function (x, i) {
							var p = x.split(':');
							return '<button role="tab" class="' + (i ? '' : 'is-on') + '" data-f="' + p[0] + '">' + p[1] + ' <span data-count="' + p[0] + '"></span></button>';
						}).join('') +
					'</div>' +
					(W.hook ? '' : '<div class="wa-warn">Webhook not connected — replies and delivery status will not arrive. ' + (cfg.isAdmin ? '<a href="' + esc(W.settingsUrl) + '">Connect it</a>' : '') + '</div>') +
				'</div>' +
				'<div class="wa-contacts" data-list><div class="wa-empty">Loading…</div></div>' +
			'</aside>' +
			'<section class="wa-chat" data-chat>' +
				'<div class="wa-blank"><div class="wa-blank-ic"><svg viewBox="0 0 24 24"><path d="M12 2a10 10 0 0 0-8.6 15.1L2 22l5-1.3A10 10 0 1 0 12 2zm0 18a8 8 0 0 1-4.1-1.1l-.3-.2-3 .8.8-2.9-.2-.3A8 8 0 1 1 12 20z"/></svg></div>' +
					'<b>Select a conversation</b><span>Automatic order messages and customer replies appear here.<br>Pick one on the left to read and reply.</span></div>' +
			'</section>' +
			'<aside class="wa-side" data-side></aside>' +
		'</div>';

	var $wrap = root.querySelector('[data-wa]'), $list = root.querySelector('[data-list]'), $chat = root.querySelector('[data-chat]'), $side = root.querySelector('[data-side]');

	// Fill the screen below the page header, so only the message list scrolls.
	function fit() {
		var top = $wrap.getBoundingClientRect().top + window.scrollY;
		var h = Math.max(460, window.innerHeight - top - (window.innerWidth <= 782 ? 8 : 20));
		$wrap.style.height = h + 'px';
	}
	fit();
	window.addEventListener('resize', fit);
	setTimeout(fit, 300);

	/* ------------------------------------------------------------------ */
	/* Helpers                                                             */
	/* ------------------------------------------------------------------ */

	var COLORS = ['#0ea5e9', '#8b5cf6', '#f59e0b', '#10b981', '#ef4444', '#ec4899', '#14b8a6', '#6366f1', '#84cc16', '#f97316'];
	function color(s) { var h = 0; String(s).split('').forEach(function (c) { h = (h * 31 + c.charCodeAt(0)) >>> 0; }); return COLORS[h % COLORS.length]; }
	function initials(n) {
		var p = String(n || '?').trim().split(/\s+/);
		return esc(((p[0] || '?')[0] || '?') + (p[1] ? p[1][0] : '')).toUpperCase();
	}
	function avatar(name, phone, nowa) {
		return '<span class="wa-av' + (nowa ? ' is-nowa' : '') + '" style="background:' + (nowa ? '#fee2e2' : color(phone)) + '">' + initials(name || phone) + '</span>';
	}
	function ticks(status, error) {
		if (status === 'nowa' || status === 'failed') { return '<span class="wa-tick is-bad" title="' + esc(error || 'Not delivered') + '">!</span>'; }
		if (status === 'read') { return '<span class="wa-tick is-read" title="Read"><svg viewBox="0 0 16 11"><path d="M11 .6 4.8 7.3 2.1 4.6 1 5.7l3.8 3.8L12.1 1.7zM15 .6 8.8 7.3l-.9-.9-1.1 1.1 2 2L16.1 1.7z"/></svg></span>'; }
		if (status === 'delivered') { return '<span class="wa-tick" title="Delivered"><svg viewBox="0 0 16 11"><path d="M11 .6 4.8 7.3 2.1 4.6 1 5.7l3.8 3.8L12.1 1.7zM15 .6 8.8 7.3l-.9-.9-1.1 1.1 2 2L16.1 1.7z"/></svg></span>'; }
		return '<span class="wa-tick" title="Sent"><svg viewBox="0 0 16 11"><path d="M11 .6 4.8 7.3 2.1 4.6 1 5.7l3.8 3.8L12.1 1.7z"/></svg></span>';
	}
	function linkify(t) {
		return esc(t).replace(/(https?:\/\/[^\s<]+)/g, '<a href="$1" target="_blank" rel="noopener">$1</a>').replace(/\*([^*\n]+)\*/g, '<b>$1</b>').replace(/\n/g, '<br>');
	}
	function hoursLeft(s) {
		var h = Math.floor(s / 3600), m = Math.floor((s % 3600) / 60);
		return h ? h + 'h ' + m + 'm' : m + 'm';
	}
	function orderUrl(n) { return cfg.ordersUrl + (cfg.ordersUrl.indexOf('?') > -1 ? '&' : '?') + 'q=' + encodeURIComponent(n); }
	var baseTitle = document.title.replace(/^\(\d+\)\s*/, '');
	function setTitle(n) { document.title = (n ? '(' + n + ') ' : '') + baseTitle; }

	/* ------------------------------------------------------------------ */
	/* Conversation list                                                   */
	/* ------------------------------------------------------------------ */

	function itemHtml(c) {
		return '<button class="wa-item' + (c.phone === S.phone ? ' is-active' : '') + (c.unread ? ' is-unread' : '') + '" data-phone="' + esc(c.phone) + '">' +
			avatar(c.name, c.phone, c.has_wa === 0) +
			'<span class="wa-item-main">' +
				'<span class="wa-row"><b class="wa-name">' + esc(c.name || c.phone) + '</b><time>' + esc(c.when) + '</time></span>' +
				'<span class="wa-row"><span class="wa-prev">' + (c.dir === 'out' ? '<span class="wa-you">You: </span>' : '') + esc(c.preview || '') + '</span>' +
					(c.unread ? '<span class="wa-count">' + c.unread + '</span>' : '') + '</span>' +
				((c.onum || c.has_wa === 0 || c.open) ? '<span class="wa-row wa-tags">' +
					(c.onum ? '<span class="wa-otag st-' + esc(c.oslug) + '">#' + esc(c.onum) + ' · ' + esc(c.ostatus) + '</span>' : '') +
					(c.has_wa === 0 ? '<span class="wa-tag is-bad">No WhatsApp</span>' : '') +
					(c.open ? '<span class="wa-tag is-open" title="Customer messaged in the last 24 hours — you can reply freely">Can reply</span>' : '') +
				'</span>' : '') +
			'</span></button>';
	}
	function paintList(append, fresh) {
		if (!S.contacts.length) {
			$list.innerHTML = '<div class="wa-empty">' + (S.q || S.filter !== 'all' ? 'No conversations match.' : 'No messages yet. When an order comes in, the automatic message and the customer\'s replies appear here.') + '</div>';
			return;
		}
		var html = (append ? fresh : S.contacts).map(itemHtml).join('') + (S.more ? '<div class="wa-more" data-more>Loading more…</div>' : '');
		if (append) {
			var m = $list.querySelector('[data-more]');
			if (m) { m.remove(); }
			$list.insertAdjacentHTML('beforeend', html);
		} else {
			$list.innerHTML = html;
		}
	}
	function paintCounts() {
		Object.keys(S.counts).forEach(function (k) {
			var el = root.querySelector('[data-count="' + k + '"]');
			if (el) { el.textContent = S.counts[k] ? S.counts[k] : ''; el.className = (k === 'unread' && S.counts[k]) ? 'is-hot' : ''; }
		});
	}
	function loadList(keepScroll) {
		var top = $list.scrollTop, n = Math.max(40, S.contacts.length);
		return A.ajax('pg_wa_inbox', { q: S.q, filter: S.filter, offset: 0 }).then(function (d) {
			S.contacts = d.contacts || []; S.more = !!d.more; S.counts = d.counts || {}; S.offset = S.contacts.length;
			// Keep pages the user already scrolled to.
			if (keepScroll && n > 40 && S.more) { return loadMore(true).then(function () { $list.scrollTop = top; }); }
			paintList(); paintCounts(); setTitle(d.unread);
			if (keepScroll) { $list.scrollTop = top; }
		}).catch(function (e) { $list.innerHTML = '<div class="wa-empty">' + esc(e.message) + '</div>'; });
	}
	function loadMore(silent) {
		if (S.loadingMore || !S.more) { return Promise.resolve(); }
		S.loadingMore = true;
		return A.ajax('pg_wa_inbox', { q: S.q, filter: S.filter, offset: S.offset }).then(function (d) {
			var fresh = (d.contacts || []).filter(function (c) { return !S.contacts.some(function (x) { return x.phone === c.phone; }); });
			S.contacts = S.contacts.concat(fresh); S.more = !!d.more; S.offset += (d.contacts || []).length;
			if (silent) { paintList(); } else { paintList(true, fresh); }
			paintCounts();
		}).finally(function () { S.loadingMore = false; });
	}
	$list.addEventListener('scroll', function () {
		if ($list.scrollTop + $list.clientHeight > $list.scrollHeight - 160) { loadMore(); }
	});
	root.querySelector('[data-q]').addEventListener('input', A.debounce(function (e) { S.q = e.target.value.trim(); $list.scrollTop = 0; loadList(); }, 300));
	root.querySelector('.wa-filters').addEventListener('click', function (e) {
		var b = e.target.closest('[data-f]');
		if (!b) { return; }
		root.querySelectorAll('.wa-filters button').forEach(function (x) { x.classList.toggle('is-on', x === b); });
		S.filter = b.getAttribute('data-f'); $list.scrollTop = 0;
		loadList();
	});
	$list.addEventListener('click', function (e) {
		var b = e.target.closest('[data-phone]');
		if (b) { openChat(b.getAttribute('data-phone')); }
	});

	/* ------------------------------------------------------------------ */
	/* Thread                                                              */
	/* ------------------------------------------------------------------ */

	function mediaHtml(m) {
		if (!m.media) { return ''; }
		var src = W.mediaUrl + '&id=' + m.id;
		if (m.type === 'image' || m.type === 'sticker') { return '<a href="' + esc(src) + '" target="_blank" rel="noopener" class="wa-img' + (m.type === 'sticker' ? ' is-sticker' : '') + '"><img loading="lazy" src="' + esc(src) + '" alt="Photo from customer"></a>'; }
		if (m.type === 'audio') { return '<audio controls preload="none" src="' + esc(src) + '"></audio>'; }
		if (m.type === 'video') { return '<video controls preload="none" src="' + esc(src) + '"></video>'; }
		return '<a class="wa-doc" href="' + esc(src) + '" target="_blank" rel="noopener"><span>📄</span>' + esc(m.filename || 'Document') + '</a>';
	}
	function msgHtml(m) {
		var bad = m.status === 'nowa' || m.status === 'failed';
		if (m.type === 'reaction') {
			return '<div class="wa-msg is-in is-reaction" data-mid="' + m.id + '"><span>' + esc(m.body) + '</span></div>';
		}
		return '<div class="wa-msg is-' + m.dir + (m.template ? ' is-tpl' : '') + (bad ? ' is-failed' : '') + '" data-mid="' + m.id + '" data-st="' + esc(m.status) + '">' +
			'<div class="wa-bub">' +
				(m.template ? '<div class="wa-tpl-tag">' + (m.by && m.by !== 'Auto' ? esc(m.by) : 'Automatic') + ' · template</div>' : (m.dir === 'out' && m.by ? '<div class="wa-by">' + esc(m.by) + '</div>' : '')) +
				mediaHtml(m) +
				(m.body ? '<div class="wa-text">' + linkify(m.body) + '</div>' : '') +
				'<div class="wa-meta"><time>' + esc(m.time) + '</time>' + (m.dir === 'out' ? ticks(m.status, m.error) : '') + '</div>' +
			'</div>' +
			(bad ? '<div class="wa-err">' + esc(m.error || 'Not delivered') + '</div>' : '') +
		'</div>';
	}
	function appendMessages(list, $box, unreadFrom) {
		var html = '';
		list.forEach(function (m) {
			if (m.day !== S.lastDay) { html += '<div class="wa-day"><span>' + esc(m.day) + '</span></div>'; S.lastDay = m.day; }
			if (unreadFrom && m.id === unreadFrom) { html += '<div class="wa-unread-line" data-unread-line><span>Unread messages</span></div>'; }
			html += msgHtml(m);
			S.lastId = Math.max(S.lastId, m.id);
		});
		$box.insertAdjacentHTML('beforeend', html);
	}

	function windowPill() {
		return S.window > 0
			? '<span class="wa-win is-open" title="The customer messaged in the last 24 hours, so you can write anything.">Reply open · ' + hoursLeft(S.window) + ' left</span>'
			: '<span class="wa-win" title="No message from the customer in the last 24 hours — WhatsApp only allows approved templates.">Template only</span>';
	}

	function composerHtml() {
		if (S.window > 0) {
			return '<div class="wa-quick" data-quickpop hidden>' + (S.quick.length ? S.quick.map(function (q, i) { return '<button type="button" data-q-i="' + i + '">' + esc(q) + '</button>'; }).join('') : '<div class="wa-empty">Add quick replies in Settings → WhatsApp.</div>') + '</div>' +
				'<div class="wa-compose">' +
					'<button type="button" class="wa-icon-btn" data-quick title="Quick replies" aria-label="Quick replies"><svg viewBox="0 0 24 24"><path d="M7 2v11h3v9l7-12h-4l4-8z"/></svg></button>' +
					'<button type="button" class="wa-icon-btn" data-tplbtn title="Send a template" aria-label="Send a template"><svg viewBox="0 0 24 24"><path d="M4 4h16v12H5.2L4 17.2V4zm0-2a2 2 0 0 0-2 2v18l4-4h14a2 2 0 0 0 2-2V4a2 2 0 0 0-2-2H4zm2 4h12v2H6zm0 3h12v2H6zm0 3h8v2H6z"/></svg></button>' +
					'<textarea data-text rows="1" placeholder="Type a message" title="Enter to send · Shift+Enter for a new line"></textarea>' +
					'<button class="wa-send" data-send aria-label="Send"><svg viewBox="0 0 24 24"><path d="M2 21 23 12 2 3v7l15 2-15 2z"/></svg></button>' +
				'</div>';
		}
		var opts = S.maps.map(function (m) { return '<option value="' + m.id + '">' + esc(m.name) + '</option>'; }).join('');
		return '<div class="wa-closed">The customer has not written in the last 24 hours, so WhatsApp only allows an approved template. When they reply, normal chat opens for 24 hours.</div>' +
			(S.maps.length ? '<div class="wa-compose"><select data-tpl>' + opts + '</select><button class="pg-btn pg-btn-primary" data-send-tpl' + (S.orders.length ? '' : ' disabled title="No order for this customer"') + '>Send template</button></div>' : '');
	}
	function paintComposer() {
		var c = $chat.querySelector('[data-composer]');
		if (!c) { return; }
		c.innerHTML = composerHtml();
		var w = $chat.querySelector('[data-win]');
		if (w) { w.innerHTML = windowPill(); }
		bindComposer();
	}

	function sideHtml() {
		var c = S.contact || {}, p = S.profile || {};
		var score = p.score == null ? '' : '<div class="wa-score ' + (p.score >= 80 ? 'is-good' : p.score >= 50 ? 'is-mid' : 'is-bad') + '"><span>Delivery score</span><b>' + p.score + '%</b></div>';
		return '<div class="wa-side-head"><button class="wa-icon-btn wa-side-x" data-side-close aria-label="Close">×</button>' +
				avatar(c.name, S.phone, c.has_wa === 0) + '<b>' + esc(c.name || S.phone) + '</b><a href="tel:' + esc(S.phone) + '">' + esc(S.phone) + '</a>' +
				(c.has_wa === 0 ? '<span class="wa-tag is-bad">No WhatsApp on this number</span>' : '') + '</div>' +
			'<div class="wa-stats"><div><b>' + (p.orders || 0) + '</b><span>Orders</span></div><div><b>' + (p.delivered || 0) + '</b><span>Delivered</span></div><div><b>' + (p.cancelled || 0) + '</b><span>Cancelled</span></div></div>' +
			score +
			(p.spent ? '<div class="wa-spent">Bought for <b>' + A.money(p.spent) + '</b></div>' : '') +
			'<h4>Orders</h4>' +
			(S.orders.length ? S.orders.map(function (o) {
				return '<a class="wa-order" href="' + esc(orderUrl(o.number)) + '"><span class="wa-row"><b>#' + esc(o.number) + '</b><span class="pg-status pg-st-' + esc(o.slug) + '">' + esc(o.status) + '</span></span>' +
					'<span class="wa-order-items">' + esc(o.items) + '</span><span class="wa-row wa-muted"><span>' + esc(o.date) + '</span><b>' + A.money(o.total) + '</b></span></a>';
			}).join('') : '<div class="wa-muted">No orders with this number.</div>') +
			'<div class="wa-side-actions"><a class="pg-btn" href="tel:' + esc(S.phone) + '">📞 Call</a>' + (S.orders.length ? '<a class="pg-btn" href="' + esc(orderUrl(S.orders[0].number)) + '">Open latest order</a>' : '') + '</div>';
	}

	function openChat(phone) {
		S.phone = phone; S.lastId = 0; S.lastDay = ''; S.unseen = 0;
		$wrap.classList.add('is-chat');
		$list.querySelectorAll('.wa-item').forEach(function (x) { x.classList.toggle('is-active', x.getAttribute('data-phone') === phone); });
		try { var u = new URL(location.href); u.searchParams.set('phone', phone); history.replaceState(null, '', u.toString()); } catch (e) {}
		$chat.innerHTML = '<div class="wa-blank"><span class="wa-spin"></span></div>';
		var before = S.contacts.filter(function (x) { return x.phone === phone; })[0];
		var unreadN = before ? before.unread : 0;
		A.ajax('pg_wa_thread', { phone: phone }).then(function (d) {
			if (S.phone !== phone) { return; }
			S.window = d.window; S.maps = d.maps || []; S.orders = d.orders || []; S.contact = d.contact || {}; S.profile = d.profile || {}; S.quick = d.quick || [];
			var c = S.contact;
			$chat.innerHTML =
				'<header class="wa-head">' +
					'<button class="wa-icon-btn wa-back" data-back aria-label="Back to conversations"><svg viewBox="0 0 24 24"><path d="M15.4 7.4 14 6l-6 6 6 6 1.4-1.4L10.8 12z"/></svg></button>' +
					avatar(c.name, phone, c.has_wa === 0) +
					'<button class="wa-head-main" data-side-open title="Customer details"><b>' + esc(c.name || phone) + '</b><span>' + esc(phone) + (S.orders[0] ? ' · #' + esc(S.orders[0].number) + ' ' + esc(S.orders[0].status) : '') + '</span></button>' +
					'<span data-win>' + windowPill() + '</span>' +
					'<a class="wa-icon-btn" href="tel:' + esc(phone) + '" title="Call" aria-label="Call"><svg viewBox="0 0 24 24"><path d="M6.6 10.8a15.2 15.2 0 0 0 6.6 6.6l2.2-2.2c.3-.3.7-.4 1-.2 1.1.4 2.3.6 3.6.6.6 0 1 .4 1 1V20c0 .6-.4 1-1 1A17 17 0 0 1 3 4c0-.6.4-1 1-1h3.5c.6 0 1 .4 1 1 0 1.3.2 2.5.6 3.6.1.3 0 .7-.2 1z"/></svg></a>' +
					'<button class="wa-icon-btn" data-unread title="Mark as unread" aria-label="Mark as unread"><svg viewBox="0 0 24 24"><path d="M20 4H4a2 2 0 0 0-2 2v12c0 1.1.9 2 2 2h16a2 2 0 0 0 2-2V6a2 2 0 0 0-2-2zm0 4-8 5-8-5V6l8 5 8-5z"/></svg></button>' +
					'<button class="wa-icon-btn wa-info-btn" data-side-open title="Customer details" aria-label="Customer details"><svg viewBox="0 0 24 24"><path d="M11 7h2v2h-2zm0 4h2v6h-2zm1-9a10 10 0 1 0 0 20 10 10 0 0 0 0-20zm0 18a8 8 0 1 1 0-16 8 8 0 0 1 0 16z"/></svg></button>' +
				'</header>' +
				'<div class="wa-body"><div class="wa-msgs" data-msgs></div>' +
					'<button class="wa-jump" data-jump hidden aria-label="Scroll to the newest message"><svg viewBox="0 0 24 24"><path d="M7.4 8.6 12 13.2l4.6-4.6L18 10l-6 6-6-6z"/></svg><span data-jump-n></span></button></div>' +
				'<div class="wa-composer" data-composer></div>';
			$side.innerHTML = sideHtml();
			var $msgs = $chat.querySelector('[data-msgs]');
			if (!d.messages.length) { $msgs.innerHTML = '<div class="wa-empty">No messages with this customer yet.</div>'; }
			// Mark where the unread messages start.
			var ins = d.messages.filter(function (m) { return m.dir === 'in'; }), firstUnread = unreadN && ins.length >= unreadN ? ins[ins.length - unreadN].id : 0;
			appendMessages(d.messages, $msgs, firstUnread);
			var line = $msgs.querySelector('[data-unread-line]');
			$msgs.scrollTop = line ? Math.max(0, line.offsetTop - 60) : $msgs.scrollHeight;
			$msgs.addEventListener('scroll', onMsgScroll);
			paintComposer();
			bindHead();
			setTitle(d.unread);
			S.contacts.forEach(function (x) { if (x.phone === phone) { x.unread = 0; } });
			var it = $list.querySelector('.wa-item[data-phone="' + phone + '"]');
			if (it) { it.classList.remove('is-unread'); var cn = it.querySelector('.wa-count'); if (cn) { cn.remove(); } }
			if (S.counts.unread && unreadN) { S.counts.unread = Math.max(0, S.counts.unread - 1); paintCounts(); }
			var t = $chat.querySelector('[data-text]');
			if (t && window.innerWidth > 900) { t.focus(); }
		}).catch(function (e) { $chat.innerHTML = '<div class="wa-blank"><span>' + esc(e.message) + '</span></div>'; });
	}

	function nearBottom($m) { return $m.scrollHeight - $m.scrollTop - $m.clientHeight < 140; }
	function onMsgScroll(e) {
		var $m = e.currentTarget, j = $chat.querySelector('[data-jump]');
		if (!j) { return; }
		var nb = nearBottom($m);
		j.hidden = nb;
		if (nb) { S.unseen = 0; $chat.querySelector('[data-jump-n]').textContent = ''; }
	}

	function bindHead() {
		$chat.querySelector('[data-back]').addEventListener('click', function () { $wrap.classList.remove('is-chat', 'is-side'); S.phone = ''; loadList(true); });
		$chat.querySelector('[data-unread]').addEventListener('click', function () {
			A.ajax('pg_wa_mark', { phone: S.phone, unread: 1 }).then(function (d) { A.toast('Marked as unread', 'ok'); setTitle(d.unread); $wrap.classList.remove('is-chat', 'is-side'); S.phone = ''; loadList(true); });
		});
		$chat.querySelectorAll('[data-side-open]').forEach(function (b) { b.addEventListener('click', function () { $wrap.classList.toggle('is-side'); }); });
		$side.querySelector('[data-side-close]').addEventListener('click', function () { $wrap.classList.remove('is-side'); });
		$chat.querySelector('[data-jump]').addEventListener('click', function () { var $m = $chat.querySelector('[data-msgs]'); $m.scrollTo({ top: $m.scrollHeight, behavior: 'smooth' }); });
	}

	function sendTemplate(mapId, btn) {
		A.busy(btn, true);
		A.ajax('pg_wa_send_tpl', { phone: S.phone, map_id: mapId }).then(function () { A.toast('Template sent', 'ok'); poll(); })
			.catch(function (e) { A.toast(e.message, 'bad'); }).finally(function () { A.busy(btn, false); });
	}

	function bindComposer() {
		var t = $chat.querySelector('[data-text]'), b = $chat.querySelector('[data-send]');
		if (t) {
			var grow = function () { t.style.height = 'auto'; t.style.height = Math.min(150, t.scrollHeight) + 'px'; };
			t.addEventListener('input', grow);
			t.addEventListener('keydown', function (e) {
				if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) { e.preventDefault(); send(); }
				if (e.key === 'Escape') { var p = $chat.querySelector('[data-quickpop]'); if (p) { p.hidden = true; } }
			});
			b.addEventListener('click', send);
			var pop = $chat.querySelector('[data-quickpop]');
			$chat.querySelector('[data-quick]').addEventListener('click', function () { pop.hidden = !pop.hidden; });
			pop.addEventListener('click', function (e) {
				var q = e.target.closest('[data-q-i]');
				if (!q) { return; }
				t.value = (t.value ? t.value.replace(/\s*$/, ' ') : '') + S.quick[+q.getAttribute('data-q-i')];
				pop.hidden = true; grow(); t.focus();
			});
			$chat.querySelector('[data-tplbtn]').addEventListener('click', function () {
				if (!S.maps.length) { A.toast('No template set up yet.', 'bad'); return; }
				var m = A.modal('Send a template', '<div class="pg-wa-pick">' + S.maps.map(function (x) { return '<button type="button" class="pg-wa-pick-btn" data-map="' + x.id + '"><b>' + esc(x.name) + '</b><span>' + esc(x.trigger) + '</span></button>'; }).join('') + '</div>', { center: true });
				m.body.addEventListener('click', function (e) { var x = e.target.closest('[data-map]'); if (x) { sendTemplate(x.getAttribute('data-map'), x); m.close(); } });
			});
		}
		var tb = $chat.querySelector('[data-send-tpl]');
		if (tb) { tb.addEventListener('click', function () { sendTemplate($chat.querySelector('[data-tpl]').value, tb); }); }
	}

	function send() {
		var t = $chat.querySelector('[data-text]'), b = $chat.querySelector('[data-send]');
		var text = t.value.trim();
		if (!text || S.sending) { return; }
		S.sending = true;
		A.busy(b, true);
		A.ajax('pg_wa_reply', { phone: S.phone, text: text }).then(function () {
			t.value = ''; t.style.height = 'auto';
			poll();
		}).catch(function (e) {
			A.toast(e.message, 'bad');
			if (e.data && e.data.closed) { S.window = 0; paintComposer(); }
		}).finally(function () { S.sending = false; A.busy(b, false); var tt = $chat.querySelector('[data-text]'); if (tt) { tt.focus(); } });
	}

	/* ------------------------------------------------------------------ */
	/* Live updates                                                        */
	/* ------------------------------------------------------------------ */

	var polling = false;
	function poll() {
		if (polling) { return; }
		polling = true;
		var jobs = [loadList(true)];
		if (S.phone && S.lastId >= 0) {
			var phone = S.phone;
			jobs.push(A.ajax('pg_wa_thread', { phone: phone, after: S.lastId || 0 }).then(function (d) {
				if (S.phone !== phone) { return; }
				var $msgs = $chat.querySelector('[data-msgs]');
				if (!$msgs) { return; }
				var nb = nearBottom($msgs), fresh = S.lastId ? d.messages : [];
				if (fresh.length) {
					var empty = $msgs.querySelector('.wa-empty');
					if (empty) { empty.remove(); }
					appendMessages(fresh, $msgs);
					var mine = fresh.some(function (m) { return m.dir === 'out'; });
					if (nb || mine) { $msgs.scrollTop = $msgs.scrollHeight; }
					else {
						S.unseen += fresh.filter(function (m) { return m.dir === 'in'; }).length;
						var j = $chat.querySelector('[data-jump]'); j.hidden = false;
						$chat.querySelector('[data-jump-n]').textContent = S.unseen ? S.unseen : '';
					}
					if (fresh.some(function (m) { return m.dir === 'in'; }) && document.hidden) { beep(); }
				}
				(d.states || []).forEach(function (s) {
					var el = $msgs.querySelector('[data-mid="' + s.id + '"]');
					if (!el || el.getAttribute('data-st') === s.status) { return; }
					el.setAttribute('data-st', s.status);
					var tick = el.querySelector('.wa-tick');
					if (tick) { tick.outerHTML = ticks(s.status, s.error); }
					if ((s.status === 'nowa' || s.status === 'failed') && !el.querySelector('.wa-err')) {
						el.classList.add('is-failed');
						el.insertAdjacentHTML('beforeend', '<div class="wa-err">' + esc(s.error || 'Not delivered') + '</div>');
					}
				});
				var wasOpen = S.window > 0;
				S.window = d.window;
				if (wasOpen !== (d.window > 0)) { paintComposer(); }
				else { var w = $chat.querySelector('[data-win]'); if (w) { w.innerHTML = windowPill(); } }
			}).catch(function () {}));
		}
		Promise.all(jobs).finally(function () { polling = false; });
	}
	function beep() {
		try {
			var C = window.AudioContext || window.webkitAudioContext, ctx = new C(), o = ctx.createOscillator(), g = ctx.createGain();
			o.frequency.value = 660; o.connect(g); g.connect(ctx.destination);
			g.gain.setValueAtTime(0.0001, ctx.currentTime); g.gain.exponentialRampToValueAtTime(0.2, ctx.currentTime + 0.02); g.gain.exponentialRampToValueAtTime(0.0001, ctx.currentTime + 0.25);
			o.start(); o.stop(ctx.currentTime + 0.3);
		} catch (e) {}
	}

	loadList().then(function () {
		var p = new URLSearchParams(location.search).get('phone');
		if (p) { openChat(p.replace(/[^\d+]/g, '').replace(/^\+?880/, '0').replace(/^88(01)/, '$1')); }
	});
	setInterval(function () { if (!document.hidden) { poll(); } }, 8000);
	document.addEventListener('visibilitychange', function () { if (!document.hidden) { poll(); } });
})();
