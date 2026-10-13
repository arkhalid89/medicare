<?php
/** @var array $inv */
use App\Core\View;

$actions = '<a class="btn btn-outline" target="_blank" href="' . e(url('billing/print', ['id' => $inv['id']])) . '">' . icon('printer') . ' Thermal</a>'
    . '<a class="btn btn-outline" target="_blank" href="' . e(url('billing/print', ['id' => $inv['id'], 'paper' => 'a5'])) . '">' . icon('printer') . ' A5</a>';
echo View::partial('page_header', [
    'title'       => 'Invoice ' . $inv['invoice_no'],
    'description' => (hospital('invoice_types')[$inv['invoice_type']] ?? '') . ' · ' . fmt_datetime($inv['created_at']) . ' · by ' . $inv['created_by_name'],
    'breadcrumbs' => [['Billing'], ['Invoices', url('billing/invoices')], [$inv['invoice_no']]],
    'actions'     => $actions,
]);
$open = !in_array($inv['status'], ['void'], true);
$paidNet = (float) $inv['paid_amount'] - (float) $inv['refunded_amount'];
?>
<div class="grid grid-main">
    <div>
        <div class="card">
            <div class="card-header"><div class="card-title"><?= icon('user') ?> <?= e($inv['patient_name'] ?: $inv['customer_name'] ?: 'Walk-in') ?></div><?= status_chip($inv['status']) ?></div>
            <div class="card-body">
                <dl class="kv">
                    <?php if ($inv['mrn']): ?><dt>MRN</dt><dd><a href="<?= e(url('patients/view', ['id' => $inv['patient_id']])) ?>"><?= e($inv['mrn']) ?></a></dd><?php endif; ?>
                    <?php if ($inv['doctor_name']): ?><dt>Doctor</dt><dd><?= e($inv['doctor_name']) ?><?= $inv['department_name'] ? ' · ' . e($inv['department_name']) : '' ?></dd><?php endif; ?>
                    <?php if ($inv['token_label']): ?><dt>Token</dt><dd><?= e($inv['token_label']) ?></dd><?php endif; ?>
                    <?php if ($inv['visit_id']): ?><dt>Visit</dt><dd><a href="<?= e(url('visits/view', ['id' => $inv['visit_id']])) ?>">Open visit</a></dd><?php endif; ?>
                    <?php if ($inv['waived']): ?><dt>Waiver</dt><dd><span class="badge badge-info">Waived</span> <?= e($inv['waiver_reason']) ?></dd><?php endif; ?>
                    <?php if ($inv['status'] === 'void'): ?><dt>Void</dt><dd class="text-danger"><?= e($inv['void_reason']) ?> (<?= e(fmt_datetime($inv['voided_at'])) ?>)</dd><?php endif; ?>
                </dl>
            </div>
        </div>
        <div class="card card-flush">
            <div class="card-header"><div class="card-title"><?= icon('list') ?> Items</div></div>
            <div class="card-body table-wrap">
                <table class="table">
                    <thead><tr><th>Description</th><th>Source</th><th class="num">Qty</th><th class="num">Price</th><th class="num">Amount</th><th class="num">Refunded</th></tr></thead>
                    <tbody>
                    <?php foreach ($inv['items'] as $it): ?>
                        <tr class="<?= (int) $it['is_void'] ? 'deleted' : '' ?>"><td><?= e($it['description']) ?></td><td class="small muted"><?= e(ucfirst($it['source_module'])) ?></td>
                            <td class="num"><?= e(num($it['quantity'])) ?></td><td class="num"><?= e(money($it['unit_price'])) ?></td><td class="num"><?= e(money($it['amount'])) ?></td>
                            <td class="num"><?= (float) $it['refunded_amount'] > 0 ? e(money($it['refunded_amount'])) : '' ?></td></tr>
                    <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr><td colspan="4" class="text-right">Gross</td><td class="num"><?= e(money($inv['gross_amount'])) ?></td><td></td></tr>
                        <?php if ((float) $inv['discount_amount'] > 0): ?><tr><td colspan="4" class="text-right">Discount <?= $inv['discount_status'] !== 'approved' ? status_chip($inv['discount_status']) : '' ?>
                            <div class="muted small"><?= e($inv['discount_reason']) ?></div></td><td class="num">-<?= e(money($inv['discount_amount'])) ?></td><td></td></tr><?php endif; ?>
                        <?php if ((float) $inv['tax_amount'] > 0): ?><tr><td colspan="4" class="text-right"><?= e(setting('tax_label')) ?></td><td class="num"><?= e(money($inv['tax_amount'])) ?></td><td></td></tr><?php endif; ?>
                        <tr><td colspan="4" class="text-right"><strong>Net</strong></td><td class="num"><strong><?= e(money($inv['net_amount'])) ?></strong></td><td></td></tr>
                        <tr><td colspan="4" class="text-right">Paid (after refunds)</td><td class="num"><?= e(money($paidNet)) ?></td><td></td></tr>
                        <tr><td colspan="4" class="text-right"><strong>Balance</strong></td><td class="num"><strong><?= e(money($inv['balance'])) ?></strong></td><td></td></tr>
                    </tfoot>
                </table>
            </div>
        </div>
        <div class="grid grid-2">
            <div class="card card-flush"><div class="card-header"><div class="card-title"><?= icon('money') ?> Payments</div></div>
                <div class="card-body"><?php if (!$inv['payments']): ?><div class="muted small" style="padding:1rem">No payments.</div><?php else: ?><ul class="list">
                    <?php foreach ($inv['payments'] as $p): ?><li><div class="grow"><div class="title"><?= e(money($p['amount'])) ?> · <?= e(hospital('payment_modes')[$p['mode']] ?? $p['mode']) ?></div>
                        <div class="meta"><?= e(fmt_datetime($p['collected_at'])) ?> · <?= e($p['collected_by_name']) ?><?= $p['reference'] ? ' · ' . e($p['reference']) : '' ?></div></div></li><?php endforeach; ?>
                </ul><?php endif; ?></div></div>
            <div class="card card-flush"><div class="card-header"><div class="card-title"><?= icon('repeat') ?> Refunds</div></div>
                <div class="card-body"><?php if (!$inv['refunds']): ?><div class="muted small" style="padding:1rem">No refunds.</div><?php else: ?><ul class="list">
                    <?php foreach ($inv['refunds'] as $r): ?><li><div class="grow"><div class="title"><?= e(money($r['amount'])) ?> <?= status_chip($r['status']) ?></div>
                        <div class="meta"><?= e($r['reason']) ?> · by <?= e($r['initiated_by_name']) ?><?= $r['approved_by_name'] ? ' · approved ' . e($r['approved_by_name']) : '' ?><?= $r['processed_by_name'] ? ' · paid by ' . e($r['processed_by_name']) : '' ?></div></div></li><?php endforeach; ?>
                </ul><?php endif; ?></div></div>
        </div>
    </div>
    <div>
        <?php if ($open && $inv['balance'] > 0 && can('billing.payment.collect')): ?>
        <form class="card" method="post" action="<?= e(url('billing/collect')) ?>">
            <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $inv['id'] ?>">
            <div class="card-header"><div class="card-title"><?= icon('money') ?> Collect payment</div></div>
            <div class="card-body">
                <div class="field mb-1"><label>Amount</label><input class="input" type="number" step="0.01" min="0.01" max="<?= e((string) $inv['balance']) ?>" name="amount" value="<?= e(num($inv['balance'])) ?>" required></div>
                <div class="field mb-1"><label>Mode</label><select class="input" name="mode"><?php foreach (hospital('payment_modes') as $k => $l): ?><option value="<?= e($k) ?>"><?= e($l) ?></option><?php endforeach; ?></select></div>
                <div class="field"><label>Reference</label><input class="input" name="reference" maxlength="100"></div>
            </div>
            <div class="card-footer"><button class="btn btn-gold"><?= icon('check') ?> Collect</button></div>
        </form>
        <?php endif; ?>
        <?php if ($open && !$inv['waived'] && (can('billing.discount.apply') || can('billing.discount.approve')) && $inv['status'] !== 'refunded'): ?>
        <form class="card" method="post" action="<?= e(url('billing/discount')) ?>">
            <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $inv['id'] ?>">
            <div class="card-header"><div class="card-title"><?= icon('sliders') ?> Discount</div>
                <?php $lim = \App\Core\Auth::discountLimit(); ?><span class="muted small"><?= $lim === null ? 'No limit' : 'Your limit ' . e(money($lim)) ?></span></div>
            <div class="card-body">
                <div class="field mb-1"><label>Amount</label><input class="input" type="number" step="1" min="0" name="amount" value="<?= e(num($inv['discount_amount'])) ?>"></div>
                <div class="field"><label>Reason</label><input class="input" name="reason" maxlength="255" required></div>
            </div>
            <div class="card-footer"><button class="btn btn-outline">Apply Discount</button></div>
        </form>
        <?php endif; ?>
        <?php if ($open && $paidNet > 0 && can('billing.refund.request')): ?>
        <form class="card" method="post" action="<?= e(url('billing/refund')) ?>">
            <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $inv['id'] ?>">
            <div class="card-header"><div class="card-title"><?= icon('repeat') ?> Request refund</div></div>
            <div class="card-body">
                <div class="field mb-1"><label>Line (optional)</label><select class="input" name="item_id"><option value="">Whole invoice</option>
                    <?php foreach ($inv['items'] as $it): ?><option value="<?= (int) $it['id'] ?>"><?= e($it['description']) ?> (<?= e(money($it['amount'])) ?>)</option><?php endforeach; ?></select></div>
                <div class="field mb-1"><label>Amount</label><input class="input" type="number" step="0.01" min="0.01" max="<?= e((string) $paidNet) ?>" name="amount" required></div>
                <div class="field"><label>Reason</label><input class="input" name="reason" maxlength="500" required></div>
            </div>
            <div class="card-footer"><button class="btn btn-outline">Request Refund</button><span class="muted small">Needs approval by HA / Billing Supervisor.</span></div>
        </form>
        <?php endif; ?>
        <?php if ($open && can('billing.void.approve')): ?>
        <form class="card" method="post" action="<?= e(url('billing/void')) ?>" data-confirm="Void invoice <?= e($inv['invoice_no']) ?>? This cannot be undone." data-confirm-ok="Void">
            <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $inv['id'] ?>">
            <div class="card-header"><div class="card-title"><?= icon('x') ?> Void invoice</div></div>
            <div class="card-body"><div class="field"><label>Reason</label><input class="input" name="reason" maxlength="255" required></div>
                <p class="muted small mb-0">Payments must be refunded first. A void is a linked reversal — the invoice number is never reused.</p></div>
            <div class="card-footer"><button class="btn btn-danger">Void</button></div>
        </form>
        <?php endif; ?>
    </div>
</div>
