/* ==========================================================================
   MediCare — stock line editor (indents, PO, GRN, transfers, adjustments,
   counter sale). Item search with on-hand quantity, optional batch picker.

   APP.itemLines(table, {
       name: 'lines',                       // form field prefix
       location: function () { return id },  // location for on-hand / batches
       columns: ['qty', 'batch', 'expiry', 'cost', 'price', 'mrp', 'reason', 'signed', 'tax', 'bonus', 'rejected', 'mfg'],
       priceField: 'sale_price' | 'avg_cost' | 'last_price',
       onChange: function () {}
   })
   ========================================================================== */
(function () {
    'use strict';
    var APP = window.APP;

    APP.itemLines = function (table, opts) {
        opts = opts || {};
        var body = table.querySelector('tbody'), n = 0, name = opts.name || 'lines', cols = opts.columns || ['qty'];
        var search = table.parentNode.querySelector('[data-item-search]');

        function cell(i, key, it) {
            var f = name + '[' + i + '][' + key + ']';
            switch (key) {
                case 'qty': return '<td style="width:110px"><input class="input" type="number" step="0.001" min="0.001" name="' + f + '" value="' + (it.qty || 1) + '" required data-qty></td>';
                case 'signed': return '<td style="width:120px"><input class="input" type="number" step="0.001" name="' + name + '[' + i + '][qty_signed]" placeholder="+ / −" required data-qty></td>';
                case 'bonus': return '<td style="width:90px"><input class="input" type="number" step="0.001" min="0" name="' + name + '[' + i + '][bonus_qty]" value="0"></td>';
                case 'rejected': return '<td style="width:90px"><input class="input" type="number" step="0.001" min="0" name="' + name + '[' + i + '][qty_rejected]" value="0"></td>';
                case 'batch':
                    if (opts.batchSelect) {
                        return '<td style="min-width:200px"><select class="input" name="' + name + '[' + i + '][batch_id]" data-batch><option value="0">FEFO (automatic)</option></select></td>';
                    }
                    return '<td style="width:140px"><input class="input" name="' + name + '[' + i + '][batch_no]" maxlength="60"' + (it.is_batch_tracked ? ' required' : '') + '></td>';
                case 'expiry': return '<td style="width:150px"><input class="input" type="date" name="' + name + '[' + i + '][expiry_date]"' + (it.is_expiry_tracked ? ' required' : '') + '></td>';
                case 'mfg': return '<td style="width:150px"><input class="input" type="date" name="' + name + '[' + i + '][mfg_date]"></td>';
                case 'cost': return '<td style="width:120px"><input class="input" type="number" step="0.01" min="0" name="' + name + '[' + i + '][unit_cost]" value="' + (it.cost || 0) + '" data-price></td>';
                case 'price': return '<td style="width:120px"><input class="input" type="number" step="0.01" min="0" name="' + name + '[' + i + '][unit_price]" value="' + (it.price || 0) + '" data-price' + (opts.priceReadonly ? ' readonly' : '') + '></td>';
                case 'mrp': return '<td style="width:110px"><input class="input" type="number" step="0.01" min="0" name="' + f + '" value="' + (it.sale_price || '') + '"></td>';
                case 'tax': return '<td style="width:90px"><input class="input" type="number" step="0.1" min="0" name="' + name + '[' + i + '][tax_pct]" value="0"></td>';
                case 'reason': return '<td><input class="input" name="' + name + '[' + i + '][reject_reason]" maxlength="255"></td>';
                case 'note': return '<td><input class="input" name="' + name + '[' + i + '][note]" maxlength="255"></td>';
            }
            return '<td></td>';
        }

        function add(it) {
            var i = n++, tr = document.createElement('tr');
            tr.innerHTML = '<td><strong>' + APP.esc(it.name) + '</strong><div class="muted small">' + APP.esc(it.sku || '') +
                (it.on_hand !== undefined && it.on_hand !== null ? ' · on hand ' + APP.num(it.on_hand) : '') + '</div>' +
                '<input type="hidden" name="' + name + '[' + i + '][item_id]" value="' + it.id + '"></td>' +
                cols.map(function (c) { return cell(i, c, it); }).join('') +
                (opts.amount ? '<td class="num" data-amount></td>' : '') +
                '<td><button type="button" class="act danger" data-x title="Remove">×</button></td>';
            body.appendChild(tr);
            var bsel = tr.querySelector('[data-batch]');
            if (bsel && opts.location) {
                APP.ajax(APP.url('inventory/batches', { item_id: it.id, location_id: opts.location() })).then(function (d) {
                    (d.items || []).forEach(function (b) {
                        var o = document.createElement('option');
                        o.value = b.batch_id; o.textContent = (b.batch_no || 'no batch') + (b.expiry_date ? ' · exp ' + b.expiry_date : '') + ' · ' + APP.num(b.qty) + (b.blocked ? ' (blocked)' : '');
                        o.disabled = !!b.blocked && !opts.allowBlocked;
                        bsel.appendChild(o);
                    });
                });
            }
            refresh();
            var q = tr.querySelector('[data-qty]'); if (q) q.focus();
        }

        function refresh() {
            var total = 0;
            body.querySelectorAll('tr').forEach(function (tr) {
                var q = tr.querySelector('[data-qty]'), p = tr.querySelector('[data-price]'), a = tr.querySelector('[data-amount]');
                var amt = (parseFloat(q && q.value) || 0) * (parseFloat(p && p.value) || 0);
                if (a) a.textContent = APP.money(amt);
                total += amt;
            });
            var t = table.querySelector('[data-total]');
            if (t) t.textContent = APP.money(total);
            if (opts.onChange) opts.onChange(total);
        }

        body.addEventListener('input', refresh);
        body.addEventListener('click', function (e) { if (e.target.closest('[data-x]')) { e.target.closest('tr').remove(); refresh(); } });
        if (search) {
            APP.liveSearch(search, {
                minLength: 1, showOnFocus: true,
                fetch: function (q) {
                    return APP.ajax(APP.url('inventory/item-search', { q: q, location_id: opts.location ? opts.location() : '', types: opts.types || '' }))
                        .then(function (d) { return d.items || []; });
                },
                render: function (it) {
                    return '<div class="grow"><div class="t">' + APP.esc(it.name) + '</div><div class="s">' + APP.esc(it.sku || '') + ' · ' + APP.esc(it.item_type) +
                        (it.on_hand !== null ? ' · on hand ' + APP.num(it.on_hand) : '') + '</div></div>' + (opts.showPrice ? '<strong>' + APP.money(it.sale_price) + '</strong>' : '');
                },
                onSelect: function (it) {
                    it.price = opts.priceField ? it[opts.priceField] : it.sale_price;
                    it.cost = it.last_price || it.avg_cost;
                    add(it);
                    search.value = '';
                }
            });
        }
        return { add: add, refresh: refresh };
    };
})();
