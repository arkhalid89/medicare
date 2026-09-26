/* ==========================================================================
   One-page consultation + template editor (BRD §33–48, §55–57)
   Quantity, billing and Rx grouping mirror modules/visits/PrescriptionMath.php;
   the server recomputes everything on save.
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

    var freqs = {};
    cfg.frequencies.forEach(function (f) { freqs[f.id] = f; });

    var state = {
        patient: null,          // selected existing patient
        lines: [],              // prescription lines
        tags: {},               // key => TagInput
        templateId: null,
        repeatedFrom: null,
        dupConfirmedKey: '',
        feeTouched: false,
        saving: false,
        done: false,
        dirty: false
    };

    // ================================================================ math
    function autoQty(l) {
        var f = freqs[l.frequency_id];
        if (!f) return null;
        var dose = parseFloat(l.dose) || 0, days = parseInt(l.duration_days, 10) || 0, per = parseFloat(f.doses_per_day) || 0;
        if (f.calc_mode === 'manual' || dose <= 0 || per <= 0 || days <= 0) return null;
        var units = f.calc_mode === 'weekly' ? dose * per * Math.ceil(days / 7) : dose * per * days;
        var pack = parseFloat(l.pack_size) || 1;
        if (pack > 1) return Math.ceil(Math.round(units / pack * 10000) / 10000);
        return round2(units);
    }

    function lineTotal(l) { return round2(Math.max(0, parseFloat(l.quantity) || 0) * Math.max(0, parseFloat(l.unit_price) || 0)); }

    function bill() {
        var total = 0;
        state.lines.forEach(function (l) { total += lineTotal(l); });
        total = round2(total);
        var dType = $('#discount-type') ? $('#discount-type').value : 'amount';
        var dVal = $('#discount-value') ? Math.max(0, parseFloat($('#discount-value').value) || 0) : 0;
        var discount = dType === 'percent' ? round2(total * Math.min(100, dVal) / 100) : round2(Math.min(dVal, total));
        var after = round2(total - discount);
        var taxP = $('#tax-percent') ? Math.max(0, parseFloat($('#tax-percent').value) || 0) : 0;
        var tax = round2(after * taxP / 100);
        var net = round2(after + tax);
        var status = paymentStatus();
        var fee = status === 'Free' ? 0 : Math.max(0, parseFloat(($('#consultation-fee') || {}).value) || 0);
        return { total: total, discount: discount, tax: tax, net: net, fee: round2(fee), grand: round2(net + fee) };
    }

    function groups() {
        var keys = [];
        state.lines.forEach(function (l) {
            var k = l.source_id ? String(l.source_id) : '0';
            if (keys.indexOf(k) === -1) keys.push(k);
        });
        if (!cfg.settings.split || keys.length <= 1) {
            return [{ key: keys[0] || '0', name: state.lines[0] ? (state.lines[0].source_name || cfg.settings.unassigned) : '', count: state.lines.length, all: true }];
        }
        keys.sort(function (a, b) {
            var oa = a === '0' ? 1e9 : (cfg.sourceOrder[a] !== undefined ? cfg.sourceOrder[a] : 1e9 - 1);
            var ob = b === '0' ? 1e9 : (cfg.sourceOrder[b] !== undefined ? cfg.sourceOrder[b] : 1e9 - 1);
            return oa - ob || parseInt(a, 10) - parseInt(b, 10);
        });
        return keys.map(function (k) {
            var ls = state.lines.filter(function (l) { return (l.source_id ? String(l.source_id) : '0') === k; });
            return { key: k, name: k === '0' ? cfg.settings.unassigned : (ls[0].source_name || cfg.settings.unassigned), count: ls.length };
        });
    }

    function paymentStatus() {
        var r = $('input[name=payment]:checked');
        return r ? r.value : 'Paid';
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
        this.suggestions = [];

        APP.liveSearch(this.input, {
            minLength: 0,
            showOnFocus: true,
            fetch: function (q) {
                return APP.ajax(APP.url('visits/lookup', { type: self.type, q: q })).then(function (d) {
                    return (d.items || []).filter(function (it) { return !self.has(it.name); });
                });
            },
            render: function (it) {
                return '<div class="grow"><div class="t">' + APP.esc(it.name) + '</div>' + (it.code ? '<div class="s">' + APP.esc(it.code) + '</div>' : '') + '</div>' + '<span class="muted">' + icon('plus') + '</span>';
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

        // Quick-pick chips: first items of the master list
        APP.ajax(APP.url('visits/lookup', { type: this.type, q: '' })).then(function (d) {
            self.suggestions = (d.items || []).slice(0, 10);
            self.renderSuggest();
        });
    }
    TagInput.prototype.has = function (name) {
        name = String(name).trim().toLowerCase();
        return this.items.some(function (i) { return i.name.toLowerCase() === name; });
    };
    TagInput.prototype.add = function (item) {
        var name = String(item.name || '').trim();
        if (!name || this.has(name)) return;
        this.items.push({ id: item.id || null, name: name, code: item.code || '' });
        this.render();
        markDirty();
    };
    TagInput.prototype.remove = function (i) {
        this.items.splice(i, 1);
        this.render();
        markDirty();
    };
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
        var list = this.suggestions.filter(function (s) { return !self.has(s.name); }).slice(0, 8);
        this.suggestEl.innerHTML = list.map(function (s, i) { return '<button type="button" data-i="' + i + '">+ ' + APP.esc(s.name) + '</button>'; }).join('');
        $$('button', this.suggestEl).forEach(function (b) {
            b.addEventListener('click', function () { self.add(list[parseInt(b.getAttribute('data-i'), 10)]); });
        });
    };
    TagInput.prototype.value = function () {
        return this.items.map(function (i) { return { id: i.id, name: i.name }; });
    };

    function icon(name) {
        var p = {
            x: '<path d="M18 6 6 18M6 6l12 12"/>',
            plus: '<path d="M12 5v14M5 12h14"/>',
            trash: '<path d="M3 6h18"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>',
            up: '<polyline points="18 15 12 9 6 15"/>',
            refresh: '<polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/>',
            check: '<polyline points="20 6 9 17 4 12"/>',
            user: '<path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
            userplus: '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M19 8v6M22 11h-6"/>',
            printer: '<polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/>'
        }[name] || '';
        return '<svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + p + '</svg>';
    }

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
        return {
            medicine_id: l.medicine_id, name: l.name, generic_name: l.generic_name || '', strength: l.strength || '',
            dosage_form: l.dosage_form || '', dose: l.dose, dose_unit: l.dose_unit || '', frequency_id: l.frequency_id || '',
            route_id: l.route_id || '', duration_days: l.duration_days === null || l.duration_days === undefined ? '' : l.duration_days,
            quantity: l.quantity, qty_manual: l.qty_manual ? 1 : 0, unit: l.unit || '', pack_size: l.pack_size || 1,
            unit_price: l.unit_price, source_id: l.source_id || null, source_name: l.source_name || '', instructions: l.instructions || ''
        };
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
            return '<tr data-i="' + i + '">' +
                '<td class="muted">' + (i + 1) + '</td>' +
                '<td><div class="med-name">' + APP.esc(l.name) + (l.strength ? ' ' + APP.esc(l.strength) : '') + '</div>' +
                '<div class="med-sub">' + APP.esc([l.generic_name, l.dosage_form].filter(Boolean).join(' · ')) + '</div>' +
                (l.source_name ? '<span class="badge badge-gold" style="margin-top:2px">' + APP.esc(l.source_name) + '</span>' : '<span class="badge badge-muted" style="margin-top:2px">No source</span>') + '</td>' +
                '<td><div class="dose-cell"><input class="input" data-f="dose" type="number" min="0.01" step="0.25" value="' + APP.esc(l.dose) + '"><span class="unit">' + APP.esc(l.dose_unit) + '</span></div></td>' +
                '<td><select class="input" data-f="frequency_id">' + freqOptions(l.frequency_id) + '</select></td>' +
                '<td><select class="input" data-f="route_id">' + routeOptions(l.route_id) + '</select></td>' +
                '<td><input class="input" data-f="duration_days" type="number" min="0" step="1" value="' + APP.esc(l.duration_days) + '"></td>' +
                '<td><div class="qty-cell"><input class="input" data-f="quantity" type="number" min="0.01" step="1" value="' + APP.esc(l.quantity) + '" title="Quantity">' +
                '<button type="button" class="qty-recalc" title="Recalculate quantity">' + icon('refresh') + '</button></div>' +
                '<div class="med-sub">' + APP.esc(l.unit) + '</div></td>' +
                (showBilling ? '<td><input class="input" data-f="unit_price" type="number" min="0" step="0.01" value="' + APP.esc(l.unit_price) + '"></td><td class="num strong" data-total></td>' : '') +
                '<td><input class="input" data-f="instructions" list="instr-list" maxlength="255" value="' + APP.esc(l.instructions) + '" placeholder="Select or type"></td>' +
                '<td><div class="actions">' + (i > 0 ? '<button type="button" class="act" data-up title="Move up">' + icon('up') + '</button>' : '') +
                '<button type="button" class="act danger" data-remove title="Remove">' + icon('trash') + '</button></div></td>' +
                '</tr>';
        }).join('');
        $('#rx-empty').style.display = state.lines.length ? 'none' : '';
        state.lines.forEach(function (l, i) { updateRow(i); });
        renderGroups();
        refreshTotals();
    }

    function updateRow(i) {
        var l = state.lines[i];
        var tr = rxBody.querySelector('tr[data-i="' + i + '"]');
        if (!tr) return;
        var auto = autoQty(l);
        var manualDiffers = l.qty_manual && auto !== null && round2(auto) !== round2(l.quantity);
        var cell = $('.qty-cell', tr);
        cell.classList.toggle('manual', !!manualDiffers);
        $('[data-f=quantity]', tr).classList.toggle('manual', !!l.qty_manual);
        $('[data-f=quantity]', tr).title = l.qty_manual ? (auto === null ? 'Manual quantity (this frequency has no automatic rule)' : 'Manual quantity — calculated would be ' + auto) : 'Calculated: dose × doses/day × days';
        var total = $('[data-total]', tr);
        if (total) total.textContent = APP.money(lineTotal(l));
    }

    rxBody.addEventListener('input', onRxChange);
    rxBody.addEventListener('change', onRxChange);
    function onRxChange(e) {
        var el = e.target, f = el.getAttribute('data-f');
        if (!f) return;
        var tr = el.closest('tr'), i = parseInt(tr.getAttribute('data-i'), 10), l = state.lines[i];
        l[f] = el.value;
        if (f === 'quantity') {
            var auto = autoQty(l);
            l.qty_manual = auto === null || round2(auto) !== round2(el.value) ? 1 : 0;
        } else if (f === 'dose' || f === 'frequency_id' || f === 'duration_days') {
            var a = autoQty(l);
            if (!l.qty_manual && a !== null) {
                l.quantity = a;
                $('[data-f=quantity]', tr).value = a;
            } else if (a === null && !l.qty_manual) {
                l.qty_manual = 1;   // manual frequency (SOS / PRN): keep the doctor's quantity
            }
        }
        updateRow(i);
        refreshTotals();
        markDirty();
    }
    rxBody.addEventListener('click', function (e) {
        var tr = e.target.closest('tr');
        if (!tr) return;
        var i = parseInt(tr.getAttribute('data-i'), 10);
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
                $('[data-f=quantity]', tr).value = a;
                updateRow(i);
                refreshTotals();
            }
        }
    });

    function renderGroups() {
        var box = $('#rx-groups');
        if (!state.lines.length) { box.innerHTML = ''; return; }
        var gs = groups();
        if (gs.length === 1) {
            box.innerHTML = '<span class="badge badge-primary">Single Rx · ' + state.lines.length + ' item(s)</span>';
            return;
        }
        box.innerHTML = gs.map(function (g, i) {
            return '<span class="badge badge-gold">Rx ' + (i + 1) + ' — ' + APP.esc(g.name) + ' (' + g.count + ')</span>';
        }).join('');
    }

    APP.liveSearch($('#med-q'), {
        minLength: 0,
        showOnFocus: true,
        fetch: function (q) {
            return APP.ajax(APP.url('visits/medicines', { q: q })).then(function (d) { return d.items || []; });
        },
        render: function (m) {
            var added = state.lines.some(function (l) { return l.medicine_id === m.medicine_id; });
            return '<div class="grow"><div class="t">' + APP.esc(m.name) + (m.strength ? ' <span class="muted">' + APP.esc(m.strength) + '</span>' : '') + '</div>' +
                '<div class="s">' + APP.esc([m.generic_name, m.dosage_form, m.source_name].filter(Boolean).join(' · ')) + '</div></div>' +
                (showBilling ? '<strong class="nowrap">' + APP.money(m.unit_price) + '</strong>' : '') +
                (added ? '<span class="badge badge-success">Added</span>' : '<span class="badge badge-primary">' + icon('plus') + ' Add</span>');
        },
        onSelect: function (m) { if (addLine(m)) { $('#med-q').value = ''; } },
        empty: function (q) { return 'No active medicine matches “' + APP.esc(q) + '”'; }
    });

    // ============================================================ vitals
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
        updateBmi();
    }
    function updateBmi() {
        var w = parseFloat(($('[data-vital=weight]') || {}).value), h = parseFloat(($('[data-vital=height]') || {}).value);
        var bmi = $('#bmi');
        if (!bmi) return;
        bmi.value = w > 0 && h > 0 ? (Math.round(w / Math.pow(h / 100, 2) * 10) / 10) : '';
    }
    $$('[data-vital=weight], [data-vital=height]').forEach(function (i) { i.addEventListener('input', updateBmi); });

    // ============================================================ tags init
    $$('[data-tags]').forEach(function (root) {
        var t = new TagInput(root);
        state.tags[t.key] = t;
    });

    // =========================================================== patient
    var pf = {};
    $$('[data-p]').forEach(function (i) { pf[i.getAttribute('data-p')] = i; });

    function patientValue() {
        var g = $('input[name=p-gender]:checked');
        return {
            id: state.patient ? state.patient.id : null,
            name: pf.name ? pf.name.value.trim() : '',
            guardian_name: pf.guardian_name ? pf.guardian_name.value.trim() : '',
            gender: g ? g.value : '',
            age: pf.age ? pf.age.value.trim() : '',
            dob: pf.dob ? pf.dob.value : '',
            dob_estimated: state.patient && state.patient.dob_estimated && pf.dob && pf.dob.value === state.patient.dob ? 1 : 0,
            cnic: pf.cnic ? pf.cnic.value.trim() : '',
            phone: pf.phone ? pf.phone.value.trim() : '',
            blood_group: pf.blood_group ? pf.blood_group.value : '',
            city: pf.city ? pf.city.value.trim() : '',
            address: pf.address ? pf.address.value.trim() : ''
        };
    }

    function fillPatient(p) {
        state.patient = p;
        Object.keys(pf).forEach(function (k) { pf[k].value = ''; });
        if (p) {
            ['name', 'guardian_name', 'cnic', 'phone', 'city', 'address', 'blood_group'].forEach(function (k) { if (pf[k]) pf[k].value = p[k] || ''; });
            if (p.dob_estimated) { pf.age.value = p.age_years !== null ? p.age_years : ''; } else { pf.dob.value = p.dob || ''; }
            $$('input[name=p-gender]').forEach(function (r) { r.checked = r.value === p.gender; });
        } else {
            $$('input[name=p-gender]').forEach(function (r) { r.checked = false; });
        }
        var lock = !!p && !cfg.canEditPatient;
        Object.keys(pf).forEach(function (k) { pf[k].disabled = lock; });
        $$('input[name=p-gender]').forEach(function (r) { r.disabled = lock; });
        var badge = $('#patient-status');
        if (p) {
            badge.className = 'patient-badge';
            badge.innerHTML = icon('check') + ' Existing patient · ' + APP.esc(p.mrn);
        } else {
            badge.className = 'patient-badge new';
            badge.innerHTML = icon('userplus') + ' New patient — MRN assigned on checkout';
        }
        updateBar();
    }

    if ($('#patient-q')) {
        APP.liveSearch($('#patient-q'), {
            minLength: 2,
            fetch: function (q) { return APP.ajax(APP.url('patients/search', { q: q })).then(function (d) { return d.items || []; }); },
            render: function (p) {
                return '<span class="avatar" style="background:var(--secondary);color:var(--primary)">' + APP.esc(p.name.charAt(0).toUpperCase()) + '</span>' +
                    '<div class="grow"><div class="t">' + APP.esc(p.name) + (p.guardian_name ? ' <span class="muted">s/o, d/o, w/o ' + APP.esc(p.guardian_name) + '</span>' : '') + '</div>' +
                    '<div class="s">' + APP.esc([p.mrn, p.gender + (p.age ? ' ' + p.age : ''), p.cnic, p.phone].filter(Boolean).join(' · ')) + '</div></div>';
            },
            onSelect: function (p) { fillPatient(p); $('#patient-q').value = ''; markDirty(); },
            empty: function (q) { return 'No patient found for “' + APP.esc(q) + '”. Fill the form below to register a new patient.'; }
        });
    }
    if ($('#btn-new-patient')) {
        $('#btn-new-patient').addEventListener('click', function () { fillPatient(null); state.dupConfirmedKey = ''; pf.name.focus(); });
    }
    if (pf.dob) pf.dob.addEventListener('change', function () { if (pf.dob.value) pf.age.value = ''; });
    if (pf.age) pf.age.addEventListener('input', function () { if (pf.age.value) pf.dob.value = ''; });
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
            b.addEventListener('click', function () {
                fillPatient(items[parseInt(b.getAttribute('data-pick'), 10)]);
                APP.closeModal('dup-modal');
            });
        });
        $('#dup-new').onclick = function () { state.dupConfirmedKey = key; APP.closeModal('dup-modal'); };
        APP.openModal('dup-modal');
    }
    $$('[data-dup-cancel]').forEach(function (b) { b.addEventListener('click', function () { APP.closeModal('dup-modal'); }); });

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
    $$('[data-follow]').forEach(function (b) {
        b.addEventListener('click', function () {
            var days = parseInt(b.getAttribute('data-follow'), 10);
            var input = $('#follow-up-date');
            if (!days) { input.value = ''; return; }
            var d = new Date();
            d.setDate(d.getDate() + days);
            input.value = d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
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
        renderGroups();
        updateBar();
    }

    function updateBar() {
        var bp = $('#bar-patient');
        if (!bp) return;
        var name = pf.name ? pf.name.value.trim() : '';
        bp.textContent = name ? name + (state.patient ? ' · ' + state.patient.mrn : ' · new patient') : 'No patient selected';
        $('#bar-meds').textContent = state.lines.length + ' medicine(s)' + (groups().length > 1 ? ' · ' + groups().length + ' Rx sections' : '');
    }

    // ============================================================ payload
    function clinicalPayload() {
        var out = {};
        Object.keys(state.tags).forEach(function (k) { out[k] = state.tags[k].value(); });
        out.vitals = vitalsValue();
        out.medicines = state.lines.map(function (l) {
            return {
                medicine_id: l.medicine_id, name: l.name, dose: l.dose, frequency_id: l.frequency_id || null, route_id: l.route_id || null,
                duration_days: l.duration_days, quantity: l.quantity, qty_manual: l.qty_manual ? 1 : 0,
                unit_price: l.unit_price, instructions: l.instructions
            };
        });
        out.overall_instructions = ($('#overall-instructions') || {}).value || '';
        return out;
    }

    function visitPayload() {
        var p = clinicalPayload();
        var d = currentDoctor();
        p.token = cfg.token;
        p.doctor_id = d ? d.id : null;
        p.patient = patientValue();
        p.current_history = ($('#current-history') || {}).value || '';
        p.follow_up_date = ($('#follow-up-date') || {}).value || '';
        p.follow_up_instructions = ($('#follow-up-instructions') || {}).value || '';
        p.consultation_fee = feeInput ? feeInput.value : '';
        p.payment_status = paymentStatus();
        p.discount_type = $('#discount-type') ? $('#discount-type').value : 'amount';
        p.discount_value = $('#discount-value') ? $('#discount-value').value : 0;
        p.tax_percent = $('#tax-percent') ? $('#tax-percent').value : 0;
        p.repeated_from_visit_id = state.repeatedFrom;
        p.template_id = state.templateId;
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

    // ===================================================== templates (visit)
    function applyTemplateData(t, replace) {
        ['complaints', 'symptoms', 'diagnoses', 'investigations'].forEach(function (k) {
            if (!state.tags[k]) return;
            if (replace) state.tags[k].set([]);
            (t[k] || []).forEach(function (it) { state.tags[k].add(it); });
        });
        if (replace) state.lines = [];
        var skipped = 0;
        (t.medicines || []).forEach(function (m) { if (!addLine(m, true)) skipped++; });
        renderRx();
        var oi = $('#overall-instructions');
        if (oi && t.overall_instructions) {
            oi.value = replace || !oi.value.trim() ? t.overall_instructions : oi.value.trim() + '\n' + t.overall_instructions;
        }
        var fu = $('#follow-up-date');
        if (fu && t.follow_up_date && !fu.value) fu.value = t.follow_up_date;
        if (t.vitals) setVitals(t.vitals);
        if (t.skipped && t.skipped.length) APP.toast('Skipped inactive medicines: ' + t.skipped.join(', '), 'warning');
        if (skipped) APP.toast(skipped + ' medicine(s) were already in the prescription.', 'info');
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
                    applyTemplateData(d.template, false);
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
    ['complaints', 'symptoms', 'diagnoses', 'investigations'].forEach(function (k) { if (state.tags[k] && pre[k]) state.tags[k].set(pre[k]); });
    (pre.medicines || []).forEach(function (m) { state.lines.push(normaliseLine(m)); });
    if (pre.overall_instructions && $('#overall-instructions')) $('#overall-instructions').value = pre.overall_instructions;
    if (pre.follow_up_date && $('#follow-up-date')) $('#follow-up-date').value = pre.follow_up_date;
    if (pre.vitals) setVitals(pre.vitals);
    state.templateId = pre.template_id || null;
    state.repeatedFrom = pre.repeated_from_visit_id || null;
    if (!isVisit && cfg.template) {
        $('#tpl-name').value = cfg.template.name || '';
        $('#tpl-description').value = cfg.template.description || '';
        $('#tpl-fav').checked = !!cfg.template.is_favourite;
        $('#tpl-follow-days').value = cfg.template.follow_up_days === null || cfg.template.follow_up_days === undefined ? '' : cfg.template.follow_up_days;
    }
    renderRx();
    refreshTotals();
    state.dirty = false;
})();
