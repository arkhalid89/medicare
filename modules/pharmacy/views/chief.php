<?php
/** @var array $interactions @var array $allergies @var array $subs @var array $medicines @var array $expiring */
use App\Core\View;

echo View::partial('page_header', [
    'title' => 'Chief Pharmacist', 'description' => 'Formulary safety data used at dispensing, sale-price approval, near-expiry stock and recalls.',
    'breadcrumbs' => [['Pharmacy', url('pharmacy')], ['Chief pharmacist']],
    'actions' => '<a class="btn btn-outline" href="' . e(url('pharmacy/controlled')) . '">' . icon('shield') . ' Controlled register</a><a class="btn btn-outline" href="' . e(url('inventory/expiry')) . '">' . icon('clock') . ' Expiry & recalls</a>',
]);
$medSelect = static function (string $name) use ($medicines): string {
    $h = '<select class="input" name="' . $name . '" required><option value="">Medicine…</option>';
    foreach ($medicines as $id => $n) {
        $h .= '<option value="' . $id . '">' . e($n) . '</option>';
    }
    return $h . '</select>';
};
?>
<div class="grid grid-2">
    <div class="card card-flush"><div class="card-header"><div class="card-title"><?= icon('alert') ?> Drug interactions (generic names)</div></div>
        <div class="card-body"><table class="table table-compact"><tbody>
            <?php foreach ($interactions as $i): ?><tr><td><strong><?= e($i['generic_a']) ?></strong> + <strong><?= e($i['generic_b']) ?></strong><div class="muted small"><?= e($i['description']) ?></div></td>
                <td><span class="badge <?= in_array($i['severity'], ['major', 'contraindicated'], true) ? 'badge-danger' : 'badge-warning' ?>"><?= e($i['severity']) ?></span></td>
                <td><form method="post" action="<?= e(url('pharmacy/chief')) ?>"><?= csrf_field() ?><input type="hidden" name="do" value="interaction_off"><input type="hidden" name="id" value="<?= (int) $i['id'] ?>"><button class="act danger">×</button></form></td></tr><?php endforeach; ?>
        </tbody></table>
        <form method="post" action="<?= e(url('pharmacy/chief')) ?>" class="form-grid" style="padding:1rem"><?= csrf_field() ?><input type="hidden" name="do" value="interaction">
            <div class="field col-4"><input class="input" name="generic_a" placeholder="Generic A" required></div><div class="field col-4"><input class="input" name="generic_b" placeholder="Generic B" required></div>
            <div class="field col-4"><select class="input" name="severity"><option>minor</option><option selected>moderate</option><option>major</option><option>contraindicated</option></select></div>
            <div class="field col-9"><input class="input" name="description" placeholder="Effect / advice" required></div><div class="field col-3"><button class="btn btn-outline">Add</button></div></form></div></div>
    <div class="card card-flush"><div class="card-header"><div class="card-title"><?= icon('alert') ?> Allergy classes</div></div>
        <div class="card-body"><table class="table table-compact"><tbody>
            <?php foreach ($allergies as $a): ?><tr><td><?= e($a['name']) ?></td><td><span class="badge badge-danger"><?= e($a['allergy_class']) ?></span></td>
                <td><form method="post" action="<?= e(url('pharmacy/chief')) ?>"><?= csrf_field() ?><input type="hidden" name="do" value="allergy_del"><input type="hidden" name="id" value="<?= (int) $a['id'] ?>"><button class="act danger">×</button></form></td></tr><?php endforeach; ?>
        </tbody></table>
        <form method="post" action="<?= e(url('pharmacy/chief')) ?>" class="form-grid" style="padding:1rem"><?= csrf_field() ?><input type="hidden" name="do" value="allergy">
            <div class="field col-6"><?= $medSelect('medicine_id') ?></div><div class="field col-4"><input class="input" name="allergy_class" placeholder="e.g. Penicillin, Sulfa, NSAID" required></div>
            <div class="field col-2"><button class="btn btn-outline">Add</button></div></form></div></div>
    <div class="card card-flush"><div class="card-header"><div class="card-title"><?= icon('repeat') ?> Substitutes (when out of stock)</div></div>
        <div class="card-body"><ul class="list"><?php foreach ($subs as $s): ?><li><?= e($s['med']) ?> → <strong><?= e($s['sub']) ?></strong></li><?php endforeach; ?></ul>
        <form method="post" action="<?= e(url('pharmacy/chief')) ?>" class="form-grid" style="padding:1rem"><?= csrf_field() ?><input type="hidden" name="do" value="substitute">
            <div class="field col-5"><?= $medSelect('medicine_id') ?></div><div class="field col-5"><?= $medSelect('substitute_medicine_id') ?></div><div class="field col-2"><button class="btn btn-outline">Add</button></div></form>
        <p class="muted small" style="padding:0 1rem 1rem">Medicines with the same generic name are offered automatically.</p></div></div>
    <div class="card card-flush"><div class="card-header"><div class="card-title"><?= icon('clock') ?> Near-expiry in pharmacies (90 days)</div></div>
        <div class="card-body"><table class="table table-compact"><tbody>
            <?php foreach ($expiring as $x): ?><tr><td><?= e($x['name']) ?></td><td class="small"><?= e($x['batch_no']) ?></td><td class="small"><?= e(fmt_date($x['expiry_date'])) ?></td><td class="num"><?= e(num($x['qty'])) ?></td></tr><?php endforeach; ?>
            <?php if (!$expiring): ?><tr><td class="muted">Nothing near expiry.</td></tr><?php endif; ?>
        </tbody></table></div></div>
</div>
<form class="card" method="post" action="<?= e(url('pharmacy/chief')) ?>"><?= csrf_field() ?><input type="hidden" name="do" value="price"><input type="hidden" name="item_id" id="pr-item">
    <div class="card-header"><div class="card-title"><?= icon('money') ?> Approve a sale price</div></div>
    <div class="card-body"><div class="filters">
        <div class="field grow"><label>Item</label><div class="search-box"><?= icon('search') ?><input class="input" id="pr-search" autocomplete="off"><div class="search-results"></div></div></div>
        <div class="field"><label>New price</label><input class="input" type="number" step="0.01" min="0" name="price" required></div>
        <div class="btns"><button class="btn btn-primary">Approve price</button></div></div></div>
</form>
<script>APP.liveSearch(document.getElementById('pr-search'), { minLength: 2,
    fetch: function (q) { return APP.ajax(APP.url('inventory/item-search', { q: q, types: 'drug' })).then(function (d) { return d.items || []; }); },
    render: function (i) { return '<div class="grow"><div class="t">' + APP.esc(i.name) + '</div></div><strong>' + APP.money(i.sale_price) + '</strong>'; },
    onSelect: function (i) { document.getElementById('pr-item').value = i.id; document.getElementById('pr-search').value = i.name; } });</script>
