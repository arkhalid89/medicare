/* MediCare v2 — selectable FDI tooth map on the consultation (Doc 8 §5.2; ported
   from dental_tooth_chart_prototype.html). Shown only for Dentistry departments. */
(function () {
    'use strict';
    var cfg, teeth = {}, procs = {}, base = {}, findings = [], nextId = 1, dent = 'permanent', selected = new Set();
    var $ = function (id) { return document.getElementById(id); };
    var Q = { perm: { ur: [18, 17, 16, 15, 14, 13, 12, 11], ul: [21, 22, 23, 24, 25, 26, 27, 28], lr: [48, 47, 46, 45, 44, 43, 42, 41], ll: [31, 32, 33, 34, 35, 36, 37, 38] },
              dec: { ur: [55, 54, 53, 52, 51], ul: [61, 62, 63, 64, 65], lr: [85, 84, 83, 82, 81], ll: [71, 72, 73, 74, 75] } };

    function last(n) {
        for (var i = findings.length - 1; i >= 0; i--) if (findings[i].teeth.indexOf(String(n)) > -1) return { condition: findings[i].condition, status: findings[i].procedure_id ? findings[i].status : 'n_a', surfaces: findings[i].surfaces };
        var b = base[String(n)];
        return b ? { condition: b.condition, status: b.procedure_status, surfaces: b.surfaces || [], old: true } : null;
    }

    function toothEl(n) {
        var t = teeth[String(n)] || { tooth_type: 'molar', name: 'Tooth ' + n, surfaces: [] }, f = last(n);
        var e = document.createElement('div');
        e.className = 'tooth ' + t.tooth_type + (f ? ' c-' + f.condition : '') + (f && f.status === 'planned' ? ' planned' : '') + (f && f.old ? ' old' : '');
        e.tabIndex = 0; e.dataset.tooth = n; e.setAttribute('role', 'button');
        var label = 'Tooth ' + n + ', ' + t.name + (f ? ', ' + f.condition.replace(/_/g, ' ') + (f.status === 'planned' ? ' (planned)' : '') : '');
        e.setAttribute('aria-label', label); e.title = label;
        var mk = f ? (f.condition === 'missing' ? '×' : (f.surfaces || []).map(function (s) { return s[0].toUpperCase(); }).join('')) : '';
        e.innerHTML = '<span class="mk">' + mk + '</span><span class="n">' + n + '</span>';
        return e;
    }

    function row(cls, l, r) {
        var w = document.createElement('div'); w.className = 'dc-row ' + cls;
        l.forEach(function (n) { w.appendChild(toothEl(n)); });
        var m = document.createElement('div'); m.className = 'mid'; w.appendChild(m);
        r.forEach(function (n) { w.appendChild(toothEl(n)); });
        return w;
    }
    function qrow(a, b) {
        var r = document.createElement('div'); r.className = 'dc-row dc-q';
        r.innerHTML = '<button type="button" class="btn btn-sm btn-ghost" data-quad="' + a + '">Q' + a + '</button><button type="button" class="btn btn-sm btn-ghost" data-quad="' + b + '">Q' + b + '</button>';
        return r;
    }

    function render() {
        var a = $('dc-arch'); a.innerHTML = '';
        var sets = dent === 'permanent' ? [Q.perm] : (dent === 'deciduous' ? [Q.dec] : [Q.dec, Q.perm]);
        var lbl = document.createElement('div'); lbl.className = 'dc-lbl'; lbl.textContent = 'UPPER · patient\'s RIGHT ◂ ▸ LEFT'; a.appendChild(lbl);
        sets.forEach(function (s, i) {
            var dec = s === Q.dec;
            if (i === 0) a.appendChild(qrow(dec ? 5 : 1, dec ? 6 : 2));
            a.appendChild(row('upper' + (dec ? ' dec' : ''), s.ur, s.ul));
        });
        sets.slice().reverse().forEach(function (s, i, arr) {
            var dec = s === Q.dec;
            a.appendChild(row('lower' + (dec ? ' dec' : ''), s.lr, s.ll));
            if (i === arr.length - 1) a.appendChild(qrow(dec ? 8 : 4, dec ? 7 : 3));
        });
        var l2 = document.createElement('div'); l2.className = 'dc-lbl'; l2.textContent = 'LOWER'; a.appendChild(l2);
        refreshSel();
    }

    function allTeeth() { return Array.prototype.map.call(document.querySelectorAll('#dc-arch .tooth'), function (e) { return +e.dataset.tooth; }); }

    function refreshSel() {
        document.querySelectorAll('#dc-arch .tooth').forEach(function (e) { e.classList.toggle('selected', selected.has(+e.dataset.tooth)); });
        var s = Array.from(selected).sort(function (a, b) { return a - b; });
        $('dc-sel').textContent = s.length ? 'Selected: ' + s.join(', ') + (s.length === 1 ? ' — ' + (teeth[String(s[0])] || {}).name : '') : 'No tooth selected';
        var list = s.length === 1 ? (teeth[String(s[0])] || {}).surfaces || [] : ['mesial', 'distal', 'occlusal', 'incisal', 'buccal', 'labial', 'lingual', 'palatal'];
        $('dc-surf').innerHTML = list.map(function (x) { return '<label class="check"><input type="checkbox" value="' + x + '"> ' + x[0].toUpperCase() + x.slice(1) + '</label>'; }).join('');
    }

    function toggle(n, multi) {
        if (!multi) { var only = selected.size === 1 && selected.has(n); selected.clear(); if (!only) selected.add(n); }
        else if (selected.has(n)) selected.delete(n); else selected.add(n);
        refreshSel();
    }

    function renderRows() {
        render();
        var tb = $('dc-rows');
        if (!findings.length) { tb.innerHTML = '<tr><td colspan="7" class="muted">Nothing recorded in this visit yet.</td></tr>'; }
        else tb.innerHTML = findings.map(function (f) {
            var p = procs[f.procedure_id];
            return '<tr><td><strong>' + (f.teeth.join(', ') || '—') + '</strong></td><td>' + APP.esc(cfg.conditions[f.condition] || f.condition) + '</td><td>' + (f.surfaces.join(', ') || '—') + '</td><td>' +
                (p ? APP.esc(p.name) : '—') + '</td><td>' + (p ? (f.status === 'completed' ? '<span class="badge badge-success">done today</span>' : '<span class="badge badge-info">planned</span>') : '') +
                '</td><td class="num">' + (p ? APP.money(p.price * Math.max(1, f.teeth.length)) : '') + '</td><td><button type="button" class="act danger" data-del="' + f.id + '" aria-label="Remove">×</button></td></tr>';
        }).join('');
        var plan = 0, today = 0;
        findings.forEach(function (f) { var p = procs[f.procedure_id]; if (!p) return; var v = p.price * Math.max(1, f.teeth.length); if (f.status === 'completed') today += v; else plan += v; });
        $('dc-total').textContent = APP.money(plan); $('dc-today').textContent = APP.money(today);
    }

    function setDent(k) {
        dent = k; selected.clear();
        document.querySelectorAll('#dc-dent button').forEach(function (b) { b.setAttribute('aria-pressed', b.dataset.d === k ? 'true' : 'false'); });
        render();
    }

    function defaultDent() {
        var p = APP.consultPatient ? APP.consultPatient() : {}, y = parseInt(p.age_years, 10);
        if (isNaN(y) && p.dob) y = Math.floor((Date.now() - new Date(p.dob).getTime()) / 31557600000);
        setDent(isNaN(y) || y > 12 ? 'permanent' : (y < 6 ? 'deciduous' : 'mixed'));
    }

    function loadBase(patient) {
        base = {};
        $('dc-baseline').textContent = '';
        if (!patient || !patient.id) { renderRows(); return; }
        APP.ajax(APP.url('dental/state', { patient_id: patient.id })).then(function (d) {
            if (!d.ok) return;
            base = d.state || {};
            var n = Object.keys(base).length;
            $('dc-baseline').textContent = n ? 'Showing existing chart (' + n + ' teeth, last ' + d.last_date + '); open plan: ' + (d.open_items || 0) + ' step(s)' : 'No previous dental chart';
            renderRows();
        });
    }

    function wire() {
        $('dc-arch').addEventListener('click', function (ev) {
            var q = ev.target.closest('[data-quad]');
            if (q) { var qd = +q.dataset.quad; allTeeth().forEach(function (n) { if (Math.floor(n / 10) === qd) selected.add(n); }); refreshSel(); return; }
            var t = ev.target.closest('.tooth'); if (t) toggle(+t.dataset.tooth, ev.ctrlKey || ev.shiftKey || ev.metaKey);
        });
        $('dc-arch').addEventListener('keydown', function (ev) {
            var t = ev.target.closest('.tooth'); if (!t) return;
            if (ev.key === ' ' || ev.key === 'Enter') { ev.preventDefault(); toggle(+t.dataset.tooth, true); }
            else if (ev.key === 'ArrowRight' || ev.key === 'ArrowLeft') {
                var all = Array.prototype.slice.call(document.querySelectorAll('#dc-arch .tooth')), j = all.indexOf(t) + (ev.key === 'ArrowRight' ? 1 : -1);
                if (all[j]) { all[j].focus(); ev.preventDefault(); }
            } else if (ev.key === 'Escape') { selected.clear(); refreshSel(); }
        });
        document.querySelectorAll('#dental-card [data-arch]').forEach(function (b) {
            b.addEventListener('click', function () {
                allTeeth().forEach(function (n) { var up = Math.floor(n / 10) % 4 === 1 || Math.floor(n / 10) % 4 === 2; if ((b.dataset.arch === 'upper') === up) selected.add(n); });
                refreshSel();
            });
        });
        $('dc-clear').addEventListener('click', function () { selected.clear(); refreshSel(); });
        document.querySelectorAll('#dc-dent button').forEach(function (b) { b.addEventListener('click', function () { setDent(b.dataset.d); }); });
        Object.keys(cfg.conditions).forEach(function (k) { var o = document.createElement('option'); o.value = k; o.textContent = cfg.conditions[k]; $('dc-cond').appendChild(o); });
        $('dc-cond').value = 'caries';
        $('dc-proc').innerHTML = '<option value="">— none —</option>' + cfg.procedures.map(function (p) { return '<option value="' + p.id + '">' + APP.esc(p.name) + (p.price ? ' — ' + APP.money(p.price) : '') + '</option>'; }).join('');
        $('dc-proc').addEventListener('change', function () {
            var p = procs[this.value], done = document.querySelector('input[name=dc-st][value=completed]');
            done.disabled = !!p && !p.can_complete; if (done.disabled) document.querySelector('input[name=dc-st][value=planned]').checked = true;
        });
        $('dc-legend').innerHTML = ['healthy', 'caries', 'filled', 'crown', 'root_canal_needed', 'fracture', 'missing', 'impacted', 'abscess', 'implant'].map(function (c) {
            return '<span><i class="c-' + c + '"></i>' + APP.esc(cfg.conditions[c]) + '</span>'; }).join('') + '<span><i class="lg-planned"></i>Planned (dashed)</span><span><i class="lg-old"></i>Earlier visit</span>';
        $('dc-add').addEventListener('click', function () {
            var p = procs[$('dc-proc').value];
            if (!selected.size && (!p || p.requires_tooth)) { $('dc-msg').textContent = 'Select at least one tooth first.'; return; }
            var surfs = Array.prototype.map.call($('dc-surf').querySelectorAll('input:checked'), function (i) { return i.value; });
            if (p && p.requires_surface && !surfs.length) { $('dc-msg').textContent = p.name + ' needs the surface(s).'; return; }
            var st = document.querySelector('input[name=dc-st]:checked').value;
            findings.push({ id: nextId++, teeth: Array.from(selected).sort(function (a, b) { return a - b; }).map(String), condition: $('dc-cond').value, surfaces: surfs,
                severity: $('dc-sev').value, mobility: $('dc-mob').value, procedure_id: p ? p.id : null, status: p ? st : 'n_a', notes: $('dc-notes').value });
            $('dc-msg').textContent = 'Added for ' + (selected.size || 'the whole mouth') + (selected.size ? ' tooth / teeth.' : '.');
            selected.clear(); $('dc-notes').value = '';
            renderRows();
        });
        $('dc-rows').addEventListener('click', function (ev) { var b = ev.target.closest('[data-del]'); if (!b) return; findings = findings.filter(function (f) { return f.id !== +b.dataset.del; }); renderRows(); });
    }

    document.addEventListener('consult:patient', function (ev) { if (cfg) { loadBase(ev.detail); defaultDent(); } });

    document.addEventListener('consult:ready', function (ev) {
        if (!ev.detail.modules || !ev.detail.modules.dental) return;
        cfg = ev.detail.modules.dental;
        cfg.teeth.forEach(function (t) { teeth[t.tooth_number] = t; });
        cfg.procedures.forEach(function (p) { procs[p.id] = p; });
        wire();
        APP.dept.onChange(function (info) { $('dental-card').classList.toggle('hidden', info.module !== 'dental'); });
        defaultDent();
        var pre = ev.detail.prefill || {};
        if (pre.patient) loadBase(pre.patient);
        renderRows();
        APP.consultModules = APP.consultModules || {};
        APP.consultModules.dental = function () {
            if ($('dental-card').classList.contains('hidden')) return null;
            return { dentition: dent, notes: $('dc-general').value, findings: findings.map(function (f) { var c = Object.assign({}, f); delete c.id; return c; }) };
        };
    });
})();
