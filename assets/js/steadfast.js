/* Product Glide — Steadfast tools */
(function () {
	'use strict';
	var A = window.PGA, cfg = A.cfg, esc = A.esc;
	var root = document.getElementById('pg-sf-root');
	if (!root) { return; }

	function card(id, title, body, head) {
		return '<div class="pg-card" id="' + id + '"><div class="pg-card-head"><h2>' + title + '</h2>' + (head || '') + '</div>' + body + '</div>';
	}

	function table(rows, cols) {
		if (!rows || !rows.length) { return '<div class="pg-muted">Nothing to show.</div>'; }
		cols = cols || Object.keys(rows[0]).filter(function (k) { return typeof rows[0][k] !== 'object'; }).slice(0, 8);
		return '<div class="pg-table-wrap"><table class="pg-table"><thead><tr>' + cols.map(function (c) { return '<th>' + esc(c.replace(/_/g, ' ')) + '</th>'; }).join('') +
			'</tr></thead><tbody>' + rows.map(function (r) {
				return '<tr>' + cols.map(function (c) { var v = r[c]; return '<td>' + esc(v === null || v === undefined ? '' : (typeof v === 'object' ? JSON.stringify(v) : v)) + '</td>'; }).join('') + '</tr>';
			}).join('') + '</tbody></table></div>';
	}

	root.innerHTML =
		(cfg.sfReady ? '' : '<div class="pg-alert pg-alert-warn">' + (cfg.isAdmin ? 'Add your Steadfast API Key and Secret Key in <a href="' + esc(cfg.adminUrl + 'admin.php?page=pg-settings&tab=steadfast') + '">Settings → Steadfast</a>.' : 'Steadfast is not connected yet. Ask an administrator to add the API keys.') + '</div>') +
		'<div class="pg-grid pg-grid-2">' +
		card('sf-health', 'Health check', '<div id="sf-health-out" class="pg-muted">Test the API connection and keys.</div>', '<button class="pg-btn pg-btn-primary" data-do="ping">Ping</button>') +
		card('sf-fraud', 'Fraud / delivery score', '<div class="pg-copyrow"><input type="tel" id="sf-fraud-phone" placeholder="01XXXXXXXXX"><button class="pg-btn pg-btn-primary" data-do="fraud">Check</button></div><div id="sf-fraud-out" style="margin-top:10px"></div>') +
		'</div>' +
		card('sf-track', 'Invoice tracking', '<div class="pg-copyrow"><input type="text" id="sf-track-q" placeholder="Order #, invoice (8029-R1) or consignment ID"><button class="pg-btn pg-btn-primary" data-do="track">Track</button></div><div id="sf-track-out" style="margin-top:12px"></div>') +
		card('sf-bulk', 'Bulk consignment booking',
			'<p class="pg-muted">Paste order numbers (space, comma or new line separated), or load all Processing orders. Uses the extended bulk endpoint; per-order errors are shown.</p>' +
			'<textarea id="sf-bulk-ids" rows="3" placeholder="8091 8092 8093"></textarea>' +
			'<div class="pg-form-actions"><button class="pg-btn" data-do="bulk-load">Load Processing orders</button><button class="pg-btn pg-btn-primary" data-do="bulk">Book parcels</button></div><ul class="pg-bulk-res" id="sf-bulk-out"></ul>') +
		'<div class="pg-grid pg-grid-2">' +
		card('sf-returns', 'Return requests', '<div class="pg-copyrow"><input type="text" id="sf-ret-cid" placeholder="Consignment ID"><input type="text" id="sf-ret-reason" placeholder="Reason"><button class="pg-btn" data-do="ret-create">Create</button></div><div id="sf-ret-out" style="margin-top:10px"></div>', '<button class="pg-btn pg-btn-sm" data-do="returns">Load</button>') +
		card('sf-payments', 'Payments (disbursements)', '<div class="pg-copyrow"><input type="text" id="sf-pay-id" placeholder="SFC-12345 (optional)"><button class="pg-btn" data-do="payment">View</button></div><div id="sf-pay-out" style="margin-top:10px"></div>', '<button class="pg-btn pg-btn-sm" data-do="payments">Load</button>') +
		'</div>' +
		'<div class="pg-grid pg-grid-2">' +
		card('sf-pickup', 'Rider pickup request',
			'<div class="pg-grid pg-grid-2"><div class="pg-field"><label>Pickup address ID (from Steadfast panel)</label><input type="number" id="pk-address-id"></div><div class="pg-field"><label>Police station ID</label><input type="number" id="pk-ps-id"></div></div>' +
			'<div class="pg-field"><label>Pickup address</label><input type="text" id="pk-address"></div>' +
			'<div class="pg-grid pg-grid-2"><div class="pg-field"><label>Contact number</label><input type="tel" id="pk-phone" value="' + esc(cfg.brand.hotline) + '"></div><div class="pg-field"><label>Expected parcels</label><input type="number" min="1" id="pk-qty" value="10"></div></div>' +
			'<div class="pg-field"><label>Note</label><input type="text" id="pk-note"></div>' +
			'<div class="pg-form-actions"><button class="pg-btn pg-btn-primary" data-do="pickup">Request pickup</button></div><div id="pk-out"></div>') +
		card('sf-police', 'Thana / police stations', '<div class="pg-copyrow"><input type="search" id="sf-ps-q" placeholder="Search thana / district"><button class="pg-btn" data-do="police">Search</button></div><div id="sf-ps-out" style="margin-top:10px;max-height:380px;overflow:auto"></div>') +
		'</div>';

	function out(id, html) { document.getElementById(id).innerHTML = html; }
	function err(id) { return function (e) { out(id, '<div class="pg-alert pg-alert-bad">' + esc(e.message) + '</div>'); }; }

	var actions = {
		ping: function (b) {
			return A.ajax('pg_sf_ping', {}).then(function (d) {
				out('sf-health-out', '<div class="pg-alert pg-alert-ok">' + esc(d.message) + '</div>' +
					(d.data.balance !== null && d.data.balance !== undefined ? '<div class="pg-stat"><span>Current balance</span><b>' + A.money(d.data.balance) + '</b></div>' : ''));
			}).catch(err('sf-health-out'));
		},
		fraud: function () {
			var ph = document.getElementById('sf-fraud-phone').value;
			return A.ajax('pg_fraud_check', { phone: ph, force: 1 }).then(function (d) {
				var f = d.data;
				out('sf-fraud-out',
					'<div class="pg-stats">' +
					'<div class="pg-stat"><span>Delivery success</span><b>' + (f.delivery_ratio === null ? '—' : f.delivery_ratio + '%') + '</b></div>' +
					'<div class="pg-stat"><span>Cancelled</span><b>' + (f.cancellation_ratio === null ? '—' : f.cancellation_ratio + '%') + '</b></div>' +
					'<div class="pg-stat' + (f.total_reports ? ' neg' : '') + '"><span>Fraud reports</span><b>' + f.total_reports + '</b></div>' +
					'<div class="pg-stat"><span>Volume</span><b>' + esc(f.volume_band || '—') + '</b></div></div>' +
					'<a class="pg-btn" href="' + esc(f.panel_url) + '" target="_blank" rel="noopener">Open in Steadfast Fraud Panel ↗</a>');
			}).catch(err('sf-fraud-out'));
		},
		track: function () {
			return A.ajax('pg_sf_tracking', { q: document.getElementById('sf-track-q').value }).then(function (d) {
				var r = d.data;
				out('sf-track-out', '<p><b>Invoice ' + esc(r.invoice) + '</b>' + (r.status ? ' · status <span class="pg-badge pg-badge-info">' + esc(r.status) + '</span>' : '') + '</p>' +
					(r.tracking.length ? '<ol class="pg-timeline">' + r.tracking.map(function (t) {
						return '<li><time>' + esc(t.created_at_bd || t.created_at) + '</time>' + esc(t.text) + '</li>';
					}).join('') + '</ol>' : '<div class="pg-muted">No scan history yet.</div>'));
			}).catch(err('sf-track-out'));
		},
		'bulk-load': function () {
			return A.ajax('pg_orders_list', { status: 'processing', per_page: 50, page: 1 }).then(function (d) {
				var ids = d.orders.filter(function (o) { return !o.courier; }).map(function (o) { return o.number; });
				document.getElementById('sf-bulk-ids').value = ids.join(' ');
				A.toast(ids.length + ' processing orders loaded (max 50)', 'ok');
			});
		},
		bulk: function () {
			var nums = document.getElementById('sf-bulk-ids').value.split(/[\s,]+/).map(function (x) { return x.replace(/\D/g, ''); }).filter(Boolean);
			if (!nums.length) { A.toast('Enter order numbers first.', 'bad'); return Promise.resolve(); }
			if (!window.confirm('Book ' + nums.length + ' parcels with Steadfast?')) { return Promise.resolve(); }
			return A.ajax('pg_sf_bulk', { order_ids: nums }).then(function (d) {
				var ok = 0;
				out('sf-bulk-out', d.results.map(function (r) {
					if (r.ok) { ok++; }
					return '<li class="' + (r.ok ? 'ok' : 'bad') + '">#' + r.order_id + ': ' + (r.ok ? '✓ ' + esc(r.tracking_code) : '✗ ' + esc((r.errors || []).join('; '))) + '</li>';
				}).join(''));
				A.toast(ok + ' booked, ' + (d.results.length - ok) + ' failed', ok ? 'ok' : 'bad');
			}).catch(err('sf-bulk-out'));
		},
		returns: function () {
			return A.ajax('pg_sf_returns', { page: 1 }).then(function (d) { out('sf-ret-out', table(d.data)); }).catch(err('sf-ret-out'));
		},
		'ret-create': function () {
			return A.ajax('pg_sf_return_request', { consignment_id: document.getElementById('sf-ret-cid').value, reason: document.getElementById('sf-ret-reason').value })
				.then(function (d) { A.toast(d.message, 'ok'); return actions.returns(); }).catch(err('sf-ret-out'));
		},
		payments: function () {
			return A.ajax('pg_sf_payments', { page: 1 }).then(function (d) { out('sf-pay-out', table(d.data)); }).catch(err('sf-pay-out'));
		},
		payment: function () {
			return A.ajax('pg_sf_payment', { payment_id: document.getElementById('sf-pay-id').value }).then(function (d) {
				var list = d.data && (d.data.consignments || d.data.data || d.data.parcels);
				out('sf-pay-out', Array.isArray(list) ? table(list) : '<pre class="pg-json">' + esc(JSON.stringify(d.data, null, 2)) + '</pre>');
			}).catch(err('sf-pay-out'));
		},
		pickup: function () {
			return A.ajax('pg_sf_pickup', {
				address_id: document.getElementById('pk-address-id').value, police_station_id: document.getElementById('pk-ps-id').value,
				address: document.getElementById('pk-address').value, contact_number: document.getElementById('pk-phone').value,
				estim_qty: document.getElementById('pk-qty').value, note: document.getElementById('pk-note').value
			}).then(function (d) { out('pk-out', '<div class="pg-alert pg-alert-ok">' + esc(d.message) + ' ID: ' + esc(d.data.id || (d.data.data && d.data.data.id) || '') + '</div>'); })
				.catch(err('pk-out'));
		},
		police: function () {
			return A.ajax('pg_sf_police', { q: document.getElementById('sf-ps-q').value }).then(function (d) {
				out('sf-ps-out', '<p class="pg-muted">' + d.total + ' match(es)</p>' + table(d.data));
			}).catch(err('sf-ps-out'));
		}
	};

	root.addEventListener('click', function (e) {
		var b = e.target.closest('[data-do]');
		if (!b) { return; }
		var fn = actions[b.getAttribute('data-do')];
		if (!fn) { return; }
		A.busy(b, true);
		Promise.resolve(fn(b)).finally(function () { A.busy(b, false); });
	});
	root.addEventListener('keydown', function (e) {
		if (e.key !== 'Enter' || e.target.tagName !== 'INPUT') { return; }
		var btn = e.target.parentNode.querySelector('[data-do]');
		if (btn) { e.preventDefault(); btn.click(); }
	});
})();
