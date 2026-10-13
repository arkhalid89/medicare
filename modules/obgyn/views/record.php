<?php
/** @var array $patient @var array|null $gyn @var array|null $oh @var array $pregnancies @var array $children */
use App\Core\View;
use App\Modules\Obgyn\ObgynService;

echo View::partial('page_header', [
    'title' => 'Obstetric & gynae record', 'description' => $patient['name'] . ' · ' . $patient['mrn'] . ' · ' . age_text($patient['dob']) . ($patient['blood_group'] ? ' · ' . $patient['blood_group'] : ''),
    'breadcrumbs' => [['Gynae / Obstetrics', url('obgyn')], [$patient['name']]],
    'actions' => '<a class="btn btn-outline" href="' . e(url('patients/view', ['id' => $patient['id']])) . '">Patient file</a>',
]);
$ongoing = null;
foreach ($pregnancies as $p) {
    if ($p['status'] === 'ongoing') {
        $ongoing = $p;
    }
}
?>
<div class="patient-banner"><strong><?= e($oh ? 'G' . $oh['gravida'] . ' P' . $oh['para'] . ' A' . $oh['abortions'] . ' L' . $oh['living_children'] : 'G0') ?></strong>
    <?= $oh && $oh['previous_cesareans'] ? ' · previous C-section × ' . (int) $oh['previous_cesareans'] : '' ?><?= $oh && $oh['previous_delivery_modes'] ? ' · ' . e($oh['previous_delivery_modes']) : '' ?>
    <span class="muted small">(calculated from the pregnancy records)</span>
    <?= $patient['allergies'] ? ' · <span class="badge badge-danger">Allergy: ' . e($patient['allergies']) . '</span>' : '' ?></div>

<div class="grid grid-2">
<form class="card" method="post" action="<?= e(url('obgyn/action')) ?>"><?= csrf_field() ?><input type="hidden" name="do" value="gyn"><input type="hidden" name="patient_id" value="<?= (int) $patient['id'] ?>">
    <div class="card-header"><div class="card-title">Gynae history</div><span class="muted small"><?= $gyn ? 'Updated ' . e(fmt_datetime($gyn['updated_at'])) . ' · ' . e($gyn['updated_by_name']) : '' ?></span></div>
    <fieldset class="card-body form-grid"<?= can('obgyn.history.edit') ? '' : ' disabled' ?>>
        <div class="field col-4"><label>Menarche (age)</label><input class="input" type="number" name="menarche_age" value="<?= e($gyn['menarche_age'] ?? '') ?>"></div>
        <div class="field col-4"><label>Cycle (days)</label><input class="input" type="number" name="cycle_length_days" value="<?= e($gyn['cycle_length_days'] ?? '') ?>"></div>
        <div class="field col-4"><label>Bleeding (days)</label><input class="input" type="number" name="cycle_duration_days" value="<?= e($gyn['cycle_duration_days'] ?? '') ?>"></div>
        <div class="field col-4"><label>Regularity</label><select class="input" name="cycle_regularity"><option value=""></option><?php foreach (['regular', 'irregular'] as $r): ?><option<?= selected($r, $gyn['cycle_regularity'] ?? '') ?>><?= $r ?></option><?php endforeach; ?></select></div>
        <div class="field col-4"><label>LMP</label><input class="input" type="date" name="lmp_date" value="<?= e($gyn['lmp_date'] ?? '') ?>"></div>
        <div class="field col-4"><label>Last Pap smear</label><input class="input" type="date" name="pap_smear_last" value="<?= e($gyn['pap_smear_last'] ?? '') ?>"></div>
        <div class="field col-6"><label>Contraception</label><select class="input" name="contraceptive_method"><option value=""></option><?php foreach (ObgynService::CONTRACEPTION as $c): ?><option<?= selected($c, $gyn['contraceptive_method'] ?? '') ?>><?= e($c) ?></option><?php endforeach; ?></select></div>
        <div class="field col-6"><label>Menopause</label><select class="input" name="menopause_status"><option value=""></option><?php foreach (ObgynService::MENOPAUSE as $k => $l): ?><option value="<?= $k ?>"<?= selected($k, $gyn['menopause_status'] ?? '') ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
        <div class="field col-12"><label>Notes</label><input class="input" name="notes" value="<?= e($gyn['notes'] ?? '') ?>"></div>
    </fieldset>
    <?php if (can('obgyn.history.edit')): ?><div class="card-footer"><button class="btn btn-primary">Save history</button></div><?php endif; ?>
</form>
<div>
    <?php if (!$ongoing && can('obgyn.pregnancy.manage') && $patient['gender'] === 'Female'): ?>
    <form class="card" method="post" action="<?= e(url('obgyn/action')) ?>"><?= csrf_field() ?><input type="hidden" name="do" value="open"><input type="hidden" name="patient_id" value="<?= (int) $patient['id'] ?>">
        <div class="card-header"><div class="card-title">Open a pregnancy</div></div>
        <div class="card-body form-grid"><div class="field col-4"><label>LMP</label><input class="input" type="date" name="lmp_date" value="<?= e($gyn['lmp_date'] ?? '') ?>"></div>
            <div class="field col-4"><label>or EDD</label><input class="input" type="date" name="edd_date"></div>
            <div class="field col-4"><label>Method</label><select class="input" name="edd_method"><option value="lmp">LMP</option><option value="ultrasound">Ultrasound</option><option value="ivf">IVF</option></select></div>
            <div class="field col-12"><label class="check"><input type="checkbox" name="is_multiple" value="1"> Multiple pregnancy</label></div></div>
        <div class="card-footer"><button class="btn btn-primary">Open pregnancy</button></div></form>
    <?php endif; ?>
    <?php if (can('obgyn.history.edit')): ?>
    <form class="card" method="post" action="<?= e(url('obgyn/action')) ?>"><?= csrf_field() ?><input type="hidden" name="do" value="past"><input type="hidden" name="patient_id" value="<?= (int) $patient['id'] ?>">
        <div class="card-header"><div class="card-title">Add a past pregnancy (from history)</div></div>
        <div class="card-body form-grid">
            <div class="field col-4"><label>Outcome</label><select class="input" name="status"><option value="delivered">Delivered</option><option value="miscarriage">Miscarriage</option><option value="termination">Termination</option><option value="ectopic">Ectopic</option><option value="molar">Molar</option></select></div>
            <div class="field col-4"><label>Year or date</label><input class="input" name="outcome_date" placeholder="2021"></div>
            <div class="field col-4"><label>Mode (if delivered)</label><select class="input" name="mode"><?php foreach (ObgynService::MODES as $k => $l): ?><option value="<?= $k ?>"><?= e($l) ?></option><?php endforeach; ?></select></div>
            <div class="field col-4"><label>Baby</label><select class="input" name="outcome"><?php foreach (ObgynService::BIRTH_OUTCOMES as $k => $l): ?><option value="<?= $k ?>"><?= e($l) ?></option><?php endforeach; ?></select></div>
            <div class="field col-4"><label>GA (weeks)</label><input class="input" type="number" name="ga_weeks"></div>
            <div class="field col-4"><label>Place</label><select class="input" name="place"><option value="other">Other facility</option><option value="home">Home</option></select></div>
            <div class="field col-12"><label>Complications / notes</label><input class="input" name="complications"></div></div>
        <div class="card-footer"><button class="btn btn-outline">Add</button></div></form>
    <?php endif; ?>
    <?php if ($children): ?><div class="card card-flush"><div class="card-header"><div class="card-title">Children registered here</div></div><div class="card-body"><table class="table table-compact"><tbody>
        <?php foreach ($children as $c): ?><tr><td><a href="<?= e(url('patients/view', ['id' => $c['id']])) ?>"><?= e($c['name']) ?></a> <span class="muted small"><?= e($c['mrn']) ?></span></td><td><?= e($c['gender']) ?></td><td><?= e(fmt_date($c['dob'])) ?></td></tr><?php endforeach; ?>
    </tbody></table></div></div><?php endif; ?>
</div>
</div>

<?php foreach ($pregnancies as $p): $ga = ObgynService::ga($p['lmp_date']); $label = 'Pregnancy #' . $p['pregnancy_no']; ?>
<div class="card card-flush">
    <div class="card-header"><div class="card-title"><?= e($label) ?> <?= status_chip($p['status']) ?><?= $p['risk_level'] === 'high' && $p['status'] === 'ongoing' ? ' <span class="badge badge-danger">High risk</span>' : '' ?><?= $p['recorded_historical'] ? ' <span class="badge badge-muted">from history</span>' : '' ?></div>
        <span class="muted small"><?= $p['lmp_date'] ? 'LMP ' . e(fmt_date($p['lmp_date'])) . ' · ' : '' ?><?= $p['edd_date'] ? 'EDD ' . e(fmt_date($p['edd_date'])) . ' (' . e($p['edd_method']) . ')' : '' ?><?= $p['status'] === 'ongoing' && $ga ? ' · <strong>GA ' . $ga[0] . 'w ' . $ga[1] . 'd</strong>' : '' ?><?= $p['outcome_date'] ? ' · ended ' . e(fmt_date($p['outcome_date'])) : '' ?></span></div>
    <div class="card-body">
        <?php if ($p['reasons'] && $p['status'] === 'ongoing'): ?><div class="alert alert-danger small"><?= icon('alert') ?> <?= e(implode(' · ', $p['reasons'])) ?><?= can('clinical.referral.create') ? ' — <a href="' . e(url('specialty', ['patient_id' => $patient['id']])) . '">refer / escalate</a>' : '' ?></div><?php endif; ?>
        <?php if ($p['status'] === 'ongoing'): ?><div class="flex flex-wrap mb-2">
            <?php if (can('obgyn.anc.record')): ?><button type="button" class="btn btn-sm btn-primary" onclick="obForm('m-anc', <?= (int) $p['id'] ?>, '<?= e($label) ?>')">ANC visit</button><?php endif; ?>
            <?php if (can('obgyn.us.biometry')): ?><button type="button" class="btn btn-sm btn-outline" onclick="obForm('m-us', <?= (int) $p['id'] ?>, '<?= e($label) ?>')">Scan findings</button><?php endif; ?>
            <?php if (can('obgyn.delivery.record')): ?><button type="button" class="btn btn-sm btn-success" onclick="obForm('m-delivery', <?= (int) $p['id'] ?>, '<?= e($label) ?>')">Record delivery</button><?php endif; ?>
            <?php if (can('obgyn.pregnancy.manage')): ?><form method="post" action="<?= e(url('obgyn/action')) ?>" class="flex"><?= csrf_field() ?><input type="hidden" name="do" value="close"><input type="hidden" name="pregnancy_id" value="<?= (int) $p['id'] ?>">
                <select class="input input-sm" name="status"><option value="">End as…</option><option value="miscarriage">Miscarriage</option><option value="termination">Termination</option><option value="ectopic">Ectopic</option><option value="molar">Molar</option></select>
                <input class="input input-sm" type="date" name="outcome_date"><button class="btn btn-sm btn-ghost" data-confirm="Close this pregnancy?">Close</button></form><?php endif; ?>
        </div><?php endif; ?>
        <?php if ($p['status'] === 'delivered' && can('obgyn.anc.record')): ?><button type="button" class="btn btn-sm btn-outline mb-2" onclick="obForm('m-pnc', <?= (int) $p['id'] ?>, '<?= e($label) ?>')">Postnatal visit</button><?php endif; ?>
        <?php if ($p['anc']): ?><div class="table-wrap"><table class="table table-compact"><thead><tr><th>Date</th><th>GA</th><th>BP</th><th>Wt</th><th>FH</th><th>FHR</th><th>Hb</th><th>Flags</th><th>By</th></tr></thead><tbody>
            <?php foreach ($p['anc'] as $a): $f = json_decode((string) $a['risk_flags_json'], true) ?: []; ?><tr><td class="small"><?= e(fmt_date($a['visit_date'])) ?></td><td><?= e(num($a['gestational_age_weeks'])) ?></td>
                <td class="<?= $a['bp_systolic'] >= (int) setting('obgyn_risk_bp_systolic', 140) || $a['bp_diastolic'] >= (int) setting('obgyn_risk_bp_diastolic', 90) ? 'text-danger' : '' ?>"><?= e($a['bp_systolic'] . '/' . $a['bp_diastolic']) ?></td>
                <td><?= e(num($a['weight_kg'])) ?></td><td><?= e(num($a['fundal_height_cm'])) ?></td><td><?= e((string) $a['fetal_heart_rate_bpm']) ?></td><td><?= e(num($a['hb_g_dl'])) ?></td>
                <td class="small"><?= $a['edema_flag'] ? 'oedema ' : '' ?><?= $a['urine_protein_flag'] ? 'protein ' : '' ?><?= $f ? '<span class="text-danger">' . e(implode('; ', $f)) . '</span>' : '' ?></td><td class="small"><?= e($a['by_name']) ?></td></tr><?php endforeach; ?>
        </tbody></table></div><?php endif; ?>
        <?php foreach ($p['us'] as $u): $b = json_decode((string) $u['biometry_json'], true) ?: []; ?><div class="small mt-1"><?= icon('image') ?> Scan <?= e(fmt_date($u['scan_date'])) ?>: <?= e(implode(' · ', array_map(static fn ($k, $v) => strtoupper($k) . ' ' . $v . ' mm', array_keys($b), $b))) ?>
            <?= $u['efw_g'] ? ' · EFW ' . (int) $u['efw_g'] . ' g' : '' ?><?= $u['afi_cm'] !== null ? ' · AFI ' . e(num($u['afi_cm'])) : '' ?><?= $u['placenta_location'] ? ' · placenta ' . e($u['placenta_location']) : '' ?><?= (int) $u['number_of_fetuses'] > 1 ? ' · ' . (int) $u['number_of_fetuses'] . ' fetuses' : '' ?></div><?php endforeach; ?>
        <?php foreach ($p['deliveries'] as $d): ?><div class="mt-1"><strong>Delivery</strong> <?= e(fmt_datetime($d['delivery_date'])) ?> · <?= e(ObgynService::MODES[$d['mode']] ?? $d['mode']) ?> · <?= e(ObgynService::BIRTH_OUTCOMES[$d['outcome']] ?? $d['outcome']) ?>
            <?= $d['baby_sex'] ? ' · ' . e($d['baby_sex']) : '' ?><?= $d['birth_weight_g'] ? ' · ' . (int) $d['birth_weight_g'] . ' g' : '' ?><?= $d['apgar_1min'] !== null ? ' · APGAR ' . (int) $d['apgar_1min'] . '/' . (int) $d['apgar_5min'] : '' ?>
            <?= $d['gestational_age_weeks'] ? ' · ' . (int) $d['gestational_age_weeks'] . ' wk' : '' ?><?= $d['complications'] ? ' · <span class="text-danger">' . e($d['complications']) . '</span>' : '' ?>
            <?= $d['baby_patient_id'] ? ' · baby <a href="' . e(url('patients/view', ['id' => $d['baby_patient_id']])) . '">' . e($d['baby_mrn']) . '</a>' : '' ?>
            <?php if ($d['is_draft']): ?><span class="badge badge-warning">Draft</span><?php if (can('obgyn.pregnancy.manage')): ?><form method="post" action="<?= e(url('obgyn/action')) ?>" style="display:inline"><?= csrf_field() ?><input type="hidden" name="do" value="confirm"><input type="hidden" name="pregnancy_id" value="<?= (int) $p['id'] ?>"><input type="hidden" name="delivery_id" value="<?= (int) $d['id'] ?>"><button class="btn btn-sm btn-outline">Confirm</button></form><?php endif; ?>
            <?php elseif ($d['cosigned_name']): ?><span class="muted small">confirmed by <?= e($d['cosigned_name']) ?></span><?php endif; ?></div><?php endforeach; ?>
        <?php foreach ($p['pnc'] as $n): ?><div class="small mt-1">PNC <?= e(fmt_date($n['visit_date'])) ?> (day <?= (int) $n['days_postpartum'] ?>): BP <?= e($n['bp_systolic'] . '/' . $n['bp_diastolic']) ?> · lochia <?= e($n['lochia']) ?> · <?= e($n['breastfeeding_status']) ?><?= $n['contraception_counselled'] ? ' · FP counselled' : '' ?></div><?php endforeach; ?>
        <?php if ($p['labs']): ?><div class="small mt-1">ANC labs: <?php foreach ($p['labs'] as $l): ?><a class="chip" href="<?= e(url('lab/order', ['id' => $l['id']])) ?>"><?= e($l['order_number']) ?> <?= status_chip($l['status']) ?></a> <?php endforeach; ?></div><?php endif; ?>
        <?php if ($p['outcome_notes']): ?><p class="small muted"><?= e($p['outcome_notes']) ?></p><?php endif; ?>
    </div>
</div>
<?php endforeach; ?>
<?= View::render('obgyn/_forms', ['back' => 'record', 'patientId' => (int) $patient['id']], null) ?>
