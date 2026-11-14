/**
 * WooCommerce Add / Edit order screen: while the address is typed, fill the
 * district (State) and thana (City) the same way the Product Glide New order
 * form does, and offer the district's thanas as suggestions in the City box.
 */
(function ($) {
	'use strict';
	if (!window.PGWCGeo) { return; }
	var C = window.PGWCGeo, GEO = null, byCode = {};

	function post(action, data) {
		return $.post(C.ajax, $.extend({ action: action, nonce: C.nonce }, data || {})).then(function (r) {
			if (!r || !r.success) { return $.Deferred().reject(r).promise(); }
			return r.data;
		});
	}

	function loadGeo() {
		if (GEO) { return $.Deferred().resolve(GEO).promise(); }
		return post('pg_geo').then(function (d) {
			GEO = d;
			(d.districts || []).forEach(function (x) { if (x.c) { byCode[x.c] = x; } });
			return d;
		});
	}

	function setup(type) {
		var $a1 = $('#_' + type + '_address_1');
		if (!$a1.length) { return; }
		var $a2 = $('#_' + type + '_address_2');
		var st = { cityAuto: false, cityManual: false, stateManual: false, lastAuto: '' };
		var listId = 'pg-thanas-' + type;
		var $hint = $('<p class="pg-wcgeo-hint" style="margin:4px 0 0;font-size:12px;color:#50575e;clear:both"></p>');
		$a1.closest('p, .form-field').after($hint);
		if (!$('#' + listId).length) { $('body').append('<datalist id="' + listId + '"></datalist>'); }

		function $state() { return $('#_' + type + '_state'); }
		function $city() { return $('#_' + type + '_city'); }
		function $country() { return $('#_' + type + '_country'); }

		function fillList() {
			var d = byCode[$state().val()];
			$('#' + listId).html(d ? d.t.map(function (t) {
				return '<option value="' + $('<i>').text(t[0]).html() + '">' + $('<i>').text(t[1]).html() + '</option>';
			}).join('') : '');
			$city().attr('list', listId).attr('autocomplete', 'off');
		}

		function hint(g) {
			if (!g || g.district == null) { $hint.text(''); return; }
			var txt = 'Product Glide: ' + g.district_bn + (g.thana_bn ? ' › ' + g.thana_bn : '') +
				(g.zone_label ? ' · ' + g.zone_label + ' ৳' + Math.round(g.fee) : '');
			if (!g.thana_en) { txt += ' · থানা পাওয়া যায়নি, City ঘরে তালিকা থেকে বেছে নিন'; }
			$hint.text(txt);
		}

		function applyState(code) {
			var $s = $state();
			if ($s.val() === code) { return; }
			$s.val(code).trigger('change');
		}

		function apply(g) {
			hint(g);
			if (!g || !g.state) { return; }
			if (!st.stateManual) {
				if ($country().val() !== 'BD') {
					$country().val('BD').trigger('change');
					// WooCommerce rebuilds the State field after a country change.
					setTimeout(function () { applyState(g.state); fillList(); fillCity(g); }, 400);
					return;
				}
				applyState(g.state);
			}
			fillList();
			fillCity(g);
		}

		function fillCity(g) {
			if (!g.thana_en || st.cityManual || (st.stateManual && $state().val() !== g.state)) { return; }
			var cur = $.trim($city().val());
			if (cur === '' || st.cityAuto) {
				$city().val(g.thana_en);
				st.cityAuto = true;
				st.lastAuto = g.thana_en;
			}
		}

		var timer = null;
		function detect() {
			clearTimeout(timer);
			timer = setTimeout(function () {
				var text = $.trim($a1.val() + ' ' + ($a2.val() || ''));
				if (text.length < 4) { hint(null); return; }
				loadGeo().then(function () {
					return post('pg_geo_detect', { address: text });
				}).then(function (d) { apply(d.geo); });
			}, 700);
		}

		$(document).on('input', '#_' + type + '_address_1, #_' + type + '_address_2', detect);
		$(document).on('input', '#_' + type + '_city', function () {
			st.cityManual = $.trim($(this).val()) !== '' && $(this).val() !== st.lastAuto;
			if (st.cityManual) { st.cityAuto = false; }
		});
		$(document).on('change', '#_' + type + '_state', function (e) {
			if (e.originalEvent) { st.stateManual = true; }
			loadGeo().then(fillList);
		});
		// select2 changes have no originalEvent: mark them manual when the user opened the box.
		$(document).on('select2:select', '#_' + type + '_state', function () {
			st.stateManual = true;
			loadGeo().then(fillList);
		});
		loadGeo().then(fillList);
	}

	$(function () {
		setup('billing');
		setup('shipping');
	});
})(jQuery);
