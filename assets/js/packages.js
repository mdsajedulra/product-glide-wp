/* Product Glide — Book Package Builder + promotional banner */
(function () {
	'use strict';
	var A = window.PGA, cfg = A.cfg, esc = A.esc;
	var root = document.getElementById('pg-pkg-root');
	if (!root) { return; }
	var F = '"Hind Siliguri", "Noto Sans Bengali", sans-serif';

	var pkg = blank();
	var saved = Array.isArray(window.PG_PKGS) ? window.PG_PKGS : [];
	var images = {}; // product_id → HTMLImageElement|null
	var dragFrom = null;

	function blank() {
		return { id: '', title: 'বিশেষ বই প্যাকেজ', subtitle: '', valid_from: A.today(), valid_to: A.today(7), discount: 0, delivery: cfg.pkgDelivery || 0, items: [] };
	}

	try {
		var draft = JSON.parse(localStorage.getItem('pg_pkg_draft') || 'null');
		if (draft && Array.isArray(draft.items)) { pkg = Object.assign(blank(), draft); }
	} catch (e) {}

	function saveDraft() {
		try { localStorage.setItem('pg_pkg_draft', JSON.stringify(pkg)); } catch (e) {}
	}

	function bn(n) {
		return String(n).replace(/\d/g, function (d) { return '০১২৩৪৫৬৭৮৯'[d]; });
	}

	function totals() {
		var regular = 0, promo = 0, qty = 0;
		pkg.items.forEach(function (it) {
			regular += (Number(it.regular) || Number(it.price) || 0) * it.qty;
			promo += (Number(it.price) || 0) * it.qty;
			qty += it.qty;
		});
		var discount = Math.min(Number(pkg.discount) || 0, promo);
		var total = promo - discount;
		return { regular: regular, promo: promo, discount: discount, total: total, save: Math.max(0, regular - total), qty: qty, delivery: Number(pkg.delivery) || 0 };
	}

	/* ---------- Layout ---------- */

	root.innerHTML =
		'<div class="pg-pkg">' +
		'<div>' +
			'<div class="pg-card"><div class="pg-card-head"><h2>Package details</h2><button class="pg-btn pg-btn-sm" data-act="new">New</button></div>' +
				'<div class="pg-field"><label>Title</label><input type="text" data-p="title"></div>' +
				'<div class="pg-field"><label>Subtitle (optional)</label><input type="text" data-p="subtitle"></div>' +
				'<div class="pg-grid pg-grid-2"><div class="pg-field"><label>Offer starts</label><input type="date" data-p="valid_from"></div>' +
				'<div class="pg-field"><label>Offer ends</label><input type="date" data-p="valid_to"></div></div>' +
				'<div class="pg-grid pg-grid-2"><div class="pg-field"><label>Extra package discount (৳)</label><input type="number" min="0" step="1" data-p="discount"></div>' +
				'<div class="pg-field"><label>Delivery charge (৳)</label><input type="number" min="0" step="1" data-p="delivery"></div></div>' +
			'</div>' +
			'<div class="pg-card"><div class="pg-card-head"><h2>Add books</h2></div>' +
				'<input type="search" id="pg-pkg-search" placeholder="Search books (published, private, draft, hidden)…">' +
				'<div class="pg-search-results" id="pg-pkg-results"></div></div>' +
			'<div class="pg-card"><div class="pg-card-head"><h2>Books in package</h2><span class="pg-muted">Drag to reorder</span></div>' +
				'<div id="pg-pkg-items"></div><div class="pg-pkg-sum" id="pg-pkg-sum"></div>' +
				'<div class="pg-form-actions"><button class="pg-btn" data-act="save">Save preset</button><button class="pg-btn pg-btn-primary" data-act="order">Create order from package</button></div>' +
			'</div>' +
			'<div class="pg-card"><div class="pg-card-head"><h2>Saved packages</h2></div><div class="pg-saved" id="pg-pkg-saved"></div></div>' +
		'</div>' +
		'<div class="pg-card" style="position:sticky;top:40px"><div class="pg-card-head"><h2>Promotional banner</h2><button class="pg-btn pg-btn-primary" data-act="jpg">Download JPG</button></div>' +
			'<div class="pg-canvas-wrap"><canvas id="pg-pkg-canvas" width="1080" height="1350"></canvas></div></div>' +
		'</div>';

	var $items = document.getElementById('pg-pkg-items');
	var $sum = document.getElementById('pg-pkg-sum');
	var $res = document.getElementById('pg-pkg-results');
	var $canvas = document.getElementById('pg-pkg-canvas');

	function fillFields() {
		root.querySelectorAll('[data-p]').forEach(function (el) { el.value = pkg[el.getAttribute('data-p')]; });
	}

	function renderItems() {
		if (!pkg.items.length) {
			$items.innerHTML = '<div class="pg-empty">Search and add books above.</div>';
		} else {
			$items.innerHTML = pkg.items.map(function (it, i) {
				return '<div class="pg-pkg-item" draggable="true" data-i="' + i + '">' +
					'<span class="handle" aria-hidden="true">⋮⋮</span>' +
					(it.thumb ? '<img src="' + esc(it.thumb) + '" alt="">' : '<span style="width:44px;height:56px;border-radius:6px;background:#e2e8f0;display:block"></span>') +
					'<div><b style="font-size:13px">' + esc(it.name) + '</b><div class="pg-muted">Regular ' + A.money(it.regular) + '</div></div>' +
					'<div><input type="number" min="0" step="1" data-i-price="' + i + '" value="' + esc(it.price) + '" aria-label="Promo price"></div>' +
					'<div class="qty"><input type="number" min="1" step="1" data-i-qty="' + i + '" value="' + esc(it.qty) + '" aria-label="Quantity"></div>' +
					'<div class="ctl"><button type="button" data-move="-1" aria-label="Move up">↑</button><button type="button" data-move="1" aria-label="Move down">↓</button><button type="button" data-remove aria-label="Remove">✕</button></div>' +
					'</div>';
			}).join('');
		}
		renderSum();
	}

	function renderSum() {
		var t = totals();
		$sum.innerHTML =
			'<div><span>Regular price (' + t.qty + ' books)</span><span><s>' + A.money(t.regular) + '</s></span></div>' +
			'<div><span>Promo prices</span><span>' + A.money(t.promo) + '</span></div>' +
			(t.discount ? '<div><span>Extra discount</span><span>-' + A.money(t.discount) + '</span></div>' : '') +
			'<div class="total"><span>Package price</span><span>' + A.money(t.total) + '</span></div>' +
			'<div><span>+ Delivery</span><span>' + A.money(t.delivery) + '</span></div>' +
			'<div class="save"><span>Customer saves</span><span>' + A.money(t.save) + '</span></div>';
	}

	function renderSaved() {
		var box = document.getElementById('pg-pkg-saved');
		if (!saved.length) { box.innerHTML = '<span class="pg-muted">No saved packages yet.</span>'; return; }
		box.innerHTML = saved.map(function (p) {
			return '<span class="pg-badge pg-badge-info" style="padding:6px 10px"><button class="pg-chip" style="border:0;padding:0;background:none" data-load="' + esc(p.id) + '">' + esc(p.title || p.id) + ' (' + p.items.length + ')</button>' +
				'<button class="pg-chip" style="border:0;padding:0 0 0 6px;background:none;color:#b91c1c" data-del="' + esc(p.id) + '" aria-label="Delete">✕</button></span>';
		}).join('');
	}

	var redraw = A.debounce(drawBanner, 250);
	function changed() { saveDraft(); renderSum(); redraw(); }

	/* ---------- Search ---------- */

	function search(q) {
		$res.innerHTML = '<div class="pg-muted">Searching…</div>';
		A.ajax('pg_products_search', { q: q }).then(function (d) {
			if (!d.products.length) { $res.innerHTML = '<div class="pg-muted">Nothing found.</div>'; return; }
			$res.innerHTML = d.products.map(function (p) {
				var tag = p.status !== 'publish' ? '<span class="pg-badge pg-badge-warn">' + esc(p.status) + '</span> ' : (p.hidden ? '<span class="pg-badge">hidden</span> ' : '');
				return '<div class="pg-prod"><img src="' + esc(p.thumb) + '" alt=""><div class="meta"><b>' + esc(p.name) + '</b><small>' + tag + A.money(p.price) +
					(p.stock !== null ? ' · stock ' + p.stock : '') + '</small></div><button class="pg-btn pg-btn-sm" data-add=\'' + esc(JSON.stringify(p)) + '\'>Add</button></div>';
			}).join('');
		}).catch(function (e) { $res.innerHTML = '<div class="pg-alert pg-alert-bad">' + esc(e.message) + '</div>'; });
	}

	function ensureImage(id) {
		if (images[id] !== undefined) { return Promise.resolve(images[id]); }
		images[id] = null;
		return A.ajax('pg_product_image', { product_id: id }).then(function (d) {
			return A.loadImage(d.data_uri).then(function (img) { images[id] = img; return img; });
		}).catch(function () { return null; });
	}

	function loadAllImages() {
		return Promise.all(pkg.items.map(function (it) { return ensureImage(it.product_id); }));
	}

	/* ---------- Banner ---------- */

	function drawBanner() {
		var ctx = $canvas.getContext('2d');
		var W = 1080, H = 1350, P = 54;
		var t = totals();
		var n = pkg.items.length;
		Promise.all([A.fontsReady(), loadAllImages(), A.loadImage(cfg.brand.logo)]).then(function (res) {
			var logo = res[2];
			ctx.clearRect(0, 0, W, H);
			// Background
			ctx.fillStyle = '#fbf7ef'; ctx.fillRect(0, 0, W, H);
			var g = ctx.createLinearGradient(0, 0, W, 330);
			g.addColorStop(0, '#0f1f4d'); g.addColorStop(1, '#1e3a8a');
			ctx.fillStyle = g; ctx.fillRect(0, 0, W, 330);
			ctx.fillStyle = 'rgba(255,255,255,.07)';
			ctx.beginPath(); ctx.arc(W - 60, 40, 220, 0, Math.PI * 2); ctx.fill();

			// Logo
			var y = 40;
			if (logo) {
				var lh = 70, lw = Math.min(300, logo.width * (lh / logo.height));
				ctx.fillStyle = '#fff'; A.roundRect(ctx, P - 12, y - 10, lw + 24, lh + 20, 14); ctx.fill();
				ctx.drawImage(logo, P, y, lw, lh);
			}
			// Validity badge
			var vt = 'অফার: ' + fmtDate(pkg.valid_from) + ' – ' + fmtDate(pkg.valid_to);
			ctx.font = '700 26px ' + F;
			var bw = ctx.measureText(vt).width + 44;
			ctx.fillStyle = '#f59e0b'; A.roundRect(ctx, W - P - bw, y + 8, bw, 56, 28); ctx.fill();
			ctx.fillStyle = '#1f1300'; ctx.textAlign = 'center'; ctx.fillText(vt, W - P - bw / 2, y + 46); ctx.textAlign = 'left';

			// Title
			ctx.fillStyle = '#fff'; ctx.font = '800 60px ' + F;
			var tl = A.wrapText(ctx, pkg.title || '', W - 2 * P, 2);
			tl.forEach(function (l, i) { ctx.fillText(l, P, 190 + i * 70); });
			if (pkg.subtitle) {
				ctx.font = '500 30px ' + F; ctx.fillStyle = 'rgba(255,255,255,.85)';
				ctx.fillText(A.wrapText(ctx, pkg.subtitle, W - 2 * P, 1)[0], P, 190 + tl.length * 70 + 4);
			}

			// Grid
			var top = 360, bottom = H - 330;
			if (!n) {
				ctx.fillStyle = '#94a3b8'; ctx.font = '600 32px ' + F; ctx.textAlign = 'center';
				ctx.fillText('বই যোগ করুন', W / 2, (top + bottom) / 2); ctx.textAlign = 'left';
			} else {
				var cols = n <= 2 ? n : n === 4 ? 2 : n <= 6 ? 3 : n <= 9 ? 3 : 4;
				var rowsN = Math.ceil(n / cols);
				var gap = 22;
				var cw = (W - 2 * P - gap * (cols - 1)) / cols;
				var chh = (bottom - top - gap * (rowsN - 1)) / rowsN;
				pkg.items.forEach(function (it, i) {
					var c = i % cols, r = Math.floor(i / cols);
					var x = P + c * (cw + gap), yy = top + r * (chh + gap);
					ctx.fillStyle = '#fff'; A.roundRect(ctx, x, yy, cw, chh, 18); ctx.fill();
					ctx.strokeStyle = '#eadfca'; ctx.lineWidth = 2; ctx.stroke();
					var priceH = 46, titleH = cw > 300 ? 64 : 58;
					var imgBox = { x: x + 14, y: yy + 14, w: cw - 28, h: chh - 28 - priceH - titleH };
					var img = images[it.product_id];
					if (img && imgBox.h > 30) {
						var s = Math.min(imgBox.w / img.width, imgBox.h / img.height);
						var iw = img.width * s, ih = img.height * s;
						ctx.save(); ctx.shadowColor = 'rgba(0,0,0,.18)'; ctx.shadowBlur = 14; ctx.shadowOffsetY = 6;
						ctx.drawImage(img, imgBox.x + (imgBox.w - iw) / 2, imgBox.y + (imgBox.h - ih) / 2, iw, ih);
						ctx.restore();
					}
					if (it.qty > 1) {
						ctx.fillStyle = '#1e3a8a'; A.roundRect(ctx, x + cw - 74, yy + 12, 62, 40, 20); ctx.fill();
						ctx.fillStyle = '#fff'; ctx.font = '700 24px ' + F; ctx.textAlign = 'center'; ctx.fillText('×' + bn(it.qty), x + cw - 43, yy + 40); ctx.textAlign = 'left';
					}
					ctx.fillStyle = '#0f172a'; ctx.font = '700 ' + (cw > 300 ? 25 : 21) + 'px ' + F; ctx.textAlign = 'center';
					var lines = A.wrapText(ctx, it.name, cw - 24, 2);
					var ty = yy + chh - priceH - titleH + 26;
					lines.forEach(function (l, li) { ctx.fillText(l, x + cw / 2, ty + li * (cw > 300 ? 30 : 26)); });
					// Price: 34px bold, regular struck through beside it.
					var py = yy + chh - 18;
					var promo = '৳' + bn(Math.round(it.price));
					ctx.font = '800 34px ' + F;
					var pw = ctx.measureText(promo).width;
					var reg = Number(it.regular) > Number(it.price) ? '৳' + bn(Math.round(it.regular)) : '';
					ctx.font = '500 22px ' + F;
					var rw = reg ? ctx.measureText(reg).width + 10 : 0;
					var sx = x + cw / 2 - (pw + rw) / 2;
					ctx.textAlign = 'left';
					if (reg) {
						ctx.fillStyle = '#94a3b8'; ctx.fillText(reg, sx, py);
						ctx.strokeStyle = '#ef4444'; ctx.lineWidth = 2; ctx.beginPath(); ctx.moveTo(sx, py - 8); ctx.lineTo(sx + rw - 10, py - 8); ctx.stroke();
					}
					ctx.fillStyle = '#b91c1c'; ctx.font = '800 34px ' + F; ctx.fillText(promo, sx + rw, py);
				});
			}

			// Price band
			var by = H - 300;
			ctx.fillStyle = '#fff'; A.roundRect(ctx, P, by, W - 2 * P, 150, 22); ctx.fill();
			ctx.strokeStyle = '#1e3a8a'; ctx.lineWidth = 3; ctx.stroke();
			ctx.fillStyle = '#475569'; ctx.font = '600 26px ' + F;
			ctx.fillText('মোট ' + bn(t.qty) + 'টি বই', P + 30, by + 46);
			if (t.regular > t.total) {
				ctx.font = '500 28px ' + F; ctx.fillStyle = '#94a3b8';
				var rs = 'নিয়মিত মূল্য ৳' + bn(Math.round(t.regular));
				ctx.fillText(rs, P + 30, by + 92);
				var rsw = ctx.measureText(rs).width;
				ctx.strokeStyle = '#ef4444'; ctx.lineWidth = 2; ctx.beginPath(); ctx.moveTo(P + 30 + ctx.measureText('নিয়মিত মূল্য ').width, by + 82); ctx.lineTo(P + 30 + rsw, by + 82); ctx.stroke();
				ctx.fillStyle = '#059669'; ctx.font = '700 28px ' + F; ctx.fillText('সাশ্রয় ৳' + bn(Math.round(t.save)), P + 30, by + 132);
			}
			ctx.textAlign = 'right';
			ctx.fillStyle = '#475569'; ctx.font = '600 26px ' + F; ctx.fillText('প্যাকেজ মূল্য', W - P - 30, by + 46);
			ctx.fillStyle = '#1e3a8a'; ctx.font = '800 64px ' + F; ctx.fillText('৳' + bn(Math.round(t.total)), W - P - 30, by + 112);
			ctx.fillStyle = '#64748b'; ctx.font = '500 22px ' + F; ctx.fillText('+ ডেলিভারি চার্জ ৳' + bn(Math.round(t.delivery)), W - P - 30, by + 140);
			ctx.textAlign = 'left';

			// Footer hotline
			var fy = H - 130;
			ctx.fillStyle = '#0f1f4d'; ctx.fillRect(0, fy, W, 130);
			ctx.fillStyle = '#f59e0b'; ctx.font = '700 26px ' + F; ctx.textAlign = 'center';
			ctx.fillText('অর্ডার করতে কল / WhatsApp করুন', W / 2, fy + 46);
			ctx.fillStyle = '#fff'; ctx.font = '800 46px ' + F;
			ctx.fillText(bn(cfg.brand.hotline || '01516549037'), W / 2, fy + 102);
			ctx.textAlign = 'left';
		});
	}

	function fmtDate(ymd) {
		if (!ymd) { return ''; }
		var m = ['জানু', 'ফেব্রু', 'মার্চ', 'এপ্রিল', 'মে', 'জুন', 'জুলাই', 'আগস্ট', 'সেপ্টে', 'অক্টো', 'নভে', 'ডিসে'];
		var p = ymd.split('-');
		return bn(parseInt(p[2], 10)) + ' ' + m[parseInt(p[1], 10) - 1];
	}

	/* ---------- Events ---------- */

	root.addEventListener('input', function (e) {
		var p = e.target.getAttribute('data-p');
		if (p) {
			pkg[p] = e.target.type === 'number' ? Number(e.target.value) : e.target.value;
			changed();
			return;
		}
		var pi = e.target.getAttribute('data-i-price');
		if (pi !== null) { pkg.items[pi].price = Number(e.target.value); changed(); return; }
		var qi = e.target.getAttribute('data-i-qty');
		if (qi !== null) { pkg.items[qi].qty = Math.max(1, parseInt(e.target.value, 10) || 1); changed(); }
	});

	document.getElementById('pg-pkg-search').addEventListener('input', A.debounce(function (e) { search(e.target.value.trim()); }, 300));

	root.addEventListener('click', function (e) {
		var add = e.target.closest('[data-add]');
		if (add) {
			var p = JSON.parse(add.getAttribute('data-add'));
			var ex = pkg.items.find(function (it) { return it.product_id === p.id; });
			if (ex) { ex.qty++; } else {
				pkg.items.push({ product_id: p.id, name: p.name, qty: 1, price: p.price, regular: p.regular, thumb: p.thumb });
			}
			renderItems(); changed();
			A.toast('Added: ' + p.name, 'ok');
			return;
		}
		var row = e.target.closest('.pg-pkg-item');
		if (row) {
			var i = parseInt(row.getAttribute('data-i'), 10);
			var mv = e.target.closest('[data-move]');
			if (mv) {
				var j = i + parseInt(mv.getAttribute('data-move'), 10);
				if (j >= 0 && j < pkg.items.length) { var tmp = pkg.items[i]; pkg.items[i] = pkg.items[j]; pkg.items[j] = tmp; renderItems(); changed(); }
				return;
			}
			if (e.target.closest('[data-remove]')) { pkg.items.splice(i, 1); renderItems(); changed(); return; }
		}
		var ld = e.target.closest('[data-load]');
		if (ld) {
			var s = saved.find(function (x) { return x.id === ld.getAttribute('data-load'); });
			if (s) {
				pkg = Object.assign(blank(), JSON.parse(JSON.stringify(s)));
				pkg.items.forEach(function (it) { it.thumb = it.thumb || ''; });
				fillFields(); renderItems(); changed();
			}
			return;
		}
		var dl = e.target.closest('[data-del]');
		if (dl) {
			if (!window.confirm('Delete this saved package?')) { return; }
			A.ajax('pg_packages_delete', { id: dl.getAttribute('data-del') }).then(function (d) { saved = d.packages; renderSaved(); });
			return;
		}
		var act = e.target.closest('[data-act]');
		if (!act) { return; }
		switch (act.getAttribute('data-act')) {
			case 'new':
				pkg = blank(); fillFields(); renderItems(); changed();
				break;
			case 'save':
				A.busy(act, true);
				A.ajax('pg_packages_save', { package: pkg }).then(function (d) {
					saved = d.packages; pkg.id = d.id; saveDraft(); renderSaved(); A.toast('Package saved', 'ok');
				}).catch(function (err) { A.toast(err.message, 'bad'); }).finally(function () { A.busy(act, false); });
				break;
			case 'order':
				if (!pkg.items.length) { A.toast('Add books first.', 'bad'); return; }
				window.PGOrderForm.open({
					items: pkg.items, discount: totals().discount, delivery: Number(pkg.delivery) || 0, source: 'package', label: pkg.title
				});
				break;
			case 'jpg':
				drawBanner();
				setTimeout(function () {
					try { A.downloadCanvas($canvas, 'package-' + (pkg.title || 'offer').replace(/\s+/g, '-').slice(0, 40) + '.jpg'); } catch (err) { A.toast('Export failed: ' + err.message, 'bad'); }
				}, 600);
				break;
		}
	});

	// Drag & drop reordering.
	$items.addEventListener('dragstart', function (e) {
		var r = e.target.closest('.pg-pkg-item');
		if (!r) { return; }
		dragFrom = parseInt(r.getAttribute('data-i'), 10);
		r.classList.add('dragging');
		e.dataTransfer.effectAllowed = 'move';
	});
	$items.addEventListener('dragover', function (e) { e.preventDefault(); });
	$items.addEventListener('drop', function (e) {
		e.preventDefault();
		var r = e.target.closest('.pg-pkg-item');
		if (!r || dragFrom === null) { return; }
		var to = parseInt(r.getAttribute('data-i'), 10);
		var moved = pkg.items.splice(dragFrom, 1)[0];
		pkg.items.splice(to, 0, moved);
		dragFrom = null;
		renderItems(); changed();
	});
	$items.addEventListener('dragend', function () { dragFrom = null; renderItems(); });

	fillFields();
	renderItems();
	renderSaved();
	search('');
	drawBanner();
})();
