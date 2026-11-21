/* Product Glide — Profit & Stock */
(function () {
	'use strict';
	var A = window.PGA, esc = A.esc;
	var root = document.getElementById('pg-profit-root');
	if (!root) { return; }

	var tab = 'parcels';
	var f = { from: A.today().slice(0, 8) + '01', to: A.today(), courier: 'all' };

	root.innerHTML =
		'<div class="pg-tabs"><button class="pg-tab is-active" data-tab="parcels">Parcel হিসাব</button><button class="pg-tab" data-tab="profit">Profit / Loss</button><button class="pg-tab" data-tab="stock">Inventory & Costs</button></div>' +
		'<div id="pg-tab-parcels"></div><div id="pg-tab-profit" hidden></div><div id="pg-tab-stock" hidden></div>';

	var $p = document.getElementById('pg-tab-profit');
	var $s = document.getElementById('pg-tab-stock');
	var $pp = document.getElementById('pg-tab-parcels');

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
			'<div class="pg-field"><label>Courier</label><select data-f="courier"><option value="all">All orders</option><option value="any">Sent by courier</option><option value="steadfast">Steadfast only</option><option value="carrybee">Carrybee only</option><option value="none">No courier</option></select></div>' +
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
		$pp.hidden = tab !== 'parcels';
		if (tab === 'stock' && !stockLoaded) { loadStock(); }
	});

	loadProfit();
	initParcels();

	/* ---------- Parcel ledger ---------- */

	function initParcels() {
		var pf = { from: f.from, to: f.to, basis: 'finished' }, show = 'done', q = '', data = null;
		var ST = {
			delivered: ['Delivered', 'pg-badge-ok'], partial: ['Partial', 'pg-badge-warn'], returned: ['Returned', 'pg-badge-bad'],
			voided: ['Voided', ''], transit: ['On the way', '']
		};
		$pp.innerHTML =
			'<div class="pg-card"><div class="pg-grid pg-grid-3">' +
				'<div class="pg-field"><label>From</label><input type="date" data-pf="from" value="' + pf.from + '"></div>' +
				'<div class="pg-field"><label>To</label><input type="date" data-pf="to" value="' + pf.to + '"></div>' +
				'<div class="pg-field"><label>Count parcels by</label><select data-pf="basis"><option value="finished">Date delivered / returned</option><option value="booked">Date sent to courier</option></select></div>' +
			'</div><div class="pg-chips">' +
				['today:Today', '7:Last 7 days', '30:Last 30 days', 'month:This month', 'last:Last month'].map(function (x) {
					var p = x.split(':'); return '<button class="pg-chip" data-ppreset="' + p[0] + '">' + p[1] + '</button>';
				}).join('') +
			'</div><p class="pg-muted" style="margin:6px 0 0">প্রতিটা পার্সেলের লাভ = কাস্টমার থেকে আসা টাকা (COD + অগ্রিম) − বইয়ের কেনা দাম − কুরিয়ার চার্জ। ফেরত পার্সেলে বই স্টকে ফিরে আসে, তাই ক্ষতি শুধু ফেরতের চার্জ। চার্জগুলো Steadfast যা জানিয়েছে সেটাই।</p></div>' +
			'<div id="pp-out"><div class="pg-skel"></div></div>';

		function money(n) { return A.money(n); }
		function pl(n) { return '<b class="' + (n < 0 ? 'pg-neg' : 'pg-pos') + '">' + (n < 0 ? '−' : '') + A.money(Math.abs(n)) + '</b>'; }

		function load() {
			document.getElementById('pp-out').innerHTML = '<div class="pg-skel"></div>';
			A.ajax('pg_parcels', pf).then(function (d) { data = d; render(); })
				.catch(function (e) { document.getElementById('pp-out').innerHTML = '<div class="pg-alert pg-alert-bad">' + esc(e.message) + '</div>'; });
		}

		function rowsFor() {
			return data.rows.filter(function (r) {
				if (show === 'done' && (r.status === 'transit' || (r.status === 'voided' && !r.charge))) { return false; }
				if (show !== 'done' && show !== 'all' && r.status !== show) { return false; }
				if (q) {
					var h = (r.number + ' ' + r.customer + ' ' + r.phone + ' ' + r.items + ' ' + r.tracking).toLowerCase();
					if (h.indexOf(q) === -1) { return false; }
				}
				return true;
			});
		}

		function render() {
			var d = data, s = d.sum;
			var html = '<div class="pg-stats">' +
				stat('নিট লাভ (বিজ্ঞাপন খরচ বাদ দিয়ে)', pl(s.net_profit), 'big' + (s.net_profit < 0 ? ' neg' : '')) +
				stat('ডেলিভারি পার্সেল থেকে লাভ', pl(s.profit_delivered)) +
				stat('ফেরত পার্সেলে ক্ষতি', pl(s.loss_returned), s.loss_returned < 0 ? 'neg' : '') +
				stat('বিজ্ঞাপন খরচ', money(s.ads)) +
				stat('পার্সেল: ডেলিভারি / আংশিক / ফেরত', s.delivered + ' / ' + s.partial + ' / ' + s.returned) +
				stat('ফেরতের হার', s.return_rate + '%', s.return_rate > 15 ? 'neg' : '') +
				stat('ডেলিভারি পার্সেল প্রতি গড় লাভ', money(s.avg_delivered)) +
				stat('কাস্টমার থেকে তোলা টাকা', money(s.collected)) +
				stat('বইয়ের কেনা দাম', money(s.cogs)) +
				stat('কুরিয়ার চার্জ (ডেলিভারি + ফেরত)', money(s.charge_delivery) + ' + ' + money(s.charge_return + s.charge_voided)) +
				stat('Steadfast থেকে আপনার পাওনা', money(s.payable)) +
				stat('রাস্তায় আছে', s.transit + ' টা · ' + money(s.transit_cod)) +
				'</div>';
			if (d.missing_cost.length) {
				html += '<div class="pg-alert pg-alert-warn">' + d.missing_cost.length + 'টা বইয়ের কেনা দাম দেওয়া নেই, তাই লাভ বেশি দেখাচ্ছে: ' + esc(d.missing_cost.slice(0, 6).join(', ')) + ' — Inventory & Costs ট্যাবে দাম দিন।</div>';
			}
			if (s.estimated) {
				html += '<div class="pg-alert pg-alert-warn">' + s.estimated + 'টা পার্সেলের চার্জ Steadfast এখনো জানায়নি, সেগুলোতে আনুমানিক চার্জ (ডেলিভারি ৳' + d.fallback.delivery + ', ফেরত ৳' + d.fallback.return + ') ধরা হয়েছে, পাশে ≈ চিহ্ন আছে।</div>';
			}
			if (s.mismatch) {
				html += '<div class="pg-alert pg-alert-warn">' + s.mismatch + 'টা ডেলিভারি পার্সেলে কুরিয়ার যে টাকা তুলেছে তা COD-এর সাথে মেলে না, তালিকায় "টাকা কম/বেশি" চিহ্ন দেখুন।</div>';
			}
			var chips = [['done', 'শেষ হওয়া সব'], ['delivered', 'ডেলিভারি'], ['partial', 'আংশিক'], ['returned', 'ফেরত'], ['transit', 'রাস্তায়'], ['all', 'সব']];
			html += '<div class="pg-card"><div class="pg-card-head" style="flex-wrap:wrap;gap:8px"><h2>প্রতিটা পার্সেল</h2>' +
				'<div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center"><div class="pg-chips" style="margin:0">' + chips.map(function (c) {
					return '<button class="pg-chip' + (show === c[0] ? ' is-active' : '') + '" data-show="' + c[0] + '">' + c[1] + '</button>';
				}).join('') + '</div><input type="search" id="pp-q" placeholder="অর্ডার, নাম, ফোন…" value="' + esc(q) + '" style="width:200px"><button class="pg-btn pg-btn-sm" id="pp-csv">⬇ Excel (CSV)</button></div></div>' +
				'<div id="pp-table"></div></div>';
			document.getElementById('pp-out').innerHTML = html;
			table();
		}

		function table() {
			var rows = rowsFor(), T = { cod: 0, collected: 0, advance: 0, revenue: 0, cogs: 0, charge: 0, profit: 0 };
			rows.forEach(function (r) { Object.keys(T).forEach(function (k) { T[k] += Number(r[k] || 0); }); });
			var noteTxt = { collected_less: 'টাকা কম', collected_more: 'টাকা বেশি', partial: 'আংশিক' };
			var h = '<div class="pg-table-wrap"><table class="pg-table pg-ledger"><thead><tr><th>অর্ডার</th><th>কাস্টমার</th><th>বই</th><th>অবস্থা</th><th>পাঠানো</th><th>শেষ</th>' +
				'<th class="r">COD</th><th class="r">তোলা হয়েছে</th><th class="r">অগ্রিম</th><th class="r">মোট আয়</th><th class="r">বইয়ের দাম</th><th class="r">কুরিয়ার চার্জ</th><th class="r">লাভ / ক্ষতি</th></tr></thead><tbody>';
			h += rows.slice(0, 1500).map(function (r) {
				var st = ST[r.status] || [r.status, ''];
				var link = A.cfg.isAdmin && A.cfg.orderEditBase ? A.cfg.orderEditBase + r.order : '';
				return '<tr class="' + (r.status === 'transit' ? 'is-muted' : '') + '"><td>' + (link ? '<a href="' + esc(link) + '" target="_blank" rel="noopener">#' + esc(r.number) + '</a>' : '#' + esc(r.number)) + '</td>' +
					'<td>' + esc(r.customer) + '<div class="pg-muted">' + esc(r.phone) + '</div></td>' +
					'<td class="pg-ledger-items" title="' + esc(r.items) + '">' + esc(r.items) + '</td>' +
					'<td><span class="pg-badge ' + st[1] + '">' + st[0] + '</span>' + (r.note && noteTxt[r.note] && r.note !== 'partial' ? ' <span class="pg-badge pg-badge-warn">' + noteTxt[r.note] + '</span>' : '') + '</td>' +
					'<td>' + esc(r.booked) + '</td><td>' + esc(r.finished) + '</td>' +
					'<td class="r">' + money(r.cod) + '</td><td class="r">' + (r.collected === null ? '—' : money(r.collected)) + '</td><td class="r">' + (r.advance ? money(r.advance) : '—') + '</td>' +
					'<td class="r">' + money(r.revenue) + '</td><td class="r">' + (r.cogs ? money(r.cogs) : '—') + (r.status === 'partial' ? ' ≈' : '') + '</td>' +
					'<td class="r">' + (r.estimated ? '≈ ' : '') + money(r.charge) + '</td><td class="r">' + pl(r.profit) + (r.status === 'transit' ? '<div class="pg-muted">আনুমানিক</div>' : '') + '</td></tr>';
			}).join('');
			if (!rows.length) { h += '<tr><td colspan="13" class="pg-muted">এই সময়ে কোনো পার্সেল নেই।</td></tr>'; }
			h += '</tbody>' + (rows.length ? '<tfoot><tr><th colspan="6">মোট ' + rows.length + 'টা পার্সেল</th><th class="r">' + money(T.cod) + '</th><th class="r">' + money(T.collected) + '</th><th class="r">' + money(T.advance) + '</th><th class="r">' + money(T.revenue) + '</th><th class="r">' + money(T.cogs) + '</th><th class="r">' + money(T.charge) + '</th><th class="r">' + pl(T.profit) + '</th></tr></tfoot>' : '') + '</table></div>';
			if (rows.length > 1500) { h += '<p class="pg-muted">প্রথম ১৫০০টা দেখানো হচ্ছে, পুরোটা CSV-তে পাবেন।</p>'; }
			document.getElementById('pp-table').innerHTML = h;
		}

		function csv() {
			var rows = rowsFor();
			var head = ['Order', 'Customer', 'Phone', 'Items', 'Status', 'Tracking', 'Sent', 'Finished', 'COD', 'Collected', 'Advance', 'Revenue', 'Product cost', 'Courier charge', 'Charge estimated', 'Profit'];
			var lines = [head].concat(rows.map(function (r) {
				return [r.number, r.customer, r.phone, r.items, r.status, r.tracking, r.booked, r.finished, r.cod, r.collected === null ? '' : r.collected, r.advance, r.revenue, r.cogs, r.charge, r.estimated ? 'yes' : '', r.profit];
			})).map(function (a) { return a.map(function (v) { v = String(v === null || v === undefined ? '' : v); return /[",\n]/.test(v) ? '"' + v.replace(/"/g, '""') + '"' : v; }).join(','); });
			var blob = new Blob(['\ufeff' + lines.join('\r\n')], { type: 'text/csv;charset=utf-8' });
			var a = document.createElement('a');
			a.href = URL.createObjectURL(blob); a.download = 'parcel-hisab-' + pf.from + '-to-' + pf.to + '.csv';
			document.body.appendChild(a); a.click(); setTimeout(function () { URL.revokeObjectURL(a.href); a.remove(); }, 500);
		}

		$pp.addEventListener('change', function (e) {
			var k = e.target.getAttribute('data-pf');
			if (k) { pf[k] = e.target.value; load(); }
		});
		$pp.addEventListener('input', function (e) {
			if (e.target.id === 'pp-q') { q = e.target.value.trim().toLowerCase(); table(); }
		});
		$pp.addEventListener('click', function (e) {
			var pr = e.target.closest('[data-ppreset]');
			if (pr) {
				var r = presetRange(pr.getAttribute('data-ppreset'));
				pf.from = r[0]; pf.to = r[1];
				$pp.querySelector('[data-pf="from"]').value = pf.from;
				$pp.querySelector('[data-pf="to"]').value = pf.to;
				load();
				return;
			}
			var sh = e.target.closest('[data-show]');
			if (sh) {
				show = sh.getAttribute('data-show');
				$pp.querySelectorAll('[data-show]').forEach(function (b) { b.classList.toggle('is-active', b === sh); });
				table();
				return;
			}
			if (e.target.closest('#pp-csv')) { csv(); }
		});
		load();
	}
})();
