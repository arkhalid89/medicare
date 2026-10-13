<?php
/** @var array $rows @var bool $all */
use App\Core\View;

echo View::partial('page_header', [
    'title' => 'Critical Lab Values', 'description' => 'Life-threatening results notified to the ordering doctor. Acknowledge with a read-back so the lab knows the value was received.',
    'breadcrumbs' => [['Laboratory'], ['Critical values']],
    'actions' => can('lab.result.verify') ? '<a class="btn btn-outline" href="' . e(url('lab/critical', $all ? [] : ['all' => 1])) . '">' . ($all ? 'Mine' : 'All doctors') . '</a>' : '',
]);
?>
<div class="card card-flush"><div class="card-body table-wrap"><table class="table">
    <thead><tr><th>Notified</th><th>Patient</th><th>Test</th><th>Value</th><?= $all ? '<th>Doctor</th>' : '' ?><th>Acknowledgement</th></tr></thead><tbody>
    <?php foreach ($rows as $r): ?><tr class="<?= $r['acknowledged_at'] ? '' : 'row-alert' ?>">
        <td class="small"><?= e(fmt_datetime($r['notified_at'])) ?></td>
        <td><?= e($r['patient_name']) ?><div class="muted small"><?= e($r['mrn']) ?></div></td>
        <td><a href="<?= e(url('lab/order', ['id' => $r['lab_order_id']])) ?>"><?= e($r['test_name']) ?></a><div class="muted small"><?= e($r['parameter_name']) ?></div></td>
        <td><span class="flag flag-<?= e($r['flag']) ?>"><?= e($r['value_numeric'] !== null ? rtrim(rtrim((string) $r['value_numeric'], '0'), '.') : $r['value_text']) ?> <?= e($r['unit']) ?></span></td>
        <?= $all ? '<td class="small">' . e($r['doctor_name']) . '</td>' : '' ?>
        <td><?php if ($r['acknowledged_at']): ?><span class="small">✓ <?= e(fmt_datetime($r['acknowledged_at'])) ?> — <?= e($r['read_back_note']) ?></span>
            <?php elseif ((int) $r['notified_user_id'] === \App\Core\Auth::id()): ?>
            <form method="post" action="<?= e(url('lab/critical')) ?>" class="flex"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                <input class="input input-sm grow" name="read_back" placeholder="Read-back / action taken" required><button class="btn btn-sm btn-danger">Acknowledge</button></form>
            <?php else: ?><span class="badge badge-danger">Not acknowledged</span><?php endif; ?></td></tr><?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="6" class="muted">No critical values.</td></tr><?php endif; ?>
</tbody></table></div></div>
