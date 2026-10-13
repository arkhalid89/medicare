<?php
/** @var array $config @var array $notices */
use App\Core\View;
use App\Modules\Patients\PatientService;

$actions = '';
if ($config['isDoctor']) {
    $actions .= '<div class="dropdown" data-dropdown><button type="button" class="btn btn-outline" data-dropdown-toggle>' . icon('star') . ' <span class="hide-sm">Load </span>Template ' . icon('chevron-down') . '</button>'
        . '<div class="dropdown-menu" id="tpl-menu" style="min-width:260px;max-height:360px;overflow:auto"></div></div>'
        . '<button type="button" class="btn btn-outline" id="btn-save-template">' . icon('save') . ' <span class="hide-sm">Save as </span>Template</button>';
}
$actions .= '<button type="button" class="btn btn-ghost" id="btn-clear">' . icon('refresh') . ' Clear</button>';

echo View::partial('page_header', [
    'title'       => 'New Visit',
    'description' => 'Patient → Vitals → Complaints → Symptoms → Diagnosis → Investigations → Medicines → Follow-up & Fee → Checkout',
    'breadcrumbs' => [['Visits', can('visits.view') ? url('visits') : null], ['New Visit']],
    'actions'     => $actions,
]);
?>
<?php foreach ($notices as $n): ?>
    <div class="alert alert-info"><?= icon('info') ?><div><?= e($n) ?></div></div>
<?php endforeach; ?>
<div class="alert alert-danger hidden" id="consult-errors"></div>
<?php
$pre = $config['prefill'];
$deptChoices = $config['myDepartments'] ? array_intersect_key($config['departments'], array_flip($config['myDepartments'])) : $config['departments'];
$defaultDept = $pre['token']['department_id'] ?? (int) (array_key_first($deptChoices) ?? 0);
?>
<div class="visit-strip card">
    <div class="card-body flex flex-wrap" style="gap:1rem;align-items:flex-end">
        <?php if (!empty($pre['token'])): ?>
            <div><span class="muted small">Token</span><div class="tok-label" style="font-size:1.4rem"><?= e($pre['token']['label']) ?></div></div>
        <?php endif; ?>
        <div class="field" style="min-width:220px"><label>Department</label>
            <select class="input" id="visit-dept"<?= !empty($pre['token']['department_id']) ? ' disabled' : '' ?>>
                <?php foreach ($deptChoices as $id => $name): ?><option value="<?= $id ?>"<?= selected($id, $defaultDept) ?>><?= e($name) ?></option><?php endforeach; ?>
            </select></div>
        <div class="field" style="min-width:180px"><label>Visit type</label>
            <select class="input" id="visit-type"><option value="">—</option>
                <?php foreach ($config['visitTypes'] as $id => $name): ?><option value="<?= $id ?>"><?= e($name) ?></option><?php endforeach; ?>
            </select></div>
        <?php if ($config['cosign']): ?><span class="badge badge-warning"><?= icon('check') ?> Your entries go to a consultant for co-sign</span><?php endif; ?>
    </div>
</div>

<div class="consult" id="consult">
    <!-- 1. Patient -->
    <div class="card">
        <div class="card-header">
            <div class="card-title"><span class="step-no">1</span> Patient Information</div>
            <div class="flex flex-wrap">
                <span class="patient-badge new" id="patient-status"><?= icon('user-plus') ?> New patient — MRN on checkout</span>
                <button type="button" class="btn btn-sm btn-gold hidden" id="btn-history"><?= icon('clock') ?> Previous Visits <span class="badge badge-primary" id="history-count">0</span></button>
                <button type="button" class="btn btn-sm btn-outline" id="btn-new-patient"><?= icon('user-plus') ?> New Patient</button>
            </div>
        </div>
        <div class="card-body">
            <?php if (!$config['isDoctor']): ?>
                <div class="form-grid mb-2">
                    <div class="field col-4"><label>Consulting Doctor <span class="req">*</span></label>
                        <select class="input" id="doctor-id">
                            <option value="">Select doctor…</option>
                            <?php foreach ($config['doctors'] as $d): ?><option value="<?= $d['id'] ?>"><?= e($d['name']) ?></option><?php endforeach; ?>
                        </select></div>
                </div>
            <?php endif; ?>
            <div class="patient-search mb-2">
                <div class="search-box">
                    <?= icon('search') ?>
                    <input class="input" id="patient-q" type="text" placeholder="Search existing patient by phone, CNIC, MRN or name…" autocomplete="off">
                    <div class="search-results"></div>
                </div>
            </div>
            <div class="form-grid" id="patient-fields">
                <div class="field col-3"><label>Phone</label><input class="input" data-p="phone" maxlength="20" inputmode="tel" placeholder="03001234567"></div>
                <div class="field col-3"><label>CNIC</label><input class="input" data-p="cnic" maxlength="15" inputmode="numeric" placeholder="35202-1234567-1"></div>
                <div class="field col-3"><label>Patient Name <span class="req">*</span></label><input class="input" data-p="name" maxlength="150"></div>
                <div class="field col-3"><label>Father / Husband Name</label><input class="input" data-p="guardian_name" maxlength="150"></div>
                <div class="field col-3"><label>Gender <span class="req">*</span></label>
                    <div class="seg" id="gender-seg">
                        <?php foreach (PatientService::GENDERS as $g): ?>
                            <input type="radio" name="p-gender" id="pg-<?= e($g) ?>" value="<?= e($g) ?>"><label for="pg-<?= e($g) ?>"><?= e($g) ?></label>
                        <?php endforeach; ?>
                    </div></div>
                <div class="field col-3"><label>Date of Birth</label><input class="input" data-p="dob" type="date" max="<?= today() ?>"></div>
                <div class="field col-6"><label>Age <span class="muted">— type any part, Date of Birth fills itself</span></label>
                    <div class="age-trio">
                        <label class="age-cell"><input class="input" data-p="age_years" type="number" min="0" max="130" inputmode="numeric" placeholder="0"><span>Years</span></label>
                        <label class="age-cell"><input class="input" data-p="age_months" type="number" min="0" max="11" inputmode="numeric" placeholder="0"><span>Months</span></label>
                        <label class="age-cell"><input class="input" data-p="age_days" type="number" min="0" max="31" inputmode="numeric" placeholder="0"><span>Days</span></label>
                    </div></div>
                <div class="field col-6"><label>Address</label><input class="input" data-p="address" maxlength="255"></div>
                <div class="field col-3"><label>City</label><input class="input" data-p="city" maxlength="80"></div>
                <div class="field col-3"><label>Blood Group</label>
                    <select class="input" data-p="blood_group"><option value="">—</option>
                        <?php foreach (PatientService::BLOOD_GROUPS as $b): ?><option><?= e($b) ?></option><?php endforeach; ?>
                    </select></div>
                <div class="field col-12"><label><?= icon('alert') ?> Allergies</label><input class="input allergy-input" id="allergies" maxlength="500" placeholder="e.g. Penicillin, Sulfa drugs — leave empty if none known"></div>
            </div>
        </div>
    </div>

    <?= View::render('visits/_clinical', ['config' => $config, 'showBilling' => true, 'showHistory' => true, 'stepStart' => 2], null) ?>

    <?php /* Department modules: tooth chart, ANC, specialty fields, lab / imaging orders (Doc 6 — they supplement the generic EHR) */ ?>
    <div id="module-panels">
        <?php foreach ($config['modules'] as $key => $m): if (!empty($m['view'])): ?>
            <?= View::render($m['view'], ['m' => $m, 'config' => $config], null) ?>
        <?php endif; endforeach; ?>
    </div>

    <!-- 8. Next visit + visit history -->
    <div class="card">
        <div class="card-header"><div class="card-title"><span class="step-no">8</span> Next Visit &amp; Visit History</div>
            <span class="muted small" id="refill-note">Refill: 0 (new prescription)</span></div>
        <div class="card-body">
            <div class="next-grid">
                <div>
                    <div class="field mb-2"><label>Next visit (follow-up) date</label>
                        <input class="input" type="date" id="follow-up-date" min="<?= today() ?>">
                        <div class="followup-quick">
                            <?php foreach ([3 => '3 days', 7 => '1 week', 14 => '2 weeks', 30 => '1 month'] as $d => $l): ?>
                                <button type="button" class="btn btn-sm btn-outline" data-follow="<?= $d ?>"><?= e($l) ?></button>
                            <?php endforeach; ?>
                            <button type="button" class="btn btn-sm btn-ghost" data-follow="0">None</button>
                        </div>
                        <div class="hint" id="follow-up-hint"></div></div>
                    <div class="field"><label>Instructions for the next visit</label>
                        <textarea class="input" id="follow-up-instructions" rows="3" maxlength="500" placeholder="e.g. Come fasting with CBC report"></textarea></div>
                </div>
                <div class="inline-history">
                    <div class="flex-between mb-1"><strong class="ih-title"><?= icon('clock') ?> Visit history</strong>
                        <button type="button" class="btn btn-sm btn-ghost hidden" id="btn-history-all">View all</button></div>
                    <div id="inline-history"><div class="muted small">Select an existing patient to see previous visits.</div></div>
                </div>
            </div>
        </div>
    </div>

    <!-- 9. Fee & bill -->
    <div class="card">
        <div class="card-header"><div class="card-title"><span class="step-no">9</span> Consultation Fee &amp; Hospital Bill</div></div>
        <div class="card-body">
            <div class="fee-grid">
                <div>
                    <div class="field mb-2"><label>Consultation fee</label>
                        <input class="input" type="number" min="0" step="1" id="consultation-fee" inputmode="numeric"></div>
                    <div class="field"><label>Payment</label>
                        <div class="seg seg-wide">
                            <?php foreach (['Paid', 'Pending', 'Free'] as $s): ?>
                                <input type="radio" name="payment" id="pay-<?= $s ?>" value="<?= $s ?>"<?= $s === 'Paid' ? ' checked' : '' ?>><label for="pay-<?= $s ?>"><?= $s ?></label>
                            <?php endforeach; ?>
                        </div></div>
                </div>
                <div class="summary bill-box">
                    <div class="row muted small" id="sum-main-note"></div>
                    <div class="row"><span>Hospital medicines</span><strong id="sum-medicines">0</strong></div>
                    <?php if ($config['settings']['discount']): ?>
                    <div class="row"><span class="flex">Discount
                            <select class="input input-sm" id="discount-type" style="width:auto"><option value="amount"><?= e(setting('currency_symbol')) ?></option><option value="percent">%</option></select></span>
                        <input class="input" type="number" min="0" step="1" id="discount-value" value="0"></div>
                    <div class="row muted"><span>Discount amount</span><span id="sum-discount">0</span></div>
                    <?php endif; ?>
                    <?php if ($config['settings']['tax']): ?>
                    <div class="row"><span>Tax (%)</span><input class="input" type="number" min="0" max="100" step="0.5" id="tax-percent" value="0"></div>
                    <div class="row muted"><span>Tax amount</span><span id="sum-tax">0</span></div>
                    <?php endif; ?>
                    <div class="row"><span>Medicine net</span><strong id="sum-net">0</strong></div>
                    <div class="row"><span>Consultation fee</span><strong id="sum-fee">0</strong></div>
                    <div class="row total"><span>Grand total</span><span id="sum-grand">0</span></div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="checkout-bar">
    <div style="min-width:0;flex:1">
        <div class="strong" id="bar-patient" style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis">No patient selected</div>
        <div class="muted small" id="bar-meds">0 medicines</div>
    </div>
    <div class="grand"><small>Grand Total</small><span id="bar-grand">0</span></div>
    <button type="button" class="btn btn-gold btn-lg" id="btn-checkout"><?= icon('check') ?> <span>Checkout &amp; Print</span></button>
</div>

<!-- Previous visits of the selected patient -->
<div class="modal-backdrop" id="history-modal">
    <div class="modal modal-xl">
        <div class="modal-head"><?= icon('clock') ?><h3>Previous Visits — <span id="history-name"></span></h3><button type="button" class="icon-btn close" data-history-close><?= icon('x') ?></button></div>
        <div class="modal-body" style="padding:0">
            <div class="table-wrap">
                <table class="table table-compact history-table">
                    <thead><tr><th>Date</th><th>Visit</th><th>Doctor</th><th>Complaints</th><th>Diagnosis</th><th>Medicines</th><th class="num">Total</th><th style="width:1%"></th></tr></thead>
                    <tbody id="history-body"></tbody>
                </table>
            </div>
        </div>
        <div class="modal-foot"><span class="muted small" style="margin-right:auto">“Repeat” copies complaints, symptoms, diagnosis, investigations, medicines, history, findings and advice — you can change anything before checkout.</span>
            <button type="button" class="btn btn-outline" data-history-close>Close</button></div>
    </div>
</div>

<!-- Duplicate (family) popup — BRD §31 -->
<div class="modal-backdrop" id="dup-modal">
    <div class="modal modal-lg">
        <div class="modal-head"><?= icon('users') ?><h3>Patients with the same CNIC / phone</h3><button type="button" class="icon-btn close" data-dup-cancel><?= icon('x') ?></button></div>
        <div class="modal-body">
            <p class="muted">Family members may share a CNIC or phone number. Select the correct existing patient, or register this person as a new patient.</p>
            <div class="table-wrap"><table class="table table-compact"><thead><tr><th>MRN</th><th>Name</th><th>Father / Husband</th><th>Gender / Age</th><th>CNIC</th><th>Phone</th><th></th></tr></thead><tbody id="dup-body"></tbody></table></div>
        </div>
        <div class="modal-foot">
            <button type="button" class="btn btn-outline" data-dup-cancel>Cancel</button>
            <button type="button" class="btn btn-primary" id="dup-new"><?= icon('user-plus') ?> Register as New Patient</button>
        </div>
    </div>
</div>

<!-- After checkout -->
<div class="modal-backdrop" id="done-modal">
    <div class="modal">
        <div class="modal-body text-center" style="padding-top:1.6rem">
            <div class="success-mark"><?= icon('check') ?></div>
            <h3 style="font-size:1.25rem;color:var(--primary)">Prescription saved successfully</h3>
            <p class="muted" id="done-text"></p>
            <div class="alert alert-warning hidden text-left" id="done-warnings"></div>
            <div class="print-options mt-2" id="done-prints"></div>
        </div>
        <div class="modal-foot">
            <a class="btn btn-outline" id="done-view" href="#"><?= icon('eye') ?> View Visit</a>
            <a class="btn btn-primary" href="<?= e(url('visits/new')) ?>"><?= icon('plus') ?> Next Patient</a>
        </div>
    </div>
</div>

<!-- Save as template -->
<div class="modal-backdrop" id="tpl-modal">
    <div class="modal">
        <div class="modal-head"><?= icon('star') ?><h3>Save as Favourite Template</h3><button type="button" class="icon-btn close" data-tpl-cancel><?= icon('x') ?></button></div>
        <div class="modal-body">
            <p class="muted">Saves complaints, symptoms, diagnosis, investigations, medicines and instructions (not the patient).</p>
            <div class="field mb-2"><label>Template Name <span class="req">*</span></label><input class="input" id="tpl-save-name" maxlength="150" placeholder="e.g. Acute URTI — Adult"></div>
            <label class="check"><input type="checkbox" id="tpl-save-fav" checked> Mark as favourite</label>
        </div>
        <div class="modal-foot"><button type="button" class="btn btn-outline" data-tpl-cancel>Cancel</button><button type="button" class="btn btn-primary" id="tpl-save-ok"><?= icon('save') ?> Save Template</button></div>
    </div>
</div>

<datalist id="instr-list"><?php foreach ($config['instructions'] as $ins): ?><option value="<?= e($ins) ?>"><?php endforeach; ?></datalist>
<script type="application/json" id="consult-data"><?= json_encode($config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?></script>
