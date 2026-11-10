/* Product Glide — WhatsApp inbox: conversations, replies, templates, customer photos. */
(function () {
	'use strict';
	var A = window.PGA, W = window.PG_WA || {};
	var root = document.getElementById('pg-wa-root');
	if (!A || !root) { return; }
	var esc = A.esc, cfg = A.cfg;

	if (!W.ready) {
		root.innerHTML = '<div class="pg-card pg-wa-setup"><h2>Connect your WhatsApp number</h2>' +
			'<p>Enter the Phone Number ID, WABA ID and access token in Settings → WhatsApp, then connect the webhook shown there. After that every automatic message and every customer reply appears here, and you can answer from this page.</p>' +
			(cfg.isAdmin ? '<a class="pg-btn pg-btn-primary" href="' + esc(W.settingsUrl) + '">Open WhatsApp settings</a>' : '<p class="pg-muted">Ask an Administrator to finish the setup.</p>') + '</div>';
		return;
	}

	var state = { filter: 'all', q: '', phone: '', lastId: 0, contacts: [], window: 0, maps: [], orders: [], contact: null, sending: false };

	root.innerHTML =
		'<div class="pg-wa" data-wa>' +
			'<aside class="pg-wa-list">' +
				'<div class="pg-wa-search"><input type="search" data-q placeholder="Search name or phone…" aria-label="Search conversations"></div>' +
				'<div class="pg-wa-filters" role="tablist">' +
					'<button class="is-active" data-f="all">All</button><button data-f="unread">Unread</button><button data-f="open">Can reply</button><button data-f="nowa">No WhatsApp</button>' +
				'</div>' +
				(W.hook ? '' : '<div class="pg-wa-warn">Webhook not connected — customer replies and delivery status will not arrive. ' + (cfg.isAdmin ? '<a href="' + esc(W.settingsUrl) + '">Connect it</a>' : '') + '</div>') +
				'<div class="pg-wa-contacts" data-list><div class="pg-wa-empty">Loading…</div></div>' +
			'</aside>' +
			'<section class="pg-wa-chat" data-chat>' +
				'<div class="pg-wa-blank"><div class="pg-wa-blank-ic">💬</div><b>Select a conversation</b><span>Automatic order messages and customer replies show up here.</span></div>' +
			'</section>' +
		'</div>';

	var $wrap = root.querySelector('[data-wa]'), $list = root.querySelector('[data-list]'), $chat = root.querySelector('[data-chat]');

	/* ---------- Conversation list ---------- */

	function initials(n) {
		var p = String(n || '').trim().split(/\s+/);
		return esc(((p[0] || '?')[0] || '?') + (p[1] ? p[1][0] : '')).toUpperCase();
	}
	function paintList() {
		if (!state.contacts.length) {
			$list.innerHTML = '<div class="pg-wa-empty">' + (state.q || state.filter !== 'all' ? 'No conversations match.' : 'No messages yet. When an order comes in, the automatic message and the customer\'s replies appear here.') + '</div>';
			return;
		}
		$list.innerHTML = state.contacts.map(function (c) {
			return '<button class="pg-wa-item' + (c.phone === state.phone ? ' is-active' : '') + (c.unread ? ' is-unread' : '') + '" data-phone="' + esc(c.phone) + '">' +
				'<span class="pg-wa-av' + (c.has_wa === 0 ? ' is-nowa' : '') + '">' + initials(c.name || c.phone) + '</span>' +
				'<span class="pg-wa-item-main">' +
					'<span class="pg-wa-item-top"><b>' + esc(c.name || c.phone) + '</b><time>' + esc(c.when) + '</time></span>' +
					'<span class="pg-wa-item-bot"><span class="pg-wa-prev">' + (c.dir === 'out' ? '<span class="pg-wa-you">You: </span>' : '') + esc(c.preview || '') + '</span>' +
						(c.has_wa === 0 ? '<span class="pg-wa-tag is-nowa">No WA</span>' : '') +
						(c.unread ? '<span class="pg-wa-count">' + c.unread + '</span>' : '') + '</span>' +
				'</span></button>';
		}).join('');
	}
	function loadList() {
		return A.ajax('pg_wa_inbox', { q: state.q, filter: state.filter }).then(function (d) {
			state.contacts = d.contacts || [];
			paintList();
			setTitle(d.unread);
		}).catch(function (e) { $list.innerHTML = '<div class="pg-wa-empty">' + esc(e.message) + '</div>'; });
	}
	var baseTitle = document.title.replace(/^\(\d+\)\s*/, '');
	function setTitle(n) { document.title = (n ? '(' + n + ') ' : '') + baseTitle; }

	root.querySelector('[data-q]').addEventListener('input', A.debounce(function (e) { state.q = e.target.value.trim(); loadList(); }, 350));
	root.querySelector('.pg-wa-filters').addEventListener('click', function (e) {
		var b = e.target.closest('[data-f]');
		if (!b) { return; }
		root.querySelectorAll('.pg-wa-filters button').forEach(function (x) { x.classList.toggle('is-active', x === b); });
		state.filter = b.getAttribute('data-f');
		loadList();
	});
	$list.addEventListener('click', function (e) {
		var b = e.target.closest('[data-phone]');
		if (b) { openChat(b.getAttribute('data-phone')); }
	});

	/* ---------- Thread ---------- */

	function ticks(m) {
		if (m.dir !== 'out') { return ''; }
		if (m.status === 'nowa' || m.status === 'failed') { return '<span class="pg-wa-tick is-bad" title="' + esc(m.error) + '">⚠</span>'; }
		if (m.status === 'read') { return '<span class="pg-wa-tick is-read" title="Read">✓✓</span>'; }
		if (m.status === 'delivered') { return '<span class="pg-wa-tick" title="Delivered">✓✓</span>'; }
		return '<span class="pg-wa-tick" title="Sent">✓</span>';
	}
	function linkify(t) {
		return esc(t).replace(/(https?:\/\/[^\s<]+)/g, '<a href="$1" target="_blank" rel="noopener">$1</a>').replace(/\n/g, '<br>');
	}
	function mediaHtml(m) {
		if (!m.media) { return ''; }
		var src = W.mediaUrl + '&id=' + m.id;
		if (m.type === 'image' || m.type === 'sticker') { return '<a href="' + esc(src) + '" target="_blank" rel="noopener" class="pg-wa-img' + (m.type === 'sticker' ? ' is-sticker' : '') + '"><img loading="lazy" src="' + esc(src) + '" alt="Photo from customer"></a>'; }
		if (m.type === 'audio') { return '<audio controls preload="none" src="' + esc(src) + '"></audio>'; }
		if (m.type === 'video') { return '<video controls preload="none" src="' + esc(src) + '"></video>'; }
		return '<a class="pg-wa-doc" href="' + esc(src) + '" target="_blank" rel="noopener">📄 ' + esc(m.filename || 'Document') + '</a>';
	}
	function msgHtml(m) {
		var bad = m.status === 'nowa' || m.status === 'failed';
		return '<div class="pg-wa-msg is-' + m.dir + (m.template ? ' is-tpl' : '') + (bad ? ' is-failed' : '') + '" data-mid="' + m.id + '">' +
			'<div class="pg-wa-bub">' +
				(m.template ? '<div class="pg-wa-tpl">🤖 ' + (m.by && m.by !== 'Auto' ? esc(m.by) + ' · ' : 'Automatic · ') + esc(m.template) + '</div>' : (m.dir === 'out' && m.by ? '<div class="pg-wa-by">' + esc(m.by) + '</div>' : '')) +
				mediaHtml(m) +
				(m.body ? '<div class="pg-wa-text">' + linkify(m.body) + '</div>' : '') +
				'<div class="pg-wa-meta"><time>' + esc(m.time) + '</time>' + ticks(m) + '</div>' +
			'</div>' +
			(bad ? '<div class="pg-wa-err">' + esc(m.error || 'Not delivered') + '</div>' : '') +
		'</div>';
	}
	var lastDay = '';
	function appendMessages(list, $box) {
		var html = '';
		list.forEach(function (m) {
			if (m.day !== lastDay) { html += '<div class="pg-wa-day"><span>' + esc(m.day) + '</span></div>'; lastDay = m.day; }
			html += msgHtml(m);
			state.lastId = Math.max(state.lastId, m.id);
		});
		$box.insertAdjacentHTML('beforeend', html);
	}
	function hoursLeft(s) {
		var h = Math.floor(s / 3600), m = Math.floor((s % 3600) / 60);
		return h ? h + ' h ' + m + ' min' : m + ' min';
	}

	function composerHtml() {
		if (state.window > 0) {
			return '<div class="pg-wa-window is-open">Reply window open · ' + hoursLeft(state.window) + ' left</div>' +
				'<div class="pg-wa-compose"><textarea data-text rows="1" placeholder="Type a message…" title="Enter to send · Shift+Enter for a new line"></textarea>' +
				'<button class="pg-btn pg-btn-primary pg-wa-send" data-send aria-label="Send">Send ➤</button></div>';
		}
		var opts = state.maps.map(function (m) { return '<option value="' + m.id + '">' + esc(m.name) + (m.trigger === 'manual' ? '' : ' (' + esc(m.trigger) + ')') + '</option>'; }).join('');
		return '<div class="pg-wa-window">The customer has not messaged in the last 24 hours, so WhatsApp only allows an approved template. When they reply, normal chat opens for 24 hours.</div>' +
			(state.maps.length ? '<div class="pg-wa-compose"><select data-tpl>' + opts + '</select><button class="pg-btn pg-btn-primary" data-send-tpl' + (state.orders.length ? '' : ' disabled title="No order for this customer"') + '>Send template</button></div>' :
				'<div class="pg-wa-compose pg-muted">Add a template in Settings → WhatsApp to message this customer.</div>');
	}
	function paintComposer() {
		var c = $chat.querySelector('[data-composer]');
		if (c) { c.innerHTML = composerHtml(); bindComposer(); }
	}
	function ordersHtml() {
		if (!state.orders.length) { return '<div class="pg-muted">No orders with this number.</div>'; }
		return state.orders.map(function (o) {
			var url = cfg.ordersUrl + (cfg.ordersUrl.indexOf('?') > -1 ? '&' : '?') + 'q=' + encodeURIComponent(o.number);
			return '<a class="pg-wa-order" href="' + esc(url) + '"><span><b>#' + esc(o.number) + '</b> <span class="pg-status pg-st-' + esc(o.slug) + '">' + esc(o.status) + '</span></span>' +
				'<span class="pg-muted">' + esc(o.date) + ' · ' + A.money(o.total) + '</span><span class="pg-wa-order-items">' + esc(o.items) + '</span></a>';
		}).join('');
	}

	function openChat(phone) {
		state.phone = phone; state.lastId = 0; lastDay = '';
		$wrap.classList.add('is-chat');
		paintList();
		try { var u = new URL(location.href); u.searchParams.set('phone', phone); history.replaceState(null, '', u.toString()); } catch (e) {}
		$chat.innerHTML = '<div class="pg-wa-blank"><span>Loading…</span></div>';
		A.ajax('pg_wa_thread', { phone: phone }).then(function (d) {
			if (state.phone !== phone) { return; }
			state.window = d.window; state.maps = d.maps || []; state.orders = d.orders || []; state.contact = d.contact;
			var c = d.contact || {};
			$chat.innerHTML =
				'<header class="pg-wa-head">' +
					'<button class="pg-wa-back" data-back aria-label="Back to conversations">‹</button>' +
					'<span class="pg-wa-av' + (c.has_wa === 0 ? ' is-nowa' : '') + '">' + initials(c.name || phone) + '</span>' +
					'<div class="pg-wa-head-main"><b>' + esc(c.name || phone) + '</b><span><a href="tel:' + esc(phone) + '">' + esc(phone) + '</a>' +
						(c.has_wa === 0 ? ' · <span class="pg-wa-tag is-nowa">No WhatsApp on this number</span>' : '') + '</span></div>' +
					'<a class="pg-btn pg-btn-sm" href="tel:' + esc(phone) + '">📞 Call</a>' +
					'<button class="pg-btn pg-btn-sm pg-btn-ghost" data-unread title="Mark as unread">Unread</button>' +
					'<button class="pg-btn pg-btn-sm pg-btn-ghost pg-wa-side-btn" data-side aria-expanded="false">Orders</button>' +
				'</header>' +
				'<div class="pg-wa-body"><div class="pg-wa-msgs" data-msgs></div><aside class="pg-wa-side" data-sidebar><h3>Orders</h3>' + ordersHtml() + '</aside></div>' +
				'<div class="pg-wa-composer" data-composer></div>';
			var $msgs = $chat.querySelector('[data-msgs]');
			if (!d.messages.length) { $msgs.innerHTML = '<div class="pg-wa-empty">No messages with this customer yet.</div>'; }
			appendMessages(d.messages, $msgs);
			$msgs.scrollTop = $msgs.scrollHeight;
			paintComposer();
			bindHead();
			setTitle(d.unread);
			state.contacts.forEach(function (x) { if (x.phone === phone) { x.unread = 0; } });
			paintList();
			var t = $chat.querySelector('[data-text]');
			if (t && window.innerWidth > 900) { t.focus(); }
		}).catch(function (e) { $chat.innerHTML = '<div class="pg-wa-blank"><span>' + esc(e.message) + '</span></div>'; });
	}

	function bindHead() {
		$chat.querySelector('[data-back]').addEventListener('click', function () { $wrap.classList.remove('is-chat'); state.phone = ''; paintList(); loadList(); });
		$chat.querySelector('[data-unread]').addEventListener('click', function () {
			A.ajax('pg_wa_mark', { phone: state.phone, unread: 1 }).then(function (d) { A.toast('Marked as unread', 'ok'); setTitle(d.unread); $wrap.classList.remove('is-chat'); state.phone = ''; loadList(); });
		});
		$chat.querySelector('[data-side]').addEventListener('click', function (e) {
			var s = $chat.querySelector('[data-sidebar]'), open = !s.classList.contains('is-open');
			s.classList.toggle('is-open', open);
			e.currentTarget.setAttribute('aria-expanded', open ? 'true' : 'false');
		});
	}

	function bindComposer() {
		var t = $chat.querySelector('[data-text]'), b = $chat.querySelector('[data-send]');
		if (t) {
			var grow = function () { t.style.height = 'auto'; t.style.height = Math.min(160, t.scrollHeight) + 'px'; };
			t.addEventListener('input', grow);
			t.addEventListener('keydown', function (e) {
				if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) { e.preventDefault(); send(); }
			});
			b.addEventListener('click', send);
		}
		var tb = $chat.querySelector('[data-send-tpl]');
		if (tb) {
			tb.addEventListener('click', function () {
				A.busy(tb, true);
				A.ajax('pg_wa_send_tpl', { phone: state.phone, map_id: $chat.querySelector('[data-tpl]').value }).then(function () { A.toast('Template sent', 'ok'); poll(); })
					.catch(function (e) { A.toast(e.message, 'bad'); }).finally(function () { A.busy(tb, false); });
			});
		}
	}

	function send() {
		var t = $chat.querySelector('[data-text]'), b = $chat.querySelector('[data-send]');
		var text = t.value.trim();
		if (!text || state.sending) { return; }
		state.sending = true;
		A.busy(b, true);
		A.ajax('pg_wa_reply', { phone: state.phone, text: text }).then(function () {
			t.value = ''; t.style.height = 'auto';
			poll();
		}).catch(function (e) {
			A.toast(e.message, 'bad');
			if (e.data && e.data.closed) { state.window = 0; paintComposer(); }
		}).finally(function () { state.sending = false; A.busy(b, false); t.focus(); });
	}

	/* ---------- Live updates ---------- */

	var polling = false;
	function poll() {
		if (polling) { return; }
		polling = true;
		var jobs = [loadList()];
		if (state.phone && state.lastId >= 0) {
			var phone = state.phone;
			jobs.push(A.ajax('pg_wa_thread', { phone: phone, after: state.lastId || 0 }).then(function (d) {
				if (state.phone !== phone) { return; }
				var $msgs = $chat.querySelector('[data-msgs]');
				if (!$msgs) { return; }
				var nearBottom = $msgs.scrollHeight - $msgs.scrollTop - $msgs.clientHeight < 120;
				var fresh = state.lastId ? d.messages : [];
				if (fresh.length) {
					var empty = $msgs.querySelector('.pg-wa-empty');
					if (empty) { empty.remove(); }
					appendMessages(fresh, $msgs);
					if (nearBottom || fresh.some(function (m) { return m.dir === 'out'; })) { $msgs.scrollTop = $msgs.scrollHeight; }
					if (fresh.some(function (m) { return m.dir === 'in'; }) && document.hidden) { beep(); }
				}
				(d.states || []).forEach(function (s) {
					var el = $msgs.querySelector('[data-mid="' + s.id + '"]');
					if (!el) { return; }
					var meta = el.querySelector('.pg-wa-meta'), cur = el.getAttribute('data-st');
					if (cur === s.status) { return; }
					el.setAttribute('data-st', s.status);
					var tick = meta.querySelector('.pg-wa-tick');
					if (tick) { tick.outerHTML = ticks({ dir: 'out', status: s.status, error: s.error }); }
					if ((s.status === 'nowa' || s.status === 'failed') && !el.querySelector('.pg-wa-err')) {
						el.classList.add('is-failed');
						el.insertAdjacentHTML('beforeend', '<div class="pg-wa-err">' + esc(s.error || 'Not delivered') + '</div>');
					}
				});
				var wasOpen = state.window > 0;
				state.window = d.window;
				if (wasOpen !== (d.window > 0)) { paintComposer(); }
				else if (d.window > 0) { var w = $chat.querySelector('.pg-wa-window'); if (w) { w.textContent = 'Reply window open · ' + hoursLeft(d.window) + ' left'; } }
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
