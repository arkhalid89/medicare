<?php
/** @var array $po @var array $lines @var array $grns */
use App\Core\Auth;
use App\Core\View;

echo View::partial('page_header', [
    'title' => 'PO ' . $po['po_number'], 'description' => $po['vendor_name'] . ' · deliver to ' . $po['location_name'] . ' · created by ' . $po['created_by_name'],
    'breadcrumbs' => [['Store', url('inventory')], ['Purchase Orders', url('inventory/po')], [$po['po_number']]],
    'actions' => in_array($po['status'], ['approved', 'sent', 'partially_received'], true) && can('inventory.grn.post') && (int) $po['created_by'] !== Auth::id()
        ? '<a class="btn btn-gold" href="' . e(url('inventory/grn-new', ['po_id' => $po['id']])) . '">' . icon('download') . ' Receive Goods</a>' : '',
]);
?>
<div class="flex mb-2"><?= status_chip($po['status']) ?><?= $po['approved_by_name'] ? '<span class="muted small">Decided by ' . e($po['approved_by_name']) . ' on ' . e(fmt_datetime($po['approved_at'])) . '</span>' : '' ?></div>
<div class="card card-flush"><div class="card-body table-wrap"><table class="table">
    <thead><tr><th>Item</th><th class="num">Ordered</th><th class="num">Received</th><th class="num">Unit price</th><th class="num">Tax %</th><th class="num">Amount</th></tr></thead><tbody>
    <?php foreach ($lines as $l): ?><tr><td><?= e($l['item_name']) ?><div class="muted small"><?= e($l['sku']) ?></div></td><td class="num"><?= e(num($l['qty_ordered'])) ?></td><td class="num"><?= e(num($l['qty_received'])) ?></td>
        <td class="num"><?= e(money($l['unit_price'])) ?></td><td class="num"><?= e(num($l['tax_pct'])) ?></td><td class="num"><?= e(money((float) $l['qty_ordered'] * (float) $l['unit_price'] * (1 + (float) $l['tax_pct'] / 100))) ?></td></tr><?php endforeach; ?>
    </tbody><tfoot><tr><td colspan="5" class="text-right"><strong>Total</strong></td><td class="num"><strong><?= e(money($po['total_amount'])) ?></strong></td></tr></tfoot></table></div>
    <div class="card-footer">
        <form method="post" action="<?= e(url('inventory/po-action')) ?>" class="flex"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $po['id'] ?>">
            <?php if ($po['status'] === 'draft' && can('inventory.po.create')): ?><button class="btn btn-primary" name="do" value="submit">Submit for approval</button><?php endif; ?>
            <?php if ($po['status'] === 'submitted' && can('inventory.po.approve') && (int) $po['created_by'] !== Auth::id()): ?>
                <button class="btn btn-danger" name="do" value="reject">Reject</button><button class="btn btn-success" name="do" value="approve"><?= icon('check') ?> Approve</button><?php endif; ?>
        </form>
    </div></div>
<?php if ($grns): ?><div class="card card-flush"><div class="card-header"><div class="card-title"><?= icon('download') ?> Receipts</div></div><div class="card-body"><ul class="list">
    <?php foreach ($grns as $g): ?><li><div class="grow"><a class="title" href="<?= e(url('inventory/grn-view', ['id' => $g['id']])) ?>"><?= e($g['grn_number']) ?></a><div class="meta"><?= e(fmt_datetime($g['received_at'])) ?> · <?= e(money($g['total_amount'])) ?></div></div><?= status_chip($g['status']) ?></li><?php endforeach; ?>
</ul></div></div><?php endif; ?>
