/* Product Glide — product browser used on the New order and Edit order screens.
 *
 *   var picker = PGProductPicker.mount(el, {
 *       onAdd: function (product) {},          // called when a product is tapped
 *       inOrder: function (productId) { return qty; }  // qty already in the order (for the badge)
 *   });
 *   picker.refresh();   // re-draw "in order" badges after the order changes
 */
(function () {
	'use strict';
	var A = window.PGA, esc = A.esc;
	var catCache = null;
	var PER_PAGE = 24;

	var ICON_SEARCH = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/></svg>';
	var ICON_X = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg>';
	var ICON_PLUS = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>';

	function mount(el, opts) {
		opts = opts || {};
		var st = { q: '', cat: 0, sort: 'popular', page: 1, pages: 1, total: 0, products: [], loading: false, req: 0 };

		el.classList.add('pg-pp');
		el.innerHTML =
			'<div class="pg-pp-bar">' +
				'<label class="pg-pp-search">' + ICON_SEARCH +
					'<input type="search" data-pp-q placeholder="Search by name, SKU or ID…" autocomplete="off" enterkeyhint="search">' +
					'<button type="button" class="pg-pp-clear" data-pp-clear hidden aria-label="Clear search">' + ICON_X + '</button>' +
				'</label>' +
				'<select data-pp-sort aria-label="Sort products">' +
					'<option value="popular">Best selling</option>' +
					'<option value="new">Newest</option>' +
					'<option value="name">Name A–Z</option>' +
					'<option value="price_asc">Price: low → high</option>' +
					'<option value="price_desc">Price: high → low</option>' +
				'</select>' +
			'</div>' +
			'<div class="pg-pp-cats" data-pp-cats></div>' +
			'<div class="pg-pp-meta" data-pp-meta></div>' +
			'<div class="pg-pp-grid" data-pp-grid></div>' +
			'<div class="pg-pp-foot" data-pp-foot></div>';

		var $q = el.querySelector('[data-pp-q]'), $clear = el.querySelector('[data-pp-clear]');
		var $grid = el.querySelector('[data-pp-grid]'), $foot = el.querySelector('[data-pp-foot]');
		var $cats = el.querySelector('[data-pp-cats]'), $meta = el.querySelector('[data-pp-meta]');

		function qtyOf(id) { return opts.inOrder ? (opts.inOrder(id) || 0) : 0; }

		function renderCats() {
			if (!catCache || !catCache.length) { $cats.hidden = true; return; }
			$cats.hidden = false;
			$cats.innerHTML = '<button type="button" class="pg-pp-chip' + (st.cat ? '' : ' is-on') + '" data-pp-cat="0">All</button>' +
				catCache.map(function (c) {
					return '<button type="button" class="pg-pp-chip' + (st.cat === c.id ? ' is-on' : '') + '" data-pp-cat="' + c.id + '">' + esc(c.name) + ' <span>' + c.count + '</span></button>';
				}).join('');
		}

		function card(p, i) {
			var q = qtyOf(p.id);
			var sale = p.regular && p.regular > p.price;
			var stock = !p.instock ? '<span class="pg-pp-stock is-out">Out of stock</span>'
				: (p.stock !== null && p.stock !== undefined ? (p.stock <= 0 ? '<span class="pg-pp-stock is-low">Backorder</span>' : '<span class="pg-pp-stock' + (p.stock <= 5 ? ' is-low' : '') + '">' + p.stock + ' in stock</span>') : '');
			var status = p.status !== 'publish' ? '<span class="pg-pp-tag">' + esc(p.status.charAt(0).toUpperCase() + p.status.slice(1)) + '</span>' : '';
			return '<button type="button" class="pg-pp-card' + (q ? ' is-in' : '') + (!p.instock ? ' is-out' : '') + '" data-pp-i="' + i + '" title="' + esc(p.name) + '">' +
				'<span class="pg-pp-img"><img src="' + esc(p.thumb) + '" alt="" loading="lazy">' +
					(q ? '<span class="pg-pp-qty">✓ ' + q + '</span>' : '') + status + '</span>' +
				'<span class="pg-pp-body">' +
					'<span class="pg-pp-name">' + esc(p.name) + '</span>' +
					'<span class="pg-pp-price"><b>' + A.money(p.price) + '</b>' + (sale ? '<s>' + A.money(p.regular) + '</s>' : '') + '</span>' +
					stock +
				'</span>' +
				'<span class="pg-pp-add">' + ICON_PLUS + (q ? 'Add more' : 'Add') + '</span>' +
			'</button>';
		}

		function renderGrid() {
			if (st.loading && !st.products.length) {
				$grid.innerHTML = new Array(8).join('<div class="pg-pp-card pg-pp-skel"><span class="pg-pp-img"></span><span class="pg-pp-body"><i></i><i></i></span></div>');
				return;
			}
			$grid.classList.toggle('is-loading', st.loading);
			$grid.innerHTML = st.products.length ? st.products.map(card).join('') :
				'<div class="pg-pp-empty"><b>No products found</b><span>' + (st.q ? 'Try another word, or clear the search.' : 'Nothing in this category.') + '</span></div>';
		}

		function renderFoot() {
			var from = st.total ? (st.page - 1) * PER_PAGE + 1 : 0, to = Math.min(st.total, st.page * PER_PAGE);
			$meta.textContent = st.total ? 'Showing ' + from + '–' + to + ' of ' + st.total + ' products' + (st.q ? ' for “' + st.q + '”' : '') : '';
			if (st.pages <= 1) { $foot.innerHTML = ''; return; }
			var nums = [], p;
			for (p = 1; p <= st.pages; p++) {
				if (p === 1 || p === st.pages || Math.abs(p - st.page) <= 1) { nums.push(p); }
				else if (nums[nums.length - 1] !== '…') { nums.push('…'); }
			}
			$foot.innerHTML =
				'<button type="button" class="pg-pp-pg" data-pp-page="' + (st.page - 1) + '"' + (st.page <= 1 ? ' disabled' : '') + ' aria-label="Previous page">‹</button>' +
				nums.map(function (n) {
					return n === '…' ? '<span class="pg-pp-dots">…</span>' :
						'<button type="button" class="pg-pp-pg' + (n === st.page ? ' is-on' : '') + '" data-pp-page="' + n + '">' + n + '</button>';
				}).join('') +
				'<button type="button" class="pg-pp-pg" data-pp-page="' + (st.page + 1) + '"' + (st.page >= st.pages ? ' disabled' : '') + ' aria-label="Next page">›</button>';
		}

		function load() {
			var id = ++st.req;
			st.loading = true;
			renderGrid();
			var params = { q: st.q, page: st.page, per_page: PER_PAGE, cat: st.cat, sort: st.sort };
			if (!catCache) { params.cats = 1; }
			A.ajax('pg_products_search', params).then(function (d) {
				if (id !== st.req) { return; }
				st.loading = false;
				st.products = d.products || [];
				st.total = d.total || st.products.length;
				st.pages = d.pages || 1;
				if (d.cats) { catCache = d.cats; }
				renderCats();
				renderGrid();
				renderFoot();
				$grid.scrollTop = 0;
			}).catch(function (err) {
				if (id !== st.req) { return; }
				st.loading = false;
				$grid.innerHTML = '<div class="pg-pp-empty"><b>Could not load products</b><span>' + esc(err.message || '') + '</span></div>';
			});
		}

		$q.addEventListener('input', A.debounce(function () {
			st.q = $q.value.trim();
			$clear.hidden = !$q.value;
			st.page = 1;
			load();
		}, 300));
		$q.addEventListener('keydown', function (e) {
			// Enter adds the first result straight away.
			if (e.key === 'Enter') {
				e.preventDefault();
				if (st.products.length && st.q && !st.loading) { add(0); }
			}
		});
		$clear.addEventListener('click', function () {
			$q.value = ''; st.q = ''; $clear.hidden = true; st.page = 1; load(); $q.focus();
		});
		el.querySelector('[data-pp-sort]').addEventListener('change', function () {
			st.sort = this.value; st.page = 1; load();
		});

		function add(i) {
			var p = st.products[i];
			if (!p) { return; }
			if (opts.onAdd) { opts.onAdd(p); }
			renderGrid();
		}

		el.addEventListener('click', function (e) {
			var c = e.target.closest('[data-pp-cat]');
			if (c) { st.cat = parseInt(c.getAttribute('data-pp-cat'), 10) || 0; st.page = 1; renderCats(); load(); return; }
			var pg = e.target.closest('[data-pp-page]');
			if (pg && !pg.disabled) { st.page = parseInt(pg.getAttribute('data-pp-page'), 10); load(); return; }
			var cd = e.target.closest('[data-pp-i]');
			if (cd) { add(parseInt(cd.getAttribute('data-pp-i'), 10)); }
		});

		renderCats();
		load();

		return {
			refresh: renderGrid,
			focus: function () { $q.focus(); }
		};
	}

	window.PGProductPicker = { mount: mount };
})();
