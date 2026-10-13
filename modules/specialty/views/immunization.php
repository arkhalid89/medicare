<?php
/** @var array|null $patient @var array $card @var array $due */
use App\Core\View;

echo View::partial('page_header', [
    'title' => 'Immunization', 'description' => 'EPI card per child. Each dose recorded is drawn from OPD / ward vaccine stock (FEFO) with its batch.',
    'breadcrumbs' => [['Clinical'], ['Immunization']],
]);
?>
<div class="card"><div class="card-body"><div class="field"><label>Child</label><div class="search-box"><?= icon('search') ?><input class="input" id="im-patient" autocomplete="off" value="<?= e($patient ? $patient['name'] . ' (' . $patient['mrn'] . ')' : '') ?>" placeholder="Name, MRN or mother's phone"><div class="search-results"></div></div></div></div></div>
<script>APP.liveSearch(document.getElementById('im-patient'), { minLength: 2,
    fetch: function (q) { return APP.ajax(APP.url('patients/search', { q: q })).then(function (d) { return d.items || []; }); },
    render: function (p) { return '<div class="grow"><div class="t">' + APP.esc(p.name) + '</div><div class="s">' + APP.esc(p.mrn) + '</div></div>'; },
    onSelect: function (p) { location.href = APP.url('specialty/immunization', { patient_id: p.id }); } });</script>
<?php if ($patient): ?>
<div class="card card-flush"><div class="card-header"><div class="card-title"><?= e($patient['name']) ?> · <?= e($patient['mrn']) ?> · DOB <?= e(fmt_date($patient['dob'])) ?> (<?= e(age_text($patient['dob'])) ?>)</div></div>
    <div class="card-body table-wrap"><table class="table">
        <thead><tr><th>Vaccine</th><th>Dose</th><th>Due</th><th>Status</th><th>Given</th><th></th></tr></thead><tbody>
        <?php foreach ($card as $c): ?><tr>
            <td><strong><?= e($c['vaccine']) ?></strong></td><td><?= (int) $c['dose'] ?> <span class="muted small"><?= e($c['label']) ?></span></td>
            <td class="small"><?= e(fmt_date($c['due'])) ?></td>
            <td><span class="badge <?= ['given' => 'badge-success', 'overdue' => 'badge-danger', 'due' => 'badge-warning'][$c['status']] ?? 'badge-muted' ?>"><?= e($c['status']) ?></span></td>
            <td class="small"><?= $c['given'] ? e(fmt_datetime($c['given']['given_at'])) . ' · ' . e($c['given']['given_by_name']) : '' ?></td>
            <td><?php if (!$c['given'] && can('immunization.record')): ?>
                <form method="post" action="<?= e(url('specialty/immunization')) ?>" class="flex"><?= csrf_field() ?><input type="hidden" name="patient_id" value="<?= (int) $patient['id'] ?>">
                    <input type="hidden" name="vaccine_id" value="<?= $c['vaccine_id'] ?>"><input type="hidden" name="dose_no" value="<?= $c['dose'] ?>">
                    <select class="input input-sm" name="site"><option value="">Site…</option><option>Left thigh</option><option>Right thigh</option><option>Left arm</option><option>Right arm</option><option>Oral</option></select>
                    <button class="btn btn-sm btn-primary" data-confirm="Record <?= e($c['vaccine']) ?> dose <?= (int) $c['dose'] ?> as given now?">Give</button></form><?php endif; ?></td></tr><?php endforeach; ?>
    </tbody></table></div></div>
<?php endif; ?>
<div class="card card-flush"><div class="card-header"><div class="card-title">Next doses due (overdue &amp; next 7 days)</div></div><div class="card-body"><table class="table table-compact"><tbody>
    <?php foreach ($due as $d): ?><tr><td><a href="<?= e(url('specialty/immunization', ['patient_id' => $d['id']])) ?>"><?= e($d['name']) ?></a> <span class="muted small"><?= e($d['mrn']) ?></span></td>
        <td><?= e($d['vaccine']) ?> dose <?= (int) $d['dose_no'] + 1 ?></td><td class="<?= $d['next_due_date'] < today() ? 'text-danger' : '' ?>"><?= e(fmt_date($d['next_due_date'])) ?></td></tr><?php endforeach; ?>
    <?php if (!$due): ?><tr><td class="muted">Nothing due.</td></tr><?php endif; ?>
</tbody></table></div></div>
