/* Product Glide — shared "create order" form (Package Builder + Screenshot Order) */
(function () {
	'use strict';
	var A = window.PGA, esc = A.esc;

	/**
	 * opts: { items:[{product_id,name,qty,price}], discount, delivery, source, label, prefill:{name,phone,address,city,note,payment} , inline: Element }
	 */
	function formHtml(p) {
		p = p || {};
		return '<div class="pg-grid pg-grid-2">' +
			'<div class="pg-field"><label>Customer name *</label><input type="text" data-f="name" value="' + esc(p.name) + '" autocomplete="off"></div>' +
			'<div class="pg-field"><label>Phone *</label><input type="tel" data-f="phone" value="' + esc(p.phone) + '" inputmode="tel" autocomplete="off"></div>' +
			'</div>' +
			'<div class="pg-field"><label>Full address *</label><textarea data-f="address" rows="2">' + esc(p.address) + '</textarea></div>' +
			'<div class="pg-grid pg-grid-2">' +
			'<div class="pg-field"><label>City / District</label><input type="text" data-f="city" value="' + esc(p.city) + '"></div>' +
			'<div class="pg-field"><label>Payment</label><select data-f="payment"><option value="cod">Cash on delivery</option><option value="bacs"' + (p.payment === 'bacs' ? ' selected' : '') + '>Bank transfer</option></select></div>' +
			'</div>' +
			'<div class="pg-field"><label>Note</label><textarea data-f="note" rows="2">' + esc(p.note) + '</textarea></div>';
	}

	function read(box) {
		var out = {};
		box.querySelectorAll('[data-f]').forEach(function (el) { out[el.getAttribute('data-f')] = el.value.trim(); });
		return out;
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
		m.body.querySelector('[data-go]').addEventListener('click', function () {
			submit(read(m.body), opts, this).then(function (o) {
				m.body.innerHTML = successHtml(o);
				if (opts.onCreated) { opts.onCreated(o); }
			}).catch(function () {});
		});
		return m;
	}

	window.PGOrderForm = { open: open, formHtml: formHtml, read: read, submit: submit, successHtml: successHtml };
})();
