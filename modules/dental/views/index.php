<?php
/** @var array $today @var array $waiting @var bool $hasDept */
use App\Core\View;

echo View::partial('page_header', [
    'title' => 'Dental Clinic', 'description' => 'Charting happens on the consultation (tooth map appears for Dentistry departments). Open a patient for the full dental record, plans and lab cases.',
    'breadcrumbs' => [['Dentistry'], ['Clinic']],
    'actions' => (can('visits.create') ? '<a class="btn btn-primary" href="' . e(url('consult')) . '">' . icon('list') . ' My OPD queue</a>' : '')
        . '<a class="btn btn-outline" href="' . e(url('dental/plans')) . '">Treatment plans</a>',
]);
?>
<?php if (!$hasDept): ?><div class="alert alert-warning">No department is tagged with the Dentistry specialty yet — tag one under Masters → Departments.</div><?php endif; ?>
<div class="card"><div class="card-body"><div class="field"><label>Open a patient's dental record</label><div class="search-box"><?= icon('search') ?><input class="input" id="dn-patient" autocomplete="off" placeholder="Name, MRN, phone or CNIC"><div class="search-results"></div></div></div></div></div>
<script>APP.liveSearch(document.getElementById('dn-patient'), { minLength: 2,
    fetch: function (q) { return APP.ajax(APP.url('patients/search', { q: q })).then(function (d) { return d.items || []; }); },
    render: function (p) { return '<div class="grow"><div class="t">' + APP.esc(p.name) + '</div><div class="s">' + APP.esc(p.mrn) + '</div></div>'; },
    onSelect: function (p) { location.href = APP.url('dental', { patient_id: p.id }); } });</script>
<div class="grid grid-2">
    <div class="card card-flush"><div class="card-header"><div class="card-title">Waiting (dental tokens)</div></div><div class="card-body"><table class="table table-compact"><tbody>
        <?php foreach ($waiting as $w): ?><tr><td><strong class="tok-label"><?= e($w['token_label']) ?></strong> <?= $w['priority'] !== 'normal' ? priority_badge($w['priority']) : '' ?></td><td><?= e($w['name']) ?> <span class="muted small"><?= e($w['mrn']) ?></span></td><td><?= status_chip($w['status']) ?></td></tr><?php endforeach; ?>
        <?php if (!$waiting): ?><tr><td class="muted">Nobody waiting.</td></tr><?php endif; ?>
    </tbody></table></div></div>
    <div class="card card-flush"><div class="card-header"><div class="card-title">Seen today</div></div><div class="card-body"><table class="table table-compact"><tbody>
        <?php foreach ($today as $v): ?><tr><td><a href="<?= e(url('dental', ['patient_id' => $v['patient_id']])) ?>"><strong><?= e($v['name']) ?></strong></a><div class="muted small"><?= e($v['mrn']) ?> · <?= e($v['visit_no']) ?></div></td>
            <td class="small"><?= e($v['doctor']) ?></td><td class="num"><?= (int) $v['findings'] ?> finding(s)</td></tr><?php endforeach; ?>
        <?php if (!$today): ?><tr><td class="muted">No dental visits today.</td></tr><?php endif; ?>
    </tbody></table></div></div>
</div>
