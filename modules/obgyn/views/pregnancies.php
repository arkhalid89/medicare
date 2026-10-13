<?php
/** @var array $rows @var string $status @var string $risk */
use App\Core\View;
use App\Modules\Obgyn\ObgynService;

echo View::partial('page_header', [
    'title' => 'Pregnancy Register', 'description' => 'Pregnancies booked at this facility with derived G / P and risk.',
    'breadcrumbs' => [['Gynae / Obstetrics'], ['Register']],
    'actions' => '<a class="btn btn-excel" href="' . e(url_with(['export' => 'xlsx'])) . '">' . icon('excel') . ' Export</a>',
]);
?>
<div class="card card-flush">
    <div class="card-header"><form class="filters" method="get" action="<?= e(form_action('obgyn/pregnancies')) ?>" data-autosubmit><?= route_field('obgyn/pregnancies') ?>
        <div class="field"><label>Status</label><select class="input" name="status"><option value="">All</option><?php foreach (ObgynService::OUTCOMES as $k => $l): ?><option value="<?= $k ?>"<?= selected($k, $status) ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
        <div class="field"><label>Risk</label><select class="input" name="risk"><option value="">All</option><option value="high"<?= selected('high', $risk) ?>>High</option><option value="low"<?= selected('low', $risk) ?>>Low</option></select></div></form></div>
    <div class="card-body table-wrap"><table class="table">
        <thead><tr><th>Patient</th><th>No.</th><th>G/P/A/L</th><th>LMP</th><th>EDD</th><th>Status</th><th>Risk</th></tr></thead><tbody>
        <?php foreach ($rows as $r): ?><tr><td><a href="<?= e(url('obgyn', ['patient_id' => $r['patient_id']])) ?>"><strong><?= e($r['name']) ?></strong></a><div class="muted small"><?= e($r['mrn']) ?> · <?= e($r['phone']) ?></div></td>
            <td>#<?= (int) $r['pregnancy_no'] ?></td><td>G<?= (int) $r['gravida'] ?> P<?= (int) $r['para'] ?> A<?= (int) $r['abortions'] ?> L<?= (int) $r['living_children'] ?></td>
            <td class="small"><?= e(fmt_date($r['lmp_date'])) ?></td><td class="small"><?= e(fmt_date($r['edd_date'])) ?></td><td><?= status_chip($r['status']) ?></td>
            <td><?= $r['risk_level'] === 'high' ? '<span class="badge badge-danger">High</span>' : '<span class="badge badge-muted">Low</span>' ?></td></tr><?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="7" class="muted">No pregnancies.</td></tr><?php endif; ?>
    </tbody></table></div>
</div>
