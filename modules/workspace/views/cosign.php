<?php
/** @var array $rows */
use App\Core\View;

echo View::partial('page_header', [
    'title'       => 'Co-sign Queue',
    'description' => 'Consultations, dental findings and obstetric entries made by residents / medical officers wait here for a consultant\'s co-signature.',
    'breadcrumbs' => [['Clinical'], ['Co-sign']],
]);
?>
<div class="card card-flush"><div class="card-body table-wrap">
    <?php if (!$rows): ?><div class="empty"><?= icon('check') ?><strong>Nothing to co-sign</strong></div><?php else: ?>
    <table class="table"><thead><tr><th>Visit</th><th>Date</th><th>Patient</th><th>Resident</th><th>Diagnosis</th><th></th></tr></thead><tbody>
        <?php foreach ($rows as $r): ?>
            <tr><td><a href="<?= e(url('visits/view', ['id' => $r['id']])) ?>"><?= e($r['visit_no']) ?></a></td><td class="small"><?= e(fmt_datetime($r['visit_date'])) ?></td>
                <td><?= e($r['patient_name']) ?><div class="muted small"><?= e($r['mrn']) ?></div></td><td><?= e($r['doctor_name']) ?></td><td class="small"><?= e($r['diagnoses']) ?></td>
                <td><form method="post" action="<?= e(url('clinical/cosign-do')) ?>"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><button class="btn btn-sm btn-success"><?= icon('check') ?> Co-sign</button></form></td></tr>
        <?php endforeach; ?>
    </tbody></table>
    <?php endif; ?>
</div></div>
