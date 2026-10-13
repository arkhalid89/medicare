<?php
/** Shared modals: ANC, delivery, PNC, ultrasound. @var string $back 'record' | 'anc' @var int $patientId */
use App\Modules\Obgyn\ObgynService;

$hidden = static fn (string $do) => csrf_field() . '<input type="hidden" name="do" value="' . $do . '"><input type="hidden" name="back" value="' . e($back) . '"><input type="hidden" name="pregnancy_id" class="js-preg"><input type="hidden" name="patient_id" value="' . (int) $patientId . '">';
$head = static fn (string $id, string $title) => '<div class="modal-head">' . icon('heart') . '<h3>' . $title . ' <span class="muted js-preg-label"></span></h3><button type="button" class="icon-btn close" onclick="APP.closeModal(\'' . $id . '\')">' . icon('x') . '</button></div>';
$foot = static fn (string $id, string $label) => '<div class="modal-foot"><button type="button" class="btn btn-outline" onclick="APP.closeModal(\'' . $id . '\')">Cancel</button><button class="btn btn-primary">' . $label . '</button></div>';
?>
<?php if (can('obgyn.anc.record')): ?>
<div class="modal-backdrop" id="m-anc"><form class="modal modal-lg" method="post" action="<?= e(url('obgyn/action')) ?>"><?= $hidden('anc') ?><?= $head('m-anc', 'ANC visit') ?>
    <div class="modal-body"><div class="form-grid">
        <div class="field col-3"><label>GA (weeks, blank = from LMP)</label><input class="input" type="number" step="0.1" name="gestational_age_weeks"></div>
        <div class="field col-3"><label>Fundal height (cm)</label><input class="input" type="number" step="0.5" name="fundal_height_cm"></div>
        <div class="field col-3"><label>FHR (bpm)</label><input class="input" type="number" name="fetal_heart_rate_bpm"></div>
        <div class="field col-3"><label>Presentation</label><select class="input" name="presentation"><option value=""></option><option>cephalic</option><option>breech</option><option>transverse</option><option>oblique</option></select></div>
        <div class="field col-3"><label>BP systolic</label><input class="input" type="number" name="bp_systolic" required></div>
        <div class="field col-3"><label>BP diastolic</label><input class="input" type="number" name="bp_diastolic" required></div>
        <div class="field col-3"><label>Weight (kg)</label><input class="input" type="number" step="0.1" name="weight_kg"></div>
        <div class="field col-3"><label>Hb (g/dL)</label><input class="input" type="number" step="0.1" name="hb_g_dl"></div>
        <div class="field col-12 flex"><label class="check"><input type="checkbox" name="edema_flag" value="1"> Oedema</label><label class="check"><input type="checkbox" name="urine_protein_flag" value="1"> Urine protein</label><label class="check"><input type="checkbox" name="tt_dose_given" value="1"> TT given</label></div>
        <div class="field col-8"><label>Notes</label><input class="input" name="notes"></div><div class="field col-4"><label>Next visit</label><input class="input" type="date" name="next_visit_date"></div>
    </div></div><?= $foot('m-anc', 'Save ANC') ?></form></div>
<div class="modal-backdrop" id="m-pnc"><form class="modal" method="post" action="<?= e(url('obgyn/action')) ?>"><?= $hidden('pnc') ?><?= $head('m-pnc', 'Postnatal visit') ?>
    <div class="modal-body"><div class="form-grid">
        <div class="field col-6"><label>BP systolic</label><input class="input" type="number" name="bp_systolic"></div><div class="field col-6"><label>BP diastolic</label><input class="input" type="number" name="bp_diastolic"></div>
        <div class="field col-6"><label>Lochia</label><select class="input" name="lochia"><option value=""></option><option>normal</option><option>heavy</option><option>offensive</option></select></div>
        <div class="field col-6"><label>Uterine involution</label><select class="input" name="uterine_involution"><option value=""></option><option>normal</option><option>sub-involution</option></select></div>
        <div class="field col-6"><label>Perineum / wound</label><select class="input" name="perineal_wound_status"><option value=""></option><option>healing well</option><option>infected</option><option>dehiscence</option></select></div>
        <div class="field col-6"><label>Breastfeeding</label><select class="input" name="breastfeeding_status"><option value=""></option><option>exclusive</option><option>mixed</option><option>not breastfeeding</option></select></div>
        <div class="field col-12"><label class="check"><input type="checkbox" name="contraception_counselled" value="1"> Family-planning counselling given</label></div>
        <div class="field col-12"><label>Notes</label><input class="input" name="notes"></div>
    </div></div><?= $foot('m-pnc', 'Save PNC') ?></form></div>
<?php endif; ?>
<?php if (can('obgyn.delivery.record')): ?>
<div class="modal-backdrop" id="m-delivery"><form class="modal modal-lg" method="post" action="<?= e(url('obgyn/action')) ?>"><?= $hidden('delivery') ?><?= $head('m-delivery', 'Delivery record') ?>
    <div class="modal-body"><div class="form-grid">
        <div class="field col-4"><label>Date &amp; time</label><input class="input" type="datetime-local" name="delivery_date" required></div>
        <div class="field col-4"><label>Mode</label><select class="input" name="mode" required><?php foreach (ObgynService::MODES as $k => $l): ?><option value="<?= $k ?>"><?= e($l) ?></option><?php endforeach; ?></select></div>
        <div class="field col-4"><label>Place</label><select class="input" name="place"><option value="this_hospital">This hospital</option><option value="home">Home</option><option value="other">Other facility</option></select></div>
        <div class="field col-4"><label>Outcome</label><select class="input" name="outcome"><?php foreach (ObgynService::BIRTH_OUTCOMES as $k => $l): ?><option value="<?= $k ?>"><?= e($l) ?></option><?php endforeach; ?></select></div>
        <div class="field col-4"><label>Baby sex</label><select class="input" name="baby_sex"><option value=""></option><option>Male</option><option>Female</option><option>Other</option></select></div>
        <div class="field col-4"><label>Birth weight (g)</label><input class="input" type="number" name="birth_weight_g"></div>
        <div class="field col-3"><label>GA (weeks)</label><input class="input" type="number" name="gestational_age_weeks"></div>
        <div class="field col-3"><label>APGAR 1 min</label><input class="input" type="number" min="0" max="10" name="apgar_1min"></div>
        <div class="field col-3"><label>APGAR 5 min</label><input class="input" type="number" min="0" max="10" name="apgar_5min"></div>
        <div class="field col-12"><label>Complications</label><input class="input" name="complications" placeholder="PPH, tear, retained placenta …"></div>
        <?php if (can('obgyn.newborn.register')): ?><div class="field col-12"><label class="check"><input type="checkbox" name="register_newborn" value="1" checked> Register the newborn as a patient linked to the mother</label></div><?php endif; ?>
        <?php if (!can('obgyn.pregnancy.manage')): ?><p class="col-12 hint">Saved as a draft until the gynecologist confirms it.</p><?php endif; ?>
    </div></div><?= $foot('m-delivery', 'Save delivery') ?></form></div>
<?php endif; ?>
<?php if (can('obgyn.us.biometry')): ?>
<div class="modal-backdrop" id="m-us"><form class="modal modal-lg" method="post" action="<?= e(url('obgyn/action')) ?>"><?= $hidden('us') ?><?= $head('m-us', 'Obstetric ultrasound') ?>
    <div class="modal-body"><div class="form-grid">
        <div class="field col-2"><label>CRL mm</label><input class="input" type="number" step="0.1" name="crl"></div><div class="field col-2"><label>BPD mm</label><input class="input" type="number" step="0.1" name="bpd"></div>
        <div class="field col-2"><label>HC mm</label><input class="input" type="number" step="0.1" name="hc"></div><div class="field col-2"><label>AC mm</label><input class="input" type="number" step="0.1" name="ac"></div>
        <div class="field col-2"><label>FL mm</label><input class="input" type="number" step="0.1" name="fl"></div><div class="field col-2"><label>Fetuses</label><input class="input" type="number" min="1" max="5" name="number_of_fetuses" value="1"></div>
        <div class="field col-3"><label>GA by scan (wk)</label><input class="input" type="number" step="0.1" name="ga_by_scan_weeks"></div><div class="field col-3"><label>EFW g (blank = Hadlock)</label><input class="input" type="number" name="efw_g"></div>
        <div class="field col-3"><label>AFI cm</label><input class="input" type="number" step="0.1" name="afi_cm"></div><div class="field col-3"><label>FHR</label><input class="input" type="number" name="fhr_bpm"></div>
        <div class="field col-4"><label>Placenta</label><input class="input" name="placenta_location" placeholder="Fundal / anterior / previa"></div><div class="field col-4"><label>Presentation</label><input class="input" name="fetal_presentation"></div>
        <div class="field col-4"><label>Cervical length mm</label><input class="input" type="number" step="0.1" name="cervical_length_mm"></div><div class="field col-12"><label>Notes</label><input class="input" name="notes"></div>
    </div></div><?= $foot('m-us', 'Save scan') ?></form></div>
<?php endif; ?>
<script>
function obForm(id, preg, label) {
    var m = document.getElementById(id); if (!m) return;
    m.querySelectorAll('.js-preg').forEach(function (i) { i.value = preg; });
    m.querySelectorAll('.js-preg-label').forEach(function (s) { s.textContent = label || ''; });
    var dt = m.querySelector('input[type=datetime-local]'); if (dt && !dt.value) { var n = new Date(); n.setMinutes(n.getMinutes() - n.getTimezoneOffset()); dt.value = n.toISOString().slice(0, 16); }
    APP.openModal(id);
}
</script>
