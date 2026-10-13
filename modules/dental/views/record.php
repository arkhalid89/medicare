<?php
/**
 * @var array $patient @var array $charts @var array $state @var array $teeth @var array $conditions @var array|null $compareState @var int $compare
 * @var array $plans @var array $procedures @var array $perio @var array $images @var array $cases
 */
use App\Core\View;

echo View::partial('page_header', [
    'title' => 'Dental record', 'description' => $patient['name'] . ' · ' . $patient['mrn'] . ' · ' . $patient['gender'] . ', ' . age_text($patient['dob']),
    'breadcrumbs' => [['Dentistry', url('dental')], [$patient['name']]],
    'actions' => '<a class="btn btn-outline" href="' . e(url('patients/view', ['id' => $patient['id']])) . '">Patient file</a>',
]);
$age = age_years($patient['dob']);
$dent = $age === null || $age > 12 ? 'permanent' : ($age < 6 ? 'deciduous' : 'mixed');
?>
<?php if ($patient['allergies']): ?><div class="alert alert-danger"><?= icon('alert') ?> Allergy: <?= e($patient['allergies']) ?></div><?php endif; ?>
<div class="card"><div class="card-header"><div class="card-title"><?= icon('tooth') ?> Current chart</div>
    <?php if (count($charts) > 1): ?><form method="get" action="<?= e(form_action('dental')) ?>" class="flex" data-autosubmit><?= route_field('dental') ?><input type="hidden" name="patient_id" value="<?= (int) $patient['id'] ?>">
        <label class="small muted">Compare with</label><select class="input input-sm" name="compare"><option value="">—</option>
        <?php foreach (array_slice($charts, 1) as $c): ?><option value="<?= (int) $c['id'] ?>"<?= selected($c['id'], $compare) ?>><?= e(fmt_date($c['chart_date'])) ?> (<?= e($c['visit_no']) ?>)</option><?php endforeach; ?></select></form><?php endif; ?></div>
    <div class="card-body">
        <?= View::render('dental/_map', ['state' => $state, 'teeth' => $teeth, 'dentition' => $dent, 'ghost' => $compareState], null) ?>
        <div class="dc-legend"><?php foreach (['healthy', 'caries', 'filled', 'crown', 'root_canal_needed', 'root_canal_done', 'fracture', 'missing', 'impacted', 'abscess', 'implant'] as $c): ?><span><i class="c-<?= $c ?>"></i><?= e($conditions[$c]) ?></span><?php endforeach; ?><span><i class="lg-planned"></i>Planned</span><?= $compareState ? '<span>• changed since compared chart</span>' : '' ?></div>
    </div></div>

<div class="card card-flush"><div class="card-header"><div class="card-title">Treatment plans</div></div><div class="card-body">
<?php foreach ($plans as $p): $open = in_array($p['status'], ['proposed', 'accepted', 'in_progress'], true); ?>
    <div class="plan-block">
        <div class="flex flex-between"><div><strong>Plan #<?= (int) $p['id'] ?></strong> <?= status_chip($p['status']) ?> <span class="muted small"><?= e($p['dentist']) ?> · <?= e(fmt_date($p['created_at'])) ?><?= $p['accepted_by_name'] ? ' · accepted by ' . e($p['accepted_by_name']) . ' ' . e(fmt_date($p['accepted_at'])) : '' ?></span></div>
            <div class="flex"><strong><?= e(money($p['estimated_total'])) ?></strong><a class="btn btn-sm btn-outline" target="_blank" href="<?= e(url('dental/plan-print', ['id' => $p['id']])) ?>"><?= icon('printer') ?></a></div></div>
        <table class="table table-compact"><thead><tr><th>#</th><th>Tooth</th><th>Procedure</th><th class="num">Est.</th><th>Status</th><th></th></tr></thead><tbody>
        <?php foreach ($p['items'] as $i): ?><tr class="<?= $i['status'] === 'skipped' ? 'muted' : '' ?>"><td><?= (int) $i['sequence_no'] ?></td><td><?= e($i['tooth_number'] ?: '—') ?><?= $i['surfaces'] ? ' <span class="muted small">' . e(strtoupper(implode('', array_map(static fn ($s) => $s[0], explode(',', $i['surfaces']))))) . '</span>' : '' ?></td>
            <td><?= e($i['procedure_name']) ?></td><td class="num"><?= e(money($i['estimated_price'])) ?></td>
            <td><?= status_chip($i['status']) ?><?= $i['performed_at'] ? '<div class="muted small">' . e(fmt_date($i['performed_at'])) . ' · ' . e($i['performed_by_name']) . '</div>' : '' ?></td>
            <td class="nowrap"><?php if ($open && in_array($i['status'], ['planned', 'scheduled'], true)): ?>
                <?php if (can('dental.procedure.complete') || ($i['hygienist_allowed'] && can('dental.procedure.hygiene'))): ?>
                <form method="post" action="<?= e(url('dental/plans')) ?>" style="display:inline"><?= csrf_field() ?><input type="hidden" name="do" value="complete"><input type="hidden" name="back" value="record"><input type="hidden" name="plan_id" value="<?= (int) $p['id'] ?>"><input type="hidden" name="patient_id" value="<?= (int) $patient['id'] ?>"><input type="hidden" name="item_id" value="<?= (int) $i['id'] ?>">
                    <button class="btn btn-sm btn-success" data-confirm="Mark done today? It is billed and consumables are deducted.">Done</button></form><?php endif; ?>
                <?php if (can('dental.plan.create')): ?><form method="post" action="<?= e(url('dental/plans')) ?>" style="display:inline"><?= csrf_field() ?><input type="hidden" name="do" value="skip"><input type="hidden" name="back" value="record"><input type="hidden" name="plan_id" value="<?= (int) $p['id'] ?>"><input type="hidden" name="patient_id" value="<?= (int) $patient['id'] ?>"><input type="hidden" name="item_id" value="<?= (int) $i['id'] ?>"><button class="act">Skip</button></form><?php endif; ?>
                <?php if ($i['needs_lab_case'] && can('dental.labcase.manage')): ?><button type="button" class="act" onclick="labCase(<?= (int) $i['id'] ?>, '<?= e($i['tooth_number']) ?>', '<?= e(addslashes($i['procedure_name'])) ?>')">Lab case</button><?php endif; ?>
            <?php endif; ?></td></tr><?php endforeach; ?>
        </tbody></table>
        <?php if ($open && can('dental.plan.create')): ?><div class="flex flex-wrap">
            <?php if ($p['status'] === 'proposed'): ?><form method="post" action="<?= e(url('dental/plans')) ?>" class="flex"><?= csrf_field() ?><input type="hidden" name="do" value="accept"><input type="hidden" name="back" value="record"><input type="hidden" name="plan_id" value="<?= (int) $p['id'] ?>"><input type="hidden" name="patient_id" value="<?= (int) $patient['id'] ?>">
                <input class="input input-sm" name="accepted_by_name" placeholder="Accepted by (patient / guardian)" required><button class="btn btn-sm btn-primary">Accept plan</button></form><?php endif; ?>
            <form method="post" action="<?= e(url('dental/plans')) ?>" class="flex"><?= csrf_field() ?><input type="hidden" name="do" value="add"><input type="hidden" name="back" value="record"><input type="hidden" name="plan_id" value="<?= (int) $p['id'] ?>"><input type="hidden" name="patient_id" value="<?= (int) $patient['id'] ?>">
                <input class="input input-sm" name="tooth_number" placeholder="Tooth" style="width:70px"><select class="input input-sm" name="procedure_id" required><option value="">Add step…</option><?php foreach ($procedures as $pr): ?><option value="<?= (int) $pr['id'] ?>"><?= e($pr['name']) ?> — <?= e(money($pr['price'])) ?></option><?php endforeach; ?></select><button class="btn btn-sm btn-outline">Add</button></form>
            <form method="post" action="<?= e(url('dental/plans')) ?>"><?= csrf_field() ?><input type="hidden" name="do" value="cancel"><input type="hidden" name="back" value="record"><input type="hidden" name="plan_id" value="<?= (int) $p['id'] ?>"><input type="hidden" name="patient_id" value="<?= (int) $patient['id'] ?>"><button class="btn btn-sm btn-ghost" data-confirm="Cancel this treatment plan?">Cancel plan</button></form>
        </div><?php endif; ?>
    </div>
<?php endforeach; ?>
<?php if (!$plans): ?><p class="muted">No treatment plans — procedures added on the tooth chart create one.</p><?php endif; ?>
</div></div>

<div class="card card-flush"><div class="card-header"><div class="card-title">Chart history</div></div><div class="card-body">
    <?php foreach ($charts as $c): ?><details class="chart-hist"<?= $c === ($charts[0] ?? null) ? ' open' : '' ?>><summary><strong><?= e(fmt_date($c['chart_date'])) ?></strong> · <?= e($c['visit_no']) ?> · <?= e(ucfirst(str_replace('_', ' ', $c['chart_type']))) ?> · <?= e($c['by_name']) ?> · <?= count($c['findings']) ?> finding(s)</summary>
        <?= $c['general_notes'] ? '<p>' . e($c['general_notes']) . '</p>' : '' ?>
        <table class="table table-compact"><tbody><?php foreach ($c['findings'] as $f): ?><tr><td><strong><?= e($f['tooth_number']) ?></strong></td><td><?= e($conditions[$f['condition']] ?? $f['condition']) ?><?= $f['surfaces'] ? ' (' . e(implode(', ', $f['surfaces'])) . ')' : '' ?><?= $f['severity'] ? ' · ' . e($f['severity']) : '' ?><?= $f['mobility_grade'] !== null ? ' · mobility ' . (int) $f['mobility_grade'] : '' ?></td>
            <td><?= e($f['procedure_name']) ?></td><td><?= $f['procedure_status'] !== 'n_a' ? status_chip($f['procedure_status']) : '' ?></td><td class="small"><?= e($f['notes']) ?></td><td class="small muted"><?= e($f['recorded_by_name']) ?><?= $f['cosigned_by_name'] ? ' · co-signed ' . e($f['cosigned_by_name']) : '' ?></td></tr><?php endforeach; ?></tbody></table></details><?php endforeach; ?>
    <?php if (!$charts): ?><p class="muted">No dental charts yet.</p><?php endif; ?>
</div></div>

<div class="grid grid-2">
    <div class="card card-flush"><div class="card-header"><div class="card-title">Perio charts</div><?php if (can('dental.perio.edit')): ?><a class="btn btn-sm btn-outline" href="<?= e(url('dental/chairside', ['patient_id' => $patient['id']])) ?>">New perio chart</a><?php endif; ?></div><div class="card-body"><table class="table table-compact"><tbody>
        <?php foreach ($perio as $p): ?><tr><td><?= e(fmt_datetime($p['charted_at'])) ?></td><td><?= e($p['by_name']) ?></td><td><?= (int) $p['deep'] ?> site(s) ≥ 4 mm · BOP <?= (int) $p['bop'] ?></td></tr><?php endforeach; ?>
        <?php if (!$perio): ?><tr><td class="muted">None.</td></tr><?php endif; ?>
    </tbody></table></div></div>
    <div class="card card-flush"><div class="card-header"><div class="card-title">Images &amp; X-rays</div></div><div class="card-body">
        <div class="flex flex-wrap"><?php foreach ($images as $im): ?><a class="chip" target="_blank" href="<?= e(url('dental/image', ['id' => $im['id'], 'patient_id' => $patient['id']])) ?>"><?= icon('image') ?> <?= e(str_replace('_', ' ', $im['image_type'])) ?><?= $im['tooth_number'] ? ' · ' . e($im['tooth_number']) : '' ?> · <?= e(fmt_date($im['uploaded_at'])) ?></a><?php endforeach; ?></div>
        <?php if (!$images): ?><p class="muted">No images.</p><?php endif; ?>
        <?php if (can('dental.chart.edit')): ?><form method="post" enctype="multipart/form-data" action="<?= e(url('dental/image')) ?>" class="flex flex-wrap mt-2"><?= csrf_field() ?><input type="hidden" name="patient_id" value="<?= (int) $patient['id'] ?>">
            <select class="input input-sm" name="image_type"><option value="intraoral_photo">Intra-oral photo</option><option value="periapical">Periapical</option><option value="bitewing">Bitewing</option><option value="opg">OPG</option><option value="cbct">CBCT</option></select>
            <input class="input input-sm" name="tooth_number" placeholder="Tooth" style="width:70px"><input type="file" name="file" accept="image/*,application/pdf" required><button class="btn btn-sm btn-outline">Upload</button></form><?php endif; ?>
    </div></div>
</div>

<div class="card card-flush"><div class="card-header"><div class="card-title">Lab cases</div><a class="btn btn-sm btn-outline" href="<?= e(url('dental/lab-cases')) ?>">All cases</a></div><div class="card-body"><table class="table table-compact"><tbody>
    <?php foreach ($cases as $c): ?><tr><td><strong><?= e($c['case_number']) ?></strong> <?= e(ucfirst($c['case_type'])) ?> <?= e($c['tooth_numbers']) ?></td><td class="small"><?= e($c['shade']) ?> <?= e($c['material']) ?></td><td class="small"><?= e($c['tech_name']) ?></td><td><?= status_chip($c['status']) ?></td><td class="small">Due <?= e(fmt_date($c['due_date'])) ?></td></tr><?php endforeach; ?>
    <?php if (!$cases): ?><tr><td class="muted">None.</td></tr><?php endif; ?>
</tbody></table></div></div>

<?php if (can('dental.labcase.manage')): ?>
<div class="modal-backdrop" id="lab-modal"><form class="modal" method="post" action="<?= e(url('dental/lab-cases')) ?>"><?= csrf_field() ?>
    <input type="hidden" name="do" value="create"><input type="hidden" name="back" value="record"><input type="hidden" name="patient_id" value="<?= (int) $patient['id'] ?>"><input type="hidden" name="treatment_plan_item_id" id="lc-item">
    <div class="modal-head"><?= icon('box') ?><h3 id="lc-title">New lab case</h3><button type="button" class="icon-btn close" onclick="APP.closeModal('lab-modal')"><?= icon('x') ?></button></div>
    <div class="modal-body"><div class="form-grid">
        <div class="field col-4"><label>Type</label><select class="input" name="case_type"><option>crown</option><option>bridge</option><option>denture</option><option>implant</option><option>aligner</option><option>other</option></select></div>
        <div class="field col-4"><label>Teeth</label><input class="input" name="tooth_numbers" id="lc-teeth"></div>
        <div class="field col-4"><label>Shade</label><input class="input" name="shade" placeholder="A2"></div>
        <div class="field col-6"><label>Material</label><input class="input" name="material" placeholder="PFM / zirconia / acrylic"></div>
        <div class="field col-6"><label>Due date</label><input class="input" type="date" name="due_date"></div>
        <div class="field col-12"><label>Instructions</label><input class="input" name="instructions"></div>
    </div></div>
    <div class="modal-foot"><button type="button" class="btn btn-outline" onclick="APP.closeModal('lab-modal')">Cancel</button><button class="btn btn-primary">Create case</button></div>
</form></div>
<script>function labCase(item, tooth, name) { document.getElementById('lc-item').value = item; document.getElementById('lc-teeth').value = tooth; document.getElementById('lc-title').textContent = 'Lab case — ' + name; APP.openModal('lab-modal'); }</script>
<?php endif; ?>
