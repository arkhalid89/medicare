/* ==========================================================================
   MediCare Practice — shared front-end behaviour (no external libraries)
   ========================================================================== */
(function () {
    'use strict';

    var APP = window.APP = window.APP || {};

    // ------------------------------------------------------------ helpers
    APP.url = function (route, params) {
        params = params || {};
        var query = [];
        Object.keys(params).forEach(function (k) {
            var v = params[k];
            if (v !== null && v !== undefined && v !== '') {
                query.push(encodeURIComponent(k) + '=' + encodeURIComponent(v));
            }
        });
        if (APP.pretty) {
            return APP.base + '/' + route + (query.length ? '?' + query.join('&') : '');
        }
        query.unshift('r=' + encodeURIComponent(route));
        return APP.base + '/index.php?' + query.join('&');
    };

    APP.esc = function (s) {
        return String(s === null || s === undefined ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    };

    APP.num = function (n) {
        n = Math.round((parseFloat(n) || 0) * 100) / 100;
        return String(n);
    };

    APP.money = function (n) {
        n = Math.round((parseFloat(n) || 0) * 100) / 100;
        var decimals = Math.abs(n - Math.round(n)) > 0.001 ? 2 : 0;
        return APP.currency + ' ' + n.toLocaleString('en-US', { minimumFractionDigits: decimals, maximumFractionDigits: decimals });
    };

    APP.debounce = function (fn, ms) {
        var t;
        return function () {
            var args = arguments, ctx = this;
            clearTimeout(t);
            t = setTimeout(function () { fn.apply(ctx, args); }, ms);
        };
    };

    /** JSON request with CSRF token. Resolves with parsed JSON (even for 4xx). */
    APP.ajax = function (url, options) {
        options = options || {};
        var init = {
            method: options.method || 'GET',
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json', 'X-CSRF-Token': APP.csrf },
            credentials: 'same-origin'
        };
        if (options.body !== undefined) {
            init.headers['Content-Type'] = 'application/json';
            init.body = JSON.stringify(options.body);
        }
        return fetch(url, init).then(function (res) {
            return res.text().then(function (text) {
                var data;
                try { data = JSON.parse(text); } catch (e) {
                    data = { ok: false, message: 'Unexpected response from the server (' + res.status + ').' };
                }
                if (res.status === 401 && data.login) {
                    APP.toast(data.message || 'Session expired.', 'warning');
                    setTimeout(function () { location.href = APP.url('login'); }, 1200);
                }
                data._status = res.status;
                return data;
            });
        }, function () {
            return { ok: false, message: 'Could not reach the server. Please check that the application is running.' };
        });
    };

    APP.toast = function (message, type) {
        var box = document.getElementById('toasts');
        if (!box) { alert(message); return; }
        var el = document.createElement('div');
        el.className = 'toast ' + (type || 'info');
        el.innerHTML = '<div>' + APP.esc(message) + '</div>';
        box.appendChild(el);
        setTimeout(function () { el.style.opacity = '0'; el.style.transition = 'opacity .3s'; }, 4200);
        setTimeout(function () { el.remove(); }, 4600);
    };

    APP.loading = function (show, text) {
        var o = document.getElementById('loading-overlay');
        if (!o) return;
        document.getElementById('loading-text').textContent = text || 'Please wait…';
        o.classList.toggle('open', !!show);
    };

    /** Promise-based confirmation dialog. */
    APP.confirm = function (text, okLabel, danger) {
        return new Promise(function (resolve) {
            var modal = document.getElementById('confirm-modal');
            if (!modal) { resolve(window.confirm(text)); return; }
            document.getElementById('confirm-text').textContent = text || 'Are you sure?';
            var ok = modal.querySelector('[data-confirm-ok]');
            var cancel = modal.querySelector('[data-confirm-cancel]');
            ok.textContent = okLabel || 'Yes, continue';
            ok.className = 'btn ' + (danger === false ? 'btn-primary' : 'btn-danger');
            modal.classList.add('open');
            ok.focus();
            function done(v) {
                modal.classList.remove('open');
                ok.removeEventListener('click', yes);
                cancel.removeEventListener('click', no);
                modal.removeEventListener('click', outside);
                document.removeEventListener('keydown', esc);
                resolve(v);
            }
            function yes() { done(true); }
            function no() { done(false); }
            function outside(e) { if (e.target === modal) done(false); }
            function esc(e) { if (e.key === 'Escape') done(false); }
            ok.addEventListener('click', yes);
            cancel.addEventListener('click', no);
            modal.addEventListener('click', outside);
            document.addEventListener('keydown', esc);
        });
    };

    APP.openModal = function (id) { var m = document.getElementById(id); if (m) m.classList.add('open'); };
    APP.closeModal = function (id) { var m = document.getElementById(id); if (m) m.classList.remove('open'); };

    /**
     * Live search dropdown bound to an input.
     * opts.fetch(q) -> Promise<items>, opts.render(item) -> html, opts.onSelect(item)
     * opts.minLength (default 1), opts.showOnFocus (bool), opts.empty(q) -> html, opts.extra(q, items) -> [{html, action}]
     */
    APP.liveSearch = function (input, opts) {
        var box = opts.results || input.parentNode.querySelector('.search-results');
        if (!box) {
            box = document.createElement('div');
            box.className = 'search-results';
            input.parentNode.appendChild(box);
        }
        var items = [], extras = [], active = -1, seq = 0;
        var min = opts.minLength === undefined ? 1 : opts.minLength;

        function close() { box.classList.remove('open'); active = -1; }
        function highlight() {
            var nodes = box.querySelectorAll('.item');
            nodes.forEach(function (n, i) { n.classList.toggle('active', i === active); });
            if (nodes[active]) nodes[active].scrollIntoView({ block: 'nearest' });
        }
        function choose(i) {
            if (i < items.length) { opts.onSelect(items[i]); }
            else if (extras[i - items.length]) { extras[i - items.length].action(); }
            close();
        }
        function run() {
            var q = input.value.trim();
            if (q.length < min) { close(); return; }
            var my = ++seq;
            box.innerHTML = '<div class="state"><span class="spinner"></span> Searching…</div>';
            box.classList.add('open');
            opts.fetch(q).then(function (result) {
                if (my !== seq) return;
                items = result || [];
                extras = opts.extra ? (opts.extra(q, items) || []) : [];
                active = -1;
                if (!items.length && !extras.length) {
                    box.innerHTML = '<div class="state">' + (opts.empty ? opts.empty(q) : 'No results found') + '</div>';
                    return;
                }
                var html = '';
                items.forEach(function (it, i) { html += '<div class="item" data-i="' + i + '">' + opts.render(it) + '</div>'; });
                extras.forEach(function (x, j) { html += '<div class="item" data-i="' + (items.length + j) + '">' + x.html + '</div>'; });
                box.innerHTML = html;
            });
        }
        var debounced = APP.debounce(run, 220);
        input.addEventListener('input', debounced);
        input.addEventListener('focus', function () { if (opts.showOnFocus || input.value.trim().length >= Math.max(min, 1)) run(); });
        input.addEventListener('keydown', function (e) {
            var count = items.length + extras.length;
            if (!box.classList.contains('open') || !count) return;
            if (e.key === 'ArrowDown') { e.preventDefault(); active = (active + 1) % count; highlight(); }
            else if (e.key === 'ArrowUp') { e.preventDefault(); active = (active - 1 + count) % count; highlight(); }
            else if (e.key === 'Enter' && active >= 0) { e.preventDefault(); choose(active); }
            else if (e.key === 'Escape') { close(); }
        });
        box.addEventListener('mousedown', function (e) {
            var node = e.target.closest('.item');
            if (node) { e.preventDefault(); choose(parseInt(node.getAttribute('data-i'), 10)); }
        });
        input.addEventListener('blur', function () { setTimeout(close, 150); });
        return { close: close, run: run };
    };

    // ------------------------------------------------------------ charts
    /**
     * Minimal SVG charts. Config: {type:'line'|'bar', labels:[], series:[{name, data:[]}], money:bool}
     */
    APP.chart = function (el, cfg) {
        var W = el.clientWidth || 600, H = el.clientHeight || 240;
        var pad = { l: 46, r: 12, t: 12, b: 28 };
        var iw = W - pad.l - pad.r, ih = H - pad.t - pad.b;
        var all = [];
        cfg.series.forEach(function (s) { all = all.concat(s.data); });
        var max = Math.max.apply(null, all.concat([0]));
        var step = niceStep(max / 4 || 1);
        max = Math.max(step * 4, step);
        var n = cfg.labels.length;
        var colors = ['var(--primary)', 'var(--secondary)', '#1f9d55', '#e07b24'];
        var svg = '<svg viewBox="0 0 ' + W + ' ' + H + '" preserveAspectRatio="none">';
        for (var g = 0; g <= 4; g++) {
            var gy = pad.t + ih - (ih * g / 4);
            svg += '<line class="gridline" x1="' + pad.l + '" x2="' + (W - pad.r) + '" y1="' + gy + '" y2="' + gy + '"/>';
            svg += '<g class="axis"><text x="' + (pad.l - 6) + '" y="' + (gy + 3) + '" text-anchor="end">' + short(step * g) + '</text></g>';
        }
        var every = Math.max(1, Math.ceil(n / Math.max(1, Math.floor(iw / 58))));
        cfg.labels.forEach(function (lab, i) {
            if (i % every !== 0 && i !== n - 1) return;
            var x = cfg.type === 'bar' ? pad.l + iw * (i + 0.5) / n : pad.l + (n > 1 ? iw * i / (n - 1) : iw / 2);
            svg += '<g class="axis"><text x="' + x + '" y="' + (H - 8) + '" text-anchor="middle">' + APP.esc(lab) + '</text></g>';
        });
        cfg.series.forEach(function (s, si) {
            var color = colors[si % colors.length];
            if (cfg.type === 'bar') {
                var groupW = iw / n, bw = Math.max(4, Math.min(34, groupW * 0.62 / cfg.series.length));
                s.data.forEach(function (v, i) {
                    var h = ih * v / max, x = pad.l + groupW * i + (groupW - bw * cfg.series.length) / 2 + bw * si;
                    svg += '<rect x="' + x + '" y="' + (pad.t + ih - h) + '" width="' + bw + '" height="' + Math.max(h, 0) + '" rx="3" fill="' + color + '" data-tip="' + APP.esc(cfg.labels[i] + ': ' + fmt(v)) + '"/>';
                });
            } else {
                var pts = s.data.map(function (v, i) {
                    return [pad.l + (n > 1 ? iw * i / (n - 1) : iw / 2), pad.t + ih - ih * v / max];
                });
                var d = pts.map(function (p, i) { return (i ? 'L' : 'M') + p[0].toFixed(1) + ' ' + p[1].toFixed(1); }).join(' ');
                if (si === 0 && pts.length) {
                    svg += '<path d="' + d + ' L' + pts[pts.length - 1][0] + ' ' + (pad.t + ih) + ' L' + pts[0][0] + ' ' + (pad.t + ih) + ' Z" fill="' + color + '" opacity=".08"/>';
                }
                svg += '<path d="' + d + '" fill="none" stroke="' + color + '" stroke-width="2.2" stroke-linejoin="round"/>';
                pts.forEach(function (p, i) {
                    svg += '<circle cx="' + p[0] + '" cy="' + p[1] + '" r="3.2" fill="#fff" stroke="' + color + '" stroke-width="2" data-tip="' + APP.esc(cfg.labels[i] + ': ' + fmt(s.data[i])) + '"/>';
                });
            }
        });
        svg += '</svg><div class="chart-tip"></div>';
        el.innerHTML = svg;
        var tip = el.querySelector('.chart-tip');
        el.addEventListener('mousemove', function (e) {
            var t = e.target.getAttribute && e.target.getAttribute('data-tip');
            if (!t) { tip.style.display = 'none'; return; }
            var r = el.getBoundingClientRect();
            tip.textContent = t;
            tip.style.left = (e.clientX - r.left) + 'px';
            tip.style.top = (e.clientY - r.top) + 'px';
            tip.style.display = 'block';
        });
        el.addEventListener('mouseleave', function () { tip.style.display = 'none'; });

        function niceStep(x) {
            var p = Math.pow(10, Math.floor(Math.log10(x))), f = x / p;
            return (f <= 1 ? 1 : f <= 2 ? 2 : f <= 5 ? 5 : 10) * p;
        }
        function short(v) {
            if (v >= 1000000) return (v / 1000000).toFixed(1).replace('.0', '') + 'M';
            if (v >= 1000) return (v / 1000).toFixed(1).replace('.0', '') + 'k';
            return String(Math.round(v));
        }
        function fmt(v) { return cfg.money ? APP.money(v) : String(v); }
    };

    // ------------------------------------------------------------ wiring
    document.addEventListener('DOMContentLoaded', function () {
        var root = document.documentElement;

        // Sidebar collapse (desktop) / slide-in (mobile)
        document.querySelectorAll('[data-sidebar-toggle]').forEach(function (b) {
            b.addEventListener('click', function () {
                if (window.innerWidth <= 991) {
                    root.classList.toggle('sidebar-open');
                } else {
                    root.classList.toggle('sidebar-collapsed');
                    try { localStorage.setItem('mc_sidebar', root.classList.contains('sidebar-collapsed') ? 'collapsed' : 'open'); } catch (e) { /* storage unavailable */ }
                }
            });
        });
        document.querySelectorAll('[data-sidebar-close]').forEach(function (b) {
            b.addEventListener('click', function () { root.classList.remove('sidebar-open'); });
        });
        document.querySelectorAll('[data-nav-toggle]').forEach(function (b) {
            b.addEventListener('click', function () {
                if (root.classList.contains('sidebar-collapsed') && window.innerWidth > 991) {
                    root.classList.remove('sidebar-collapsed');
                    try { localStorage.setItem('mc_sidebar', 'open'); } catch (e) { /* ignore */ }
                }
                b.parentNode.classList.toggle('open');
            });
        });

        // Dropdowns
        document.addEventListener('click', function (e) {
            var toggle = e.target.closest('[data-dropdown-toggle]');
            document.querySelectorAll('[data-dropdown].open').forEach(function (d) {
                if (!toggle || d !== toggle.closest('[data-dropdown]')) d.classList.remove('open');
            });
            if (toggle) toggle.closest('[data-dropdown]').classList.toggle('open');
        });

        // Flash messages
        document.querySelectorAll('[data-dismiss]').forEach(function (b) {
            b.addEventListener('click', function () { b.closest('.alert').remove(); });
        });
        document.querySelectorAll('.alert-success[data-flash]').forEach(function (a) {
            setTimeout(function () { a.style.transition = 'opacity .4s'; a.style.opacity = '0'; setTimeout(function () { a.remove(); }, 400); }, 6000);
        });

        // Confirmation for destructive forms/links: data-confirm="message"
        document.addEventListener('submit', function (e) {
            var form = e.target;
            var msg = form.getAttribute('data-confirm');
            if (msg && !form._confirmed) {
                e.preventDefault();
                APP.confirm(msg, form.getAttribute('data-confirm-ok'), form.getAttribute('data-confirm-danger') !== 'false').then(function (ok) {
                    if (ok) { form._confirmed = true; lockSubmit(form); form.submit(); }
                });
                return;
            }
            lockSubmit(form);
        });
        document.addEventListener('click', function (e) {
            var a = e.target.closest('a[data-confirm]');
            if (!a) return;
            e.preventDefault();
            APP.confirm(a.getAttribute('data-confirm'), a.getAttribute('data-confirm-ok')).then(function (ok) { if (ok) location.href = a.href; });
        });

        // Prevent double submission of POST forms
        function lockSubmit(form) {
            if ((form.method || '').toLowerCase() !== 'post' || form.hasAttribute('data-no-lock')) return;
            var btn = form.querySelector('button[type=submit]:not([name]), button:not([type]):not([name])');
            if (btn && !btn.disabled) {
                setTimeout(function () {
                    btn.disabled = true;
                    btn.insertAdjacentHTML('afterbegin', '<span class="spinner"></span>');
                }, 0);
            }
            if (form.hasAttribute('data-loading')) APP.loading(true, form.getAttribute('data-loading'));
        }

        // Rows per page
        document.querySelectorAll('[data-per-page]').forEach(function (s) {
            s.addEventListener('change', function () {
                var u = new URL(location.href);
                u.searchParams.set('per_page', s.value);
                u.searchParams.delete('page');
                location.href = u.toString();
            });
        });

        // Password show/hide
        document.querySelectorAll('[data-pw-toggle]').forEach(function (b) {
            b.addEventListener('click', function () {
                var input = b.parentNode.querySelector('input');
                input.type = input.type === 'password' ? 'text' : 'password';
            });
        });

        // Charts
        document.querySelectorAll('[data-chart]').forEach(function (el) {
            try { APP.chart(el, JSON.parse(el.getAttribute('data-chart'))); } catch (e) { el.textContent = 'Chart unavailable'; }
        });

        // Global search suggestions
        var gs = document.querySelector('[data-global-search] input[name=q]');
        if (gs) {
            APP.liveSearch(gs, {
                minLength: 2,
                fetch: function (q) {
                    return APP.ajax(APP.url('search/quick', { q: q })).then(function (d) { return d.items || []; });
                },
                render: function (it) {
                    return '<div class="grow"><div class="t">' + APP.esc(it.title) + '</div><div class="s">' + APP.esc(it.subtitle) + '</div></div>'
                        + '<span class="badge ' + (it.type === 'Visit' ? 'badge-gold' : 'badge-primary') + '">' + APP.esc(it.type) + '</span>';
                },
                onSelect: function (it) { location.href = it.url; },
                empty: function (q) { return 'No match for “' + APP.esc(q) + '”. Press Enter for full search.'; }
            });
        }

        // Auto-submit selects inside filter forms marked data-autosubmit
        document.querySelectorAll('form[data-autosubmit] select').forEach(function (s) {
            s.addEventListener('change', function () { s.form.submit(); });
        });
    });
})();
