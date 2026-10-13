<?php
/** @var string $tab @var array $rows @var array $counts */
use App\Core\View;
use App\Modules\Radiology\RadiologyService;

echo View::partial('page_header', [
    'title' => 'Radiologist Desk', 'description' => 'Quality gate first — Accept, Reprocess (repeat, no new charge) or Reject (charge refunded) — then report. A finalized report is never edited, only addended.',
    'breadcrumbs' => [['Radiology'], ['Radiologist']],
]);
?>
<div class="tabs mb-2">
    <?php if (can('radiology.review')): ?><a class="<?= $tab === 'review' ? 'active' : '' ?>" href="<?= e(url('radiology/radiologist')) ?>">Quality gate<?= !empty($counts['under_review']) ? ' <span class="badge badge-warning">' . (int) $counts['under_review'] . '</span>' : '' ?></a><?php endif; ?>
    <?php if (can('radiology.report')): ?><a class="<?= $tab === 'report' ? 'active' : '' ?>" href="<?= e(url('radiology/radiologist', ['tab' => 'report'])) ?>">To report<?= !empty($counts['accepted']) ? ' <span class="badge badge-warning">' . (int) $counts['accepted'] . '</span>' : '' ?></a><?php endif; ?>
</div>
<div class="card card-flush"><div class="card-body table-wrap"><table class="table">
    <thead><tr><th>Study</th><th>Patient</th><th>Acquisition</th><th></th></tr></thead><tbody>
    <?php foreach ($rows as $o): $p = $o['procedure']; ?><tr>
        <td><strong><?= e($o['procedure_name']) ?></strong> <?= $o['priority'] !== 'routine' ? '<span class="badge badge-danger">' . e(strtoupper($o['priority'])) . '</span>' : '' ?><div class="muted small"><?= e($o['order_number']) ?> · <?= e($o['doctor_name'] ?: $o['referring_doctor']) ?></div>
            <?= $o['clinical_indication'] ? '<div class="small">' . e($o['clinical_indication']) . '</div>' : '' ?></td>
        <td><?= e($o['patient_name']) ?><div class="muted small"><?= e($o['mrn']) ?> · <?= e($o['gender']) ?>, <?= e(age_text($o['dob'])) ?></div></td>
        <td class="small"><?php if ($p): ?><?= (int) $p['image_count'] ?> image(s) · <?= e($p['tech_name']) ?> · <?= e(fmt_datetime($p['ended_at'])) ?><?= $o['attempts'] > 1 ? ' · attempt ' . (int) $o['attempts'] : '' ?>
            <?= $p['image_reference'] ? '<div>Ref: ' . e($p['image_reference']) . '</div>' : '' ?><?= $p['image_path'] ? '<a target="_blank" href="' . e(url('radiology/image', ['id' => $p['id']])) . '">' . icon('image') . ' key image</a>' : '' ?>
            <?= $p['technical_notes'] ? '<div class="muted">' . e($p['technical_notes']) . '</div>' : '' ?><?php endif; ?></td>
        <td><?php if ($tab === 'review' && $p): ?>
            <form method="post" action="<?= e(url('radiology/radiologist')) ?>" class="flex flex-wrap"><?= csrf_field() ?><input type="hidden" name="procedure_id" value="<?= (int) $p['id'] ?>">
                <button class="btn btn-sm btn-success" name="decision" value="accept"><?= icon('check') ?> Accept</button>
                <select class="input input-sm" name="reason"><option value="">Reason…</option><?php foreach (RadiologyService::REJECT_REASONS as $r): ?><option><?= e($r) ?></option><?php endforeach; ?></select>
                <button class="btn btn-sm btn-outline" name="decision" value="reprocess">Reprocess</button>
                <button class="btn btn-sm btn-danger" name="decision" value="reject" data-confirm="Reject and refund this study?">Reject + refund</button></form>
        <?php else: ?><a class="btn btn-sm btn-primary" href="<?= e(url('radiology/report', ['id' => $o['id']])) ?>"><?= $o['draft'] ? 'Continue draft' : 'Report' ?></a><?php endif; ?></td></tr><?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="4" class="muted">Nothing waiting.</td></tr><?php endif; ?>
</tbody></table></div></div>
