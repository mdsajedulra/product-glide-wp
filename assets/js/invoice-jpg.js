/* Product Glide — digital colour invoice (JPG) for WhatsApp */
(function () {
	'use strict';
	var A = window.PGA, cfg = A.cfg, esc = A.esc;
	var F = '"Hind Siliguri", "Noto Sans Bengali", sans-serif';
	var C = { navy: '#1e3a8a', navy2: '#0f1f4d', slate: '#334155', slate2: '#64748b', line: '#e2e8f0', bg: '#f1f5f9', accent: '#f59e0b', ok: '#059669' };

	function barcode(text) {
		if (!window.JsBarcode || !text) { return null; }
		var c = document.createElement('canvas');
		try {
			window.JsBarcode(c, text, { format: 'CODE128', height: 70, width: 2.4, displayValue: false, margin: 0, background: '#ffffff', lineColor: '#0f172a' });
			return c;
		} catch (e) { return null; }
	}

	function draw(o, logo) {
		var W = 1080, P = 56;
		var cv = document.createElement('canvas');
		var ctx = cv.getContext('2d');
		ctx.font = '500 26px ' + F;
		// Pre-measure item rows to size the canvas.
		var rows = o.items.map(function (it) {
			ctx.font = '600 27px ' + F;
			return { it: it, lines: A.wrapText(ctx, it.name, 560, 2) };
		});
		ctx.font = '400 26px ' + F;
		var addrLines = A.wrapText(ctx, o.address, W - 2 * P - 60, 3);
		var itemsH = rows.reduce(function (s, r) { return s + 30 + r.lines.length * 36; }, 0);
		var H = 250 + 70 + (150 + addrLines.length * 36) + 40 + (o.courier && o.courier.tracking ? 190 : 0) + 90 + itemsH + 300 + 140;
		cv.width = W; cv.height = H;
		ctx = cv.getContext('2d');
		ctx.textBaseline = 'alphabetic';

		// Background
		ctx.fillStyle = C.bg; ctx.fillRect(0, 0, W, H);

		// Header band
		var g = ctx.createLinearGradient(0, 0, W, 250);
		g.addColorStop(0, C.navy2); g.addColorStop(1, C.navy);
		ctx.fillStyle = g; ctx.fillRect(0, 0, W, 250);
		ctx.fillStyle = 'rgba(255,255,255,.06)';
		ctx.beginPath(); ctx.arc(W - 80, 30, 180, 0, Math.PI * 2); ctx.fill();

		var y = 60;
		if (logo) {
			var lh = 92, lw = Math.min(380, logo.width * (lh / logo.height));
			ctx.fillStyle = '#fff'; A.roundRect(ctx, P - 14, y - 14, lw + 28, lh + 28, 16); ctx.fill();
			ctx.drawImage(logo, P, y, lw, lh);
		} else {
			ctx.fillStyle = '#fff'; ctx.font = '700 46px ' + F; ctx.fillText(cfg.brand.name || '', P, y + 60);
		}
		ctx.textAlign = 'right';
		ctx.fillStyle = 'rgba(255,255,255,.7)'; ctx.font = '600 24px ' + F; ctx.fillText('INVOICE', W - P, 82);
		ctx.fillStyle = '#fff'; ctx.font = '800 50px ' + F; ctx.fillText('#' + o.number, W - P, 140);
		ctx.fillStyle = 'rgba(255,255,255,.8)'; ctx.font = '500 24px ' + F; ctx.fillText(o.date, W - P, 180);
		ctx.textAlign = 'left';
		ctx.fillStyle = 'rgba(255,255,255,.85)'; ctx.font = '500 24px ' + F;
		ctx.fillText((cfg.brand.hotline ? 'Hotline: ' + cfg.brand.hotline : '') + (cfg.brand.site ? '   ·   ' + cfg.brand.site : ''), P, 222);

		y = 290;
		// Customer card
		var ch = 120 + addrLines.length * 36;
		ctx.fillStyle = '#fff'; A.roundRect(ctx, P, y, W - 2 * P, ch, 20); ctx.fill();
		ctx.fillStyle = C.accent; A.roundRect(ctx, P, y, 10, ch, 5); ctx.fill();
		ctx.fillStyle = C.slate2; ctx.font = '700 20px ' + F; ctx.fillText('DELIVER TO', P + 34, y + 40);
		ctx.fillStyle = '#0f172a'; ctx.font = '700 34px ' + F; ctx.fillText(o.name || '', P + 34, y + 84);
		ctx.textAlign = 'right'; ctx.font = '700 30px ' + F; ctx.fillText(o.phone_bd || o.phone || '', W - P - 30, y + 84); ctx.textAlign = 'left';
		ctx.fillStyle = C.slate; ctx.font = '400 26px ' + F;
		addrLines.forEach(function (l, i) { ctx.fillText(l, P + 34, y + 124 + i * 36); });
		y += ch + 30;

		// Tracking card
		if (o.courier && o.courier.tracking) {
			ctx.fillStyle = '#fff'; A.roundRect(ctx, P, y, W - 2 * P, 160, 20); ctx.fill();
			ctx.fillStyle = C.slate2; ctx.font = '700 20px ' + F; ctx.fillText('STEADFAST TRACKING', P + 34, y + 42);
			ctx.fillStyle = C.navy; ctx.font = '800 34px ' + F; ctx.fillText(o.courier.tracking, P + 34, y + 88);
			ctx.fillStyle = C.slate2; ctx.font = '500 22px ' + F; ctx.fillText('CID ' + o.courier.cid, P + 34, y + 126);
			var bc = barcode(o.courier.tracking);
			if (bc) {
				var bw = Math.min(420, bc.width), bh = 80;
				ctx.drawImage(bc, W - P - 30 - bw, y + 40, bw, bh);
			}
			y += 190;
		}

		// Items table
		var tableTop = y;
		ctx.fillStyle = '#fff'; A.roundRect(ctx, P, y, W - 2 * P, 80 + itemsH, 20); ctx.fill();
		ctx.fillStyle = C.navy; A.roundRect(ctx, P, y, W - 2 * P, 60, 20); ctx.fill();
		ctx.fillRect(P, y + 30, W - 2 * P, 30);
		ctx.fillStyle = '#fff'; ctx.font = '700 22px ' + F;
		ctx.fillText('ITEM', P + 30, y + 40);
		ctx.textAlign = 'center'; ctx.fillText('QTY', 700, y + 40);
		ctx.textAlign = 'right'; ctx.fillText('PRICE', 860, y + 40); ctx.fillText('TOTAL', W - P - 30, y + 40);
		ctx.textAlign = 'left';
		y += 60;
		rows.forEach(function (r, idx) {
			var rh = 30 + r.lines.length * 36;
			if (idx % 2 === 1) { ctx.fillStyle = '#f8fafc'; ctx.fillRect(P, y, W - 2 * P, rh); }
			ctx.fillStyle = '#0f172a'; ctx.font = '600 27px ' + F;
			r.lines.forEach(function (l, i) { ctx.fillText(l, P + 30, y + 44 + i * 36); });
			ctx.font = '600 26px ' + F;
			ctx.textAlign = 'center'; ctx.fillText(String(r.it.qty), 700, y + 44);
			ctx.textAlign = 'right'; ctx.fillStyle = C.slate; ctx.fillText(A.money(r.it.unit, true), 860, y + 44);
			ctx.fillStyle = '#0f172a'; ctx.fillText(A.money(r.it.subtotal, true), W - P - 30, y + 44);
			ctx.textAlign = 'left';
			y += rh;
			ctx.strokeStyle = C.line; ctx.lineWidth = 1; ctx.beginPath(); ctx.moveTo(P + 20, y); ctx.lineTo(W - P - 20, y); ctx.stroke();
		});
		y = tableTop + 80 + itemsH + 30;

		// Totals
		var tx = W - P - 30, lx = 600;
		function tline(label, val, bold) {
			ctx.font = (bold ? '700 ' : '500 ') + '26px ' + F;
			ctx.fillStyle = C.slate; ctx.fillText(label, lx, y);
			ctx.textAlign = 'right'; ctx.fillStyle = '#0f172a'; ctx.fillText(val, tx, y); ctx.textAlign = 'left';
			y += 42;
		}
		tline('Subtotal', A.money(o.subtotal));
		if (o.discount > 0) { tline('Discount', '-' + A.money(o.discount)); }
		tline('Delivery charge', A.money(o.shipping));
		tline('Total', A.money(o.total), true);
		y += 6;
		var cod = o.courier ? o.courier.cod : o.cod;
		ctx.fillStyle = C.navy; A.roundRect(ctx, lx - 24, y - 6, tx - lx + 54, 92, 18); ctx.fill();
		ctx.fillStyle = 'rgba(255,255,255,.8)'; ctx.font = '700 22px ' + F; ctx.fillText('CASH ON DELIVERY', lx, y + 32);
		ctx.textAlign = 'right'; ctx.fillStyle = '#fff'; ctx.font = '800 44px ' + F; ctx.fillText(A.money(cod), tx, y + 66); ctx.textAlign = 'left';
		ctx.fillStyle = C.slate2; ctx.font = '500 22px ' + F; ctx.fillText(o.payment || '', P, y + 32);

		// Footer
		var fy = H - 110;
		ctx.fillStyle = C.navy2; ctx.fillRect(0, fy, W, 110);
		ctx.fillStyle = '#fff'; ctx.font = '600 26px ' + F; ctx.textAlign = 'center';
		ctx.fillText(cfg.brand.footer || 'ধন্যবাদ!', W / 2, fy + 48);
		ctx.fillStyle = 'rgba(255,255,255,.75)'; ctx.font = '500 22px ' + F;
		ctx.fillText([cfg.brand.hotline ? 'Hotline / WhatsApp: ' + cfg.brand.hotline : '', cfg.brand.site || ''].filter(Boolean).join('   ·   '), W / 2, fy + 86);
		ctx.textAlign = 'left';
		return cv;
	}

	function open(o) {
		var m = A.modal('Invoice #' + o.number, '<div class="pg-canvas-wrap"><div class="pg-muted">Drawing…</div></div>' +
			'<div class="pg-form-actions"><button class="pg-btn" data-dl disabled>Download JPG</button><button class="pg-btn pg-btn-ok" data-wa disabled>Share via WhatsApp</button></div>');
		Promise.all([A.fontsReady(), A.loadImage(cfg.brand.logo)]).then(function (res) {
			var cv = draw(o, res[1]);
			var wrap = m.body.querySelector('.pg-canvas-wrap');
			wrap.innerHTML = '';
			wrap.appendChild(cv);
			var name = 'invoice-' + o.number + '.jpg';
			var dl = m.body.querySelector('[data-dl]'), wa = m.body.querySelector('[data-wa]');
			dl.disabled = false; wa.disabled = false;
			dl.addEventListener('click', function () {
				try { A.downloadCanvas(cv, name); } catch (e) { A.toast('Logo blocks export (CORS). Set a logo from this site in Settings.', 'bad'); }
			});
			wa.addEventListener('click', function () {
				var text = 'আসসালামু আলাইকুম ' + (o.name || '') + ', আপনার অর্ডার #' + o.number + ' এর ইনভয়েস।' +
					(o.courier && o.courier.tracking ? ' ট্র্যাকিং: ' + o.courier.tracking + ' ' + o.courier.url : '');
				try { A.shareCanvas(cv, name, text, o.phone_bd); } catch (e) { A.toast('Could not export the image.', 'bad'); }
			});
		});
	}

	window.PGInvoiceJPG = { open: open, draw: draw };
	void esc;
})();
