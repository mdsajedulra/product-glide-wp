/* Product Glide — Profit & Stock */
(function () {
	'use strict';
	var A = window.PGA, esc = A.esc;
	var root = document.getElementById('pg-profit-root');
	if (!root) { return; }

	var tab = 'profit';
	var f = { from: A.today().slice(0, 8) + '01', to: A.today(), courier: 'all' };

	root.innerHTML =
		'<div class="pg-tabs"><button class="pg-tab is-active" data-tab="profit">Profit / Loss</button><button class="pg-tab" data-tab="stock">Inventory & Costs</button></div>' +
		'<div id="pg-tab-profit"></div><div id="pg-tab-stock" hidden></div>';

	var $p = document.getElementById('pg-tab-profit');
	var $s = document.getElementById('pg-tab-stock');

	/* ---------- Profit ---------- */

	function presetRange(k) {
		var t = A.today();
		if (k === 'today') { return [t, t]; }
		if (k === '7') { return [A.today(-6), t]; }
		if (k === '30') { return [A.today(-29), t]; }
		if (k === 'month') { return [t.slice(0, 8) + '01', t]; }
		if (k === 'last') {
			var y = parseInt(t.slice(0, 4), 10), mo = parseInt(t.slice(5, 7), 10) - 1;
			if (mo === 0) { y--; mo = 12; }
			var last = new Date(y, mo, 0).getDate();
			var end = y + '-' + String(mo).padStart(2, '0') + '-' + String(last).padStart(2, '0');
			return [end.slice(0, 8) + '01', end];
		}
		return [f.from, f.to];
	}

	$p.innerHTML =
		'<div class="pg-card"><div class="pg-grid pg-grid-3">' +
			'<div class="pg-field"><label>From</label><input type="date" data-f="from"></div>' +
			'<div class="pg-field"><label>To</label><input type="date" data-f="to"></div>' +
			'<div class="pg-field"><label>Courier</label><select data-f="courier"><option value="all">All orders</option><option value="steadfast">Steadfast only</option><option value="none">No courier</option></select></div>' +
		'</div><div class="pg-chips">' +
			['today:Today', '7:Last 7 days', '30:Last 30 days', 'month:This month', 'last:Last month'].map(function (x) {
				var p = x.split(':'); return '<button class="pg-chip" data-preset="' + p[0] + '">' + p[1] + '</button>';
			}).join('') +
		'</div><p class="pg-muted">Net Profit = Delivered revenue − product cost − courier delivery & return charges − ad spend. Orders are counted by order date (Bangladesh time).</p></div>' +
		'<div id="pg-profit-out"><div class="pg-skel"></div></div>' +
		'<div class="pg-card"><div class="pg-card-head"><h2>Ad spend</h2></div>' +
			'<div class="pg-grid pg-grid-3"><div class="pg-field"><label>From</label><input type="date" id="ad-from"></div><div class="pg-field"><label>To</label><input type="date" id="ad-to"></div>' +
			'<div class="pg-field"><label>Platform</label><select id="ad-platform"><option>Facebook Ads</option><option>Google Ads</option><option>Other</option></select></div></div>' +
			'<div class="pg-grid pg-grid-2"><div class="pg-field"><label>Amount (৳)</label><input type="number" min="0" step="1" id="ad-amount"></div><div class="pg-field"><label>Note</label><input type="text" id="ad-note"></div></div>' +
			'<div class="pg-form-actions"><button class="pg-btn pg-btn-primary" id="ad-add">Add ad spend</button></div>' +
			'<div class="pg-table-wrap"><table class="pg-table" id="ad-table"></table></div></div>';

	$p.querySelectorAll('[data-f]').forEach(function (el) { el.value = f[el.getAttribute('data-f')]; });
	document.getElementById('ad-from').value = f.from;
	document.getElementById('ad-to').value = f.to;

	function stat(label, val, cls) {
		return '<div class="pg-stat ' + (cls || '') + '"><span>' + esc(label) + '</span><b>' + val + '</b></div>';
	}

	function loadProfit() {
		document.getElementById('pg-profit-out').innerHTML = '<div class="pg-skel"></div>';
		A.ajax('pg_profit', f).then(function (d) {
			var neg = d.net_profit < 0;
			var html = '<div class="pg-stats">' +
				stat('Net profit', A.money(d.net_profit), 'big' + (neg ? ' neg' : '')) +
				stat('Margin', d.margin_pct + '%', neg ? 'neg' : '') +
				stat('Delivered orders', d.orders) +
				stat('Revenue', A.money(d.revenue)) +
				stat('Product cost (COGS)', A.money(d.cogs)) +
				stat('Gross profit', A.money(d.gross_profit), d.gross_profit < 0 ? 'neg' : '') +
				stat('Courier delivery', A.money(d.courier_delivery)) +
				stat('Return penalties', A.money(d.return_penalty)) +
				stat('Ad spend', A.money(d.ad_spend)) +
				stat('Parcels: delivered / returned / in transit', d.parcels.delivered + ' / ' + d.parcels.cancelled + ' / ' + d.parcels.transit) +
				'</div>';
			if (d.missing_cost.length) {
				html += '<div class="pg-alert pg-alert-warn">' + d.missing_cost.length + ' product(s) have no purchase cost, so profit is overstated: ' +
					esc(d.missing_cost.slice(0, 8).join(', ')) + (d.missing_cost.length > 8 ? '…' : '') + ' — add costs in the Inventory tab.</div>';
			}
			if (d.products.length) {
				html += '<div class="pg-card"><div class="pg-card-head"><h2>Top products</h2></div><div class="pg-table-wrap"><table class="pg-table"><thead><tr><th>Product</th><th class="r">Qty</th><th class="r">Revenue</th><th class="r">Cost</th><th class="r">Profit</th></tr></thead><tbody>' +
					d.products.map(function (p) {
						return '<tr><td>' + esc(p.name) + '</td><td class="r">' + p.qty + '</td><td class="r">' + A.money(p.revenue) + '</td><td class="r">' + A.money(p.cogs) + '</td><td class="r">' + A.money(p.revenue - p.cogs) + '</td></tr>';
					}).join('') + '</tbody></table></div></div>';
			}
			document.getElementById('pg-profit-out').innerHTML = html;
			renderAds(d.ads);
		}).catch(function (e) { document.getElementById('pg-profit-out').innerHTML = '<div class="pg-alert pg-alert-bad">' + esc(e.message) + '</div>'; });
	}

	function renderAds(ads) {
		var t = document.getElementById('ad-table');
		if (!ads.length) { t.innerHTML = '<tr><td class="pg-muted">No ad spend recorded.</td></tr>'; return; }
		t.innerHTML = '<thead><tr><th>Period</th><th>Platform</th><th class="r">Amount</th><th>Note</th><th></th></tr></thead><tbody>' +
			ads.slice().reverse().map(function (a) {
				return '<tr><td>' + esc(a.from) + ' → ' + esc(a.to) + '</td><td>' + esc(a.platform) + '</td><td class="r">' + A.money(a.amount) + '</td><td>' + esc(a.note) +
					'</td><td class="r"><button class="pg-btn pg-btn-sm pg-btn-bad" data-ad-del="' + esc(a.id) + '">Delete</button></td></tr>';
			}).join('') + '</tbody>';
	}

	$p.addEventListener('change', function (e) {
		var k = e.target.getAttribute('data-f');
		if (k) { f[k] = e.target.value; loadProfit(); }
	});
	$p.addEventListener('click', function (e) {
		var pr = e.target.closest('[data-preset]');
		if (pr) {
			var r = presetRange(pr.getAttribute('data-preset'));
			f.from = r[0]; f.to = r[1];
			$p.querySelector('[data-f="from"]').value = f.from;
			$p.querySelector('[data-f="to"]').value = f.to;
			loadProfit();
			return;
		}
		var del = e.target.closest('[data-ad-del]');
		if (del && window.confirm('Delete this ad spend entry?')) {
			A.ajax('pg_ads_delete', { id: del.getAttribute('data-ad-del') }).then(function () { loadProfit(); });
		}
	});
	document.getElementById('ad-add').addEventListener('click', function () {
		var b = this;
		A.busy(b, true);
		A.ajax('pg_ads_save', {
			from: document.getElementById('ad-from').value, to: document.getElementById('ad-to').value,
			platform: document.getElementById('ad-platform').value, amount: document.getElementById('ad-amount').value,
			note: document.getElementById('ad-note').value
		}).then(function () {
			document.getElementById('ad-amount').value = ''; document.getElementById('ad-note').value = '';
			A.toast('Ad spend added', 'ok'); loadProfit();
		}).catch(function (e) { A.toast(e.message, 'bad'); }).finally(function () { A.busy(b, false); });
	});

	/* ---------- Inventory ---------- */

	var stockLoaded = false;
	function loadStock() {
		$s.innerHTML = '<div class="pg-skel"></div>';
		A.ajax('pg_inventory', {}).then(function (d) {
			stockLoaded = true;
			var t = d.totals;
			$s.innerHTML = '<div class="pg-stats">' +
				stat('Units in stock', t.stock) + stat('Stock cost value', A.money(t.cost_value)) + stat('Retail value', A.money(t.retail_value)) +
				stat('Potential margin', A.money(t.potential_margin), 'big') + stat('Products without cost', t.missing_cost, t.missing_cost ? 'neg' : '') +
				'</div><div class="pg-card"><input type="search" id="inv-q" placeholder="Filter products…" style="margin-bottom:10px">' +
				'<div class="pg-table-wrap"><table class="pg-table"><thead><tr><th>Product</th><th class="r">Stock</th><th class="r">Price</th><th class="r">Cost</th><th class="r">Cost value</th><th class="r">Retail value</th><th class="r">Margin</th></tr></thead><tbody id="inv-body">' +
				d.rows.map(function (r) {
					return '<tr data-name="' + esc(r.name.toLowerCase()) + '"><td>' + (A.cfg.isAdmin ? '<a href="' + esc(r.edit_url) + '" target="_blank" rel="noopener">' + esc(r.name) + '</a>' : esc(r.name)) +
						(r.status !== 'publish' ? ' <span class="pg-badge pg-badge-warn">' + esc(r.status) + '</span>' : '') + '</td>' +
						'<td class="r">' + (r.stock === null ? '—' : r.stock) + '</td><td class="r">' + A.money(r.price) + '</td>' +
						'<td class="r"><input class="pg-cost" type="number" min="0" step="0.01" data-cost="' + r.id + '" value="' + (r.cost === null ? '' : r.cost) + '" placeholder="—"></td>' +
						'<td class="r">' + (r.cost_value === null ? '—' : A.money(r.cost_value)) + '</td><td class="r">' + A.money(r.retail_value) + '</td>' +
						'<td class="r">' + (r.margin === null ? '—' : A.money(r.margin) + ' (' + r.margin_pct + '%)') + '</td></tr>';
				}).join('') + '</tbody></table></div></div>';
		}).catch(function (e) { $s.innerHTML = '<div class="pg-alert pg-alert-bad">' + esc(e.message) + '</div>'; });
	}

	$s.addEventListener('change', function (e) {
		var id = e.target.getAttribute('data-cost');
		if (!id) { return; }
		e.target.disabled = true;
		A.ajax('pg_cost_save', { product_id: id, cost: e.target.value }).then(function () { A.toast('Cost saved', 'ok'); })
			.catch(function (err) { A.toast(err.message, 'bad'); }).finally(function () { e.target.disabled = false; });
	});
	$s.addEventListener('input', function (e) {
		if (e.target.id !== 'inv-q') { return; }
		var q = e.target.value.trim().toLowerCase();
		document.querySelectorAll('#inv-body tr').forEach(function (tr) { tr.hidden = q && tr.getAttribute('data-name').indexOf(q) === -1; });
	});

	root.querySelector('.pg-tabs').addEventListener('click', function (e) {
		var b = e.target.closest('[data-tab]');
		if (!b) { return; }
		tab = b.getAttribute('data-tab');
		root.querySelectorAll('.pg-tab').forEach(function (x) { x.classList.toggle('is-active', x === b); });
		$p.hidden = tab !== 'profit';
		$s.hidden = tab !== 'stock';
		if (tab === 'stock' && !stockLoaded) { loadStock(); }
	});

	loadProfit();
})();
