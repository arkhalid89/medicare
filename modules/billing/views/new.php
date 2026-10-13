<?php
/** @var array|null $patient */
use App\Core\View;

echo View::partial('page_header', [
    'title'       => 'New Invoice',
    'description' => 'Bill services (procedures, packages, certificates) for a patient or a walk-in customer. Prices come from the service catalog.',
    'breadcrumbs' => [['Billing'], ['New Invoice']],
]);
$canPrice = can('billing.services.manage');
?>
<form class="card" method="post" action="<?= e(url('billing/new')) ?>" id="inv-form" style="max-width:980px">
    <?= csrf_field() ?>
    <div class="card-body">
        <div class="form-grid">
            <div class="field col-6"><label>Patient</label>
                <input type="hidden" name="patient_id" id="inv-pid" value="<?= (int) ($patient['id'] ?? 0) ?>">
                <div class="search-box"><?= icon('search') ?><input class="input" id="inv-patient" autocomplete="off" placeholder="Search MRN, name, phone…" value="<?= e($patient ? $patient['name'] . ' (' . $patient['mrn'] . ')' : '') ?>"><div class="search-results"></div></div></div>
            <div class="field col-6"><label>…or walk-in customer name</label><input class="input" name="customer_name" maxlength="150"></div>
            <div class="field col-12"><label>Add service</label>
                <div class="search-box"><?= icon('plus') ?><input class="input" id="inv-svc" autocomplete="off" placeholder="Search services…"><div class="search-results"></div></div></div>
        </div>
        <table class="table mt-2"><thead><tr><th>Service</th><th class="num" style="width:110px">Qty</th><th class="num" style="width:150px">Price</th><th class="num">Amount</th><th></th></tr></thead>
            <tbody id="inv-lines"></tbody>
            <tfoot><tr><td colspan="3" class="text-right"><strong>Total</strong></td><td class="num"><strong id="inv-total">0</strong></td><td></td></tr></tfoot></table>
        <div class="field mt-2"><label>Notes</label><input class="input" name="notes" maxlength="500"></div>
    </div>
    <div class="card-footer"><button class="btn btn-primary"><?= icon('save') ?> Create Invoice</button></div>
</form>
<script>
(function () {
    var lines = document.getElementById('inv-lines'), n = 0, canPrice = <?= $canPrice ? 'true' : 'false' ?>;
    function total() {
        var t = 0;
        lines.querySelectorAll('tr').forEach(function (tr) {
            var a = (parseFloat(tr.querySelector('[data-q]').value) || 0) * (parseFloat(tr.querySelector('[data-p]').value) || 0);
            tr.querySelector('[data-a]').textContent = APP.money(a); t += a;
        });
        document.getElementById('inv-total').textContent = APP.money(t);
    }
    APP.liveSearch(document.getElementById('inv-patient'), {
        minLength: 2,
        fetch: function (q) { return APP.ajax(APP.url('patients/search', { q: q })).then(function (d) { return d.items || []; }); },
        render: function (p) { return '<div class="grow"><div class="t">' + APP.esc(p.name) + '</div><div class="s">' + APP.esc(p.mrn) + ' · ' + APP.esc(p.phone) + '</div></div>'; },
        onSelect: function (p) { document.getElementById('inv-pid').value = p.id; document.getElementById('inv-patient').value = p.name + ' (' + p.mrn + ')'; }
    });
    APP.liveSearch(document.getElementById('inv-svc'), {
        minLength: 1, showOnFocus: true,
        fetch: function (q) { return APP.ajax(APP.url('billing/service-search', { q: q })).then(function (d) { return d.items || []; }); },
        render: function (s) { return '<div class="grow"><div class="t">' + APP.esc(s.name) + '</div><div class="s">' + APP.esc(s.code || '') + ' · ' + APP.esc(s.service_type) + '</div></div><strong>' + APP.money(s.price) + '</strong>'; },
        onSelect: function (s) {
            var i = n++, tr = document.createElement('tr');
            tr.innerHTML = '<td>' + APP.esc(s.name) + '<input type="hidden" name="items[' + i + '][service_id]" value="' + s.id + '"></td>' +
                '<td><input class="input" type="number" min="1" step="1" name="items[' + i + '][quantity]" value="1" data-q></td>' +
                '<td><input class="input" type="number" min="0" step="1" name="items[' + i + '][unit_price]" value="' + s.price + '" data-p' + (canPrice ? '' : ' readonly') + '></td>' +
                '<td class="num" data-a></td><td><button type="button" class="act danger" data-x>×</button></td>';
            lines.appendChild(tr); total();
            document.getElementById('inv-svc').value = '';
        }
    });
    lines.addEventListener('input', total);
    lines.addEventListener('click', function (e) { if (e.target.closest('[data-x]')) { e.target.closest('tr').remove(); total(); } });
})();
</script>
