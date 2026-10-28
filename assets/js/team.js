/* Product Glide — Team performance (admin only) */
(function () {
	'use strict';
	var A = window.PGA, esc = A.esc;
	var root = document.getElementById('pg-team-root');
	if (!root) { return; }

	var ACT = {
		courier:   { label: 'Sent to courier', short: 'Courier', cls: 'c-courier' },
		confirmed: { label: 'Confirmed', short: 'Confirmed', cls: 'c-confirmed' },
		created:   { label: 'Created order', short: 'Created', cls: 'c-created' },
		cancelled: { label: 'Cancelled', short: 'Cancelled', cls: 'c-cancelled' },
		edited:    { label: 'Edited order', short: 'Edited', cls: 'c-edited' },
		status:    { label: 'Changed status', short: 'Status', cls: 'c-status' },
		note:      { label: 'Added note', short: 'Notes', cls: 'c-note' },
		printed:   { label: 'Printed invoice', short: 'Printed', cls: 'c-printed' }
	};
	var f = { from: A.today(), to: A.today(), preset: 'today' };
	var data = null;

	function presetRange(k) {
		var t = A.today();
		if (k === 'today') { return [t, t]; }
		if (k === 'yesterday') { return [A.today(-1), A.today(-1)]; }
		if (k === '7') { return [A.today(-6), t]; }
		if (k === '30') { return [A.today(-29), t]; }
		if (k === 'month') { return [t.slice(0, 8) + '01', t]; }
		return [f.from, f.to];
	}

	root.innerHTML =
		'<div class="pg-card pg-tm-filter">' +
			'<div class="pg-chips" data-presets>' +
				[['today', 'Today'], ['yesterday', 'Yesterday'], ['7', 'Last 7 days'], ['30', 'Last 30 days'], ['month', 'This month']].map(function (p) {
					return '<button type="button" class="pg-chip' + (p[0] === f.preset ? ' is-on' : '') + '" data-preset="' + p[0] + '">' + p[1] + '</button>';
				}).join('') +
			'</div>' +
			'<div class="pg-tm-dates"><label>From <input type="date" data-f="from"></label><label>To <input type="date" data-f="to"></label></div>' +
		'</div>' +
		'<div class="pg-kpis" data-kpis></div>' +
		'<div class="pg-muted pg-tm-since" data-since></div>' +
		'<div class="pg-tm-grid" data-grid></div>';

	var $from = root.querySelector('[data-f="from"]'), $to = root.querySelector('[data-f="to"]');
	$from.value = f.from; $to.value = f.to;

	function initials(name) {
		var w = String(name || '?').trim().split(/\s+/).filter(function (x) { return /[A-Za-z0-9\u0980-\u09FF]/.test(x.charAt(0)); });
		return (w.length ? w : ['?']).slice(0, 2).map(function (x) { return x.charAt(0).toUpperCase(); }).join('');
	}
	function n(v) { return Number(v || 0).toLocaleString('en-IN'); }
	function rangeText() {
		return f.from === f.to ? f.from : f.from + ' → ' + f.to;
	}

	function load() {
		root.querySelector('[data-grid]').innerHTML = '<div class="pg-empty">Loading…</div>';
		A.ajax('pg_team_report', { from: f.from, to: f.to }).then(function (d) {
			data = d;
			render();
		}).catch(function (e) {
			root.querySelector('[data-grid]').innerHTML = '<div class="pg-empty">' + esc(e.message) + '</div>';
		});
	}

	function outcomeBar(o) {
		var total = o.delivered + o.partial + o.returned + o.transit;
		if (!total) { return ''; }
		var done = o.delivered + o.partial + o.returned;
		var rate = done ? Math.round((o.delivered + o.partial) / done * 100) : null;
		function seg(v, cls) { return v ? '<i class="' + cls + '" style="flex:' + v + '"></i>' : ''; }
		return '<div class="pg-tm-out">' +
			'<div class="pg-tm-out-head"><span>Parcels sent: <b>' + total + '</b></span>' +
				(rate !== null ? '<span class="pg-tm-rate' + (rate < 70 ? ' is-low' : '') + '">' + rate + '% delivered</span>' : '<span class="pg-muted">all in transit</span>') + '</div>' +
			'<div class="pg-tm-bar">' + seg(o.delivered, 'ok') + seg(o.partial, 'part') + seg(o.returned, 'bad') + seg(o.transit, 'wait') + '</div>' +
			'<div class="pg-tm-legend"><span><i class="ok"></i>Delivered ' + o.delivered + '</span><span><i class="part"></i>Partial ' + o.partial + '</span><span><i class="bad"></i>Returned ' + o.returned + '</span><span><i class="wait"></i>In transit ' + o.transit + '</span></div>' +
		'</div>';
	}

	function spark(days) {
		if (!days || days.length < 2) { return ''; }
		var max = Math.max.apply(null, days.map(function (d) { return d.orders; })) || 1;
		return '<div class="pg-tm-spark" aria-hidden="true">' + days.map(function (d) {
			return '<i style="height:' + Math.max(6, Math.round(d.orders / max * 100)) + '%" title="' + esc(d.day + ': ' + d.orders + ' orders') + '"></i>';
		}).join('') + '</div>';
	}

	function render() {
		var t = data.totals;
		root.querySelector('[data-kpis]').innerHTML =
			'<div class="pg-kpi"><span>Orders handled</span><b>' + n(t.orders) + '</b></div>' +
			'<div class="pg-kpi pg-kpi-ok"><span>Sent to courier</span><b>' + n(t.courier) + '</b></div>' +
			'<div class="pg-kpi pg-kpi-warn"><span>Orders created</span><b>' + n(t.created) + '</b></div>' +
			'<div class="pg-kpi"><span>Active staff</span><b>' + n(t.active) + '</b></div>';
		root.querySelector('[data-since]').textContent = data.since ? 'Activity is recorded from ' + data.since + '. Showing ' + rangeText() + '.' : '';

		var grid = root.querySelector('[data-grid]');
		if (!data.people.length) { grid.innerHTML = '<div class="pg-empty">No Order Managers yet. Create one in Users → Add New with the "Order Manager" role.</div>'; return; }
		grid.innerHTML = data.people.map(function (p, i) {
			var a = p.acts, idle = !p.actions;
			return '<button type="button" class="pg-tm-card' + (idle ? ' is-idle' : '') + '" data-person="' + i + '">' +
				'<div class="pg-tm-top">' +
					'<span class="pg-tm-av">' + esc(initials(p.name)) + '</span>' +
					'<span class="pg-tm-who"><b>' + esc(p.name) + '</b><small><span class="pg-tm-role' + (p.staff ? '' : ' is-admin') + '">' + esc(p.role) + '</span>' + (p.last ? ' · last active ' + esc(p.last) : '') + '</small></span>' +
					'<span class="pg-tm-big"><b>' + n(p.orders) + '</b><small>orders</small></span>' +
				'</div>' +
				(idle ? '<div class="pg-tm-idle">No activity in this period</div>' :
					'<div class="pg-tm-stats">' +
						['courier', 'confirmed', 'created', 'cancelled'].map(function (k) {
							return '<span class="' + ACT[k].cls + '"><b>' + n(a[k]) + '</b>' + ACT[k].short + '</span>';
						}).join('') +
					'</div>' +
					'<div class="pg-tm-sub">' +
						['edited', 'status', 'note', 'printed'].map(function (k) { return ACT[k].short + ' <b>' + n(a[k]) + '</b>'; }).join(' · ') +
					'</div>' +
					spark(p.days) + outcomeBar(p.outcome)) +
				'<span class="pg-tm-more">' + (idle ? '' : 'View orders & timeline →') + '</span>' +
			'</button>';
		}).join('');
	}

	/* ---------- Person detail ---------- */

	function openPerson(p) {
		var m = A.modal(p.name + ' · ' + rangeText(), '<div class="pg-empty">Loading…</div>', { center: true, wide: true });
		A.ajax('pg_team_activity', { user_id: p.id, from: f.from, to: f.to }).then(function (d) {
			var acts = d.activity, orders = d.orders;
			var view = 'orders', filter = '';
			var counts = {};
			acts.forEach(function (x) { counts[x.action] = (counts[x.action] || 0) + 1; });

			function dayTable() {
				if (!p.days || p.days.length < 2) { return ''; }
				return '<div class="pg-table-wrap pg-tm-days"><table class="pg-table"><thead><tr><th>Day</th><th>Orders</th><th>Courier</th><th>Confirmed</th><th>Created</th><th>Cancelled</th></tr></thead><tbody>' +
					p.days.slice().reverse().map(function (r) {
						return '<tr><td>' + esc(r.day) + '</td><td><b>' + r.orders + '</b></td><td>' + r.courier + '</td><td>' + r.confirmed + '</td><td>' + r.created + '</td><td>' + r.cancelled + '</td></tr>';
					}).join('') + '</tbody></table></div>';
			}

			function orderHead(o) {
				if (!o || o.missing) { return '<b>#' + esc(o ? o.number : '?') + '</b> <span class="pg-muted">(deleted)</span>'; }
				return '<a class="pg-tm-ono" href="' + esc((A.cfg.ordersUrl || '') + (String(A.cfg.ordersUrl || '').indexOf('?') > -1 ? '&' : '?') + 'q=' + encodeURIComponent(o.number)) + '" target="_blank" rel="noopener">#' + esc(o.number) + '</a>' +
					'<span class="pg-tm-cust">' + esc(o.name || '') + (o.phone ? ' · ' + esc(o.phone) : '') + '</span>' +
					'<span class="pg-tm-amt">' + A.money(o.total) + '</span>' +
					'<span class="order-status status-' + esc(o.status) + ' pg-tm-st">' + esc(o.status_label) + '</span>';
			}

			function list() {
				var rows = filter ? acts.filter(function (x) { return x.action === filter; }) : acts;
				if (!rows.length) { return '<div class="pg-empty">Nothing here.</div>'; }
				if (view === 'orders') {
					var by = {}, order = [];
					rows.forEach(function (x) { if (!by[x.order_id]) { by[x.order_id] = []; order.push(x.order_id); } by[x.order_id].push(x); });
					return '<div class="pg-tm-orders">' + order.map(function (oid) {
						return '<div class="pg-tm-order"><div class="pg-tm-ohead">' + orderHead(orders[oid]) + '</div>' +
							'<div class="pg-tm-acts">' + by[oid].slice().reverse().map(function (x) {
								var a = ACT[x.action] || { label: x.action, cls: '' };
								return '<span class="pg-tm-act ' + a.cls + '" title="' + esc(x.detail) + '">' + esc(a.label) + ' <small>' + esc(x.time) + (x.day !== acts[0].day || p.days.length > 1 ? ', ' + esc(x.day.slice(5)) : '') + '</small></span>';
							}).join('') + '</div></div>';
					}).join('') + '</div>';
				}
				var day = '';
				return '<div class="pg-tm-timeline">' + rows.map(function (x) {
					var a = ACT[x.action] || { label: x.action, cls: '' }, o = orders[x.order_id] || {};
					var h = '';
					if (x.day !== day) { day = x.day; h = '<div class="pg-tm-day">' + esc(day) + '</div>'; }
					return h + '<div class="pg-tm-row"><span class="pg-tm-time">' + esc(x.time) + '</span>' +
						'<span class="pg-tm-act ' + a.cls + '">' + esc(a.label) + '</span>' +
						'<span class="pg-tm-rowbody"><b>#' + esc(o.number || x.order_id) + '</b> ' + esc(o.name || '') + (x.detail ? '<small>' + esc(x.detail) + '</small>' : '') + '</span></div>';
				}).join('') + '</div>';
			}

			function draw() {
				m.body.innerHTML =
					'<div class="pg-kpis pg-tm-mkpis">' +
						'<div class="pg-kpi"><span>Orders handled</span><b>' + n(p.orders) + '</b></div>' +
						'<div class="pg-kpi pg-kpi-ok"><span>Sent to courier</span><b>' + n(p.acts.courier) + '</b></div>' +
						'<div class="pg-kpi pg-kpi-warn"><span>Confirmed</span><b>' + n(p.acts.confirmed) + '</b></div>' +
						'<div class="pg-kpi pg-kpi-bad"><span>Cancelled</span><b>' + n(p.acts.cancelled) + '</b></div>' +
					'</div>' +
					outcomeBar(p.outcome) + dayTable() +
					'<div class="pg-tm-bar2">' +
						'<div class="pg-tabs"><button type="button" class="pg-tab' + (view === 'orders' ? ' is-active' : '') + '" data-view="orders">Orders (' + Object.keys(orders).length + ')</button>' +
						'<button type="button" class="pg-tab' + (view === 'timeline' ? ' is-active' : '') + '" data-view="timeline">Timeline (' + acts.length + ')</button></div>' +
						'<div class="pg-chips">' + '<button type="button" class="pg-chip' + (filter ? '' : ' is-on') + '" data-filter="">All</button>' +
							Object.keys(ACT).filter(function (k) { return counts[k]; }).map(function (k) {
								return '<button type="button" class="pg-chip' + (filter === k ? ' is-on' : '') + '" data-filter="' + k + '">' + ACT[k].short + ' ' + counts[k] + '</button>';
							}).join('') +
						'</div>' +
					'</div>' + list();
			}
			m.body.addEventListener('click', function (e) {
				var v = e.target.closest('[data-view]'), fl = e.target.closest('[data-filter]');
				if (v) { view = v.getAttribute('data-view'); draw(); }
				else if (fl) { filter = fl.getAttribute('data-filter'); draw(); }
			});
			draw();
		}).catch(function (e) { m.body.innerHTML = '<div class="pg-empty">' + esc(e.message) + '</div>'; });
	}

	/* ---------- Events ---------- */

	root.addEventListener('click', function (e) {
		var pr = e.target.closest('[data-preset]');
		if (pr) {
			f.preset = pr.getAttribute('data-preset');
			var r = presetRange(f.preset);
			f.from = r[0]; f.to = r[1]; $from.value = f.from; $to.value = f.to;
			root.querySelectorAll('[data-preset]').forEach(function (b) { b.classList.toggle('is-on', b === pr); });
			load();
			return;
		}
		var c = e.target.closest('[data-person]');
		if (c && data) {
			var p = data.people[parseInt(c.getAttribute('data-person'), 10)];
			if (p && p.actions) { openPerson(p); }
		}
	});
	root.addEventListener('change', function (e) {
		var k = e.target.getAttribute('data-f');
		if (!k || !e.target.value) { return; }
		f[k] = e.target.value; f.preset = '';
		root.querySelectorAll('[data-preset]').forEach(function (b) { b.classList.remove('is-on'); });
		load();
	});

	load();
})();
