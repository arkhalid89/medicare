<?php
/** @var array $rows @var array $locations @var array $vendors */
use App\Core\View;

echo View::partial('page_header', [
    'title' => 'Assets & Maintenance', 'description' => 'Equipment register (analyzers, imaging equipment, dental chairs …) with preventive maintenance and calibration due dates.',
    'breadcrumbs' => [['Store', url('inventory')], ['Assets']],
    'actions' => '<button class="btn btn-primary" type="button" onclick="APP.openModal(\'asset-modal\')">' . icon('plus') . ' Register Asset</button>',
]);
?>
<div class="card card-flush"><div class="card-body table-wrap"><table class="table">
    <thead><tr><th>Asset</th><th>Tag / serial</th><th>Location</th><th>Vendor</th><th>Warranty</th><th>Last service</th><th>Next due</th><th></th></tr></thead><tbody>
    <?php foreach ($rows as $a): $due = $a['next_maintenance'] && $a['next_maintenance'] <= date('Y-m-d', strtotime('+14 days')); ?>
        <tr><td><strong><?= e($a['name']) ?></strong></td><td class="small"><?= e($a['asset_tag']) ?><div class="muted"><?= e($a['serial_no']) ?></div></td><td class="small"><?= e($a['location_name']) ?></td>
            <td class="small"><?= e($a['vendor_name']) ?></td><td class="small"><?= e(fmt_date($a['warranty_until'])) ?></td><td class="small"><?= e(fmt_date($a['last_service'])) ?></td>
            <td><?= $a['next_maintenance'] ? '<span class="badge ' . ($due ? 'badge-danger' : 'badge-muted') . '">' . e(fmt_date($a['next_maintenance'])) . '</span>' : '' ?></td>
            <td><button class="btn btn-sm btn-outline" type="button" data-maint="<?= (int) $a['id'] ?>" data-name="<?= e($a['name']) ?>">Log service</button></td></tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="8" class="muted">No assets registered.</td></tr><?php endif; ?>
</tbody></table></div></div>
<div class="modal-backdrop" id="asset-modal"><form class="modal" method="post" action="<?= e(url('inventory/assets')) ?>"><?= csrf_field() ?>
    <div class="modal-head"><?= icon('box') ?><h3>Register asset</h3><button type="button" class="icon-btn close" onclick="APP.closeModal('asset-modal')"><?= icon('x') ?></button></div>
    <div class="modal-body"><div class="form-grid">
        <div class="field col-12"><label>Name</label><input class="input" name="name" required maxlength="150"></div>
        <div class="field col-6"><label>Asset tag</label><input class="input" name="asset_tag" maxlength="40"></div><div class="field col-6"><label>Serial no.</label><input class="input" name="serial_no" maxlength="80"></div>
        <div class="field col-6"><label>Location</label><select class="input" name="location_id"><option value="">—</option><?php foreach ($locations as $id => $n): ?><option value="<?= $id ?>"><?= e($n) ?></option><?php endforeach; ?></select></div>
        <div class="field col-6"><label>Vendor</label><select class="input" name="vendor_id"><option value="">—</option><?php foreach ($vendors as $id => $n): ?><option value="<?= $id ?>"><?= e($n) ?></option><?php endforeach; ?></select></div>
        <div class="field col-4"><label>Purchase date</label><input class="input" type="date" name="purchase_date"></div><div class="field col-4"><label>Cost</label><input class="input" type="number" min="0" name="cost"></div>
        <div class="field col-4"><label>Warranty until</label><input class="input" type="date" name="warranty_until"></div><div class="field col-6"><label>Next maintenance</label><input class="input" type="date" name="next_maintenance"></div>
    </div></div>
    <div class="modal-foot"><button type="button" class="btn btn-outline" onclick="APP.closeModal('asset-modal')">Cancel</button><button class="btn btn-primary">Save</button></div></form></div>
<div class="modal-backdrop" id="maint-modal"><form class="modal" method="post" action="<?= e(url('inventory/assets')) ?>"><?= csrf_field() ?><input type="hidden" name="maintenance_for" id="mt-id">
    <div class="modal-head"><?= icon('settings') ?><h3>Service — <span id="mt-name"></span></h3><button type="button" class="icon-btn close" onclick="APP.closeModal('maint-modal')"><?= icon('x') ?></button></div>
    <div class="modal-body"><div class="form-grid">
        <div class="field col-6"><label>Type</label><select class="input" name="type"><option value="preventive">Preventive</option><option value="repair">Repair</option><option value="calibration">Calibration</option></select></div>
        <div class="field col-6"><label>Performed on</label><input class="input" type="date" name="performed_on" value="<?= e(today()) ?>"></div>
        <div class="field col-6"><label>Cost</label><input class="input" type="number" min="0" name="cost"></div><div class="field col-6"><label>Next due</label><input class="input" type="date" name="next_due"></div>
        <div class="field col-12"><label>Notes</label><input class="input" name="notes" maxlength="500"></div>
    </div></div>
    <div class="modal-foot"><button type="button" class="btn btn-outline" onclick="APP.closeModal('maint-modal')">Cancel</button><button class="btn btn-primary">Save</button></div></form></div>
<script>document.querySelectorAll('[data-maint]').forEach(function (b) { b.addEventListener('click', function () { document.getElementById('mt-id').value = b.dataset.maint; document.getElementById('mt-name').textContent = b.dataset.name; APP.openModal('maint-modal'); }); });</script>
