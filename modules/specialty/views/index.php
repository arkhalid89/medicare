<?php
/** @var array|null $patient */
use App\Core\View;

echo View::partial('page_header', [
    'title' => 'Specialty Exams', 'description' => 'Eye, cardiac, audiometry and growth records of a patient, with clinical notes and referrals. Exams are saved against one of the patient\'s visits of the last 30 days.',
    'breadcrumbs' => [['Clinical'], ['Specialty exams']],
    'actions' => '<a class="btn btn-outline" href="' . e(url('clinical/referrals')) . '">' . icon('send') . ' Referrals</a>',
]);
?>
<div class="card"><div class="card-body"><form class="filters" method="get" action="<?= e(form_action('specialty')) ?>"><?= route_field('specialty') ?>
    <input type="hidden" name="patient_id" id="sx-pid" value="<?= (int) ($patient['id'] ?? 0) ?>">
    <div class="field grow"><label>Patient</label><div class="search-box"><?= icon('search') ?><input class="input" id="sx-patient" autocomplete="off" value="<?= e($patient ? $patient['name'] . ' (' . $patient['mrn'] . ')' : '') ?>" placeholder="Name, MRN, phone or CNIC"><div class="search-results"></div></div></div>
</form></div></div>
<script>APP.liveSearch(document.getElementById('sx-patient'), { minLength: 2,
    fetch: function (q) { return APP.ajax(APP.url('patients/search', { q: q })).then(function (d) { return d.items || []; }); },
    render: function (p) { return '<div class="grow"><div class="t">' + APP.esc(p.name) + '</div><div class="s">' + APP.esc(p.mrn) + '</div></div>'; },
    onSelect: function (p) { location.href = APP.url('specialty', { patient_id: p.id }); } });</script>
<?php if ($patient):
    $visitSel = '<select class="input" name="visit_id" required>' . implode('', array_map(static fn ($v) => '<option value="' . (int) $v['id'] . '">' . e($v['visit_no'] . ' · ' . fmt_date($v['visit_date']) . ' · ' . ($v['dept'] ?: '') . ' · ' . $v['doctor']) . '</option>', $visits)) . '</select>';
    $form = static fn (string $kind, string $body) => $visits ? '<form method="post" action="' . e(url('specialty/record')) . '" class="mt-2">' . csrf_field() . '<input type="hidden" name="kind" value="' . $kind . '">'
        . '<div class="form-grid">' . $body . '<div class="field col-8"><label>Visit</label>' . $visitSel . '</div><div class="field col-4 btns"><button class="btn btn-primary btn-sm">Save</button></div></div></form>'
        : '<p class="muted small mt-2">No visit in the last 30 days — the patient must be seen first.</p>';
    $va = '<option value=""></option>' . implode('', array_map(static fn ($s) => '<option>' . $s . '</option>', $snellen));
?>
<div class="patient-banner"><strong><?= e($patient['name']) ?></strong> · <?= e($patient['mrn']) ?> · <?= e($patient['gender']) ?>, <?= e(age_text($patient['dob'])) ?>
    <?= $patient['allergies'] ? ' · <span class="badge badge-danger">Allergy: ' . e($patient['allergies']) . '</span>' : '' ?>
    <a class="btn btn-sm btn-outline" href="<?= e(url('patients/view', ['id' => $patient['id']])) ?>">Patient file</a></div>
<div class="grid grid-2">
    <div class="card"><div class="card-header"><div class="card-title"><?= icon('eye') ?> Eye examinations</div></div><div class="card-body">
        <table class="table table-compact"><thead><tr><th>Visit</th><th>VA OD / OS</th><th>IOP</th><th>Fundus</th></tr></thead><tbody>
        <?php foreach ($eye as $x): ?><tr><td class="small"><?= e($x['visit_no']) ?></td><td><?= e(($x['va_od_unaided'] ?: '—') . ' / ' . ($x['va_os_unaided'] ?: '—')) ?></td>
            <td><?= e(num($x['iop_od_mmhg']) . ' / ' . num($x['iop_os_mmhg'])) ?></td><td class="small"><?= e($x['fundus_findings']) ?></td></tr><?php endforeach; ?>
        <?php if (!$eye): ?><tr><td colspan="4" class="muted">None.</td></tr><?php endif; ?></tbody></table>
        <?php if (can('specialty.exam.record')): ?><details><summary class="small">Record eye exam</summary><?= $form('eye',
            '<div class="field col-3"><label>VA OD</label><select class="input" name="va_od_unaided">' . $va . '</select></div><div class="field col-3"><label>VA OS</label><select class="input" name="va_os_unaided">' . $va . '</select></div>'
            . '<div class="field col-3"><label>IOP OD</label><input class="input" type="number" step="0.1" name="iop_od"></div><div class="field col-3"><label>IOP OS</label><input class="input" type="number" step="0.1" name="iop_os"></div>'
            . '<div class="field col-12"><label>Fundus</label><input class="input" name="fundus"></div>') ?></details><?php endif; ?>
    </div></div>
    <div class="card"><div class="card-header"><div class="card-title"><?= icon('heart') ?> Cardiac investigations</div></div><div class="card-body">
        <table class="table table-compact"><tbody>
        <?php foreach ($cardiac as $c): ?><tr><td class="small"><?= e(fmt_date($c['performed_at'])) ?></td><td><?= e($cardiacTypes[$c['type']] ?? $c['type']) ?></td>
            <td class="small"><?= e(trim(($c['rhythm'] ?: '') . ($c['ef_percent'] !== null ? ' · EF ' . num($c['ef_percent']) . '%' : ''), ' ·')) ?> <?= e($c['findings']) ?></td></tr><?php endforeach; ?>
        <?php if (!$cardiac): ?><tr><td class="muted">None.</td></tr><?php endif; ?></tbody></table>
        <?php if (can('specialty.exam.record')): ?><details><summary class="small">Record ECG / echo</summary><?= $form('cardiac',
            '<div class="field col-4"><label>Type</label><select class="input" name="type" required>' . implode('', array_map(static fn ($k, $l) => '<option value="' . $k . '">' . $l . '</option>', array_keys($cardiacTypes), $cardiacTypes)) . '</select></div>'
            . '<div class="field col-4"><label>Rhythm</label><input class="input" name="rhythm"></div><div class="field col-2"><label>HR</label><input class="input" type="number" name="heart_rate"></div>'
            . '<div class="field col-2"><label>EF %</label><input class="input" type="number" step="0.1" name="ef_percent"></div><div class="field col-12"><label>Findings</label><input class="input" name="findings"></div>') ?></details><?php endif; ?>
    </div></div>
    <div class="card"><div class="card-header"><div class="card-title"><?= icon('volume') ?> Audiograms</div></div><div class="card-body">
        <table class="table table-compact"><tbody>
        <?php foreach ($audio as $a): $ac = json_decode((string) $a['air_conduction_json'], true) ?: []; ?><tr><td class="small"><?= e(fmt_date($a['performed_at'])) ?></td><td><?= $a['ear'] === 'L' ? 'Left' : 'Right' ?></td>
            <td class="small"><?= e(implode(' · ', array_map(static fn ($f, $d) => $f . ' Hz ' . $d, array_keys($ac), $ac))) ?></td><td>PTA <?= e(num($a['pta_db'])) ?> dB</td></tr><?php endforeach; ?>
        <?php if (!$audio): ?><tr><td class="muted">None.</td></tr><?php endif; ?></tbody></table>
        <?php if (can('specialty.exam.record')):
            $cells = static fn (string $ear) => implode('', array_map(static fn ($f) => '<td><input class="input input-sm" type="number" step="5" name="' . $ear . '[' . $f . ']" style="width:56px"></td>', $freqs)); ?>
        <details><summary class="small">Record audiogram</summary><?= $form('audiogram',
            '<div class="field col-12 table-wrap"><table class="table table-compact"><tr><th></th>' . implode('', array_map(static fn ($f) => '<th>' . $f . '</th>', $freqs)) . '<th>Tymp</th></tr>'
            . '<tr><td>R</td>' . $cells('R') . '<td><input class="input input-sm" name="tymp_R" style="width:50px"></td></tr><tr><td>L</td>' . $cells('L') . '<td><input class="input input-sm" name="tymp_L" style="width:50px"></td></tr></table></div>') ?></details><?php endif; ?>
    </div></div>
    <div class="card"><div class="card-header"><div class="card-title"><?= icon('activity') ?> Growth</div><a class="btn btn-sm btn-outline" href="<?= e(url('specialty/immunization', ['patient_id' => $patient['id']])) ?>">Immunization card</a></div><div class="card-body">
        <?php if ($growth): ?><?= View::render('specialty/_growth_chart', ['rows' => $growth, 'gender' => $patient['gender']], null) ?><?php endif; ?>
        <table class="table table-compact"><tbody>
        <?php foreach (array_reverse($growth) as $g): $pc = json_decode((string) $g['percentiles_json'], true) ?: []; ?><tr><td class="small"><?= e(fmt_date($g['measured_on'])) ?> (<?= e(num($g['age_months'])) ?> m)</td>
            <td><?= $g['weight_kg'] !== null ? e(num($g['weight_kg'])) . ' kg' . (isset($pc['weight_pct']) ? ' <span class="muted">P' . e($pc['weight_pct']) . '</span>' : '') : '' ?></td>
            <td><?= $g['height_cm'] !== null ? e(num($g['height_cm'])) . ' cm' . (isset($pc['height_pct']) ? ' <span class="muted">P' . e($pc['height_pct']) . '</span>' : '') : '' ?></td>
            <td><?= $g['head_circumference_cm'] !== null ? 'HC ' . e(num($g['head_circumference_cm'])) : '' ?></td></tr><?php endforeach; ?>
        <?php if (!$growth): ?><tr><td class="muted">No measurements.</td></tr><?php endif; ?></tbody></table>
        <?php if (can('specialty.exam.record') || can('vitals.record')): ?><details><summary class="small">Record growth</summary><?= $form('growth',
            '<div class="field col-4"><label>Weight kg</label><input class="input" type="number" step="0.01" name="weight_kg"></div><div class="field col-4"><label>Height cm</label><input class="input" type="number" step="0.1" name="height_cm"></div>'
            . '<div class="field col-4"><label>Head cm</label><input class="input" type="number" step="0.1" name="head_cm"></div>') ?></details><?php endif; ?>
    </div></div>
</div>
<?php if ($body): ?><div class="card card-flush"><div class="card-header"><div class="card-title">Body-map findings</div></div><div class="card-body"><table class="table table-compact"><tbody>
    <?php foreach ($body as $b): ?><tr><td class="small"><?= e(fmt_date($b['recorded_at'])) ?> · <?= e($b['visit_no']) ?></td><td><?= e($b['label']) ?></td><td><?= e($b['finding_type']) ?><?= $b['severity'] ? ' · ' . e($b['severity']) : '' ?><?= $b['size_cm'] !== null ? ' · ' . e(num($b['size_cm'])) . ' cm' : '' ?></td><td class="small"><?= e($b['description']) ?></td></tr><?php endforeach; ?>
</tbody></table></div></div><?php endif; ?>
<div class="grid grid-2">
    <div class="card"><div class="card-header"><div class="card-title"><?= icon('file-text') ?> Clinical notes</div></div><div class="card-body">
        <?php foreach ($notes as $n): ?><div class="note-row"><div class="muted small"><?= e(fmt_datetime($n['created_at'])) ?> · <?= e($n['author_name']) ?><?= $n['visit_no'] ? ' · ' . e($n['visit_no']) : '' ?><?= $n['is_restricted'] ? ' · ' . icon('lock') . ' restricted' : '' ?></div>
            <?= $n['visible'] ? nl2br(e($n['note_text'])) : '<em class="muted">Restricted — visible to the treating team only.</em>' ?></div><?php endforeach; ?>
        <?php if (!$notes): ?><p class="muted">No notes.</p><?php endif; ?>
        <?php if (can('clinical.notes.write') || can('visits.create')): ?>
        <form method="post" action="<?= e(url('clinical/note')) ?>" class="mt-2"><?= csrf_field() ?><input type="hidden" name="patient_id" value="<?= (int) $patient['id'] ?>">
            <textarea class="input" name="note_text" rows="2" required></textarea>
            <div class="flex mt-1"><label class="check"><input type="checkbox" name="is_restricted" value="1"> <?= icon('lock') ?> Restricted</label><span class="grow"></span><button class="btn btn-sm btn-primary">Add note</button></div></form>
        <?php endif; ?>
    </div></div>
    <div class="card"><div class="card-header"><div class="card-title"><?= icon('send') ?> Referrals</div></div><div class="card-body">
        <table class="table table-compact"><tbody>
        <?php foreach ($referrals as $r): ?><tr><td class="small"><?= e(fmt_date($r['created_at'])) ?></td><td>To <?= e($r['dept'] ?: $r['external_to']) ?><div class="muted small"><?= e($r['reason']) ?> — <?= e($r['from_name']) ?></div></td><td><?= status_chip($r['status']) ?></td></tr><?php endforeach; ?>
        <?php if (!$referrals): ?><tr><td class="muted">No referrals.</td></tr><?php endif; ?></tbody></table>
    </div></div>
</div>
<?php endif; ?>
