<?php
/** @var array $g @var array $lines */
use App\Core\View;

echo View::partial('page_header', [
    'title' => 'GRN ' . $g['grn_number'], 'description' => $g['vendor_name'] . ' → ' . $g['location_name'] . ($g['po_number'] ? ' · PO ' . $g['po_number'] : ' · direct receipt') . ' · invoice ' . ($g['vendor_invoice_no'] ?: '—'),
    'breadcrumbs' => [['Store', url('inventory')], ['GRN', url('inventory/grn')], [$g['grn_number']]],
]);
?>
<div class="flex mb-2"><?= status_chip($g['status']) ?><span class="muted small">Received by <?= e($g['received_by_name']) ?> on <?= e(fmt_datetime($g['received_at'])) ?><?= $g['posted_by_name'] ? ' · posted by ' . e($g['posted_by_name']) . ' ' . e(fmt_datetime($g['posted_at'])) : '' ?></span></div>
<div class="card card-flush"><div class="card-body table-wrap"><table class="table">
    <thead><tr><th>Item</th><th>Batch</th><th>Expiry</th><th class="num">Received</th><th class="num">Bonus</th><th class="num">Rejected</th><th class="num">Unit cost</th><th class="num">Amount</th></tr></thead><tbody>
    <?php foreach ($lines as $l): ?><tr><td><?= e($l['item_name']) ?><div class="muted small"><?= e($l['sku']) ?></div></td><td><?= e($l['batch_no']) ?></td><td><?= e(fmt_date($l['expiry_date'])) ?></td>
        <td class="num"><?= e(num($l['qty_received'])) ?></td><td class="num"><?= e(num($l['bonus_qty'])) ?></td><td class="num"><?= (float) $l['qty_rejected'] > 0 ? e(num($l['qty_rejected'])) . '<div class="muted small">' . e($l['reject_reason']) . '</div>' : '' ?></td>
        <td class="num"><?= e(money($l['unit_cost'])) ?></td><td class="num"><?= e(money((float) $l['qty_received'] * (float) $l['unit_cost'])) ?></td></tr><?php endforeach; ?>
    </tbody><tfoot><tr><td colspan="7" class="text-right"><strong>Total</strong></td><td class="num"><strong><?= e(money($g['total_amount'])) ?></strong></td></tr></tfoot></table></div>
    <?php if ($g['status'] === 'draft' && can('inventory.grn.post')): ?>
    <div class="card-footer"><form method="post" action="<?= e(url('inventory/grn-view', ['id' => $g['id']])) ?>" data-confirm="Post this receipt? Batches and stock are created; this cannot be undone (use a vendor return or adjustment)." data-confirm-danger="false">
        <?= csrf_field() ?><button class="btn btn-primary"><?= icon('check') ?> Post to stock</button></form></div>
    <?php endif; ?></div>
