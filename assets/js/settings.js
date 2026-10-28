/* Product Glide — settings page: logo picker + WhatsApp template mappings */
(function () {
	'use strict';
	var A = window.PGA, esc = A.esc;

	var pick = document.getElementById('pg-pick-logo');
	if (pick && window.wp && window.wp.media) {
		pick.addEventListener('click', function () {
			var frame = window.wp.media({ title: 'Choose logo', library: { type: 'image' }, multiple: false });
			frame.on('select', function () {
				var a = frame.state().get('selection').first().toJSON();
				document.getElementById('pg-logo-url').value = (a.sizes && a.sizes.medium ? a.sizes.medium.url : a.url);
			});
			frame.open();
		});
	}
	Array.prototype.forEach.call(document.querySelectorAll('[data-pick-image]'), function (btn) {
		if (!window.wp || !window.wp.media) { return; }
		btn.addEventListener('click', function () {
			var frame = window.wp.media({ title: 'Choose image', library: { type: 'image' }, multiple: false });
			frame.on('select', function () {
				var a = frame.state().get('selection').first().toJSON();
				document.getElementById(btn.getAttribute('data-pick-image')).value = a.url;
			});
			frame.open();
		});
	});

	/* OpenRouter: pick a vision model + test the connection */
	var orLoad = document.getElementById('pg-or-load');
	if (orLoad) {
		var orInput = document.getElementById('pg-or_model');
		var orBox = document.getElementById('pg-or-models');
		var all = [];
		var renderModels = function (q) {
			q = (q || '').toLowerCase();
			var list = all.filter(function (m) { return !q || m.id.toLowerCase().indexOf(q) !== -1 || m.name.toLowerCase().indexOf(q) !== -1; }).slice(0, 150);
			orBox.innerHTML = '<input type="search" class="pg-or-filter" placeholder="Filter models (e.g. gemini, qwen, free)…" value="' + esc(q) + '">' +
				'<div class="pg-or-list">' + (list.length ? list.map(function (m) {
					var price = m.free ? '<span class="pg-badge pg-badge-ok">FREE</span>' : '<span class="pg-badge">$' + m.in_1m + ' / $' + m.out_1m + ' per 1M</span>';
					return '<button type="button" class="pg-or-item' + (m.id === orInput.value ? ' is-active' : '') + '" data-id="' + esc(m.id) + '"><b>' + esc(m.name) + '</b><code>' + esc(m.id) + '</code>' + price + '</button>';
				}).join('') : '<div class="pg-muted">No model matches.</div>') + '</div>';
			var f = orBox.querySelector('.pg-or-filter');
			f.focus(); f.setSelectionRange(f.value.length, f.value.length);
		};
		orLoad.addEventListener('click', function () {
			A.busy(orLoad, true);
			A.ajax('pg_ai_models', {}).then(function (d) {
				all = d.models;
				renderModels('');
				A.toast(all.length + ' vision models loaded (' + all.filter(function (m) { return m.free; }).length + ' free)', 'ok');
			}).catch(function (e) { A.toast(e.message, 'bad'); }).finally(function () { A.busy(orLoad, false); });
		});
		orBox.addEventListener('input', function (e) { if (e.target.classList.contains('pg-or-filter')) { renderModels(e.target.value); } });
		orBox.addEventListener('click', function (e) {
			var b = e.target.closest('.pg-or-item');
			if (!b) { return; }
			orInput.value = b.getAttribute('data-id');
			orBox.querySelectorAll('.pg-or-item').forEach(function (x) { x.classList.toggle('is-active', x === b); });
			A.toast('Selected ' + orInput.value + ' — click Save settings.', 'ok');
		});
		var orTest = document.getElementById('pg-or-test');
		orTest.addEventListener('click', function () {
			var out = document.getElementById('pg-or-test-out');
			out.textContent = 'Testing saved key + model…';
			A.busy(orTest, true);
			A.ajax('pg_ai_test', {}).then(function (d) { out.textContent = d.message; out.style.color = '#166534'; })
				.catch(function (e) { out.textContent = e.message; out.style.color = '#b91c1c'; })
				.finally(function () { A.busy(orTest, false); });
		});
	}

	var box = document.getElementById('pg-wa-map');
	if (!box) { return; }
	var rows = JSON.parse(box.getAttribute('data-rows') || '[]');
	var statuses = JSON.parse(box.getAttribute('data-statuses') || '{}');
	var templates = [];
	var $rows = document.getElementById('pg-wa-rows');

	function tplOptions(sel) {
		var names = templates.map(function (t) { return t.name + '|' + t.language; });
		var opts = '<option value="">— template —</option>';
		templates.forEach(function (t) {
			var v = t.name + '|' + t.language;
			opts += '<option value="' + esc(v) + '"' + (v === sel ? ' selected' : '') + '>' + esc(t.name + ' (' + t.language + ', ' + t.params + ' vars)') + '</option>';
		});
		if (sel && names.indexOf(sel) === -1) { opts += '<option value="' + esc(sel) + '" selected>' + esc(sel.replace('|', ' (') + ')') + '</option>'; }
		return opts;
	}

	function render() {
		if (!rows.length) { $rows.innerHTML = '<div class="pg-muted">No mappings yet. Load templates, then add a mapping.</div>'; return; }
		$rows.innerHTML = rows.map(function (r, i) {
			var st = Object.keys(statuses).map(function (k) { return '<option value="' + esc(k) + '"' + (k === r.trigger_status ? ' selected' : '') + '>' + esc(statuses[k]) + '</option>'; }).join('');
			var tpl = templates.filter(function (t) { return t.name === r.template_name && t.language === r.language; })[0];
			return '<div class="pg-card" style="background:#f8fafc" data-i="' + i + '"><div class="pg-grid pg-grid-3">' +
				'<div class="pg-field"><label>When status becomes</label><select data-k="trigger_status">' + st + '</select></div>' +
				'<div class="pg-field"><label>Template</label><select data-k="tpl">' + tplOptions(r.template_name ? r.template_name + '|' + r.language : '') + '</select></div>' +
				'<div class="pg-field"><label>Variables in order</label><input type="text" data-k="variables" value="' + esc(r.variables) + '" placeholder="name,order_id,items,total,tracking"></div></div>' +
				(tpl ? '<div class="pg-muted" style="white-space:pre-wrap;margin-bottom:8px">' + esc(tpl.body) + '</div>' : '') +
				'<label class="pg-check"><input type="checkbox" data-k="enabled"' + (Number(r.enabled) ? ' checked' : '') + '> Enabled</label> ' +
				'<label class="pg-check"><input type="checkbox" data-k="has_header_text"' + (Number(r.has_header_text) ? ' checked' : '') + '> Header has a variable (uses the first one)</label> ' +
				'<button type="button" class="pg-btn pg-btn-sm pg-btn-bad" data-rm>Remove</button></div>';
		}).join('');
	}

	$rows.addEventListener('change', function (e) {
		var c = e.target.closest('[data-i]'); if (!c) { return; }
		var r = rows[c.getAttribute('data-i')], k = e.target.getAttribute('data-k');
		if (k === 'tpl') {
			var p = e.target.value.split('|');
			r.template_name = p[0] || ''; r.language = p[1] || 'bn';
			var t = templates.filter(function (x) { return x.name === r.template_name && x.language === r.language; })[0];
			if (t) {
				r.has_header_text = t.header_text ? 1 : 0;
				if (!r.variables) { r.variables = ['name', 'order_id', 'items', 'total', 'tracking'].slice(0, t.params).join(','); }
			}
			render();
		} else if (k === 'enabled' || k === 'has_header_text') { r[k] = e.target.checked ? 1 : 0; }
		else if (k) { r[k] = e.target.value; }
	});
	$rows.addEventListener('input', function (e) {
		var c = e.target.closest('[data-i]');
		if (c && e.target.getAttribute('data-k') === 'variables') { rows[c.getAttribute('data-i')].variables = e.target.value; }
	});
	$rows.addEventListener('click', function (e) {
		if (!e.target.closest('[data-rm]')) { return; }
		rows.splice(e.target.closest('[data-i]').getAttribute('data-i'), 1); render();
	});

	document.getElementById('pg-wa-add').addEventListener('click', function () {
		rows.push({ trigger_status: 'courier_sent', template_name: '', language: 'bn', variables: 'name,order_id,tracking', has_header_text: 0, enabled: 1 });
		render();
	});
	document.getElementById('pg-wa-load').addEventListener('click', function () {
		var b = this;
		A.busy(b, true);
		A.ajax('pg_wa_templates', { force: 1 }).then(function (d) {
			templates = d.templates; render(); A.toast(templates.length + ' approved templates loaded', 'ok');
		}).catch(function (e) { A.toast(e.message, 'bad'); }).finally(function () { A.busy(b, false); });
	});
	document.getElementById('pg-wa-save').addEventListener('click', function () {
		var b = this;
		A.busy(b, true);
		A.ajax('pg_wa_save_map', { rows: rows }).then(function (d) { rows = d.rows; render(); A.toast(d.message, 'ok'); })
			.catch(function (e) { A.toast(e.message, 'bad'); }).finally(function () { A.busy(b, false); });
	});

	render();
	if (rows.length) {
		A.ajax('pg_wa_templates', {}).then(function (d) { templates = d.templates; render(); }).catch(function () {});
	}
})();
