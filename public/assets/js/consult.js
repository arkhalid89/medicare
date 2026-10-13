/* ==========================================================================
   One-page consultation + template editor (BRD §33–48, §55–57, PHC update)
   Quantity, billing and the main-Rx / hospital split mirror
   modules/visits/PrescriptionMath.php; the server recomputes on save.
   ========================================================================== */
(function () {
    'use strict';

    var dataEl = document.getElementById('consult-data');
    if (!dataEl) return;
    var cfg = JSON.parse(dataEl.textContent);
    var isVisit = cfg.mode === 'visit';
    var $ = function (sel, root) { return (root || document).querySelector(sel); };
    var $$ = function (sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); };
    var round2 = function (n) { return Math.round((parseFloat(n) || 0) * 100) / 100; };

    var freqs = {}, routes = {};
    cfg.frequencies.forEach(function (f) { freqs[f.id] = f; });
    cfg.routes.forEach(function (r) { routes[r.id] = r; });

    var state = {
        patient: null, lines: [], tags: {}, templateId: null, repeatedFrom: null,
        dupConfirmedKey: '', feeTouched: false, saving: false, done: false, dirty: false
    };

    // ================================================================ icons
    function icon(name) {
        var p = {
            x: '<path d="M18 6 6 18M6 6l12 12"/>',
            plus: '<path d="M12 5v14M5 12h14"/>',
            trash: '<path d="M3 6h18"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>',
            up: '<polyline points="18 15 12 9 6 15"/>',
            refresh: '<polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/>',
            check: '<polyline points="20 6 9 17 4 12"/>',
            userplus: '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M19 8v6M22 11h-6"/>',
            printer: '<polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/>',
            repeat: '<polyline points="17 1 21 5 17 9"/><path d="M3 11V9a4 4 0 0 1 4-4h14"/><polyline points="7 23 3 19 7 15"/><path d="M21 13v2a4 4 0 0 1-4 4H3"/>',
            eye: '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>'
        }[name] || '';
        return '<svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + p + '</svg>';
    }

    // ================================================================ math
    function unitFactor(unit) { return (cfg.units[unit] && cfg.units[unit].factor) || 1; }

    function autoQty(l) {
        var f = freqs[l.frequency_id];
        if (!f) return null;
        var dose = parseFloat(l.dose) || 0, days = parseInt(l.duration_days, 10) || 0, per = parseFloat(f.doses_per_day) || 0;
        if (f.calc_mode === 'manual' || dose <= 0 || per <= 0 || days <= 0) return null;
        var units = f.calc_mode === 'weekly' ? dose * per * Math.ceil(days / 7) : dose * per * days;
        units *= unitFactor(l.dose_unit);
        var pack = parseFloat(l.pack_size) || 1;
        if (pack > 1) return Math.ceil(Math.round(units / pack * 10000) / 10000);
        return round2(units);
    }

    /** Only the ONE main source is written on the main Rx; everything else (incl. no source) goes below the cut. */
    function isMain(l) {
        if (!cfg.settings.split) return true;
        return !!l.source_id && String(l.source_id) === String(effectiveMain(l));
    }
    /**
     * Mirrors PrescriptionMath::effectiveMain(): the configured / order-1 source
     * if this prescription has it, else the lowest-order source present, so the
     * main Rx is never empty. `extra` = a search result not yet added.
     */
    function effectiveMain(extra) {
        var present = {};
        state.lines.concat(extra ? [extra] : []).forEach(function (l) { if (l.source_id) present[String(l.source_id)] = true; });
        var ids = Object.keys(present);
        if (!ids.length) return null;
        if (cfg.mainSourceId && present[String(cfg.mainSourceId)]) return String(cfg.mainSourceId);
        var ord = function (id) { return cfg.sourceOrder[id] !== undefined ? cfg.sourceOrder[id] : Infinity; };
        ids.sort(function (a, b) { return (ord(a) - ord(b)) || (parseInt(a, 10) - parseInt(b, 10)); });
        return ids[0];
    }
    function billable(l) { return !cfg.settings.split || !isMain(l); }
    function lineTotal(l) { return round2(Math.max(0, parseFloat(l.quantity) || 0) * Math.max(0, parseFloat(l.unit_price) || 0)); }

    function bill() {
        var total = 0;
        state.lines.forEach(function (l) { if (billable(l)) total += lineTotal(l); });
        total = round2(total);
        var dType = $('#discount-type') ? $('#discount-type').value : 'amount';
        var dVal = $('#discount-value') ? Math.max(0, parseFloat($('#discount-value').value) || 0) : 0;
        var discount = dType === 'percent' ? round2(total * Math.min(100, dVal) / 100) : round2(Math.min(dVal, total));
        var after = round2(total - discount);
        var taxP = $('#tax-percent') ? Math.max(0, parseFloat($('#tax-percent').value) || 0) : 0;
        var tax = round2(after * taxP / 100);
        var net = round2(after + tax);
        var fee = paymentStatus() === 'Free' ? 0 : Math.max(0, parseFloat(($('#consultation-fee') || {}).value) || 0);
        return { total: total, discount: discount, tax: tax, net: net, fee: round2(fee), grand: round2(net + fee) };
    }

    function paymentStatus() { var r = $('input[name=payment]:checked'); return r ? r.value : 'Paid'; }

    // ============================================================ wording
    function fraction(n) {
        n = parseFloat(n) || 0;
        var whole = Math.floor(n + 0.0001), rest = Math.round((n - whole) * 1000) / 1000;
        var map = { '0.25': '¼', '0.333': '⅓', '0.33': '⅓', '0.5': '½', '0.667': '⅔', '0.75': '¾' };
        var frac = map[String(rest)] || (rest > 0 ? String(rest).replace(/^0/, '') : '');
        return whole === 0 && frac ? frac : String(whole) + frac;
    }

    /** English and Urdu directions for one line (Line 2 + 3 of the PHC format). */
    function directions(l) {
        var u = cfg.units[l.dose_unit] || { en: l.dose_unit, ur: l.dose_unit };
        var f = freqs[l.frequency_id], r = routes[l.route_id];
        var days = parseInt(l.duration_days, 10) || 0;
        var dose = parseFloat(l.dose) || 0;
        var en = [fraction(dose) + ' ' + u.en + (dose > 1 && u.en.slice(-1) !== 's' ? 's' : '')];
        if (r && r.name !== 'Oral') en.push((cfg.routesEn && cfg.routesEn[r.name]) || r.name.toLowerCase());
        if (f) en.push(f.name.toLowerCase() + (f.code ? ' (' + f.code + ')' : ''));
        if (l.instructions) en.push(l.instructions.toLowerCase());
        if (days) en.push('for ' + days + ' day' + (days > 1 ? 's' : ''));
        var ur = [fraction(dose) + ' ' + u.ur];
        if (r && r.name !== 'Oral' && r.ur) ur.push(r.ur);
        if (f && f.ur) ur.push(f.ur);
        if (l.instructions) ur.push(cfg.instructionsUr[l.instructions] || l.instructions);
        if (days) ur.push(days + ' دن تک');
        return { en: en.join(', '), ur: ur.join('، ') };
    }

    // ============================================================ tag input
    function TagInput(root) {
        var self = this;
        this.key = root.getAttribute('data-tags');
        this.type = root.getAttribute('data-type');
        this.free = root.getAttribute('data-free') === '1';
        this.items = [];
        this.tagsEl = $('.tags', root);
        this.input = $('input', root);
        this.suggestEl = $('[data-suggest="' + this.key + '"]');
        this.suggestions = (cfg.trending && cfg.trending[this.key]) || [];

        APP.liveSearch(this.input, {
            minLength: 0, showOnFocus: true,
            fetch: function (q) {
                return APP.ajax(APP.url('visits/lookup', { type: self.type, q: q })).then(function (d) {
                    return (d.items || []).filter(function (it) { return !self.has(it.name); });
                });
            },
            render: function (it) {
                return '<div class="grow"><div class="t">' + APP.esc(it.name) + '</div>' + (it.code ? '<div class="s">' + APP.esc(it.code) + '</div>' : '') + '</div><span class="muted">' + icon('plus') + '</span>';
            },
            onSelect: function (it) { self.add(it); self.input.value = ''; },
            extra: function (q) {
                if (!self.free || !q || self.has(q)) return [];
                return [{ html: '<div class="grow"><div class="t">Add “' + APP.esc(q) + '”</div><div class="s">Free text</div></div>', action: function () { self.add({ id: null, name: q }); self.input.value = ''; } }];
            },
            empty: function (q) { return q ? 'No match for “' + APP.esc(q) + '”' : 'No items in the master list'; }
        });
        this.input.addEventListener('keydown', function (e) {
            if (e.key !== 'Enter' || e.defaultPrevented) return;
            e.preventDefault();
            var q = self.input.value.trim();
            if (q && self.free) { self.add({ id: null, name: q }); self.input.value = ''; }
        });
        this.renderSuggest();
    }
    TagInput.prototype.has = function (name) {
        name = String(name).trim().toLowerCase();
        return this.items.some(function (i) { return i.name.toLowerCase() === name; });
    };
    TagInput.prototype.add = function (item) {
        var name = String(item.name || '').trim();
        if (!name || this.has(name)) return;
        if (!item.id && !this.free) return;
        this.items.push({ id: item.id || null, name: name, code: item.code || '' });
        this.render();
        markDirty();
    };
    TagInput.prototype.remove = function (i) { this.items.splice(i, 1); this.render(); markDirty(); };
    TagInput.prototype.set = function (items) {
        this.items = [];
        (items || []).forEach(function (it) { this.add(it); }, this);
        this.render();
    };
    TagInput.prototype.render = function () {
        var self = this;
        this.tagsEl.innerHTML = this.items.map(function (it, i) {
            return '<span class="tag' + (it.id ? '' : ' free') + '">' + APP.esc(it.name) + (it.code ? ' <span class="code">' + APP.esc(it.code) + '</span>' : '') +
                '<button type="button" data-i="' + i + '" title="Remove" aria-label="Remove ' + APP.esc(it.name) + '">' + icon('x') + '</button></span>';
        }).join('');
        $$('button', this.tagsEl).forEach(function (b) {
            b.addEventListener('click', function () { self.remove(parseInt(b.getAttribute('data-i'), 10)); });
        });
        this.renderSuggest();
    };
    TagInput.prototype.renderSuggest = function () {
        var self = this;
        if (!this.suggestEl) return;
        var list = this.suggestions.filter(function (s) { return !self.has(s.name); }).slice(0, 20);
        this.suggestEl.innerHTML = list.map(function (s, i) {
            return '<button type="button" data-i="' + i + '">+ ' + APP.esc(s.name) + (s.count ? ' <span class="cnt">' + s.count + '</span>' : '') + '</button>';
        }).join('') || '<span class="muted small">Nothing left to suggest.</span>';
        $$('button', this.suggestEl).forEach(function (b) {
            b.addEventListener('click', function () { self.add(list[parseInt(b.getAttribute('data-i'), 10)]); });
        });
    };
    TagInput.prototype.value = function () { return this.items.map(function (i) { return { id: i.id, name: i.name }; }); };

    // ======================================================= prescription
    var rxBody = $('#rx-body');
    var showBilling = !!$('#sum-grand');

    function addLine(line, silent) {
        if (state.lines.some(function (l) { return l.medicine_id === line.medicine_id; })) {
            if (!silent) APP.toast('Medicine already added: ' + line.name, 'warning');
            return false;
        }
        state.lines.push(normaliseLine(line));
        renderRx();
        markDirty();
        return true;
    }

    function normaliseLine(l) {
        var type = l.dose_type || 'other';
        var choices = cfg.doseChoices[type] || cfg.doseChoices.other;
        var unit = l.dose_unit && choices[0].indexOf(l.dose_unit) !== -1 ? l.dose_unit : (type === 'other' && cfg.units[l.dose_unit] ? l.dose_unit : choices[0][0]);
        return {
            medicine_id: l.medicine_id, name: l.name, generic_name: l.generic_name || '', strength: l.strength || '',
            dosage_form: l.dosage_form || '', dose: l.dose, dose_unit: unit, dose_type: type, frequency_id: l.frequency_id || '',
            route_id: l.route_id || '', duration_days: l.duration_days === null || l.duration_days === undefined ? '' : l.duration_days,
            quantity: l.quantity, qty_manual: l.qty_manual ? 1 : 0, unit: l.unit || '', pack_size: l.pack_size || 1,
            unit_price: l.unit_price, source_id: l.source_id || null, source_name: l.source_name || '', instructions: l.instructions || ''
        };
    }

    function doseSelect(l) {
        var choices = cfg.doseChoices[l.dose_type] || cfg.doseChoices.other;
        var amounts = choices[1].slice();
        var cur = String(parseFloat(l.dose));
        if (amounts.map(function (a) { return String(parseFloat(a)); }).indexOf(cur) === -1) amounts.push(cur);
        amounts.sort(function (a, b) { return parseFloat(a) - parseFloat(b); });
        var amountHtml = amounts.map(function (a) {
            return '<option value="' + a + '"' + (String(parseFloat(a)) === cur ? ' selected' : '') + '>' + fraction(a) + '</option>';
        }).join('') + '<option value="__other">Other…</option>';
        var unitHtml = choices[0].map(function (u) {
            return '<option value="' + u + '"' + (u === l.dose_unit ? ' selected' : '') + '>' + APP.esc((cfg.units[u] || { en: u }).en) + '</option>';
        }).join('');
        return '<div class="dose-pick"><select class="input" data-f="dose" aria-label="Dose">' + amountHtml + '</select>' +
            (choices[0].length > 1 ? '<select class="input" data-f="dose_unit" aria-label="Dose unit">' + unitHtml + '</select>'
                : '<span class="unit">' + APP.esc((cfg.units[l.dose_unit] || { en: l.dose_unit }).en) + '</span>') + '</div>';
    }

    function renderRx() {
        var freqOptions = function (sel) {
            return '<option value="">—</option>' + cfg.frequencies.map(function (f) {
                return '<option value="' + f.id + '"' + (String(f.id) === String(sel) ? ' selected' : '') + '>' + APP.esc(f.code ? f.code + ' — ' + f.name : f.name) + '</option>';
            }).join('');
        };
        var routeOptions = function (sel) {
            return '<option value="">—</option>' + cfg.routes.map(function (r) {
                return '<option value="' + r.id + '"' + (String(r.id) === String(sel) ? ' selected' : '') + '>' + APP.esc(r.name) + '</option>';
            }).join('');
        };
        rxBody.innerHTML = state.lines.map(function (l, i) {
            var main = isMain(l), bill = showBilling && billable(l);
            return '<div class="rx-item' + (main ? ' main' : ' hospital') + '" data-i="' + i + '">' +
                '<div class="rx-top"><span class="rx-no">' + (i + 1) + '</span>' +
                '<div class="rx-name"><div class="med-name">' + APP.esc(l.dosage_form ? l.dosage_form + ' ' : '') + APP.esc(l.name) + (l.strength ? ' ' + APP.esc(l.strength) : '') + '</div>' +
                '<div class="med-sub">' + APP.esc(l.generic_name) + '</div></div>' +
                (showBilling && cfg.settings.split ? (main ? '<span class="badge badge-primary">Main Rx · ' + APP.esc(l.source_name || cfg.settings.unassigned) + '</span>'
                    : '<span class="badge badge-gold">Hospital · ' + APP.esc(l.source_name) + '</span>') : (l.source_name ? '<span class="badge badge-gold">' + APP.esc(l.source_name) + '</span>' : '')) +
                '<div class="actions">' + (i > 0 ? '<button type="button" class="act" data-up title="Move up">' + icon('up') + '</button>' : '') +
                '<button type="button" class="act danger" data-remove title="Remove">' + icon('trash') + '</button></div></div>' +
                '<div class="rx-fields">' +
                '<label class="rx-f f-dose"><span>Dose</span>' + doseSelect(l) + '</label>' +
                '<label class="rx-f f-freq"><span>Frequency</span><select class="input" data-f="frequency_id">' + freqOptions(l.frequency_id) + '</select></label>' +
                '<label class="rx-f f-route"><span>Route</span><select class="input" data-f="route_id">' + routeOptions(l.route_id) + '</select></label>' +
                '<label class="rx-f f-days"><span>Days</span><input class="input" data-f="duration_days" type="number" min="0" step="1" inputmode="numeric" value="' + APP.esc(l.duration_days) + '"></label>' +
                '<label class="rx-f f-qty"><span>Qty <em class="muted">' + APP.esc(l.unit) + '</em></span><div class="qty-cell"><input class="input" data-f="quantity" type="number" min="0.01" step="1" inputmode="decimal" value="' + APP.esc(l.quantity) + '">' +
                '<button type="button" class="qty-recalc" title="Recalculate quantity">' + icon('refresh') + '</button></div></label>' +
                (bill ? '<label class="rx-f f-price"><span>Unit Price</span><input class="input" data-f="unit_price" type="number" min="0" step="0.01" inputmode="decimal" value="' + APP.esc(l.unit_price) + '"></label>' +
                    '<div class="rx-f f-total"><span>Total</span><strong data-total></strong></div>' : '') +
                '<label class="rx-f f-instr"><span>Instructions</span><input class="input" data-f="instructions" list="instr-list" maxlength="255" value="' + APP.esc(l.instructions) + '" placeholder="Select or type"></label>' +
                '</div>' +
                '<div class="rx-dir"><span class="en" data-dir-en></span>' + (cfg.settings.urdu ? '<span class="ur" dir="rtl" lang="ur" data-dir-ur></span>' : '') + '</div>' +
                '</div>';
        }).join('');
        $('#rx-empty').style.display = state.lines.length ? 'none' : '';
        state.lines.forEach(function (l, i) { updateRow(i); });
        renderGroups();
        refreshTotals();
    }

    function updateRow(i) {
        var l = state.lines[i];
        var row = rxBody.querySelector('.rx-item[data-i="' + i + '"]');
        if (!row) return;
        var auto = autoQty(l);
        var manualDiffers = l.qty_manual && auto !== null && round2(auto) !== round2(l.quantity);
        $('.qty-cell', row).classList.toggle('manual', !!manualDiffers);
        var q = $('[data-f=quantity]', row);
        q.classList.toggle('manual', !!l.qty_manual);
        q.title = l.qty_manual ? (auto === null ? 'Manual quantity (this frequency has no automatic rule)' : 'Manual quantity — calculated would be ' + auto) : 'Calculated automatically';
        var total = $('[data-total]', row);
        if (total) total.textContent = APP.money(lineTotal(l));
        var d = directions(l);
        $('[data-dir-en]', row).textContent = d.en;
        var ur = $('[data-dir-ur]', row);
        if (ur) ur.textContent = d.ur;
    }

    rxBody.addEventListener('input', onRxChange);
    rxBody.addEventListener('change', onRxChange);
    function onRxChange(e) {
        var el = e.target, f = el.getAttribute('data-f');
        if (!f) return;
        var row = el.closest('.rx-item'), i = parseInt(row.getAttribute('data-i'), 10), l = state.lines[i];
        if (f === 'dose' && el.value === '__other') {
            if (e.type !== 'change') return;
            var v = window.prompt('Dose amount (e.g. 1.5 or 0.5):', String(parseFloat(l.dose) || 1));
            if (v !== null && parseFloat(v) > 0) l.dose = parseFloat(v);
            renderRx();
            return;
        }
        l[f] = el.value;
        if (f === 'quantity') {
            var auto = autoQty(l);
            l.qty_manual = auto === null || round2(auto) !== round2(el.value) ? 1 : 0;
        } else if (f === 'dose' || f === 'dose_unit' || f === 'frequency_id' || f === 'duration_days') {
            var a = autoQty(l);
            if (!l.qty_manual && a !== null) {
                l.quantity = a;
                $('[data-f=quantity]', row).value = a;
            } else if (a === null && !l.qty_manual) {
                l.qty_manual = 1;
            }
        }
        updateRow(i);
        refreshTotals();
        markDirty();
    }
    rxBody.addEventListener('click', function (e) {
        var row = e.target.closest('.rx-item');
        if (!row) return;
        var i = parseInt(row.getAttribute('data-i'), 10);
        if (e.target.closest('[data-remove]')) {
            APP.confirm('Remove ' + state.lines[i].name + ' from the prescription?', 'Remove').then(function (ok) {
                if (!ok) return;
                state.lines.splice(i, 1);
                renderRx();
                markDirty();
            });
        } else if (e.target.closest('[data-up]') && i > 0) {
            var tmp = state.lines[i - 1];
            state.lines[i - 1] = state.lines[i];
            state.lines[i] = tmp;
            renderRx();
        } else if (e.target.closest('.qty-recalc')) {
            var a = autoQty(state.lines[i]);
            if (a !== null) {
                state.lines[i].quantity = a;
                state.lines[i].qty_manual = 0;
                $('[data-f=quantity]', row).value = a;
                updateRow(i);
                refreshTotals();
            }
        }
    });

    function renderGroups() {
        var box = $('#rx-groups');
        if (!state.lines.length) { box.innerHTML = ''; return; }
        if (!showBilling || !cfg.settings.split) {
            box.innerHTML = '<span class="badge badge-primary">' + state.lines.length + ' item(s)</span>';
            return;
        }
        var main = state.lines.filter(isMain).length, sources = {};
        state.lines.forEach(function (l) { if (!isMain(l)) sources[l.source_name] = (sources[l.source_name] || 0) + 1; });
        var html = '<span class="badge badge-primary">Main Rx (' + main + ')</span>';
        Object.keys(sources).forEach(function (s) { html += '<span class="badge badge-gold">' + APP.esc(s) + ' (' + sources[s] + ')</span>'; });
        box.innerHTML = html;
    }

    APP.liveSearch($('#med-q'), {
        minLength: 0, showOnFocus: true,
        fetch: function (q) { return APP.ajax(APP.url('visits/medicines', { q: q })).then(function (d) { return d.items || []; }); },
        render: function (m) {
            var added = state.lines.some(function (l) { return l.medicine_id === m.medicine_id; });
            var main = isMain(m);
            return '<div class="grow"><div class="t">' + APP.esc(m.name) + (m.strength ? ' <span class="muted">' + APP.esc(m.strength) + '</span>' : '') + '</div>' +
                '<div class="s">' + APP.esc([m.generic_name, m.dosage_form, m.source_name].filter(Boolean).join(' · ')) + '</div></div>' +
                (showBilling && !(cfg.settings.split && main) ? '<strong class="nowrap">' + APP.money(m.unit_price) + '</strong>' : '') +
                (added ? '<span class="badge badge-success">Added</span>' : '<span class="badge badge-primary">' + icon('plus') + ' Add</span>');
        },
        onSelect: function (m) { if (addLine(m)) { $('#med-q').value = ''; } },
        empty: function (q) { return 'No active medicine matches “' + APP.esc(q) + '”'; }
    });

    // ============================================================ vitals
    var ranges = {};
    try { ranges = JSON.parse(($('#vitals') || { getAttribute: function () { return '{}'; } }).getAttribute('data-ranges') || '{}'); } catch (e) { ranges = {}; }

    function vitalsValue() {
        var v = { other: {} };
        $$('[data-vital]').forEach(function (i) { v[i.getAttribute('data-vital')] = i.value.trim(); });
        $$('[data-extra-vital]').forEach(function (i) { if (i.value.trim()) v.other[i.getAttribute('data-extra-vital')] = i.value.trim(); });
        return v;
    }
    function setVitals(v) {
        v = v || {};
        $$('[data-vital]').forEach(function (i) {
            var k = i.getAttribute('data-vital');
            if (v[k] !== undefined && v[k] !== null && i.value === '') i.value = v[k];
        });
        checkVitals();
    }
    /** Live hint under each vital: outside the usual range = amber, still saved. */
    function checkVitals() {
        $$('.vital').forEach(function (box) {
            var hint = $('.vital-hint', box);
            if (!hint || hint.id === 'bmi-hint') return;
            var msgs = [];
            $$('[data-vital]', box).forEach(function (i) {
                var k = i.getAttribute('data-vital'), r = ranges[k], val = i.value.trim();
                var bad = false;
                if (val !== '' && r) {
                    var n = parseFloat(val);
                    if (isNaN(n)) { msgs.push('Not a number'); bad = true; }
                    else if (n < r[1] || n > r[2]) { msgs.push('Usual ' + r[1] + '–' + r[2]); bad = true; }
                }
                i.classList.toggle('is-warn', bad);
            });
            var s = $('[data-vital=bp_systolic]', box), d = $('[data-vital=bp_diastolic]', box);
            if (s && d && s.value && d.value && parseFloat(d.value) >= parseFloat(s.value)) msgs.push('Diastolic ≥ systolic');
            hint.textContent = msgs.length ? '⚠ ' + msgs.join(' · ') : '';
        });
        updateBmi();
    }
    function updateBmi() {
        var w = parseFloat(($('[data-vital=weight]') || {}).value), h = parseFloat(($('[data-vital=height]') || {}).value);
        var bmi = $('#bmi'), hint = $('#bmi-hint');
        if (!bmi) return;
        var val = w > 0 && h > 0 ? Math.round(w / Math.pow(h / 100, 2) * 10) / 10 : '';
        bmi.value = val;
        if (hint) hint.textContent = val === '' ? '' : val < 18.5 ? 'Underweight' : val < 25 ? 'Normal' : val < 30 ? 'Overweight' : 'Obese';
    }
    $$('[data-vital]').forEach(function (i) { i.addEventListener('input', checkVitals); });

    // ============================================================ tags init
    $$('[data-tags]').forEach(function (root) { var t = new TagInput(root); state.tags[t.key] = t; });

    // =========================================================== patient
    var pf = {};
    $$('[data-p]').forEach(function (i) { pf[i.getAttribute('data-p')] = i; });

    // Age (years / months / days) ↔ date of birth, both directions.
    function pad(n) { return String(n).padStart(2, '0'); }
    function iso(d) { return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()); }
    function ageFromDob(dob) {
        var b = new Date(dob + 'T00:00:00'), t = new Date(new Date().toDateString());
        if (isNaN(b) || b > t) return null;
        var y = t.getFullYear() - b.getFullYear(), m = t.getMonth() - b.getMonth(), d = t.getDate() - b.getDate();
        if (d < 0) { m--; d += new Date(t.getFullYear(), t.getMonth(), 0).getDate(); }
        if (m < 0) { y--; m += 12; }
        return [y, m, d];
    }
    function dobFromAge(y, m, d) {
        var t = new Date(new Date().toDateString());
        t.setFullYear(t.getFullYear() - y);
        t.setMonth(t.getMonth() - m);
        t.setDate(t.getDate() - d);
        return iso(t);
    }
    var estimatedDob = false;
    if (pf.dob) {
        pf.dob.addEventListener('change', function () {
            var a = pf.dob.value ? ageFromDob(pf.dob.value) : null;
            estimatedDob = false;
            pf.age_years.value = a ? a[0] : '';
            pf.age_months.value = a ? a[1] : '';
            pf.age_days.value = a ? a[2] : '';
        });
        ['age_years', 'age_months', 'age_days'].forEach(function (k) {
            pf[k].addEventListener('input', function () {
                var y = parseInt(pf.age_years.value, 10) || 0, m = parseInt(pf.age_months.value, 10) || 0, d = parseInt(pf.age_days.value, 10) || 0;
                if (!pf.age_years.value && !pf.age_months.value && !pf.age_days.value) { pf.dob.value = ''; return; }
                pf.dob.value = dobFromAge(y, m, d);
                estimatedDob = true;
            });
        });
    }

    function patientValue() {
        var g = $('input[name=p-gender]:checked');
        var val = function (k) { return pf[k] ? String(pf[k].value).trim() : ''; };
        return {
            id: state.patient ? state.patient.id : null,
            name: val('name'), guardian_name: val('guardian_name'), gender: g ? g.value : '',
            dob: val('dob'), dob_estimated: estimatedDob ? 1 : 0,
            age_years: val('age_years'), age_months: val('age_months'), age_days: val('age_days'),
            cnic: val('cnic'), phone: val('phone'), blood_group: val('blood_group'), city: val('city'), address: val('address')
        };
    }

    APP.consultPatient = patientValue;

    function fillPatient(p) {
        state.patient = p;
        document.dispatchEvent(new CustomEvent('consult:patient', { detail: p || null }));
        Object.keys(pf).forEach(function (k) { pf[k].value = ''; });
        estimatedDob = false;
        if (p) {
            ['name', 'guardian_name', 'cnic', 'phone', 'city', 'address', 'blood_group'].forEach(function (k) { if (pf[k]) pf[k].value = p[k] || ''; });
            if (pf.dob) {
                pf.dob.value = p.dob || '';
                estimatedDob = !!p.dob_estimated;
                pf.age_years.value = p.age_y !== null && p.age_y !== undefined ? p.age_y : '';
                pf.age_months.value = p.age_m !== null && p.age_m !== undefined ? p.age_m : '';
                pf.age_days.value = p.age_d !== null && p.age_d !== undefined ? p.age_d : '';
            }
            $$('input[name=p-gender]').forEach(function (r) { r.checked = r.value === p.gender; });
            if ($('#allergies')) $('#allergies').value = p.allergies || '';
        } else {
            $$('input[name=p-gender]').forEach(function (r) { r.checked = false; });
            if ($('#allergies')) $('#allergies').value = '';
        }
        var lock = !!p && !cfg.canEditPatient;
        Object.keys(pf).forEach(function (k) { pf[k].disabled = lock; });
        $$('input[name=p-gender]').forEach(function (r) { r.disabled = lock; });
        var badge = $('#patient-status');
        if (badge) {
            if (p) {
                badge.className = 'patient-badge';
                badge.innerHTML = icon('check') + ' ' + APP.esc(p.mrn) + (p.visits ? ' · visit #' + (p.visits + 1) : '');
            } else {
                badge.className = 'patient-badge new';
                badge.innerHTML = icon('userplus') + ' New patient — MRN on checkout';
            }
        }
        var hb = $('#btn-history');
        if (hb) {
            hb.classList.toggle('hidden', !(p && p.visits));
            $('#history-count').textContent = p ? p.visits : 0;
        }
        loadHistory();
        updateBar();
    }

    if ($('#patient-q')) {
        APP.liveSearch($('#patient-q'), {
            minLength: 2,
            fetch: function (q) { return APP.ajax(APP.url('patients/search', { q: q })).then(function (d) { return d.items || []; }); },
            render: function (p) {
                return '<span class="avatar" style="background:var(--secondary);color:var(--primary)">' + APP.esc(p.name.charAt(0).toUpperCase()) + '</span>' +
                    '<div class="grow"><div class="t">' + APP.esc(p.name) + (p.guardian_name ? ' <span class="muted">s/o, d/o, w/o ' + APP.esc(p.guardian_name) + '</span>' : '') + '</div>' +
                    '<div class="s">' + APP.esc([p.mrn, p.gender + (p.age ? ' ' + p.age : ''), p.phone, p.cnic].filter(Boolean).join(' · ')) + '</div></div>' +
                    (p.visits ? '<span class="badge badge-gold">' + p.visits + ' visit(s)</span>' : '');
            },
            onSelect: function (p) { fillPatient(p); $('#patient-q').value = ''; markDirty(); },
            empty: function (q) { return 'No patient found for “' + APP.esc(q) + '”. Fill the form below to register a new patient.'; }
        });
    }
    if ($('#btn-new-patient')) {
        $('#btn-new-patient').addEventListener('click', function () { fillPatient(null); state.dupConfirmedKey = ''; pf.phone.focus(); });
    }
    if (pf.name) pf.name.addEventListener('input', updateBar);

    // Family / duplicate popup for NEW patients (BRD §31)
    var dupCheck = APP.debounce(function () {
        if (state.patient || !pf.cnic) return;
        var cnic = pf.cnic.value, phone = pf.phone.value;
        var key = cnic.replace(/\D/g, '') + '|' + phone.replace(/\D/g, '');
        if (cnic.replace(/\D/g, '').length !== 13 && phone.replace(/\D/g, '').length < 10) return;
        if (key === state.dupConfirmedKey) return;
        APP.ajax(APP.url('patients/duplicates', { cnic: cnic, phone: phone })).then(function (d) {
            if (!d.items || !d.items.length || state.patient) return;
            showDuplicates(d.items, key);
        });
    }, 500);
    ['cnic', 'phone'].forEach(function (k) { if (pf[k]) pf[k].addEventListener('input', dupCheck); });

    function showDuplicates(items, key) {
        $('#dup-body').innerHTML = items.map(function (p, i) {
            return '<tr><td>' + APP.esc(p.mrn) + '</td><td><strong>' + APP.esc(p.name) + '</strong></td><td>' + APP.esc(p.guardian_name) + '</td><td>' +
                APP.esc(p.gender + ' ' + (p.age || '')) + '</td><td>' + APP.esc(p.cnic) + '</td><td>' + APP.esc(p.phone) + '</td>' +
                '<td><button type="button" class="btn btn-sm btn-primary" data-pick="' + i + '">Select</button></td></tr>';
        }).join('');
        $$('[data-pick]', $('#dup-body')).forEach(function (b) {
            b.addEventListener('click', function () { fillPatient(items[parseInt(b.getAttribute('data-pick'), 10)]); APP.closeModal('dup-modal'); });
        });
        $('#dup-new').onclick = function () { state.dupConfirmedKey = key; APP.closeModal('dup-modal'); };
        APP.openModal('dup-modal');
    }
    $$('[data-dup-cancel]').forEach(function (b) { b.addEventListener('click', function () { APP.closeModal('dup-modal'); }); });

    // ============================================= previous visits (inline + popup)
    var historyRows = [];
    var inline = $('#inline-history');

    function loadHistory() {
        historyRows = [];
        if (!inline) return Promise.resolve([]);
        if (!state.patient) {
            inline.innerHTML = '<div class="muted small">Select an existing patient to see previous visits.</div>';
            $('#btn-history-all').classList.add('hidden');
            return Promise.resolve([]);
        }
        inline.innerHTML = '<div class="muted small"><span class="spinner"></span> Loading visits…</div>';
        return APP.ajax(APP.url('visits/history', { patient_id: state.patient.id })).then(function (d) {
            historyRows = d.items || [];
            $('#btn-history-all').classList.toggle('hidden', historyRows.length <= 4);
            if (!historyRows.length) {
                inline.innerHTML = '<div class="muted small">First visit — no history yet.</div>';
                return historyRows;
            }
            inline.innerHTML = historyRows.slice(0, 4).map(function (v, i) {
                var dt = new Date(v.visit_date.replace(' ', 'T'));
                var months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
                return '<div class="ih-item"><div class="ih-date"><strong>' + dt.getDate() + '</strong><small>' + months[dt.getMonth()] + ' ' + String(dt.getFullYear()).slice(2) + '</small></div>' +
                    '<div class="ih-main"><div class="t">' + APP.esc(v.diagnoses || v.complaints || 'Consultation') + '</div>' +
                    '<div class="s">' + APP.esc(v.visit_no + ' · ' + v.doctor_name + (v.follow_up_text ? ' · next visit ' + v.follow_up_text : '') + ' · ' + v.total_text) + '</div></div>' +
                    '<div class="ih-actions"><a class="act" target="_blank" title="Open visit" href="' + APP.url('visits/view', { id: v.id }) + '">' + icon('eye') + '</a>' +
                    '<button type="button" class="btn btn-sm btn-gold" data-ih-repeat="' + i + '">' + icon('repeat') + ' Repeat</button></div></div>';
            }).join('');
            return historyRows;
        });
    }

    function repeatVisit(v) {
        APP.confirm('Refill this visit with everything from ' + v.visit_no + ' (' + v.date_text + ')? Current complaints, diagnosis and medicines are replaced; you can modify them before checkout.', 'Repeat', false).then(function (ok) {
            if (!ok) return;
            APP.loading(true, 'Loading previous visit…');
            APP.ajax(APP.url('visits/refill', { id: v.id })).then(function (d) {
                APP.loading(false);
                if (!d.ok) { APP.toast(d.message || 'Visit not found.', 'error'); return; }
                applyData(d.visit, true);
                state.repeatedFrom = v.id;
                setRefill((parseInt(d.visit.refills, 10) || 0) + 1);
                APP.closeModal('history-modal');
                APP.toast('Visit ' + v.visit_no + ' repeated — review and modify, then checkout.', 'success');
            });
        });
    }

    function renderHistoryModal() {
        if (!historyRows.length) {
            $('#history-body').innerHTML = '<tr><td colspan="8" class="text-center muted">No previous visits.</td></tr>';
            return;
        }
        $('#history-body').innerHTML = historyRows.map(function (v, i) {
            return '<tr class="h-row" data-i="' + i + '"><td class="nowrap">' + APP.esc(v.date_text) + '</td><td class="nowrap">' + APP.esc(v.visit_no) + '</td><td>' + APP.esc(v.doctor_name) + '</td>' +
                '<td class="small">' + APP.esc(v.complaints || '—') + '</td><td class="small">' + APP.esc(v.diagnoses || '—') + '</td>' +
                '<td class="small">' + APP.esc(v.medicines || '—') + '</td><td class="num">' + APP.esc(v.total_text) + '</td>' +
                '<td><div class="actions"><button type="button" class="act" data-h-view title="Details">' + icon('eye') + '</button>' +
                '<button type="button" class="btn btn-sm btn-gold" data-h-repeat>' + icon('repeat') + ' Repeat</button></div></td></tr>' +
                '<tr class="h-detail hidden" data-d="' + i + '"><td colspan="8"><div class="h-box">' +
                (v.bp ? '<span class="badge">BP ' + APP.esc(v.bp) + '</span> ' : '') + (v.pulse ? '<span class="badge">Pulse ' + APP.esc(v.pulse) + '</span> ' : '') +
                (v.temperature ? '<span class="badge">Temp ' + APP.esc(v.temperature) + '°F</span> ' : '') + (v.weight ? '<span class="badge">Wt ' + APP.esc(v.weight) + ' kg</span> ' : '') +
                '<div class="mt-1"><strong>Investigations:</strong> ' + APP.esc(v.investigations || '—') + '</div>' +
                '<div><strong>Next visit:</strong> ' + APP.esc(v.follow_up_text || '—') + '</div>' +
                '<div class="mt-1"><a target="_blank" href="' + APP.url('visits/view', { id: v.id }) + '">Open full visit ↗</a></div></div></td></tr>';
        }).join('');
    }

    function openHistory() {
        if (!state.patient) return;
        $('#history-name').textContent = state.patient.name + ' · ' + state.patient.mrn;
        renderHistoryModal();
        APP.openModal('history-modal');
    }
    if ($('#btn-history')) $('#btn-history').addEventListener('click', openHistory);
    if ($('#btn-history-all')) $('#btn-history-all').addEventListener('click', openHistory);
    if (inline) {
        inline.addEventListener('click', function (e) {
            var b = e.target.closest('[data-ih-repeat]');
            if (b) repeatVisit(historyRows[parseInt(b.getAttribute('data-ih-repeat'), 10)]);
        });
    }
    $$('[data-history-close]').forEach(function (b) { b.addEventListener('click', function () { APP.closeModal('history-modal'); }); });
    if ($('#history-body')) {
        $('#history-body').addEventListener('click', function (e) {
            var tr = e.target.closest('tr.h-row');
            if (!tr) return;
            var i = parseInt(tr.getAttribute('data-i'), 10);
            if (e.target.closest('[data-h-view]')) {
                $('#history-body').querySelector('[data-d="' + i + '"]').classList.toggle('hidden');
            } else if (e.target.closest('[data-h-repeat]')) {
                repeatVisit(historyRows[i]);
            }
        });
    }

    /** Refill counter is automatic: 0 for a new prescription, +1 each time one is repeated. */
    var refillCount = 0;
    function setRefill(n) {
        refillCount = n;
        var note = $('#refill-note');
        if (note) note.textContent = n ? 'Refill: ' + n + ' (repeat #' + n + ' of the original prescription)' : 'Refill: 0 (new prescription)';
    }

    // ======================================================= fee & follow-up
    var feeInput = $('#consultation-fee');
    function currentDoctor() {
        if (cfg.isDoctor) return cfg.doctor;
        var id = parseInt(($('#doctor-id') || {}).value, 10);
        return cfg.doctors.filter(function (d) { return d.id === id; })[0] || null;
    }
    if (feeInput) {
        var d0 = currentDoctor();
        feeInput.value = d0 ? d0.default_fee : 0;
        feeInput.addEventListener('input', function () { state.feeTouched = true; refreshTotals(); markDirty(); });
    }
    if ($('#doctor-id')) {
        $('#doctor-id').addEventListener('change', function () {
            var d = currentDoctor();
            if (d && feeInput && !state.feeTouched) feeInput.value = d.default_fee;
            refreshTotals();
        });
    }
    $$('input[name=payment]').forEach(function (r) { r.addEventListener('change', refreshTotals); });
    ['#discount-type', '#discount-value', '#tax-percent'].forEach(function (s) {
        var el = $(s);
        if (el) { el.addEventListener('input', refreshTotals); el.addEventListener('change', refreshTotals); }
    });
    function followHint() {
        var fu = $('#follow-up-date'), hint = $('#follow-up-hint');
        if (!fu || !hint) return;
        if (!fu.value) { hint.textContent = ''; return; }
        var d = new Date(fu.value + 'T00:00:00'), days = Math.round((d - new Date(new Date().toDateString())) / 86400000);
        hint.textContent = d.toLocaleDateString('en-GB', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' }) + ' · in ' + days + ' day(s)';
    }
    if ($('#follow-up-date')) $('#follow-up-date').addEventListener('change', followHint);
    $$('[data-follow]').forEach(function (b) {
        b.addEventListener('click', function () {
            var days = parseInt(b.getAttribute('data-follow'), 10);
            var input = $('#follow-up-date');
            if (!days) { input.value = ''; followHint(); return; }
            var d = new Date();
            d.setDate(d.getDate() + days);
            input.value = iso(d);
            followHint();
            markDirty();
        });
    });

    function refreshTotals() {
        var b = bill();
        var set = function (id, v) { var el = $(id); if (el) el.textContent = v; };
        set('#sum-medicines', APP.money(b.total));
        set('#sum-discount', '− ' + APP.money(b.discount));
        set('#sum-tax', APP.money(b.tax));
        set('#sum-net', APP.money(b.net));
        set('#sum-fee', APP.money(b.fee));
        set('#sum-grand', APP.money(b.grand));
        set('#bar-grand', APP.money(b.grand));
        var mainCount = cfg.settings.split ? state.lines.filter(isMain).length : 0;
        set('#sum-main-note', mainCount ? mainCount + ' main-Rx medicine(s) are bought outside and not billed.' : '');
        renderGroups();
        updateBar();
    }

    function updateBar() {
        var bp = $('#bar-patient');
        if (!bp) return;
        var name = pf.name ? pf.name.value.trim() : '';
        bp.textContent = name ? name + (state.patient ? ' · ' + state.patient.mrn : ' · new patient') : 'No patient selected';
        $('#bar-meds').textContent = state.lines.length + ' medicine(s)';
    }

    // ============================================================ payload
    function clinicalPayload() {
        var out = {};
        Object.keys(state.tags).forEach(function (k) { out[k] = state.tags[k].value(); });
        out.vitals = vitalsValue();
        out.medicines = state.lines.map(function (l) {
            return {
                medicine_id: l.medicine_id, name: l.name, dose: l.dose, dose_unit: l.dose_unit, frequency_id: l.frequency_id || null, route_id: l.route_id || null,
                duration_days: l.duration_days, quantity: l.quantity, qty_manual: l.qty_manual ? 1 : 0, unit_price: l.unit_price, instructions: l.instructions
            };
        });
        out.overall_instructions = ($('#overall-instructions') || {}).value || '';
        return out;
    }

    function visitPayload() {
        var p = clinicalPayload(), d = currentDoctor();
        p.token = cfg.token;
        p.doctor_id = d ? d.id : null;
        p.patient = patientValue();
        p.allergies = ($('#allergies') || {}).value || '';
        p.current_history = ($('#current-history') || {}).value || '';
        p.findings = ($('#findings') || {}).value || '';
        p.follow_up_date = ($('#follow-up-date') || {}).value || '';
        p.follow_up_instructions = ($('#follow-up-instructions') || {}).value || '';
        p.consultation_fee = feeInput ? feeInput.value : '';
        p.payment_status = paymentStatus();
        p.discount_type = $('#discount-type') ? $('#discount-type').value : 'amount';
        p.discount_value = $('#discount-value') ? $('#discount-value').value : 0;
        p.tax_percent = $('#tax-percent') ? $('#tax-percent').value : 0;
        p.repeated_from_visit_id = state.repeatedFrom;
        p.template_id = state.templateId;
        // v2: token, department, visit type and department-module data (tooth chart, ANC, lab orders …)
        var pre = cfg.prefill || {};
        p.token_id = pre.token ? pre.token.id : null;
        p.department_id = ($('#visit-dept') || {}).value || (pre.token ? pre.token.department_id : null);
        p.visit_type_id = ($('#visit-type') || {}).value || null;
        p.modules = {};
        Object.keys(APP.consultModules || {}).forEach(function (k) {
            try { p.modules[k] = APP.consultModules[k](); } catch (e) { p.modules[k] = null; }
        });
        return p;
    }

    function showErrors(list) {
        var box = $('#consult-errors');
        if (!list || !list.length) { box.classList.add('hidden'); return; }
        box.innerHTML = '<div><strong>Unable to save — please correct the following:</strong><ul>' +
            list.map(function (e) { return '<li>' + APP.esc(e) + '</li>'; }).join('') + '</ul></div>';
        box.classList.remove('hidden');
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    // ============================================================ checkout
    var checkoutBtn = $('#btn-checkout');
    if (checkoutBtn) {
        checkoutBtn.addEventListener('click', function () {
            if (state.saving || state.done) return;
            var p = visitPayload(), errs = [];
            if (!p.doctor_id) errs.push('Please select the consulting doctor.');
            if (!p.patient.name) errs.push('Patient name is required.');
            if (!p.patient.gender) errs.push('Gender is required.');
            state.lines.forEach(function (l) { if (!(parseFloat(l.quantity) > 0)) errs.push('Invalid quantity for ' + l.name + '.'); });
            if (errs.length) { showErrors(errs); return; }
            showErrors([]);

            state.saving = true;
            checkoutBtn.disabled = true;
            checkoutBtn.innerHTML = '<span class="spinner"></span> Saving…';
            APP.loading(true, 'Saving prescription…');
            APP.ajax(APP.url('visits/checkout'), { method: 'POST', body: p }).then(function (res) {
                APP.loading(false);
                state.saving = false;
                if (!res.ok) {
                    checkoutBtn.disabled = false;
                    checkoutBtn.innerHTML = icon('check') + ' Checkout &amp; Print';
                    showErrors(res.errors || [res.message || 'Unable to save prescription.']);
                    APP.toast(res.message || 'Unable to save prescription.', 'error');
                    return;
                }
                state.done = true;
                state.dirty = false;
                checkoutBtn.innerHTML = icon('check') + ' Saved';
                lockScreen();
                $('#done-text').textContent = 'Visit ' + res.visit_no + (res.duplicate ? ' (already saved earlier)' : '') + ' — choose a print format:';
                var w = $('#done-warnings');
                if (res.warnings && res.warnings.length) {
                    w.innerHTML = '<div><strong>Saved with unusual vitals:</strong><ul>' + res.warnings.map(function (x) { return '<li>' + APP.esc(x) + '</li>'; }).join('') + '</ul></div>';
                    w.classList.remove('hidden');
                }
                $('#done-view').href = APP.url('visits/view', { id: res.visit_id });
                $('#done-prints').innerHTML = Object.keys(cfg.papers).map(function (k) {
                    return '<a target="_blank" href="' + APP.url('visits/print', { id: res.visit_id, paper: k }) + '">' + icon('printer') +
                        APP.esc(cfg.papers[k]) + (k === cfg.settings.defaultPaper ? '<span>Default</span>' : '<span>&nbsp;</span>') + '</a>';
                }).join('');
                APP.openModal('done-modal');
            });
        });
    }

    function lockScreen() {
        $$('#consult input, #consult select, #consult textarea, #consult button').forEach(function (el) { el.disabled = true; });
    }

    // ============================================== templates / refill data
    /** Applies template or previous-visit data. replace=true clears current clinical entries first. */
    function applyData(t, replace) {
        ['complaints', 'symptoms', 'diagnoses', 'investigations'].forEach(function (k) {
            if (!state.tags[k]) return;
            if (replace) state.tags[k].set([]);
            (t[k] || []).forEach(function (it) { state.tags[k].add(it); });
        });
        if (replace) state.lines = [];
        var skipped = 0;
        (t.medicines || []).forEach(function (m) { if (state.lines.some(function (l) { return l.medicine_id === m.medicine_id; })) { skipped++; } else { state.lines.push(normaliseLine(m)); } });
        renderRx();
        var textFields = { overall_instructions: '#overall-instructions', current_history: '#current-history', findings: '#findings', follow_up_instructions: '#follow-up-instructions', allergies: '#allergies' };
        Object.keys(textFields).forEach(function (k) {
            var el = $(textFields[k]);
            if (!el || !t[k]) return;
            el.value = replace || !el.value.trim() ? t[k] : el.value.trim() + '\n' + t[k];
        });
        var fu = $('#follow-up-date');
        if (fu && t.follow_up_date && !fu.value) fu.value = t.follow_up_date;
        if (t.vitals) setVitals(t.vitals);
        if (t.skipped && t.skipped.length) APP.toast('Skipped inactive medicines: ' + t.skipped.join(', '), 'warning');
        if (skipped) APP.toast(skipped + ' medicine(s) were already in the prescription.', 'info');
        markDirty();
    }

    var tplMenu = $('#tpl-menu');
    function renderTplMenu() {
        if (!tplMenu) return;
        if (!cfg.templates.length) {
            tplMenu.innerHTML = '<div class="muted small" style="padding:.5rem .65rem">No templates yet. Use “Save as Template”.</div>';
            return;
        }
        tplMenu.innerHTML = '<div class="dropdown-header">Your templates</div>' + cfg.templates.map(function (t) {
            return '<button type="button" data-tpl="' + t.id + '">' + (t.fav ? '★ ' : '') + APP.esc(t.name) + '</button>';
        }).join('');
        $$('[data-tpl]', tplMenu).forEach(function (b) {
            b.addEventListener('click', function () {
                var id = parseInt(b.getAttribute('data-tpl'), 10);
                APP.loading(true, 'Loading template…');
                APP.ajax(APP.url('templates/load', { id: id })).then(function (d) {
                    APP.loading(false);
                    if (!d.ok) { APP.toast(d.message || 'Template not found.', 'error'); return; }
                    applyData(d.template, false);
                    state.templateId = id;
                    APP.toast('Template “' + d.template.name + '” loaded. Edits here do not change the template.', 'success');
                });
            });
        });
    }
    renderTplMenu();

    if ($('#btn-save-template')) {
        $('#btn-save-template').addEventListener('click', function () {
            $('#tpl-save-name').value = '';
            APP.openModal('tpl-modal');
            $('#tpl-save-name').focus();
        });
        $$('[data-tpl-cancel]').forEach(function (b) { b.addEventListener('click', function () { APP.closeModal('tpl-modal'); }); });
        $('#tpl-save-ok').addEventListener('click', function () {
            var p = clinicalPayload();
            p.name = $('#tpl-save-name').value.trim();
            p.is_favourite = $('#tpl-save-fav').checked ? 1 : 0;
            p.follow_up_days = followUpDays();
            if (!p.name) { APP.toast('Template name is required.', 'warning'); return; }
            var btn = $('#tpl-save-ok');
            btn.disabled = true;
            APP.ajax(APP.url('templates/save'), { method: 'POST', body: p }).then(function (res) {
                btn.disabled = false;
                if (!res.ok) { APP.toast((res.errors || [res.message]).join(' '), 'error'); return; }
                cfg.templates.unshift({ id: res.id, name: p.name, fav: p.is_favourite });
                renderTplMenu();
                APP.closeModal('tpl-modal');
                APP.toast('Template saved successfully.', 'success');
            });
        });
    }

    function followUpDays() {
        var fu = $('#follow-up-date');
        if (!fu || !fu.value) return '';
        var diff = Math.round((new Date(fu.value + 'T00:00:00') - new Date(new Date().toDateString())) / 86400000);
        return diff > 0 ? diff : '';
    }

    if ($('#btn-clear')) {
        $('#btn-clear').addEventListener('click', function () {
            APP.confirm('Clear the whole form and start again?', 'Clear', true).then(function (ok) {
                if (ok) { state.dirty = false; location.href = APP.url(isVisit ? 'visits/new' : 'templates/create'); }
            });
        });
    }

    // ======================================================= template editor
    var tplSave = $('#btn-template-save');
    if (tplSave) {
        tplSave.addEventListener('click', function () {
            var p = clinicalPayload();
            p.id = cfg.templateId || null;
            p.name = $('#tpl-name').value.trim();
            p.description = $('#tpl-description').value.trim();
            p.is_favourite = $('#tpl-fav').checked ? 1 : 0;
            p.follow_up_days = $('#tpl-follow-days').value;
            if (!p.name) { showErrors(['Template name is required.']); return; }
            tplSave.disabled = true;
            APP.ajax(APP.url('templates/save'), { method: 'POST', body: p }).then(function (res) {
                tplSave.disabled = false;
                if (!res.ok) { showErrors(res.errors || [res.message]); return; }
                state.dirty = false;
                location.href = APP.url('templates', { saved: 1 });
            });
        });
    }

    // ============================================================ misc
    function markDirty() { state.dirty = true; }
    document.addEventListener('input', function (e) { if (e.target.closest && e.target.closest('#consult')) markDirty(); });
    window.addEventListener('beforeunload', function (e) {
        if (state.dirty && !state.done) { e.preventDefault(); e.returnValue = ''; }
    });

    // ============================================================ prefill
    var pre = cfg.prefill || {};
    if (pre.patient) fillPatient(pre.patient); else if (isVisit) fillPatient(null);
    applyData({
        complaints: pre.complaints, symptoms: pre.symptoms, diagnoses: pre.diagnoses, investigations: pre.investigations,
        medicines: pre.medicines, overall_instructions: pre.overall_instructions, current_history: pre.current_history,
        findings: pre.findings, follow_up_instructions: pre.follow_up_instructions, allergies: pre.allergies,
        follow_up_date: pre.follow_up_date, vitals: pre.vitals, refills: pre.refills
    }, true);
    state.templateId = pre.template_id || null;
    state.repeatedFrom = pre.repeated_from_visit_id || null;
    // v2: doctor of the token (desk staff opening the consultation)
    if (pre.doctor_id && $('#doctor-id')) { $('#doctor-id').value = String(pre.doctor_id); $('#doctor-id').dispatchEvent(new Event('change')); }
    // v2: consultation fee already on the registration receipt — locked here.
    if (pre.prepaid && feeInput) {
        feeInput.value = pre.prepaid.amount;
        feeInput.readOnly = true;
        $$('input[name=payment]').forEach(function (r) { r.checked = r.value === pre.prepaid.status; r.disabled = true; });
        var note = document.createElement('div');
        note.className = 'hint';
        note.textContent = 'Collected at registration on receipt ' + pre.prepaid.invoice_no + ' (' + pre.prepaid.status + '). Hospital medicines are billed to the same invoice.';
        feeInput.parentNode.appendChild(note);
    }
    document.dispatchEvent(new CustomEvent('consult:ready', { detail: cfg }));
    setRefill(state.repeatedFrom ? (parseInt(pre.refills, 10) || 0) + 1 : 0);
    followHint();
    if (!isVisit && cfg.template) {
        $('#tpl-name').value = cfg.template.name || '';
        $('#tpl-description').value = cfg.template.description || '';
        $('#tpl-fav').checked = !!cfg.template.is_favourite;
        $('#tpl-follow-days').value = cfg.template.follow_up_days === null || cfg.template.follow_up_days === undefined ? '' : cfg.template.follow_up_days;
    }
    checkVitals();
    refreshTotals();
    state.dirty = false;
})();
