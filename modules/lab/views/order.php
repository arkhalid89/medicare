<?php
/** @var array $o @var array $samples @var array $reports @var array|null $invoice */
use App\Core\View;

echo View::partial('page_header', [
    'title' => 'Lab Order ' . $o['order_number'], 'description' => $o['patient_name'] . ' · ' . $o['mrn'] . ' · ' . $o['gender'] . ', ' . age_text($o['dob']),
    'breadcrumbs' => [['Laboratory'], ['Orders', url('lab/orders')], [$o['order_number']]],
    'actions' => ($o['report'] ? '<a class="btn btn-primary" target="_blank" href="' . e(url('lab/report', ['id' => $o['id']])) . '">' . icon('printer') . ' Report</a>' : '')
        . ($invoice && can('billing.view') ? '<a class="btn btn-outline" href="' . e(url('billing/invoice', ['id' => $invoice['id']])) . '">' . icon('receipt') . ' ' . e($invoice['invoice_no']) . '</a>' : ''),
]);
?>
<div class="grid grid-main">
<div>
    <div class="card card-flush"><div class="card-header"><div class="card-title">Tests</div><?= status_chip($o['status']) ?></div>
        <div class="card-body table-wrap"><table class="table">
        <thead><tr><th>Test</th><th>Section</th><th>Status</th><th>Result</th><th></th></tr></thead><tbody>
        <?php foreach ($o['items'] as $i): ?><tr>
            <td><strong><?= e($i['test_name']) ?></strong><div class="muted small"><?= e(money($i['price'])) ?></div></td><td class="small"><?= e($i['section_name']) ?></td>
            <td><?= status_chip($i['status']) ?><?= $i['verified_by_name'] ? '<div class="muted small">' . e($i['verified_by_name']) . '</div>' : '' ?></td>
            <td class="small"><?php foreach ($i['values'] as $v): ?><div><?= e($v['name']) ?>: <strong><?= e($v['value_numeric'] !== null ? rtrim(rtrim((string) $v['value_numeric'], '0'), '.') : $v['value_text']) ?></strong> <?= e($v['unit']) ?>
                <?= $v['flag'] !== 'normal' ? '<span class="flag flag-' . e($v['flag']) . '">' . e(str_replace('_', ' ', $v['flag'])) . '</span>' : '' ?></div><?php endforeach; ?></td>
            <td class="nowrap">
                <?php if (in_array($i['status'], ['accepted', 'result_entered'], true) && can('lab.result.enter')): ?><a class="btn btn-sm btn-primary" href="<?= e(url('lab/result', ['id' => $i['id']])) ?>">Results</a><?php endif; ?>
                <?php if ($i['status'] === 'released' && can('lab.result.verify')): ?><a class="btn btn-sm btn-outline" href="<?= e(url('lab/result', ['id' => $i['id']])) ?>">Amend</a><?php endif; ?>
                <?php if (in_array($i['status'], ['ordered', 'sample_pending'], true) && (can('lab.reception') || can('lab.result.verify'))): ?>
                    <form method="post" action="<?= e(url('lab/order', ['id' => $o['id']])) ?>" style="display:inline"><?= csrf_field() ?><input type="hidden" name="do" value="cancel"><input type="hidden" name="item_id" value="<?= (int) $i['id'] ?>">
                        <button class="act danger" data-confirm="Cancel this test? The bill line is voided or refunded.">Cancel</button></form><?php endif; ?>
                <?php if (in_array($i['status'], ['collected', 'accepted'], true) && can('lab.outsource') && !$i['outsourced_referral_id']): ?>
                    <form method="post" action="<?= e(url('lab/outsource')) ?>" class="flex mt-1"><?= csrf_field() ?><input type="hidden" name="item_id" value="<?= (int) $i['id'] ?>">
                        <select class="input input-sm" name="vendor_id" required><option value="">Send to lab…</option><?php foreach (\App\Core\DB::pairs('SELECT id, name FROM vendors WHERE is_active = 1 AND deleted_at IS NULL ORDER BY name') as $vid => $vn): ?><option value="<?= $vid ?>"><?= e($vn) ?></option><?php endforeach; ?></select>
                        <button class="btn btn-sm btn-outline">Outsource</button></form><?php endif; ?>
            </td></tr><?php endforeach; ?>
        </tbody></table></div></div>
    <div class="card card-flush"><div class="card-header"><div class="card-title">Samples</div></div><div class="card-body table-wrap"><table class="table table-compact">
        <thead><tr><th>Barcode</th><th>Test</th><th>Collected</th><th>Review</th></tr></thead><tbody>
        <?php foreach ($samples as $s): ?><tr><td><strong class="tok-label"><?= e($s['barcode']) ?></strong></td><td><?= e($s['test_name']) ?></td>
            <td class="small"><?= e(fmt_datetime($s['collected_at'])) ?> · <?= e($s['collected_by_name']) ?></td>
            <td class="small"><?= status_chip($s['status']) ?><?php if ($s['decision']): ?> <?= e($s['reviewed_by_name']) ?><?= $s['rejection_reason'] ? ' — ' . e($s['rejection_reason']) . ' → <strong>' . e($s['resolution']) . '</strong>' . ($s['refund_amount'] ? ' ' . e(money($s['refund_amount'])) : '') : '' ?><?php endif; ?></td></tr><?php endforeach; ?>
        <?php if (!$samples): ?><tr><td colspan="4" class="muted">No samples collected yet.</td></tr><?php endif; ?>
    </tbody></table></div></div>
</div>
<div>
    <div class="card"><div class="card-body"><dl class="kv">
        <dt>Ordered</dt><dd><?= e(fmt_datetime($o['ordered_at'])) ?></dd>
        <dt>By</dt><dd><?= e($o['doctor_name'] ?: ($o['referring_doctor'] ?: '—')) ?></dd>
        <dt>Source</dt><dd><?= e(ucfirst(str_replace('_', ' ', $o['source']))) ?><?= $o['visit_no'] ? ' · <a href="' . e(url('visits/view', ['id' => $o['visit_id']])) . '">' . e($o['visit_no']) . '</a>' : '' ?></dd>
        <dt>Priority</dt><dd><?= e(strtoupper($o['priority'])) ?></dd>
        <?php if ($o['clinical_notes']): ?><dt>Notes</dt><dd><?= e($o['clinical_notes']) ?></dd><?php endif; ?>
        <?php if ($invoice): ?><dt>Bill</dt><dd><?= status_chip($invoice['status']) ?> <?= e(money($invoice['paid_amount'])) ?> / <?= e(money($invoice['net_amount'])) ?></dd><?php endif; ?>
    </dl></div></div>
    <?php if ($reports): ?><div class="card card-flush"><div class="card-header"><div class="card-title">Reports</div></div><div class="card-body"><table class="table table-compact"><tbody>
        <?php foreach ($reports as $r): ?><tr><td><strong><?= e($r['report_number']) ?></strong> v<?= (int) $r['version'] ?><div class="muted small"><?= e(fmt_datetime($r['released_at'])) ?> · <?= e($r['released_by_name']) ?></div></td>
            <td><?= status_chip($r['status']) ?><?= $r['handed_over_at'] ? '<div class="muted small">To ' . e($r['handed_over_to']) . '</div>' : '' ?></td></tr><?php endforeach; ?>
    </tbody></table></div></div><?php endif; ?>
</div>
</div>
