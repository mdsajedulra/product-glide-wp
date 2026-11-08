/* Product Glide — shared "create order" form (Package Builder + Screenshot Order) */
(function () {
	'use strict';
	var A = window.PGA, esc = A.esc;

	/**
	 * opts: { items:[{product_id,name,qty,price}], discount, delivery, source, label, prefill:{name,phone,address,district,thana,note,payment} , inline: Element }
	 */
	function formHtml(p) {
		p = p || {};
		return '<div class="pg-grid pg-grid-2">' +
			'<div class="pg-field"><label>Customer name *</label><input type="text" data-f="name" value="' + esc(p.name) + '" autocomplete="off"></div>' +
			'<div class="pg-field"><label>Phone *</label><input type="tel" data-f="phone" value="' + esc(p.phone) + '" inputmode="tel" autocomplete="off"></div>' +
			'</div>' +
			'<div class="pg-field"><label>Full address *</label><textarea data-f="address" rows="2">' + esc(p.address) + '</textarea></div>' +
			'<div class="pg-grid pg-grid-2">' +
			'<div class="pg-field"><label>District (জেলা)</label><select data-f="district" data-geo-d data-val="' + esc(p.district == null ? '' : p.district) + '"><option value="">Loading…</option></select></div>' +
			'<div class="pg-field"><label>Thana / Upazila</label><select data-f="thana" data-geo-t data-val="' + esc(p.thana == null ? '' : p.thana) + '"><option value="">—</option></select></div>' +
			'</div>' +
			'<div class="pg-geo-zone" data-geo-zone hidden></div>' +
			'<div class="pg-field"><label>Payment</label><select data-f="payment"><option value="cod">Cash on delivery</option><option value="bacs"' + (p.payment === 'bacs' ? ' selected' : '') + '>Bank transfer</option></select></div>' +
			'<div class="pg-field"><label>Note</label><textarea data-f="note" rows="2">' + esc(p.note) + '</textarea></div>';
	}

	function read(box) {
		var out = {};
		box.querySelectorAll('[data-f]').forEach(function (el) { out[el.getAttribute('data-f')] = el.value.trim(); });
		var d = geoDistrict(out.district), t = d && out.thana !== '' ? d.t[out.thana] : null;
		out.city = t ? t[0] : (d ? d.n : '');
		return out;
	}

	/* ---------- District / thana dropdowns + delivery zone ---------- */

	var geoP = null, GEO = null;
	function loadGeo() {
		if (!geoP) {
			geoP = A.ajax('pg_geo', {}).then(function (d) { GEO = d; return d; }).catch(function (e) { geoP = null; throw e; });
		}
		return geoP;
	}
	function geoDistrict(i) {
		if (!GEO || i === '' || i == null) { return null; }
		return GEO.districts.filter(function (x) { return String(x.i) === String(i); })[0] || null;
	}
	/** { zone, fee, label } for the current selects, or null when no district is chosen. */
	function zoneOf(box) {
		var dSel = box.querySelector('[data-geo-d]'), tSel = box.querySelector('[data-geo-t]');
		var d = dSel ? geoDistrict(dSel.value) : null;
		if (!d) { return null; }
		var t = tSel && tSel.value !== '' ? d.t[tSel.value] : null;
		var z = t ? t[2] : d.z;
		return { zone: z, fee: GEO.fees[z], label: GEO.labels[z], district: d.n, thana: t ? t[0] : '' };
	}
	function fillThanas(box, d, val) {
		var tSel = box.querySelector('[data-geo-t]');
		if (!tSel) { return; }
		tSel.innerHTML = '<option value="">' + (d ? '— Select thana —' : '—') + '</option>' + (d ? d.t.map(function (t, ti) {
			return '<option value="' + ti + '"' + (String(val) === String(ti) ? ' selected' : '') + '>' + esc(t[0]) + ' · ' + esc(t[1]) + '</option>';
		}).sort(function (a, b) { return a.replace(/<[^>]+>/g, '').localeCompare(b.replace(/<[^>]+>/g, '')); }).join('') : '');
		tSel.disabled = !d;
	}
	function paintZone(box) {
		var el = box.querySelector('[data-geo-zone]'), z = zoneOf(box);
		if (!el) { return z; }
		el.hidden = !z;
		el.className = 'pg-geo-zone' + (z ? ' is-' + z.zone : '');
		el.innerHTML = z ? '<span>🚚 ' + esc(z.label) + '</span><b>' + A.money(z.fee) + '</b>' : '';
		return z;
	}
	/** Set district / thana by index (from the server) and repaint. */
	function setGeo(box, di, ti) {
		var dSel = box.querySelector('[data-geo-d]');
		if (!dSel) { return null; }
		dSel.value = di == null ? '' : String(di);
		fillThanas(box, geoDistrict(dSel.value), ti == null ? '' : ti);
		var z = paintZone(box);
		box.dispatchEvent(new CustomEvent('pg:zone', { detail: z }));
		return z;
	}
	/**
	 * Fill the dropdowns inside a form box and keep the zone line updated.
	 * The box fires a "pg:zone" event ({zone, fee, label} or null) whenever district / thana change.
	 * opts.detect: also guess district / thana from the typed address when none is chosen.
	 */
	function initGeo(box, opts) {
		opts = opts || {};
		var dSel = box.querySelector('[data-geo-d]');
		if (!dSel) { return Promise.resolve(); }
		return loadGeo().then(function () {
			var dv = dSel.getAttribute('data-val'), tv = box.querySelector('[data-geo-t]').getAttribute('data-val');
			dSel.innerHTML = '<option value="">— Select district —</option>' + GEO.districts.map(function (d) {
				return '<option value="' + d.i + '"' + (String(dv) === String(d.i) ? ' selected' : '') + '>' + esc(d.n) + ' · ' + esc(d.b) + '</option>';
			}).join('');
			fillThanas(box, geoDistrict(dSel.value), tv);
			box.dispatchEvent(new CustomEvent('pg:zone', { detail: paintZone(box) }));
			if (box.__pgGeo) { return; }
			box.__pgGeo = true;
			box.addEventListener('change', function (e) {
				if (e.target.hasAttribute('data-geo-d')) { fillThanas(box, geoDistrict(e.target.value), ''); }
				if (e.target.hasAttribute('data-geo-d') || e.target.hasAttribute('data-geo-t')) {
					box.__pgGeoManual = true;
					box.dispatchEvent(new CustomEvent('pg:zone', { detail: paintZone(box) }));
				}
			});
			if (opts.detect) {
				var detect = A.debounce(function (val) {
					if (box.__pgGeoManual || val.length < 6) { return; }
					A.ajax('pg_geo_detect', { address: val }).then(function (d) {
						if (box.__pgGeoManual || d.geo.district == null) { return; }
						setGeo(box, d.geo.district, d.geo.thana);
					}).catch(function () {});
				}, 700);
				box.addEventListener('input', function (e) {
					if (e.target.getAttribute('data-f') === 'address') { detect(e.target.value.trim()); }
				});
			}
		}).catch(function () {
			dSel.innerHTML = '<option value="">—</option>';
		});
	}

	function submit(customer, opts, btn) {
		if (!customer.name || !customer.phone || !customer.address) {
			A.toast('Name, phone and address are required.', 'bad');
			return Promise.reject();
		}
		if (!opts.items.length) {
			A.toast('Add at least one product.', 'bad');
			return Promise.reject();
		}
		A.busy(btn, true);
		return A.ajax('pg_create_order', {
			customer: customer,
			items: opts.items.map(function (it) { return { product_id: it.product_id, qty: it.qty, price: it.price }; }),
			discount: opts.discount || 0,
			delivery: opts.delivery || 0,
			source: opts.source || 'package',
			label: opts.label || ''
		}).then(function (d) {
			A.toast(d.message, 'ok');
			return d.order;
		}).catch(function (e) {
			if (e) { A.toast(e.message, 'bad'); }
			throw e;
		}).finally(function () { A.busy(btn, false); });
	}

	function successHtml(o) {
		var ou = A.cfg.ordersUrl || (A.cfg.adminUrl + 'admin.php?page=product-glide');
		var base = ou + (ou.indexOf('?') === -1 ? '?' : '&') + 'q=' + encodeURIComponent(o.number);
		return '<div class="pg-alert pg-alert-ok">Order #' + esc(o.number) + ' created · ' + A.money(o.total) + '</div>' +
			'<div class="pg-form-actions" style="justify-content:flex-start">' +
			'<a class="pg-btn pg-btn-primary" href="' + esc(base) + '">Open in Orders</a>' +
			'<a class="pg-btn" target="_blank" rel="noopener" href="' + esc(A.printUrl([o.id])) + '">Print invoice</a>' +
			(A.cfg.canWoo ? '<a class="pg-btn" target="_blank" rel="noopener" href="' + esc(o.edit_url) + '">WooCommerce ↗</a>' : '') + '</div>';
	}

	function open(opts) {
		var sum = opts.items.reduce(function (s, it) { return s + it.qty * it.price; }, 0);
		var total = Math.max(0, sum - (opts.discount || 0)) + (opts.delivery || 0);
		var m = A.modal('Create order', formHtml(opts.prefill) +
			'<div class="pg-pkg-sum"><div><span>' + opts.items.length + ' item(s)</span><span>' + A.money(sum) + '</span></div>' +
			(opts.discount ? '<div><span>Package discount</span><span>-' + A.money(opts.discount) + '</span></div>' : '') +
			'<div><span>Delivery</span><span>' + A.money(opts.delivery || 0) + '</span></div>' +
			'<div class="total"><span>Total (COD)</span><span>' + A.money(total) + '</span></div></div>' +
			'<div class="pg-form-actions"><button class="pg-btn" data-close>Cancel</button><button class="pg-btn pg-btn-primary" data-go>Create order</button></div>',
			{ center: true });
		initGeo(m.body, { detect: true });
		m.body.querySelector('[data-go]').addEventListener('click', function () {
			submit(read(m.body), opts, this).then(function (o) {
				m.body.innerHTML = successHtml(o);
				if (opts.onCreated) { opts.onCreated(o); }
			}).catch(function () {});
		});
		return m;
	}

	window.PGOrderForm = { open: open, formHtml: formHtml, read: read, submit: submit, successHtml: successHtml, initGeo: initGeo, setGeo: setGeo, zoneOf: zoneOf, loadGeo: loadGeo };
})();
