<?php
/** @var array $rows @var array $sections @var int $section @var array|null $mine */
use App\Core\View;

echo View::partial('page_header', [
    'title' => 'Bench / Results', 'description' => 'Accepted samples of your section(s). Enter provisional results; the pathologist verifies before anything is released.',
    'breadcrumbs' => [['Laboratory'], ['Bench']],
    'actions' => can('lab.qc.record') ? '<a class="btn btn-outline" href="' . e(url('lab/qc')) . '">' . icon('activity') . ' QC</a>' : '',
]);
?>
<div class="card card-flush">
    <div class="card-header"><form class="filters" method="get" action="<?= e(form_action('lab/bench')) ?>" data-autosubmit><?= route_field('lab/bench') ?>
        <div class="field"><label>Section</label><select class="input" name="section_id"><option value="">All<?= $mine !== null ? ' my sections' : '' ?></option>
            <?php foreach ($sections as $id => $n): if ($mine !== null && !in_array((int) $id, $mine, true)) { continue; } ?><option value="<?= $id ?>"<?= selected($id, $section) ?>><?= e($n) ?></option><?php endforeach; ?></select></div></form></div>
    <div class="card-body table-wrap"><table class="table">
        <thead><tr><th>Barcode</th><th>Patient</th><th>Test</th><th>Section</th><th>Accepted</th><th>TAT</th><th>Status</th><th></th></tr></thead><tbody>
        <?php foreach ($rows as $r):
            $due = $r['accepted_at'] ? strtotime($r['ordered_at']) + (int) $r['turnaround_hours'] * 3600 : null; $late = $due && $due < time(); ?><tr>
            <td><strong class="tok-label"><?= e($r['barcode']) ?></strong></td>
            <td><?= e($r['patient_name']) ?><div class="muted small"><?= e($r['mrn']) ?> · <?= e(substr($r['gender'], 0, 1)) ?>/<?= e(age_text($r['dob'])) ?></div></td>
            <td><strong><?= e($r['test_name']) ?></strong><?= $r['priority'] !== 'routine' ? ' <span class="badge badge-danger">' . e(strtoupper($r['priority'])) . '</span>' : '' ?></td>
            <td class="small"><?= e($r['section_name']) ?></td><td class="small"><?= e(fmt_datetime($r['accepted_at'])) ?></td>
            <td class="small <?= $late ? 'text-danger' : '' ?>"><?= $due ? e(date('d M H:i', $due)) : '' ?></td>
            <td><?= status_chip($r['status']) ?></td>
            <td><a class="btn btn-sm btn-primary" href="<?= e(url('lab/result', ['id' => $r['id']])) ?>"><?= $r['status'] === 'accepted' ? 'Enter' : 'Edit' ?></a></td></tr><?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="8" class="muted">No samples on the bench.</td></tr><?php endif; ?>
    </tbody></table></div>
</div>
<script>setTimeout(function () { location.reload(); }, 60000);</script>
