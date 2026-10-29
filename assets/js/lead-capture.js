/* Product Glide — saves checkout details (after a valid mobile number is typed) so the
   shop can call customers who leave without pressing "Place order". */
(function () {
	'use strict';
	var C = window.PG_LEAD;
	if (!C || !window.fetch) { return; }

	var last = '', timer = null, stopped = false, busy = false, again = false;
	var SKIP = /pass|card|cvc|cvv|expiry|token|nonce|security|otp|createaccount|terms/i;

	function root() {
		return document.querySelector('form.checkout, form.woocommerce-checkout') ||
			document.querySelector('.wc-block-checkout, .wp-block-woocommerce-checkout');
	}

	function keyFor(el) {
		if (el.name && !/^radio-control-/.test(el.name)) { return el.name; }
		var id = el.id || '';
		if (id === 'email') { return 'billing_email'; }
		if (/^(billing|shipping)-/.test(id)) { return id.replace('-', '_'); }
		if (el.tagName === 'TEXTAREA' && el.closest && el.closest('.wc-block-checkout__order-notes, .wc-block-components-checkout-order-notes, .wp-block-woocommerce-checkout-order-note-block')) { return 'order_comments'; }
		if (el.name && /^radio-control-wc-payment-method/.test(el.name)) { return 'payment_method'; }
		return '';
	}

	function collect() {
		var r = root(), out = {};
		if (!r) { return null; }
		r.querySelectorAll('input, select, textarea').forEach(function (el) {
			var k = keyFor(el), t = (el.type || '').toLowerCase();
			if (!k || SKIP.test(k) || t === 'password' || t === 'hidden' || el.disabled) { return; }
			if (!/^(billing_|shipping_|order_comments$|payment_method$|ship_to_different_address$)/.test(k)) { return; }
			if (t === 'radio' && !el.checked) { return; }
			var v = t === 'checkbox' ? (el.checked ? '1' : '0') : String(el.value || '').trim();
			if (v !== '' && v.length < 500) { out[k] = v; }
		});
		return out;
	}

	function digits(s) {
		var bn = '০১২৩৪৫৬৭৮৯';
		return String(s || '').replace(/[০-৯]/g, function (d) { return bn.indexOf(d); }).replace(/\D/g, '');
	}

	function hasPhone(f) {
		var d = digits(f.billing_phone || f.shipping_phone);
		return /01[3-9]\d{8}$/.test(d);
	}

	function body(f) {
		var fd = new FormData();
		fd.append('action', 'pg_lead_track');
		fd.append('f', JSON.stringify(f));
		return fd;
	}

	function send(beacon) {
		if (stopped) { return; }
		var f = collect();
		if (!f || !hasPhone(f)) { return; }
		var sig = JSON.stringify(f);
		if (sig === last) { return; }
		if (beacon && navigator.sendBeacon) {
			last = sig;
			navigator.sendBeacon(C.ajax, body(f));
			return;
		}
		if (busy) { again = true; return; }
		busy = true;
		fetch(C.ajax, { method: 'POST', body: body(f), credentials: 'same-origin', keepalive: true })
			.then(function () { last = sig; })
			.catch(function () {})
			.finally(function () { busy = false; if (again) { again = false; queue(); } });
	}

	function queue() {
		clearTimeout(timer);
		timer = setTimeout(function () { send(false); }, C.wait || 1500);
	}

	function onEdit(e) {
		var t = e.target;
		if (!t || !t.closest || !root() || !root().contains(t)) { return; }
		queue();
	}

	document.addEventListener('input', onEdit, true);
	document.addEventListener('change', onEdit, true);
	document.addEventListener('focusout', function (e) {
		if (e.target && /phone/i.test((e.target.name || '') + (e.target.id || ''))) { send(false); }
	}, true);

	// Leaving the page: save whatever is there.
	document.addEventListener('visibilitychange', function () {
		if (document.visibilityState === 'hidden') { clearTimeout(timer); send(true); }
	});
	window.addEventListener('pagehide', function () { clearTimeout(timer); send(true); });

	// Order placed: stop (the server marks the entry as ordered).
	document.addEventListener('submit', function (e) {
		if (e.target && e.target.matches && e.target.matches('form.checkout, form.woocommerce-checkout')) { clearTimeout(timer); send(false); }
	}, true);
	if (window.jQuery) {
		window.jQuery(document.body).on('checkout_error', function () { stopped = false; });
	}

	// Details already filled (returning customer / autofill).
	setTimeout(function () { send(false); }, 2500);
})();
