<?php
/** @var int $loc @var array $locations @var array $today */
use App\Core\View;

echo View::partial('page_header', [
    'title' => 'Counter Sale', 'description' => 'OTC and outside-prescription sales: scan or search, FEFO batches are picked automatically. Controlled drugs are never sold over the counter.',
    'breadcrumbs' => [['Pharmacy', url('pharmacy')], ['Counter sale']],
]);
$print = (int) input('print', 0);
?>
<div class="grid grid-main">
    <form class="card" method="post" action="<?= e(url('pharmacy/sale', ['location_id' => $loc])) ?>">
        <?= csrf_field() ?>
        <div class="card-body">
            <div class="form-grid mb-2">
                <div class="field col-4"><label>Pharmacy</label><select class="input" id="ps-loc" onchange="location.href=APP.url('pharmacy/sale',{location_id:this.value})"><?php foreach ($locations as $id => $n): ?><option value="<?= $id ?>"<?= selected($id, $loc) ?>><?= e($n) ?></option><?php endforeach; ?></select></div>
                <div class="field col-4"><label>Patient (optional)</label><input type="hidden" name="patient_id" id="ps-pid"><div class="search-box"><?= icon('search') ?><input class="input" id="ps-patient" autocomplete="off" placeholder="MRN / phone"><div class="search-results"></div></div></div>
                <div class="field col-4"><label>…or customer name</label><input class="input" name="customer_name" maxlength="150"></div>
            </div>
            <div class="search-box mb-2"><?= icon('barcode') ?><input class="input" data-item-search placeholder="Scan barcode or type a medicine…" autocomplete="off" autofocus><div class="search-results"></div></div>
            <table class="table" id="ps-lines"><thead><tr><th>Item</th><th>Qty</th><th>Price</th><th class="num">Amount</th><th></th></tr></thead><tbody></tbody>
                <tfoot><tr><td colspan="3" class="text-right"><strong>Total</strong></td><td class="num"><strong data-total style="font-size:1.3rem">0</strong></td><td></td></tr></tfoot></table>
        </div>
        <div class="card-footer">
            <select class="input" name="sale_type" style="max-width:220px"><option value="otc_walk_in">OTC walk-in</option><option value="prescription_outside">Outside prescription</option><option value="staff">Staff</option></select>
            <select class="input" name="mode" style="max-width:180px"><?php foreach (hospital('payment_modes') as $k => $l): ?><option value="<?= e($k) ?>"><?= e($l) ?></option><?php endforeach; ?></select>
            <button class="btn btn-gold btn-lg"><?= icon('check') ?> Complete Sale</button>
        </div>
    </form>
    <div class="card card-flush"><div class="card-header"><div class="card-title"><?= icon('list') ?> Today's sales</div></div><div class="card-body"><ul class="list">
        <?php foreach ($today as $s): ?><li><div class="grow"><div class="title"><?= e($s['sale_number']) ?> · <?= e(money($s['total_amount'])) ?></div><div class="meta"><?= e(date('h:i A', strtotime($s['sold_at']))) ?> · <?= e($s['sold_by_name']) ?></div></div>
            <?php if ($s['invoice_id']): ?><a class="act" target="_blank" href="<?= e(url('billing/print', ['id' => $s['invoice_id']])) ?>"><?= icon('printer') ?></a><?php endif; ?></li><?php endforeach; ?>
        <?php if (!$today): ?><li class="muted small">No sales yet today.</li><?php endif; ?>
    </ul></div></div>
</div>
<script>document.addEventListener('DOMContentLoaded', function () {
    APP.itemLines(document.getElementById('ps-lines'), { columns: ['qty', 'price'], amount: true, showPrice: true, types: 'drug,medical_consumable',
        priceReadonly: <?= can('pharmacy.formulary.manage') ? 'false' : 'true' ?>, location: function () { return document.getElementById('ps-loc').value; } });
    APP.liveSearch(document.getElementById('ps-patient'), { minLength: 2,
        fetch: function (q) { return APP.ajax(APP.url('patients/search', { q: q })).then(function (d) { return d.items || []; }); },
        render: function (p) { return '<div class="grow"><div class="t">' + APP.esc(p.name) + '</div><div class="s">' + APP.esc(p.mrn) + '</div></div>'; },
        onSelect: function (p) { document.getElementById('ps-pid').value = p.id; document.getElementById('ps-patient').value = p.name; } });
    <?php if ($print): ?>window.open(APP.url('billing/print', { id: <?= $print ?> }), '_blank', 'width=420,height=640');<?php endif; ?>
});</script>
