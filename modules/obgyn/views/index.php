<?php
/** @var array $today @var bool $hasDept @var array $stats */
use App\Core\View;

echo View::partial('page_header', [
    'title' => 'Gynae / Obs Clinic', 'description' => 'Gynae history, pregnancy and ANC are recorded on the consultation of a Gynecology / Obstetrics department. Open a patient for the full obstetric record.',
    'breadcrumbs' => [['Gynae / Obstetrics'], ['Clinic']],
    'actions' => '<a class="btn btn-outline" href="' . e(url('obgyn/pregnancies')) . '">Pregnancy register</a>' . (can('obgyn.anc.record') ? '<a class="btn btn-outline" href="' . e(url('obgyn/anc')) . '">ANC / labour room</a>' : ''),
]);
?>
<?php if (!$hasDept): ?><div class="alert alert-warning">No department is tagged with the Gynecology / Obstetrics specialty yet.</div><?php endif; ?>
<div class="grid grid-4 mb-2">
    <a class="stat" href="<?= e(url('obgyn/pregnancies')) ?>"><div class="stat-icon"><?= icon('heart') ?></div><div><p class="label">Ongoing pregnancies</p><div class="value"><?= $stats['ongoing'] ?></div></div></a>
    <a class="stat" href="<?= e(url('obgyn/pregnancies', ['risk' => 'high'])) ?>"><div class="stat-icon orange"><?= icon('alert') ?></div><div><p class="label">High risk</p><div class="value"><?= $stats['high'] ?></div></div></a>
    <a class="stat" href="<?= e(url('obgyn/anc')) ?>"><div class="stat-icon green"><?= icon('calendar') ?></div><div><p class="label">EDD in 30 days</p><div class="value"><?= $stats['due'] ?></div></div></a>
    <div class="stat"><div class="stat-icon gold"><?= icon('clock') ?></div><div><p class="label">Missed ANC follow-up</p><div class="value"><?= $stats['overdue'] ?></div></div></div>
</div>
<div class="card"><div class="card-body"><div class="field"><label>Open a patient's obstetric record</label><div class="search-box"><?= icon('search') ?><input class="input" id="ob-patient" autocomplete="off" placeholder="Name, MRN, phone or CNIC"><div class="search-results"></div></div></div></div></div>
<script>APP.liveSearch(document.getElementById('ob-patient'), { minLength: 2,
    fetch: function (q) { return APP.ajax(APP.url('patients/search', { q: q })).then(function (d) { return d.items || []; }); },
    render: function (p) { return '<div class="grow"><div class="t">' + APP.esc(p.name) + '</div><div class="s">' + APP.esc(p.mrn) + '</div></div>'; },
    onSelect: function (p) { location.href = APP.url('obgyn', { patient_id: p.id }); } });</script>
<div class="card card-flush"><div class="card-header"><div class="card-title">Seen today</div></div><div class="card-body"><table class="table table-compact"><tbody>
    <?php foreach ($today as $v): ?><tr><td><a href="<?= e(url('obgyn', ['patient_id' => $v['patient_id']])) ?>"><strong><?= e($v['name']) ?></strong></a> <span class="muted small"><?= e($v['mrn']) ?> · <?= e(age_text($v['dob'])) ?></span></td>
        <td class="small"><?= e($v['visit_no']) ?> · <?= e($v['doctor']) ?></td><td><?= $v['risk'] === 'high' ? '<span class="badge badge-danger">High-risk pregnancy</span>' : ($v['risk'] ? '<span class="badge badge-info">Pregnant</span>' : '') ?></td></tr><?php endforeach; ?>
    <?php if (!$today): ?><tr><td class="muted">No visits today.</td></tr><?php endif; ?>
</tbody></table></div></div>
