<?php
/** @var array $o @var array $procs @var array|null $report @var array $addenda */
use App\Core\Auth;
use App\Core\View;
use App\Modules\Radiology\RadiologyService;

echo View::partial('page_header', [
    'title' => $o['procedure_name'], 'description' => $o['order_number'] . ' · ' . $o['patient_name'] . ' · ' . $o['mrn'],
    'breadcrumbs' => [['Radiology'], ['Orders', url('radiology/orders')], [$o['order_number']]],
    'actions' => $report && $report['status'] !== 'draft' ? '<a class="btn btn-primary" target="_blank" href="' . e(url('radiology/print', ['id' => $o['id']])) . '">' . icon('printer') . ' Report</a>' : '',
]);
?>
<?php if ($report && $report['is_critical_finding'] && !$report['critical_acknowledged_at'] && (int) $report['critical_notified_user_id'] === Auth::id()): ?>
<form class="alert alert-danger flex" method="post" action="<?= e(url('radiology/order', ['id' => $o['id']])) ?>"><?= csrf_field() ?><input type="hidden" name="do" value="ack"><input type="hidden" name="report_id" value="<?= (int) $report['id'] ?>">
    <?= icon('alert') ?> <strong>Critical finding</strong> reported on this study. <button class="btn btn-sm btn-danger">Acknowledge</button></form>
<?php endif; ?>
<div class="grid grid-main">
<div>
    <?php if ($report && $report['status'] !== 'draft'): ?><?= View::render('radiology/_report_body', ['report' => $report, 'addenda' => $addenda], null) ?><?php endif; ?>
    <div class="card card-flush"><div class="card-header"><div class="card-title">Acquisitions &amp; quality gate</div></div><div class="card-body table-wrap"><table class="table table-compact">
        <thead><tr><th>#</th><th>Performed</th><th>Images</th><th>Dose</th><th>Contrast</th><th>Review</th></tr></thead><tbody>
        <?php foreach ($procs as $i => $p): ?><tr><td><?= $i + 1 ?><?= $p['reprocess_of_procedure_id'] ? ' <span class="badge badge-warning">repeat</span>' : '' ?></td>
            <td class="small"><?= e(fmt_datetime($p['started_at'])) ?> · <?= e($p['tech_name']) ?><div class="muted"><?= e($p['equipment_name']) ?> · <?= e($p['protocol_name']) ?></div></td>
            <td><?= (int) $p['image_count'] ?><?= $p['image_path'] && can('radiology.view') ? ' <a target="_blank" href="' . e(url('radiology/image', ['id' => $p['id']])) . '">' . icon('image') . '</a>' : '' ?></td>
            <td class="small"><?= $p['dose'] ? e(trim(($p['dose']['kvp'] ? $p['dose']['kvp'] . ' kVp ' : '') . ($p['dose']['dap_value'] ? 'DAP ' . $p['dose']['dap_value'] . ' ' : '') . ($p['dose']['dlp'] ? 'DLP ' . $p['dose']['dlp'] : ''))) : '—' ?></td>
            <td class="small"><?php foreach ($p['contrast'] as $c): ?><?= e($c['item_name']) ?> <?= e(num($c['volume_ml'])) ?> ml <?= e($c['batch_no']) ?><?= $c['reaction'] ? ' <span class="text-danger">' . e($c['reaction']) . '</span>' : '' ?><?php endforeach; ?></td>
            <td><?= $p['decision'] ? status_chip($p['decision']) . ' <span class="small">' . e($p['reviewer']) . ($p['review_reason'] ? ' — ' . e($p['review_reason']) : '') . '</span>' : '' ?></td></tr><?php endforeach; ?>
        <?php if (!$procs): ?><tr><td colspan="6" class="muted">Not performed yet.</td></tr><?php endif; ?>
    </tbody></table></div></div>
</div>
<div class="card"><div class="card-body"><dl class="kv">
    <dt>Status</dt><dd><?= status_chip($o['status']) ?></dd>
    <dt>Ordered</dt><dd><?= e(fmt_datetime($o['ordered_at'])) ?> · <?= e($o['doctor_name'] ?: ($o['referring_doctor'] ?: 'walk-in')) ?></dd>
    <dt>Priority</dt><dd><?= e(strtoupper($o['priority'])) ?></dd>
    <dt>Modality</dt><dd><?= e($o['modality_name']) ?> · <?= e($o['body_part']) ?></dd>
    <dt>Pregnancy</dt><dd><?= e(RadiologyService::PREGNANCY[$o['pregnancy_status']] ?? $o['pregnancy_status']) ?></dd>
    <?php if ($o['contrast_required']): ?><dt>Contrast</dt><dd>Allergy <?= $o['allergy_checked'] ? 'checked' : 'NOT checked' ?> · creatinine <?= e($o['creatinine_value'] ?? '—') ?></dd><?php endif; ?>
    <?php if ($o['scheduled_at']): ?><dt>Booked</dt><dd><?= e(fmt_datetime($o['scheduled_at'])) ?> · <?= e($o['room_name']) ?></dd><?php endif; ?>
    <?php if ($o['clinical_indication']): ?><dt>Indication</dt><dd><?= e($o['clinical_indication']) ?></dd><?php endif; ?>
    <dt>Price</dt><dd><?= e(money($o['price'])) ?></dd>
</dl></div></div>
</div>
