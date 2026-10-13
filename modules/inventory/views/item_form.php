<?php
/** @var array|null $item @var array $errors @var array $categories @var array $uoms @var array $locations @var array $rules @var array $vendors */
use App\Core\View;

echo View::partial('page_header', [
    'title' => $item ? $item['name'] : 'New Item', 'description' => 'Item master, tracking flags and reorder levels per location.',
    'breadcrumbs' => [['Store', url('inventory')], ['Items', url('inventory/items')], [$item ? 'Edit' : 'New']],
    'actions' => $item ? '<a class="btn btn-outline" href="' . e(url('inventory/card', ['item_id' => $item['id']])) . '">' . icon('list') . ' Stock card</a>' : '',
]);
$v = static fn (string $f, $d = '') => old($f, $item[$f] ?? $d);
$ruleBy = [];
foreach ($rules as $r) {
    $ruleBy[(int) $r['location_id']] = $r;
}
$manage = can('inventory.items.manage');
?>
<?php if ($errors): ?><div class="alert alert-danger"><?= icon('alert') ?><div><?= implode('<br>', array_map('e', $errors)) ?></div></div><?php endif; ?>
<?php if ($item && $item['medicine_id']): ?><div class="alert alert-info"><?= icon('pill') ?><div>This item is the stock record of a medicine. Name and price follow the medicine master (Master Data → Medicines).</div></div><?php endif; ?>
<form method="post" action="<?= e(url('inventory/item', ['id' => $item['id'] ?? null])) ?>">
    <?= csrf_field() ?>
    <div class="grid grid-main">
        <div class="card"><div class="card-header"><div class="card-title"><?= icon('box') ?> Item</div></div>
            <div class="card-body"><div class="form-grid">
                <div class="field col-8"><label>Name</label><input class="input" name="name" value="<?= e($v('name')) ?>" maxlength="200" required></div>
                <div class="field col-4"><label>SKU</label><input class="input" name="sku" value="<?= e($v('sku')) ?>" maxlength="40" placeholder="Automatic"></div>
                <div class="field col-4"><label>Category</label><select class="input" name="category_id"><option value="">—</option><?php foreach ($categories as $id => $n): ?><option value="<?= $id ?>"<?= selected($id, $v('category_id')) ?>><?= e($n) ?></option><?php endforeach; ?></select></div>
                <div class="field col-4"><label>Base unit</label><select class="input" name="base_uom_id"><option value="">—</option><?php foreach ($uoms as $id => $n): ?><option value="<?= $id ?>"<?= selected($id, $v('base_uom_id')) ?>><?= e($n) ?></option><?php endforeach; ?></select></div>
                <div class="field col-4"><label>Barcode</label><input class="input" name="barcode" value="<?= e($v('barcode')) ?>" maxlength="60"></div>
                <div class="field col-4"><label>Standard cost</label><input class="input" type="number" step="0.01" min="0" name="standard_cost" value="<?= e(num($v('standard_cost', 0))) ?>"></div>
                <div class="field col-4"><label>Sale price</label><input class="input" type="number" step="0.01" min="0" name="sale_price" value="<?= e(num($v('sale_price', 0))) ?>"></div>
                <div class="field col-4"><label>Shelf life (days)</label><input class="input" type="number" min="0" name="shelf_life_days" value="<?= e((string) $v('shelf_life_days')) ?>"></div>
                <div class="field col-12"><div class="flex flex-wrap">
                    <label class="check"><input type="checkbox" name="is_batch_tracked" value="1"<?= checked((int) $v('is_batch_tracked', 1)) ?>> Batch tracked</label>
                    <label class="check"><input type="checkbox" name="is_expiry_tracked" value="1"<?= checked((int) $v('is_expiry_tracked', 1)) ?>> Expiry tracked (expiry required on GRN)</label>
                    <label class="check"><input type="checkbox" name="is_controlled" value="1"<?= checked((int) $v('is_controlled', 0)) ?>> Controlled drug</label>
                    <label class="check"><input type="checkbox" name="is_cold_chain" value="1"<?= checked((int) $v('is_cold_chain', 0)) ?>> Cold chain</label>
                    <label class="check"><input type="checkbox" name="is_active" value="1"<?= checked((int) $v('is_active', 1)) ?>> Active</label></div></div>
                <?php if ($item): ?><div class="field col-12 muted small">Average cost (weighted, from receipts): <strong><?= e(money($item['avg_cost'])) ?></strong></div><?php endif; ?>
            </div></div></div>
        <div class="card card-flush"><div class="card-header"><div class="card-title"><?= icon('truck') ?> Vendors &amp; last prices</div></div>
            <div class="card-body"><ul class="list"><?php foreach ($vendors as $vi): ?><li><div class="grow"><div class="title"><?= e($vi['vendor_name']) ?></div><div class="meta"><?= e(fmt_date($vi['last_bought_at'])) ?></div></div><strong><?= e(money($vi['last_price'])) ?></strong></li><?php endforeach; ?>
                <?php if (!$vendors): ?><li class="muted small">No purchases yet.</li><?php endif; ?></ul></div></div>
    </div>
    <div class="card card-flush"><div class="card-header"><div class="card-title"><?= icon('sliders') ?> Reorder levels per location</div></div>
        <div class="card-body table-wrap"><table class="table table-compact"><thead><tr><th>Location</th><th>Minimum</th><th>Reorder qty</th><th>Maximum</th><th>Bin</th></tr></thead><tbody>
            <?php foreach ($locations as $lid => $ln): $r = $ruleBy[(int) $lid] ?? []; ?>
                <tr><td><?= e($ln) ?></td>
                    <td><input class="input input-sm" type="number" step="0.001" min="0" name="rules[<?= $lid ?>][min]" value="<?= e(num($r['min_qty'] ?? '')) ?>"></td>
                    <td><input class="input input-sm" type="number" step="0.001" min="0" name="rules[<?= $lid ?>][reorder]" value="<?= e(num($r['reorder_qty'] ?? '')) ?>"></td>
                    <td><input class="input input-sm" type="number" step="0.001" min="0" name="rules[<?= $lid ?>][max]" value="<?= e(num($r['max_qty'] ?? '')) ?>"></td>
                    <td><input class="input input-sm" name="rules[<?= $lid ?>][bin]" value="<?= e($r['bin_code'] ?? '') ?>" maxlength="30"></td></tr>
            <?php endforeach; ?>
        </tbody></table></div>
        <?php if ($manage): ?><div class="card-footer"><button class="btn btn-primary"><?= icon('save') ?> Save Item</button></div><?php endif; ?></div>
</form>
