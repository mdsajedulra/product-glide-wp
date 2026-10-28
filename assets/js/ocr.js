/* Product Glide — Screenshot / visiting-card order creator (page + modal) */
(function () {
	'use strict';
	var A = window.PGA, cfg = A.cfg, esc = A.esc;
	var active = null; // the instance that receives Ctrl+V pastes

	function mount(root, opts) {
		opts = opts || {};
		var items = []; // {product_id,name,qty,price,regular,thumb}
		var imageData = '';
		var $ = function (sel) { return root.querySelector(sel); };

		root.innerHTML =
			'<div class="pg-pkg pg-ocr">' +
			'<div>' +
				'<div class="pg-card"><div class="pg-card-head"><h2>1. Screenshot</h2><span class="pg-muted">Optional</span></div>' +
					'<label class="pg-drop" data-drop tabindex="0">' +
						'<input type="file" accept="image/*" data-file hidden>' +
						'<span class="pg-drop-empty">' +
							'<span class="pg-drop-icon" aria-hidden="true">' +
								'<svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="3"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="M21 15l-5-5L5 21"/></svg>' +
							'</span>' +
							'<span class="pg-drop-title"><b>Tap to upload</b> or drop an image</span>' +
							'<span class="pg-drop-sub">You can also paste with Ctrl+V · chat screenshot, note or visiting card</span>' +
						'</span>' +
						'<span class="pg-drop-preview" data-preview hidden></span>' +
					'</label>' +
					'<div class="pg-form-actions"><button type="button" class="pg-btn" data-clear hidden>Remove image</button><button type="button" class="pg-btn pg-btn-primary" data-read disabled>Read with AI</button></div>' +
				'</div>' +
				'<div class="pg-card" data-matches-card hidden><div class="pg-card-head"><h2>Books found</h2></div><div data-matches></div></div>' +
			'</div>' +
			'<div>' +
				'<div class="pg-card"><div class="pg-card-head"><h2>2. Customer</h2></div><div data-form>' + window.PGOrderForm.formHtml({}) + '</div>' +
					'<div data-hist-hint></div></div>' +
				'<div class="pg-card"><div class="pg-card-head"><h2>3. Products</h2></div>' +
					'<div data-picker></div>' +
					'<div class="pg-pp-cart-head">In this order</div>' +
					'<div data-items></div>' +
					'<div class="pg-grid pg-grid-2" style="margin-top:10px"><div class="pg-field"><label>Discount (৳)</label><input type="number" min="0" step="1" data-discount value="0"></div>' +
					'<div class="pg-field"><label>Delivery charge (৳)</label><input type="number" min="0" step="1" data-delivery value="' + esc(cfg.pkgDelivery || 0) + '"></div></div>' +
					'<div class="pg-pkg-sum" data-sum></div>' +
					'<div class="pg-form-actions"><button type="button" class="pg-btn pg-btn-primary" data-create>Create order</button></div>' +
					'<div data-done></div>' +
				'</div>' +
			'</div></div>';

		var $drop = $('[data-drop]'), $file = $('[data-file]'), $read = $('[data-read]'), $clear = $('[data-clear]');
		var $form = $('[data-form]');

		/* ---------- Image intake (downscaled to keep uploads small) ---------- */

		function setImage(data) {
			imageData = data;
			var prev = $('[data-preview]');
			$('.pg-drop-empty').hidden = !!data;
			prev.hidden = !data;
			prev.innerHTML = data ? '<img src="' + data + '" alt="Uploaded screenshot"><span class="pg-drop-change">Tap to change image</span>' : '';
			$drop.classList.toggle('has-image', !!data);
			$read.disabled = !data;
			$clear.hidden = !data;
		}

		function takeFile(file) {
			if (!file || !/^image\//.test(file.type)) { A.toast('Choose an image file.', 'bad'); return; }
			var reader = new FileReader();
			reader.onload = function () {
				A.loadImage(reader.result).then(function (img) {
					if (!img) { A.toast('Could not open this image.', 'bad'); return; }
					var max = 1800, s = Math.min(1, max / Math.max(img.width, img.height));
					var c = document.createElement('canvas');
					c.width = Math.round(img.width * s); c.height = Math.round(img.height * s);
					c.getContext('2d').drawImage(img, 0, 0, c.width, c.height);
					setImage(c.toDataURL('image/jpeg', 0.88));
				});
			};
			reader.readAsDataURL(file);
		}

		$file.addEventListener('change', function () { takeFile($file.files[0]); $file.value = ''; });
		$drop.addEventListener('dragover', function (e) { e.preventDefault(); $drop.classList.add('over'); });
		$drop.addEventListener('dragleave', function () { $drop.classList.remove('over'); });
		$drop.addEventListener('drop', function (e) { e.preventDefault(); $drop.classList.remove('over'); takeFile(e.dataTransfer.files[0]); });
		$drop.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); $file.click(); } });
		$clear.addEventListener('click', function () { setImage(''); });

		/* ---------- AI parse ---------- */

		$read.addEventListener('click', function () {
			if (!imageData) { return; }
			A.busy($read, true);
			A.ajax('pg_ocr_parse', { image: imageData }).then(function (d) {
				var r = d.data;
				var addr = [r.address, r.thana, r.district].filter(function (x, i, a) { return x && a.indexOf(x) === i && (i === 0 || r.address.indexOf(x) === -1); }).join(', ');
				$form.innerHTML = window.PGOrderForm.formHtml({ name: r.name, phone: r.phone_bd || r.phone, address: addr, city: r.district, note: [r.note, r.alt_phone ? 'Alt: ' + r.alt_phone : ''].filter(Boolean).join(' | ') });
				renderMatches(r.matches || [], r.books || []);
				checkPhone();
				A.toast('Details extracted — please check before creating.', 'ok');
			}).catch(function (e) { A.toast(e.message, 'bad'); }).finally(function () { A.busy($read, false); });
		});

		function renderMatches(matches, books) {
			var card = $('[data-matches-card]');
			var box = $('[data-matches]');
			card.hidden = false;
			if (!matches.length) { box.innerHTML = '<div class="pg-muted">No book names found in the image. Add products manually.</div>'; return; }
			box.innerHTML = matches.map(function (m, i) {
				var qty = books[i] ? books[i].qty : 1;
				if (!m.candidates.length) {
					return '<div class="pg-match"><div class="pg-match-title">“' + esc(m.title) + '”</div><div class="pg-muted">No matching product — search manually.</div></div>';
				}
				return '<div class="pg-match"><div class="pg-match-title">“' + esc(m.title) + '” <span class="pg-muted">×' + qty + '</span></div>' +
					m.candidates.map(function (c, ci) {
						return '<div class="pg-cand"><span class="score">' + c.score + '%</span><span style="flex:1">' + esc(c.product.name) + ' · ' + A.money(c.product.price) + '</span>' +
							'<button type="button" class="pg-btn pg-btn-sm' + (ci === 0 ? ' pg-btn-primary' : '') + '" data-pick=\'' + esc(JSON.stringify(c.product)) + '\' data-qty="' + qty + '">Add</button></div>';
					}).join('') + '</div>';
			}).join('');
			matches.forEach(function (m, i) {
				if (m.candidates.length && m.candidates[0].score >= 60) {
					addItem(m.candidates[0].product, books[i] ? books[i].qty : 1, true);
				}
			});
			renderItems();
		}

		/* ---------- Items ---------- */

		function addItem(p, qty, silent) {
			var ex = items.find(function (it) { return it.product_id === p.id; });
			if (ex) { ex.qty += qty || 1; } else {
				items.push({ product_id: p.id, name: p.name, qty: qty || 1, price: p.price, regular: p.regular, thumb: p.thumb });
			}
			if (!silent) { renderItems(); A.toast('Added: ' + p.name, 'ok'); }
		}

		function sums() {
			var sub = items.reduce(function (s, it) { return s + it.qty * (Number(it.price) || 0); }, 0);
			var disc = Math.min(Number($('[data-discount]').value) || 0, sub);
			var del = Number($('[data-delivery]').value) || 0;
			return { sub: sub, disc: disc, del: del, total: sub - disc + del };
		}

		function renderItems() {
			$('[data-items]').innerHTML = items.length ? items.map(function (it, i) {
				return '<div class="pg-pkg-item pg-ocr-item" data-i="' + i + '">' +
					(it.thumb ? '<img src="' + esc(it.thumb) + '" alt="">' : '<span class="pg-thumb-ph"></span>') +
					'<div class="nm"><b>' + esc(it.name) + '</b></div>' +
					'<input type="number" min="0" step="1" data-price="' + i + '" value="' + esc(it.price) + '" aria-label="Price">' +
					'<input type="number" min="1" step="1" data-qty="' + i + '" value="' + esc(it.qty) + '" aria-label="Quantity">' +
					'<div class="ctl"><button type="button" data-rm="' + i + '" aria-label="Remove">✕</button></div></div>';
			}).join('') : '<div class="pg-pp-cart-empty">No products yet — tap a book above to add it.</div>';
			renderSum();
			if (typeof picker !== 'undefined' && picker) { picker.refresh(); }
		}

		function renderSum() {
			var s = sums();
			$('[data-sum]').innerHTML =
				'<div><span>Subtotal</span><span>' + A.money(s.sub) + '</span></div>' +
				(s.disc ? '<div><span>Discount</span><span>-' + A.money(s.disc) + '</span></div>' : '') +
				'<div><span>Delivery</span><span>' + A.money(s.del) + '</span></div>' +
				'<div class="total"><span>Total (COD)</span><span>' + A.money(s.total) + '</span></div>';
		}

		root.addEventListener('click', function (e) {
			var pk = e.target.closest('[data-pick]');
			if (pk) { addItem(JSON.parse(pk.getAttribute('data-pick')), parseInt(pk.getAttribute('data-qty'), 10) || 1); return; }
			var rm = e.target.closest('[data-rm]');
			if (rm) { items.splice(parseInt(rm.getAttribute('data-rm'), 10), 1); renderItems(); return; }
			var ad = e.target.closest('[data-add]');
			if (ad) { addItem(JSON.parse(ad.getAttribute('data-add')), 1); }
		});

		root.addEventListener('input', function (e) {
			var t = e.target, pi = t.getAttribute('data-price'), qi = t.getAttribute('data-qty');
			if (pi !== null) { items[pi].price = Number(t.value); renderSum(); }
			else if (qi !== null) { items[qi].qty = Math.max(1, parseInt(t.value, 10) || 1); renderSum(); }
			else if (t.hasAttribute('data-discount') || t.hasAttribute('data-delivery')) { renderSum(); }
		});

		var picker = window.PGProductPicker.mount($('[data-picker]'), {
			onAdd: function (p) { addItem(p, 1); },
			inOrder: function (pid) { var it = items.find(function (x) { return x.product_id === pid; }); return it ? it.qty : 0; }
		});

		/* ---------- Duplicate hint on phone ---------- */

		var checkPhone = A.debounce(function () {
			var ph = $form.querySelector('[data-f="phone"]');
			var hint = $('[data-hist-hint]');
			var digits = (ph.value || '').replace(/\D/g, '');
			if (digits.length < 10) { hint.innerHTML = ''; return; }
			A.ajax('pg_orders_history', { phone: ph.value }).then(function (d) {
				if (!d.orders.length) { hint.innerHTML = ''; return; }
				hint.innerHTML = '<div class="pg-alert pg-alert-warn">This number already has ' + d.orders.length + ' order(s): ' +
					d.orders.slice(0, 5).map(function (o) { return '#' + esc(o.number) + ' (' + esc(o.status_label) + ', ' + esc(o.date) + ')'; }).join(', ') + '</div>';
			}).catch(function () {});
		}, 500);
		$form.addEventListener('input', function (e) { if (e.target.getAttribute('data-f') === 'phone') { checkPhone(); } });

		/* ---------- Create ---------- */

		$('[data-create]').addEventListener('click', function () {
			var s = sums();
			window.PGOrderForm.submit(window.PGOrderForm.read($form), { items: items, discount: s.disc, delivery: s.del, source: 'ocr' }, this)
				.then(function (o) {
					$('[data-done]').innerHTML = window.PGOrderForm.successHtml(o);
					items = []; renderItems();
					$form.innerHTML = window.PGOrderForm.formHtml({});
					$('[data-hist-hint]').innerHTML = '';
					setImage('');
					if (opts.onCreated) { opts.onCreated(o); }
				}).catch(function () {});
		});

		var inst = { root: root, takeFile: takeFile };
		active = inst;
		renderItems();
		return inst;
	}

	// Ctrl+V pastes go to the most recently opened form that is still on the page.
	document.addEventListener('paste', function (e) {
		if (!active || !document.body.contains(active.root)) { return; }
		var it = Array.from((e.clipboardData || {}).items || []).find(function (x) { return x.type.indexOf('image/') === 0; });
		if (it) { active.takeFile(it.getAsFile()); }
	});

	window.PGOcrForm = { mount: mount };

	var pageRoot = document.getElementById('pg-ocr-root');
	if (pageRoot) { mount(pageRoot); }
})();
