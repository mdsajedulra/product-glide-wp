/* Product Glide — settings page: logo picker + WhatsApp template mappings */
(function () {
	'use strict';
	var A = window.PGA, esc = A.esc;

	/* ---------- Steadfast daily pickup ---------- */
	var pkNow = document.getElementById('pg-sf-pickup-now');
	if (pkNow) {
		pkNow.addEventListener('click', function () {
			var out = document.getElementById('pg-sf-pickup-out');
			A.busy(pkNow, true);
			out.innerHTML = '';
			A.ajax('pg_sf_pickup_now', {}).then(function (d) { out.innerHTML = '<div class="pg-alert pg-alert-ok">' + esc(d.message) + '</div>'; })
				.catch(function (e) { out.innerHTML = '<div class="pg-alert pg-alert-bad">' + esc(e.message) + '</div>'; })
				.finally(function () { A.busy(pkNow, false); });
		});
	}

	/* ---------- WhatsApp quick connect ---------- */
	var waQ = document.getElementById('pg-wa-quick');
	if (waQ) {
		var $go = document.getElementById('pg-wa-q-go'), $out = document.getElementById('pg-wa-q-out'), $st = document.getElementById('pg-wa-q-status'), $ch = document.getElementById('pg-wa-q-choose');
		var line = function (ok, text) { return '<li class="' + (ok ? 'is-ok' : 'is-bad') + '"><span>' + (ok ? '✓' : '!') + '</span>' + esc(text) + '</li>'; };
		var paintStatus = function (s) {
			if (!s || !s.connected) { $st.innerHTML = ''; return; }
			var tpl = '', mk = function (label) { return ' <button type="button" class="pg-btn pg-btn-sm pg-btn-primary" id="pg-wa-q-tpl">' + label + '</button>'; };
			if (s.tpl === 'approved') { tpl = line(true, 'Template in use: ' + s.tpl_name + (s.buttons ? ' — with Confirm / Cancel buttons' : '')); }
			if (s.v2 === 'pending' || s.v2 === 'in_review') { tpl += '<li class="is-wait"><span>…</span>Button version is waiting for Meta approval (minutes to a few hours). The current message keeps going out; this page switches over by itself.</li>'; }
			else if (s.v2 === 'rejected') { tpl += '<li class="is-bad"><span>!</span>Meta rejected the button version' + (s.tpl_reason ? ' (' + esc(s.tpl_reason) + ')' : '') + '.' + mk('Try again') + '</li>'; }
			else if (s.can_upgrade && s.tpl === 'approved' && !s.buttons) { tpl += '<li class="is-todo"><span>+</span>Add "কনফার্ম করছি / বাতিল করতে চাই" buttons to the message' + mk('Create button version') + '</li>'; }
			else if (s.can_upgrade && !s.tpl) { tpl += '<li class="is-todo"><span>3</span>Create the order-confirmation template' + mk('Create & send for approval') + '</li>'; }
			$st.innerHTML = '<ul class="pg-wa-q-list">' +
				line(true, 'Connected' + (s.phone ? ': ' + s.phone : '')) +
				line(s.hook, s.hook ? 'Webhook connected (replies + no-WhatsApp detection)' : 'Webhook not connected — add the App Secret and click Reconnect') +
				tpl +
				line(s.auto && s.map, s.auto && s.map ? 'New-order message is ON' : 'New-order message turns on by itself when the template is approved') +
				'</ul>' +
				(s.map ? '<div class="pg-wa-q-test"><input type="tel" id="pg-wa-q-phone" placeholder="Your mobile, e.g. 017…"><button type="button" class="pg-btn" id="pg-wa-q-send">Send a test message</button></div>' : '');
			var tb = document.getElementById('pg-wa-q-tpl');
			if (tb) {
				tb.addEventListener('click', function () {
					A.busy(tb, true);
					A.ajax('pg_wa_setup_make_tpl', {}).then(function (d) { A.toast(d.message, 'ok'); paintStatus(d.status); poll(); })
						.catch(function (e) { A.toast(e.message, 'bad'); A.busy(tb, false); });
				});
			}
			var sb = document.getElementById('pg-wa-q-send');
			if (sb) {
				sb.addEventListener('click', function () {
					A.busy(sb, true);
					A.ajax('pg_wa_setup_test', { phone: document.getElementById('pg-wa-q-phone').value }).then(function (d) { A.toast(d.message, 'ok'); })
						.catch(function (e) { A.toast(e.message, 'bad'); }).finally(function () { A.busy(sb, false); });
				});
			}
			if (s.v2 === 'pending' || s.v2 === 'in_review') { poll(); }
		};
		var timer = null;
		var poll = function () {
			clearTimeout(timer);
			timer = setTimeout(function () { A.ajax('pg_wa_setup_status', {}).then(function (d) { paintStatus(d.status); }).catch(function () {}); }, 60000);
		};
		var connect = function (extra) {
			A.busy($go, true);
			$out.innerHTML = '';
			var data = Object.assign({ token: document.getElementById('pg-wa-q-token').value.trim(), secret: document.getElementById('pg-wa-q-secret').value.trim() }, extra || {});
			A.ajax('pg_wa_setup_connect', data).then(function (d) {
				$out.innerHTML = (d.steps || []).map(function (x) { return line(x[0] === 'ok', x[1]); }).join('');
				if (d.choose) {
					$ch.innerHTML = '<div class="pg-field"><label>This account has more than one number — which one?</label><select id="pg-wa-q-num">' +
						d.choose.map(function (n) { return '<option value="' + esc(n.id) + '">' + esc(n.phone + ' · ' + n.name) + '</option>'; }).join('') + '</select></div>';
					$go.textContent = 'Use this number';
					return;
				}
				$ch.innerHTML = '';
				document.getElementById('pg-wa-q-token').value = '';
				document.getElementById('pg-wa-q-secret').value = '';
				paintStatus(d.status);
				A.toast('WhatsApp connected', 'ok');
			}).catch(function (e) {
				$out.innerHTML = line(false, e.message);
			}).finally(function () { A.busy($go, false); });
		};
		$go.addEventListener('click', function () {
			var sel = document.getElementById('pg-wa-q-num');
			connect(sel ? { phone_id: sel.value } : {});
		});
		if ($st.textContent.trim()) {
			A.ajax('pg_wa_setup_status', {}).then(function (d) { paintStatus(d.status); }).catch(function (e) { $st.innerHTML = '<p class="pg-alert pg-alert-bad">' + esc(e.message) + '</p>'; });
		}
	}

	/* ---------- Carrybee ---------- */
	var cbTest = document.getElementById('pg-cb-test');
	if (cbTest) {
		cbTest.addEventListener('click', function () {
			var out = document.getElementById('pg-cb-test-out');
			A.busy(cbTest, true);
			out.textContent = '';
			A.ajax('pg_cb_test', {}).then(function (d) { out.textContent = '✓ ' + d.message; renderStores(d.data); })
				.catch(function (e) { out.textContent = '✕ ' + e.message; })
				.finally(function () { A.busy(cbTest, false); });
		});
	}
	function renderStores(list) {
		var box = document.getElementById('pg-cb-store-list'), input = document.getElementById('pg-cb_store_id');
		if (!box || !input) { return; }
		if (!list || !list.length) { box.innerHTML = '<p class="pg-muted">No stores yet — create one below.</p>'; return; }
		box.innerHTML = '<div class="pg-cb-stores">' + list.map(function (st) {
			var bad = !st.is_active || st.is_approved === false;
			return '<label class="pg-check pg-cb-store"><input type="radio" name="pg-cb-store-pick" value="' + esc(st.id) + '"' + (String(st.id) === input.value ? ' checked' : '') + '> ' +
				'<span><b>' + esc(st.name) + '</b>' + (st.is_default_pickup_store ? ' <span class="pg-badge pg-badge-info">default pickup</span>' : '') +
				(bad ? ' <span class="pg-badge pg-badge-warn">' + (st.is_approved === false ? 'waiting for approval' : 'inactive') + '</span>' : '') +
				'<br><small class="pg-muted">' + esc(st.address || '') + ' · ' + esc(st.contact_person_number || '') + ' · ID ' + esc(st.id) + '</small></span></label>';
		}).join('') + '</div><p class="pg-muted">Pick one, then press Save settings.</p>';
		box.querySelectorAll('input[name="pg-cb-store-pick"]').forEach(function (r) {
			r.addEventListener('change', function () { input.value = r.value; });
		});
		if (!input.value && list.length === 1) { input.value = list[0].id; box.querySelector('input').checked = true; }
	}
	var cbStores = document.getElementById('pg-cb-stores');
	if (cbStores) {
		cbStores.addEventListener('click', function () {
			A.busy(cbStores, true);
			A.ajax('pg_cb_stores', {}).then(function (d) { renderStores(d.data); })
				.catch(function (e) { A.toast(e.message, 'bad'); })
				.finally(function () { A.busy(cbStores, false); });
		});
	}
	var cbForm = document.getElementById('pg-cb-store-form');
	if (cbForm) {
		var opened = false;
		cbForm.closest('details').addEventListener('toggle', function () {
			if (opened || !this.open) { return; }
			opened = true;
			cbForm.innerHTML =
				'<div class="pg-grid pg-grid-2">' +
					'<div class="pg-field"><label>Store name (3–30)</label><input type="text" data-s="name" maxlength="30"></div>' +
					'<div class="pg-field"><label>Contact person (3–30)</label><input type="text" data-s="contact_person_name" maxlength="30"></div>' +
					'<div class="pg-field"><label>Contact phone</label><input type="tel" data-s="contact_person_number"></div>' +
					'<div class="pg-field"><label>Address (3–100)</label><input type="text" data-s="address" maxlength="100"></div>' +
				'</div>' +
				'<div class="pg-grid pg-grid-3">' +
					'<div class="pg-field"><label>City</label><select data-s="city_id"><option value="">Loading…</option></select></div>' +
					'<div class="pg-field"><label>Zone</label><select data-s="zone_id" disabled><option value="">—</option></select></div>' +
					'<div class="pg-field"><label>Area</label><select data-s="area_id" disabled><option value="">—</option></select></div>' +
				'</div>' +
				'<button type="button" class="pg-btn pg-btn-primary" data-create>Create store</button> <span class="pg-muted" data-out></span>';
			var sel = function (k) { return cbForm.querySelector('[data-s="' + k + '"]'); };
			var fill = function (el, list) {
				el.innerHTML = '<option value="">Choose…</option>' + list.map(function (x) { return '<option value="' + x.id + '">' + esc(x.name) + '</option>'; }).join('');
				el.disabled = false;
			};
			A.ajax('pg_cb_cities', {}).then(function (d) { fill(sel('city_id'), d.data); }).catch(function (e) { A.toast(e.message, 'bad'); });
			sel('city_id').addEventListener('change', function () {
				sel('zone_id').disabled = sel('area_id').disabled = true;
				if (!this.value) { return; }
				A.ajax('pg_cb_zones', { city_id: this.value }).then(function (d) { fill(sel('zone_id'), d.data); }).catch(function (e) { A.toast(e.message, 'bad'); });
			});
			sel('zone_id').addEventListener('change', function () {
				sel('area_id').disabled = true;
				if (!this.value) { return; }
				A.ajax('pg_cb_areas', { city_id: sel('city_id').value, zone_id: this.value }).then(function (d) { fill(sel('area_id'), d.data); }).catch(function (e) { A.toast(e.message, 'bad'); });
			});
			cbForm.querySelector('[data-create]').addEventListener('click', function () {
				var b = this, data = {};
				cbForm.querySelectorAll('[data-s]').forEach(function (el) { data[el.getAttribute('data-s')] = el.value.trim(); });
				if (!data.name || !data.contact_person_name || !data.contact_person_number || !data.address || !data.city_id || !data.zone_id || !data.area_id) {
					A.toast('Fill in every field.', 'bad'); return;
				}
				A.busy(b, true);
				A.ajax('pg_cb_create_store', data).then(function (d) {
					cbForm.querySelector('[data-out]').textContent = d.message;
					A.toast(d.message, 'ok');
					return A.ajax('pg_cb_stores', {}).then(function (x) { renderStores(x.data); });
				}).catch(function (e) { A.toast(e.message, 'bad'); }).finally(function () { A.busy(b, false); });
			});
		});
	}

	var odBtn = document.getElementById('pg-od-import');
	if (odBtn) {
		odBtn.addEventListener('click', function () {
			var out = document.getElementById('pg-od-import-out');
			A.busy(odBtn, true);
			A.ajax('pg_leads_import_od', {}).then(function (d) {
				out.textContent = d.message + (d.left ? ' (' + d.left + ' left — press again)' : '');
				A.toast(d.message, 'ok');
				if (!d.left) { odBtn.remove(); }
			}).catch(function (e) { A.toast(e.message, 'bad'); }).finally(function () { A.busy(odBtn, false); });
		});
	}

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
