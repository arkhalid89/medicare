/* MediCare v2 — Gynae / Obs panel on the consultation (Doc 8 §5.3). GA from LMP,
   G/P/A/L derived on the server, live risk banner. Only for Gyn/Obs departments. */
(function () {
    'use strict';
    var cfg, info = null, gynDirty = false;
    var $ = function (id) { return document.getElementById(id); };
    var card = function () { return $('ob-card'); };

    function gaFrom(lmp) {
        if (!lmp) return null;
        var d = Math.floor((Date.now() - new Date(lmp + 'T00:00:00').getTime()) / 86400000);
        return d >= 0 && d <= 320 ? { w: Math.floor(d / 7), d: d % 7, dec: Math.round(d / 7 * 10) / 10 } : null;
    }
    function addDays(date, n) { var t = new Date(date + 'T00:00:00'); t.setDate(t.getDate() + n); return t.toISOString().slice(0, 10); }

    function riskCheck() {
        var v = function (k) { var e = card().querySelector('[data-a=' + k + ']'); return e ? (e.type === 'checkbox' ? e.checked : parseFloat(e.value)) : null; };
        var flags = [], sys = v('bp_systolic'), dia = v('bp_diastolic'), high = sys >= cfg.risk.sys || dia >= cfg.risk.dia;
        if (high && v('urine_protein_flag')) flags.push('BP ≥ ' + cfg.risk.sys + '/' + cfg.risk.dia + ' with proteinuria — suggests pre-eclampsia');
        else if (high) flags.push('Raised blood pressure');
        if (sys >= 160 || dia >= 110) flags.push('Severe-range BP');
        var hb = v('hb_g_dl'); if (hb && hb < cfg.risk.hb) flags.push('Anaemia (Hb ' + hb + ')');
        var fhr = v('fetal_heart_rate_bpm'); if (fhr && (fhr < 110 || fhr > 160)) flags.push('FHR outside 110–160');
        var ga = v('gestational_age_weeks'); if (ga >= 41) flags.push('Post-dates (≥ 41 weeks)');
        var hist = info && info.ongoing ? (info.ongoing.risk_reasons || []).filter(function (r) { return /C-section|age|Multiple|multipara/i.test(r); }) : [];
        var all = hist.concat(flags), box = $('ob-risk');
        box.classList.toggle('hidden', !all.length);
        box.innerHTML = all.length ? '<strong>Risk:</strong> ' + all.map(APP.esc).join(' · ') + ' — consider referral / escalation.' : '';
    }

    function fill(d) {
        info = d;
        var g = d.gyn || {};
        card().querySelectorAll('[data-g]').forEach(function (e) { e.value = g[e.dataset.g] !== undefined && g[e.dataset.g] !== null ? g[e.dataset.g] : ''; });
        gynDirty = false;
        $('ob-summary').textContent = d.gpal + (d.ongoing ? ' · pregnancy #' + d.ongoing.pregnancy_no + ' ongoing' : '');
        var cur = $('ob-preg-current'), nw = $('ob-preg-new');
        if (d.ongoing) {
            var o = d.ongoing;
            cur.innerHTML = '<div class="kv-row"><strong>Pregnancy #' + o.pregnancy_no + '</strong> · LMP ' + APP.esc(o.lmp_label || '—') + ' · <strong>GA ' + APP.esc(o.ga_label || '?') + '</strong> · EDD ' + APP.esc(o.edd_label) +
                (o.risk_level === 'high' ? ' <span class="badge badge-danger">High risk</span>' : '') + '</div>' +
                (o.last_anc ? '<div class="muted small mt-1">Last ANC ' + APP.esc(o.last_anc.date) + ': BP ' + APP.esc(o.last_anc.bp) + ', Wt ' + APP.esc(o.last_anc.weight || '—') + ' kg, FH ' + APP.esc(o.last_anc.fh || '—') + '</div>' : '') +
                '<div class="muted small mt-1">' + (o.anc_count || 0) + ' ANC visit(s). Delivery, PNC and scans: <a href="' + APP.url('obgyn', { patient_id: d.patient_id }) + '" target="_blank">obstetric record</a>.</div>';
            nw.classList.add('hidden');
            var ga = card().querySelector('[data-a=gestational_age_weeks]');
            if (ga && !ga.value && o.ga_dec) ga.value = o.ga_dec;
        } else {
            cur.innerHTML = '<p class="muted">No ongoing pregnancy. ' + (d.pregnancies ? d.pregnancies + ' previous pregnancy record(s).' : '') + '</p>';
            nw.classList.toggle('hidden', !cfg.canPregnancy);
            if (g.lmp_date) $('ob-p-lmp').value = g.lmp_date;
        }
        riskCheck();
    }

    function load(p) {
        info = null;
        if (!p || !p.id) { $('ob-summary').textContent = 'New patient — history starts today'; fill({ gyn: {}, gpal: 'G0', ongoing: null, pregnancies: 0 }); return; }
        APP.ajax(APP.url('obgyn/summary', { patient_id: p.id })).then(function (d) { if (d.ok) fill(d); });
    }

    function wire() {
        $('ob-tabs').addEventListener('click', function (e) {
            var a = e.target.closest('[data-tab]'); if (!a) return; e.preventDefault();
            $('ob-tabs').querySelectorAll('a').forEach(function (x) { x.classList.toggle('active', x === a); });
            card().querySelectorAll('[data-pane]').forEach(function (s) { s.classList.toggle('hidden', s.dataset.pane !== a.dataset.tab); });
        });
        card().querySelectorAll('[data-g]').forEach(function (e) { e.addEventListener('input', function () { gynDirty = true; }); e.disabled = !cfg.canEdit; });
        card().querySelectorAll('[data-a]').forEach(function (e) { e.addEventListener('input', riskCheck); e.addEventListener('change', riskCheck); e.disabled = !cfg.canAnc; });
        var calc = function () {
            var l = $('ob-p-lmp').value, ga = gaFrom(l);
            $('ob-p-calc').textContent = l ? 'GA ' + (ga ? ga.w + 'w ' + ga.d + 'd' : '?') + ' · EDD ' + addDays(l, 280) : '';
            if (ga && !card().querySelector('[data-a=gestational_age_weeks]').value) card().querySelector('[data-a=gestational_age_weeks]').value = ga.dec;
        };
        $('ob-p-lmp').addEventListener('change', function () { $('ob-open').checked = true; calc(); });
        $('ob-p-edd').addEventListener('change', function () { $('ob-open').checked = true; });
    }

    document.addEventListener('consult:patient', function (ev) { if (cfg) load(ev.detail); });

    document.addEventListener('consult:ready', function (ev) {
        if (!ev.detail.modules || !ev.detail.modules.obgyn) return;
        cfg = ev.detail.modules.obgyn;
        wire();
        APP.dept.onChange(function (i) { card().classList.toggle('hidden', i.module !== 'obgyn'); });
        load((ev.detail.prefill || {}).patient);
        APP.consultModules = APP.consultModules || {};
        APP.consultModules.obgyn = function () {
            if (card().classList.contains('hidden')) return null;
            var out = {};
            if (gynDirty && cfg.canEdit) { out.gyn = {}; card().querySelectorAll('[data-g]').forEach(function (e) { out.gyn[e.dataset.g] = e.value; }); }
            if (!(info && info.ongoing) && $('ob-open').checked) {
                out.pregnancy = { lmp_date: $('ob-p-lmp').value, edd_date: $('ob-p-edd').value, edd_method: $('ob-p-method').value, blood_group: $('ob-p-bg').value, is_multiple: $('ob-p-multi').checked };
            }
            var anc = {}, any = false;
            card().querySelectorAll('[data-a]').forEach(function (e) { var v = e.type === 'checkbox' ? e.checked : e.value; anc[e.dataset.a] = v; if (v && e.dataset.a !== 'gestational_age_weeks') any = true; });
            if (any && cfg.canAnc && ((info && info.ongoing) || out.pregnancy)) out.anc = anc;
            return out;
        };
    });
})();
