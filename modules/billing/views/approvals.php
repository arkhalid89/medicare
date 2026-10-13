<?php
/** @var array $refunds @var array $discounts @var array $recent */
use App\Core\View;

echo View::partial('page_header', [
    'title'       => 'Billing Approvals',
    'description' => 'General refunds and discounts above the cashier\'s limit. The person who requested cannot approve their own request. Lab / radiology rejection refunds are pre-approved by the clinician and do not appear here.',
    'breadcrumbs' => [['Billing'], ['Approvals']],
]);
?>
<div class="grid grid-2">
    <div class="card card-flush">
        <div class="card-header"><div class="card-title"><?= icon('repeat') ?> Refund requests</div></div>
        <div class="card-body">
            <?php if (!$refunds): ?><div class="empty"><strong>Nothing to approve</strong></div><?php endif; ?>
            <ul class="list">
                <?php foreach ($refunds as $r): ?>
                    <li><div class="grow"><div class="title"><?= e(money($r['amount'])) ?> · <a href="<?= e(url('billing/invoice', ['id' => $r['invoice_id']])) ?>"><?= e($r['invoice_no']) ?></a> · <?= e($r['patient_name']) ?></div>
                        <div class="meta"><?= e($r['reason']) ?> · requested by <?= e($r['initiated_by_name']) ?>, <?= e(fmt_datetime($r['initiated_at'])) ?></div></div>
                        <?php if (can('billing.refund.approve')): ?>
                        <form method="post" action="<?= e(url('billing/refund-decide')) ?>" class="flex"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                            <input class="input input-sm" name="note" placeholder="Note" style="width:120px">
                            <button class="btn btn-sm btn-success" name="decision" value="approve">Approve</button>
                            <button class="btn btn-sm btn-danger" name="decision" value="reject">Reject</button></form>
                        <?php endif; ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
    <div class="card card-flush">
        <div class="card-header"><div class="card-title"><?= icon('sliders') ?> Discounts above limit</div></div>
        <div class="card-body">
            <?php if (!$discounts): ?><div class="empty"><strong>Nothing to approve</strong></div><?php endif; ?>
            <ul class="list">
                <?php foreach ($discounts as $d): ?>
                    <li><div class="grow"><div class="title"><?= e(money($d['discount_amount'])) ?> on <?= e(money($d['gross_amount'])) ?> · <a href="<?= e(url('billing/invoice', ['id' => $d['id']])) ?>"><?= e($d['invoice_no']) ?></a></div>
                        <div class="meta"><?= e($d['patient_name']) ?> · <?= e($d['discount_reason']) ?> · by <?= e($d['discount_by_name']) ?></div></div>
                        <?php if (can('billing.discount.approve')): ?>
                        <form method="post" action="<?= e(url('billing/discount-decide')) ?>" class="flex"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $d['id'] ?>">
                            <button class="btn btn-sm btn-success" name="decision" value="approve">Approve</button>
                            <button class="btn btn-sm btn-danger" name="decision" value="reject">Reject</button></form>
                        <?php endif; ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
</div>
<div class="card card-flush mt-2">
    <div class="card-header"><div class="card-title"><?= icon('list') ?> Recent refund decisions</div></div>
    <div class="card-body table-wrap"><table class="table table-compact"><thead><tr><th>Invoice</th><th>Amount</th><th>Source</th><th>Status</th><th>Approved by</th><th>When</th></tr></thead><tbody>
        <?php foreach ($recent as $r): ?><tr><td><a href="<?= e(url('billing/invoice', ['id' => $r['invoice_id']])) ?>"><?= e($r['invoice_no']) ?></a></td><td><?= e(money($r['amount'])) ?></td>
            <td><?= e(ucfirst($r['source_module'])) ?></td><td><?= status_chip($r['status']) ?></td><td><?= e($r['approved_by_name']) ?></td><td class="small"><?= e(fmt_datetime($r['approved_at'])) ?></td></tr><?php endforeach; ?>
    </tbody></table></div>
</div>
