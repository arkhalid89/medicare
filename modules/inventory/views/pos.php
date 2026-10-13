<?php
/** @var array $rows @var \App\Core\Paginator $pager @var string $status @var array $suggest */
use App\Core\View;

echo View::partial('page_header', [
    'title' => 'Purchase Orders', 'description' => 'Procurement creates; the Store Manager (or Hospital Admin above the limit) approves; receiving posts the GRN. The creator can neither approve nor receive.',
    'breadcrumbs' => [['Store', url('inventory')], ['Purchase Orders']],
    'actions' => can('inventory.po.create') ? '<a class="btn btn-primary" href="' . e(url('inventory/po-new')) . '">' . icon('plus') . ' New PO</a>' : '',
]);
?>
<?php if ($suggest && can('inventory.po.create')): ?><div class="alert alert-info"><?= icon('alert') ?><div><?= count($suggest) ?> item(s) are below their reorder level — <a href="<?= e(url('inventory/po-new')) ?>">create a PO from the suggestions</a>.</div></div><?php endif; ?>
<div class="tabs mb-2"><?php foreach (['' => 'All', 'draft' => 'Waiting approval', 'approved' => 'Approved', 'partially_received' => 'Partly received', 'received' => 'Received'] as $k => $l): ?>
    <a class="<?= $status === $k ? 'active' : '' ?>" href="<?= e(url('inventory/po', ['status' => $k])) ?>"><?= e($l) ?></a><?php endforeach; ?></div>
<div class="card card-flush"><div class="card-body table-wrap"><table class="table">
    <thead><tr><th>PO</th><th>Vendor</th><th>Expected</th><th class="num">Amount</th><th>Created by</th><th>Status</th></tr></thead><tbody>
    <?php foreach ($rows as $r): ?><tr><td><a href="<?= e(url('inventory/po-view', ['id' => $r['id']])) ?>"><strong><?= e($r['po_number']) ?></strong></a><div class="muted small"><?= e(fmt_date($r['created_at'])) ?></div></td>
        <td><?= e($r['vendor_name']) ?></td><td class="small"><?= e(fmt_date($r['expected_date'])) ?></td><td class="num"><?= e(money($r['total_amount'])) ?></td><td class="small"><?= e($r['created_by_name']) ?></td><td><?= status_chip($r['status']) ?></td></tr><?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="6" class="muted">No purchase orders.</td></tr><?php endif; ?>
</tbody></table></div><?= View::partial('pagination', ['pager' => $pager]) ?></div>
