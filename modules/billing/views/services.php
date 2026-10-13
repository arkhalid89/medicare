<?php
/** @var array $rows @var \App\Core\Paginator $pager @var array $departments @var array $branches */
use App\Core\View;

echo View::partial('page_header', [
    'title'       => 'Services & Prices',
    'description' => 'The single list of everything that can be billed (Doc 7 §8). Price changes create a new dated version, so past bills never change.',
    'breadcrumbs' => [['Master Data'], ['Services & Prices']],
    'actions'     => '<a class="btn btn-excel" href="' . e(url_with(['export' => 'xlsx', 'page' => null])) . '">' . icon('excel') . ' Export</a>'
        . '<button class="btn btn-primary" type="button" onclick="APP.openModal(\'svc-modal\')">' . icon('plus') . ' Add Service</button>',
]);
?>
<div class="card card-flush">
    <div class="card-header">
        <form class="filters" method="get" action="<?= e(form_action('billing/services')) ?>" data-autosubmit>
            <?= route_field('billing/services') ?>
            <div class="field grow"><label>Search</label><input class="input" type="search" name="q" value="<?= e(input('q', '')) ?>"></div>
            <div class="field"><label>Type</label><select class="input" name="type"><option value="">All</option><?php foreach (hospital('service_types') as $k => $l): ?><option value="<?= e($k) ?>"<?= selected($k, input('type', '')) ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
            <div class="btns"><button class="btn btn-primary"><?= icon('filter') ?> Filter</button></div>
        </form>
    </div>
    <div class="card-body table-wrap">
        <table class="table">
            <thead><tr><th>Code</th><th>Service</th><th>Type</th><th>Department</th><th class="num">Current price</th><th>Price history</th><th>Status</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($rows as $s): ?>
                <tr><td><code><?= e($s['code']) ?></code></td><td><strong><?= e($s['name']) ?></strong></td><td class="small"><?= e(hospital('service_types')[$s['service_type']] ?? $s['service_type']) ?></td>
                    <td class="small"><?= e($s['department_name']) ?></td><td class="num"><strong><?= e(money($s['current'])) ?></strong></td>
                    <td class="small"><?php foreach ($s['prices'] as $p): ?><div><?= e(money($p['price'])) ?> <?= e($p['price_list_type']) ?> · <?= e($p['branch_name'] ?? 'all branches') ?> · from <?= e(fmt_date($p['valid_from'])) ?><?= $p['valid_to'] ? ' to ' . e(fmt_date($p['valid_to'])) : '' ?></div><?php endforeach; ?></td>
                    <td><?= status_badge($s['is_active']) ?></td>
                    <td class="nowrap"><button class="act" type="button" title="Edit" data-edit='<?= e(json_encode(['id' => $s['id'], 'code' => $s['code'], 'name' => $s['name'], 'service_type' => $s['service_type'], 'department_id' => $s['department_id'], 'revenue_head' => $s['revenue_head'], 'tax_code' => $s['tax_code'], 'is_active' => (int) $s['is_active']])) ?>'><?= icon('edit') ?></button>
                        <button class="act" type="button" title="New price" data-price="<?= (int) $s['id'] ?>" data-name="<?= e($s['name']) ?>"><?= icon('money') ?></button></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?= View::partial('pagination', ['pager' => $pager]) ?>
</div>

<div class="modal-backdrop" id="svc-modal">
    <form class="modal" method="post" action="<?= e(url('billing/service-save')) ?>">
        <?= csrf_field() ?><input type="hidden" name="id" id="svc-id" value="0">
        <div class="modal-head"><?= icon('receipt') ?><h3>Service</h3><button type="button" class="icon-btn close" onclick="APP.closeModal('svc-modal')"><?= icon('x') ?></button></div>
        <div class="modal-body"><div class="form-grid">
            <div class="field col-4"><label>Code</label><input class="input" name="code" id="svc-code" maxlength="30"></div>
            <div class="field col-8"><label>Name</label><input class="input" name="name" id="svc-name" maxlength="150" required></div>
            <div class="field col-6"><label>Type</label><select class="input" name="service_type" id="svc-type"><?php foreach (hospital('service_types') as $k => $l): ?><option value="<?= e($k) ?>"><?= e($l) ?></option><?php endforeach; ?></select></div>
            <div class="field col-6"><label>Department</label><select class="input" name="department_id" id="svc-dept"><option value="">—</option><?php foreach ($departments as $id => $n): ?><option value="<?= $id ?>"><?= e($n) ?></option><?php endforeach; ?></select></div>
            <div class="field col-4"><label>Revenue head</label><input class="input" name="revenue_head" id="svc-rev" maxlength="60"></div>
            <div class="field col-4"><label>Tax code</label><input class="input" name="tax_code" id="svc-tax" maxlength="20"></div>
            <div class="field col-4" id="svc-price-wrap"><label>Price</label><input class="input" type="number" min="0" step="1" name="price" value="0"></div>
            <div class="field col-12"><label class="check"><input type="checkbox" name="is_active" id="svc-active" value="1" checked> Active</label></div>
        </div></div>
        <div class="modal-foot"><button type="button" class="btn btn-outline" onclick="APP.closeModal('svc-modal')">Cancel</button><button class="btn btn-primary">Save</button></div>
    </form>
</div>
<div class="modal-backdrop" id="price-modal">
    <form class="modal" method="post" action="<?= e(url('billing/price-save')) ?>">
        <?= csrf_field() ?><input type="hidden" name="service_id" id="pr-sid">
        <div class="modal-head"><?= icon('money') ?><h3>New price — <span id="pr-name"></span></h3><button type="button" class="icon-btn close" onclick="APP.closeModal('price-modal')"><?= icon('x') ?></button></div>
        <div class="modal-body"><div class="form-grid">
            <div class="field col-4"><label>Price</label><input class="input" type="number" min="0" step="1" name="price" required></div>
            <div class="field col-4"><label>Price list</label><select class="input" name="price_list_type"><option value="cash">Cash</option><option value="panel">Panel / corporate</option><option value="staff">Staff</option></select></div>
            <div class="field col-4"><label>Valid from</label><input class="input" type="date" name="valid_from" value="<?= e(today()) ?>"></div>
            <div class="field col-12"><label>Branch</label><select class="input" name="branch_id"><option value="">All branches</option><?php foreach ($branches as $id => $n): ?><option value="<?= $id ?>"><?= e($n) ?></option><?php endforeach; ?></select></div>
        </div></div>
        <div class="modal-foot"><button type="button" class="btn btn-outline" onclick="APP.closeModal('price-modal')">Cancel</button><button class="btn btn-primary">Save Price</button></div>
    </form>
</div>
<script>
document.querySelectorAll('[data-edit]').forEach(function (b) {
    b.addEventListener('click', function () {
        var s = JSON.parse(b.dataset.edit);
        document.getElementById('svc-id').value = s.id; document.getElementById('svc-code').value = s.code || ''; document.getElementById('svc-name').value = s.name;
        document.getElementById('svc-type').value = s.service_type; document.getElementById('svc-dept').value = s.department_id || '';
        document.getElementById('svc-rev').value = s.revenue_head || ''; document.getElementById('svc-tax').value = s.tax_code || '';
        document.getElementById('svc-active').checked = !!s.is_active; document.getElementById('svc-price-wrap').style.display = 'none';
        APP.openModal('svc-modal');
    });
});
document.querySelectorAll('[data-price]').forEach(function (b) {
    b.addEventListener('click', function () { document.getElementById('pr-sid').value = b.dataset.price; document.getElementById('pr-name').textContent = b.dataset.name; APP.openModal('price-modal'); });
});
</script>
