<?php
/** @var array $rows @var \App\Core\Paginator $pager @var array $openPos */
use App\Core\View;

echo View::partial('page_header', [
    'title' => 'Goods Receipt Notes', 'description' => 'Receive against an approved PO (or a direct receipt): batch, expiry, received and rejected quantities. Posting creates the batches and the stock.',
    'breadcrumbs' => [['Store', url('inventory')], ['GRN']],
    'actions' => can('inventory.grn.post') ? '<a class="btn btn-outline" href="' . e(url('inventory/grn-new')) . '">' . icon('plus') . ' Direct receipt</a>' : '',
]);
?>
<?php if ($openPos && can('inventory.grn.post')): ?>
<div class="card"><div class="card-header"><div class="card-title"><?= icon('truck') ?> Purchase orders waiting for delivery</div></div><div class="card-body"><div class="chip-list">
    <?php foreach ($openPos as $p): ?><a class="chip" href="<?= e(url('inventory/grn-new', ['po_id' => $p['id']])) ?>"><?= e($p['po_number']) ?> · <?= e($p['vendor_name']) ?></a><?php endforeach; ?>
</div></div></div>
<?php endif; ?>
<div class="card card-flush"><div class="card-body table-wrap"><table class="table">
    <thead><tr><th>GRN</th><th>Vendor</th><th>PO</th><th>Location</th><th>Invoice</th><th class="num">Amount</th><th>Received by</th><th>Status</th></tr></thead><tbody>
    <?php foreach ($rows as $g): ?><tr><td><a href="<?= e(url('inventory/grn-view', ['id' => $g['id']])) ?>"><strong><?= e($g['grn_number']) ?></strong></a><div class="muted small"><?= e(fmt_datetime($g['received_at'])) ?></div></td>
        <td><?= e($g['vendor_name']) ?></td><td class="small"><?= e($g['po_number'] ?: 'Direct') ?></td><td class="small"><?= e($g['location_name']) ?></td><td class="small"><?= e($g['vendor_invoice_no']) ?></td>
        <td class="num"><?= e(money($g['total_amount'])) ?></td><td class="small"><?= e($g['received_by_name']) ?></td><td><?= status_chip($g['status']) ?></td></tr><?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="8" class="muted">No receipts yet.</td></tr><?php endif; ?>
</tbody></table></div><?= View::partial('pagination', ['pager' => $pager]) ?></div>
