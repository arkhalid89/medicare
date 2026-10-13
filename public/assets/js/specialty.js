/* MediCare v2 — specialty panels on the consultation screen (Doc 6).
   The encounter's department decides which specialty panel is shown; data of a
   hidden panel is never sent. APP.dept is shared by the dental / obgyn modules. */
(function () {
    'use strict';
    var cfg = null, listeners = [], deptMap = {};

    APP.dept = {
        id: function () { var s = document.getElementById('visit-dept'); return s ? parseInt(s.value, 10) || null : null; },
        info: function () { return deptMap[APP.dept.id()] || { specialty_id: null, module: '', name: null }; },
        module: function () { return APP.dept.info().module || ''; },
        onChange: function (fn) { listeners.push(fn); if (cfg) fn(APP.dept.info()); },
        fire: function () { var i = APP.dept.info(); listeners.forEach(function (fn) { fn(i); }); }
    };

    function el(tag, attrs, html) {
        var e = document.createElement(tag);
        Object.keys(attrs || {}).forEach(function (k) { e.setAttribute(k, attrs[k]); });
        if (html !== undefined) e.innerHTML = html;
        return e;
    }

    // ------------------------------------------------------------ Tier 1 fields
    function renderFields(specId) {
        var box = document.getElementById('sp-fields'), fields = (cfg.templates || {})[specId] || [];
        box.innerHTML = '';
        fields.forEach(function (f) {
            var w = el('div', { 'class': 'field ' + (f.type === 'text' || f.type === 'multiselect' ? 'col-6' : 'col-3') });
            w.appendChild(el('label', {}, APP.esc(f.label) + (f.unit ? ' <span class="muted">(' + APP.esc(f.unit) + ')</span>' : '') + (f.required ? ' <span class="req">*</span>' : '')));
            var inp;
            if (f.type === 'select') {
                inp = el('select', { 'class': 'input' }, '<option value=""></option>' + f.options.map(function (o) { return '<option>' + APP.esc(o) + '</option>'; }).join(''));
            } else if (f.type === 'multiselect') {
                inp = el('div', { 'class': 'check-list' }, f.options.map(function (o) { return '<label class="check"><input type="checkbox" value="' + APP.esc(o) + '"> ' + APP.esc(o) + '</label>'; }).join(''));
            } else if (f.type === 'boolean') {
                inp = el('select', { 'class': 'input' }, '<option value=""></option><option value="1">Yes</option><option value="0">No</option>');
            } else {
                inp = el('input', { 'class': 'input', type: f.type === 'number' ? 'number' : (f.type === 'date' ? 'date' : 'text'), step: 'any' });
            }
            inp.dataset.field = f.id; inp.dataset.type = f.type;
            w.appendChild(inp);
            box.appendChild(w);
        });
        return fields.length;
    }

    function collectFields() {
        var out = {};
        document.querySelectorAll('#sp-fields [data-field]').forEach(function (i) {
            var t = i.dataset.type, v;
            if (t === 'multiselect') v = Array.prototype.map.call(i.querySelectorAll('input:checked'), function (c) { return c.value; });
            else if (t === 'boolean') v = i.value === '' ? '' : i.value === '1';
            else v = i.value;
            out[i.dataset.field] = v;
        });
        return out;
    }

    // ------------------------------------------------------------ light forms
    var bodyFindings = [];
    function renderForm(kind) {
        var box = document.getElementById('sp-form');
        box.innerHTML = '';
        box.dataset.kind = kind || '';
        if (!kind) return;
        if (kind === 'eye') {
            var va = '<option value=""></option>' + cfg.snellen.map(function (s) { return '<option>' + s + '</option>'; }).join('');
            box.innerHTML = '<div class="section-title small">Eye examination</div><table class="table table-compact eye-table"><thead><tr><th></th><th>Right (OD)</th><th>Left (OS)</th></tr></thead><tbody>' +
                '<tr><td>VA unaided</td><td><select class="input" data-k="va_od_unaided">' + va + '</select></td><td><select class="input" data-k="va_os_unaided">' + va + '</select></td></tr>' +
                '<tr><td>VA corrected</td><td><select class="input" data-k="va_od_corrected">' + va + '</select></td><td><select class="input" data-k="va_os_corrected">' + va + '</select></td></tr>' +
                '<tr><td>IOP (mmHg)</td><td><input class="input" type="number" step="0.1" data-k="iop_od"></td><td><input class="input" type="number" step="0.1" data-k="iop_os"></td></tr>' +
                '<tr><td>Refraction (sph / cyl / axis)</td><td class="flex"><input class="input" data-k="ref_od_sph" placeholder="Sph"><input class="input" data-k="ref_od_cyl" placeholder="Cyl"><input class="input" data-k="ref_od_axis" placeholder="Axis"></td>' +
                '<td class="flex"><input class="input" data-k="ref_os_sph" placeholder="Sph"><input class="input" data-k="ref_os_cyl" placeholder="Cyl"><input class="input" data-k="ref_os_axis" placeholder="Axis"></td></tr>' +
                '<tr><td>Slit lamp</td><td><input class="input" data-k="slit_od"></td><td><input class="input" data-k="slit_os"></td></tr></tbody></table>' +
                '<div class="field"><label>Fundus findings</label><input class="input" data-k="fundus"></div>';
        } else if (kind === 'growth') {
            box.innerHTML = '<div class="section-title small">Growth (WHO, under 5 years)</div><div class="form-grid">' +
                '<div class="field col-4"><label>Weight (kg)</label><input class="input" type="number" step="0.01" data-k="weight_kg"></div>' +
                '<div class="field col-4"><label>Length / height (cm)</label><input class="input" type="number" step="0.1" data-k="height_cm"></div>' +
                '<div class="field col-4"><label>Head circumference (cm)</label><input class="input" type="number" step="0.1" data-k="head_cm"></div></div>' +
                '<p class="hint">Percentiles are calculated on save and shown on the visit and the growth chart.</p>';
        } else if (kind === 'cardiac') {
            box.innerHTML = '<div class="section-title small">Cardiac investigation (optional)</div><div class="form-grid">' +
                '<div class="field col-3"><label>Type</label><select class="input" data-k="type"><option value=""></option>' + Object.keys(cfg.cardiacTypes).map(function (k) { return '<option value="' + k + '">' + cfg.cardiacTypes[k] + '</option>'; }).join('') + '</select></div>' +
                '<div class="field col-3"><label>Rhythm</label><input class="input" data-k="rhythm" placeholder="Sinus rhythm"></div>' +
                '<div class="field col-2"><label>HR</label><input class="input" type="number" data-k="heart_rate"></div>' +
                '<div class="field col-2"><label>EF %</label><input class="input" type="number" step="0.1" data-k="ef_percent"></div>' +
                '<div class="field col-12"><label>Findings</label><input class="input" data-k="findings"></div></div>';
        } else if (kind === 'audiogram') {
            var head = cfg.audioFreqs.map(function (f) { return '<th>' + (f >= 1000 ? (f / 1000) + 'k' : f) + '</th>'; }).join('');
            var row = function (ear) { return '<tr><td>' + (ear === 'R' ? 'Right' : 'Left') + '</td>' + cfg.audioFreqs.map(function (f) { return '<td><input class="input input-sm" type="number" step="5" min="-10" max="120" data-ear="' + ear + '" data-f="' + f + '" style="width:58px"></td>'; }).join('') +
                '<td><select class="input input-sm" data-tymp="' + ear + '"><option></option><option>A</option><option>As</option><option>Ad</option><option>B</option><option>C</option></select></td></tr>'; };
            box.innerHTML = '<div class="section-title small">Audiogram — air conduction (dB HL)</div><div class="table-wrap"><table class="table table-compact"><thead><tr><th></th>' + head + '<th>Tymp</th></tr></thead><tbody>' + row('R') + row('L') + '</tbody></table></div>';
        } else if (kind === 'bodymap') {
            bodyFindings = [];
            var maps = cfg.bodyMaps || [];
            var svg = maps.map(function (t) {
                return '<figure class="bodymap"><figcaption>' + APP.esc(t.name) + '</figcaption><svg viewBox="0 0 200 440" role="group" aria-label="' + APP.esc(t.name) + '">' +
                    t.regions.map(function (r) {
                        var s = r.shape, attrs = 'data-region="' + r.id + '" data-label="' + APP.esc(r.label) + '" tabindex="0" role="button" aria-label="' + APP.esc(r.label) + '" class="bm-region"';
                        return s.t === 'e' ? '<ellipse ' + attrs + ' cx="' + s.cx + '" cy="' + s.cy + '" rx="' + s.rx + '" ry="' + s.ry + '"><title>' + APP.esc(r.label) + '</title></ellipse>'
                            : '<rect ' + attrs + ' x="' + s.x + '" y="' + s.y + '" width="' + s.w + '" height="' + s.h + '" rx="6"><title>' + APP.esc(r.label) + '</title></rect>';
                    }).join('') + '</svg></figure>';
            }).join('');
            box.innerHTML = '<div class="section-title small">Body map — click a region</div><div class="bodymap-wrap">' + svg +
                '<div class="grow"><div class="form-grid" id="bm-form"><div class="field col-12"><strong id="bm-sel" class="muted">No region selected</strong></div>' +
                '<div class="field col-6"><label>Finding</label><select class="input" id="bm-type">' + cfg.findingTypes.map(function (t) { return '<option>' + APP.esc(t) + '</option>'; }).join('') + '</select></div>' +
                '<div class="field col-6"><label>Severity</label><select class="input" id="bm-sev"><option value=""></option><option>mild</option><option>moderate</option><option>severe</option></select></div>' +
                '<div class="field col-4"><label>Size (cm)</label><input class="input" type="number" step="0.1" id="bm-size"></div>' +
                '<div class="field col-8"><label>Description</label><input class="input" id="bm-desc"></div>' +
                '<div class="field col-12"><button type="button" class="btn btn-sm btn-outline" id="bm-add">Add finding</button></div></div>' +
                '<table class="table table-compact"><tbody id="bm-list"></tbody></table></div></div>';
            var selected = null;
            box.querySelectorAll('.bm-region').forEach(function (r) {
                var pick = function () {
                    box.querySelectorAll('.bm-region.sel').forEach(function (x) { x.classList.remove('sel'); });
                    r.classList.add('sel'); selected = r;
                    document.getElementById('bm-sel').textContent = r.dataset.label;
                };
                r.addEventListener('click', pick);
                r.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); pick(); } });
            });
            var list = function () {
                document.getElementById('bm-list').innerHTML = bodyFindings.map(function (f, i) {
                    return '<tr><td>' + APP.esc(f.label) + '</td><td>' + APP.esc(f.finding_type) + (f.severity ? ' · ' + f.severity : '') + (f.size_cm ? ' · ' + f.size_cm + ' cm' : '') + (f.description ? ' — ' + APP.esc(f.description) : '') +
                        '</td><td><button type="button" class="act danger" data-del="' + i + '">×</button></td></tr>';
                }).join('');
                box.querySelectorAll('.bm-region').forEach(function (r) { r.classList.toggle('has', bodyFindings.some(function (f) { return String(f.region_id) === r.dataset.region; })); });
            };
            document.getElementById('bm-add').addEventListener('click', function () {
                if (!selected) { APP.toast('Select a body region first.', 'warning'); return; }
                var code = selected.dataset.label.toLowerCase();
                bodyFindings.push({ region_id: selected.dataset.region, label: selected.dataset.label, laterality: code.indexOf('right') === 0 ? 'right' : (code.indexOf('left') === 0 ? 'left' : 'na'),
                    finding_type: document.getElementById('bm-type').value, severity: document.getElementById('bm-sev').value, size_cm: document.getElementById('bm-size').value,
                    description: document.getElementById('bm-desc').value });
                document.getElementById('bm-desc').value = ''; document.getElementById('bm-size').value = '';
                list();
            });
            document.getElementById('bm-list').addEventListener('click', function (e) { var b = e.target.closest('[data-del]'); if (b) { bodyFindings.splice(+b.dataset.del, 1); list(); } });
        }
    }

    function collectForm() {
        var box = document.getElementById('sp-form'), kind = box.dataset.kind, out = {};
        if (!kind) return null;
        if (kind === 'bodymap') return bodyFindings.map(function (f) { var c = Object.assign({}, f); delete c.label; return c; });
        if (kind === 'audiogram') {
            out = { L: {}, R: {} };
            box.querySelectorAll('[data-ear]').forEach(function (i) { if (i.value !== '') out[i.dataset.ear][i.dataset.f] = i.value; });
            box.querySelectorAll('[data-tymp]').forEach(function (s) { out['tymp_' + s.dataset.tymp] = s.value; });
            return out;
        }
        box.querySelectorAll('[data-k]').forEach(function (i) { out[i.dataset.k] = i.value; });
        return out;
    }

    // ------------------------------------------------------------ wiring
    function apply(info) {
        var card = document.getElementById('sp-card');
        if (!card) return;
        var n = info.specialty_id ? renderFields(info.specialty_id) : 0;
        var form = (cfg.forms || {})[info.module] || '';
        renderForm(form);
        card.classList.toggle('hidden', !n && !form);
        document.getElementById('sp-title').textContent = (info.name || 'Specialty') + ' — structured fields';
        var note = document.getElementById('sp-note-restricted');
        if (note) note.checked = info.module === 'psychiatry' || info.module === 'obgyn';
    }

    document.addEventListener('consult:ready', function (ev) {
        if (!ev.detail.modules || !ev.detail.modules.specialty) return;
        cfg = ev.detail.modules.specialty;
        deptMap = cfg.deptMap || {};
        var sel = document.getElementById('visit-dept');
        if (sel) sel.addEventListener('change', APP.dept.fire);
        APP.dept.onChange(apply);
        APP.consultModules = APP.consultModules || {};
        APP.consultModules.specialty = function () {
            var info = APP.dept.info(), card = document.getElementById('sp-card'), out = {};
            if (card && !card.classList.contains('hidden')) {
                out.params = collectFields();
                var f = collectForm(), kind = document.getElementById('sp-form').dataset.kind;
                if (f && kind) out[kind] = f;
            }
            var nt = document.getElementById('sp-note');
            if (nt && nt.value.trim()) out.note = { text: nt.value, restricted: document.getElementById('sp-note-restricted').checked };
            var rr = document.getElementById('sp-ref-reason');
            if (rr && rr.value.trim()) {
                out.referral = { reason: rr.value, to_department_id: document.getElementById('sp-ref-dept').value, external_to: document.getElementById('sp-ref-ext').value,
                    urgency: document.getElementById('sp-ref-urgency').value };
            }
            out.module = info.module;
            return out;
        };
    });
})();
