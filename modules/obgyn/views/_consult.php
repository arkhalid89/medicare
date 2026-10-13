<?php
/** @var array $m module config */
?>
<div class="card module-card hidden" id="ob-card">
    <div class="card-header"><div class="card-title"><?= icon('heart') ?> Gynecology / Obstetrics</div><span class="muted small" id="ob-summary">Select the patient to load her history</span></div>
    <div class="card-body">
        <div class="alert alert-danger hidden" id="ob-risk"></div>
        <div class="tabs mb-2" id="ob-tabs"><a href="#" data-tab="gyn" class="active">Gynae history</a><a href="#" data-tab="preg">Pregnancy</a><a href="#" data-tab="anc">ANC visit</a></div>
        <section data-pane="gyn" class="form-grid">
            <div class="field col-3"><label>Menarche (age)</label><input class="input" type="number" min="5" max="25" data-g="menarche_age"></div>
            <div class="field col-3"><label>Cycle length (days)</label><input class="input" type="number" min="10" max="120" data-g="cycle_length_days"></div>
            <div class="field col-3"><label>Bleeding (days)</label><input class="input" type="number" min="1" max="30" data-g="cycle_duration_days"></div>
            <div class="field col-3"><label>Regularity</label><select class="input" data-g="cycle_regularity"><option value=""></option><option value="regular">Regular</option><option value="irregular">Irregular</option></select></div>
            <div class="field col-3"><label>LMP</label><input class="input" type="date" data-g="lmp_date" id="ob-lmp"></div>
            <div class="field col-3"><label>Contraception</label><select class="input" data-g="contraceptive_method"><option value=""></option><?php foreach ($m['contraception'] as $c): ?><option><?= e($c) ?></option><?php endforeach; ?></select></div>
            <div class="field col-3"><label>Menopause</label><select class="input" data-g="menopause_status"><option value=""></option><?php foreach ($m['menopause'] as $k => $l): ?><option value="<?= $k ?>"><?= e($l) ?></option><?php endforeach; ?></select></div>
            <div class="field col-3"><label>Last Pap smear</label><input class="input" type="date" data-g="pap_smear_last"></div>
            <div class="field col-12"><label>Gynae notes</label><input class="input" data-g="notes" maxlength="1000"></div>
            <?php if (!$m['canEdit']): ?><p class="col-12 muted small">View only.</p><?php endif; ?>
        </section>
        <section data-pane="preg" class="hidden">
            <div id="ob-preg-current"></div>
            <div id="ob-preg-new" class="form-grid hidden">
                <div class="field col-12"><label class="check"><input type="checkbox" id="ob-open"> Confirm pregnancy &amp; open a pregnancy record at this visit</label></div>
                <div class="field col-3"><label>LMP</label><input class="input" type="date" id="ob-p-lmp"></div>
                <div class="field col-3"><label>or EDD (ultrasound / IVF)</label><input class="input" type="date" id="ob-p-edd"></div>
                <div class="field col-3"><label>EDD method</label><select class="input" id="ob-p-method"><option value="lmp">LMP</option><option value="ultrasound">Ultrasound</option><option value="ivf">IVF</option></select></div>
                <div class="field col-3"><label>Blood group</label><select class="input" id="ob-p-bg"><option value=""></option><?php foreach (['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'] as $b): ?><option><?= $b ?></option><?php endforeach; ?></select></div>
                <div class="field col-12"><label class="check"><input type="checkbox" id="ob-p-multi"> Multiple pregnancy</label> <span class="hint" id="ob-p-calc"></span></div>
            </div>
        </section>
        <section data-pane="anc" class="form-grid hidden">
            <div class="field col-2"><label>GA (weeks)</label><input class="input" type="number" step="0.1" data-a="gestational_age_weeks" id="ob-ga"></div>
            <div class="field col-2"><label>Fundal ht (cm)</label><input class="input" type="number" step="0.5" data-a="fundal_height_cm"></div>
            <div class="field col-2"><label>FHR (bpm)</label><input class="input" type="number" data-a="fetal_heart_rate_bpm"></div>
            <div class="field col-3"><label>Presentation</label><select class="input" data-a="presentation"><option value=""></option><option>cephalic</option><option>breech</option><option>transverse</option><option>oblique</option></select></div>
            <div class="field col-3"><label>Fetal position / lie</label><input class="input" data-a="fetal_position"></div>
            <div class="field col-2"><label>BP systolic</label><input class="input" type="number" data-a="bp_systolic"></div>
            <div class="field col-2"><label>BP diastolic</label><input class="input" type="number" data-a="bp_diastolic"></div>
            <div class="field col-2"><label>Weight (kg)</label><input class="input" type="number" step="0.1" data-a="weight_kg"></div>
            <div class="field col-2"><label>Hb (g/dL)</label><input class="input" type="number" step="0.1" data-a="hb_g_dl"></div>
            <div class="field col-4 flex"><label class="check"><input type="checkbox" data-a="edema_flag"> Oedema</label><label class="check"><input type="checkbox" data-a="urine_protein_flag"> Urine protein</label><label class="check"><input type="checkbox" data-a="tt_dose_given"> TT given</label></div>
            <div class="field col-8"><label>ANC notes</label><input class="input" data-a="notes" maxlength="1000"></div>
            <div class="field col-4"><label>Next ANC visit</label><input class="input" type="date" data-a="next_visit_date"></div>
            <p class="col-12 hint">Risk banner rules: BP ≥ <?= (int) $m['risk']['sys'] ?>/<?= (int) $m['risk']['dia'] ?> (with proteinuria → pre-eclampsia), Hb &lt; <?= e((string) $m['risk']['hb']) ?>, FHR outside 110–160, ≥ 41 weeks. Use the referral box below to escalate.</p>
        </section>
    </div>
</div>
