<?php
/** @var array $config @var array $notices */
use App\Core\View;
use App\Modules\Patients\PatientService;

$actions = '';
if ($config['isDoctor']) {
    $actions .= '<div class="dropdown" data-dropdown><button type="button" class="btn btn-outline" data-dropdown-toggle>' . icon('star') . ' Load Template ' . icon('chevron-down') . '</button>'
        . '<div class="dropdown-menu" id="tpl-menu" style="min-width:260px;max-height:360px;overflow:auto"></div></div>'
        . '<button type="button" class="btn btn-outline" id="btn-save-template">' . icon('save') . ' Save as Template</button>';
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

<div class="consult" id="consult">
    <!-- 1. Patient -->
    <div class="card">
        <div class="card-header">
            <div class="card-title"><span class="step-no">1</span> Patient Information</div>
            <div class="flex">
                <span class="patient-badge new" id="patient-status"><?= icon('user-plus') ?> New patient — MRN assigned on checkout</span>
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
                    <input class="input" id="patient-q" type="text" placeholder="Search existing patient by MRN, CNIC, phone or name…" autocomplete="off">
                    <div class="search-results"></div>
                </div>
            </div>
            <div class="form-grid" id="patient-fields">
                <div class="field col-4"><label>Patient Name <span class="req">*</span></label><input class="input" data-p="name" maxlength="150"></div>
                <div class="field col-4"><label>Father / Husband Name</label><input class="input" data-p="guardian_name" maxlength="150"></div>
                <div class="field col-4"><label>Gender <span class="req">*</span></label>
                    <div class="seg" id="gender-seg">
                        <?php foreach (PatientService::GENDERS as $g): ?>
                            <input type="radio" name="p-gender" id="pg-<?= e($g) ?>" value="<?= e($g) ?>"><label for="pg-<?= e($g) ?>"><?= e($g) ?></label>
                        <?php endforeach; ?>
                    </div></div>
                <div class="field col-2"><label>Age (years)</label><input class="input" data-p="age" type="number" min="0" max="130"></div>
                <div class="field col-2"><label>or Date of Birth</label><input class="input" data-p="dob" type="date" max="<?= today() ?>"></div>
                <div class="field col-3"><label>CNIC</label><input class="input" data-p="cnic" maxlength="15" placeholder="35202-1234567-1"></div>
                <div class="field col-3"><label>Phone</label><input class="input" data-p="phone" maxlength="20" placeholder="03001234567"></div>
                <div class="field col-2"><label>Blood Group</label>
                    <select class="input" data-p="blood_group"><option value="">—</option>
                        <?php foreach (PatientService::BLOOD_GROUPS as $b): ?><option><?= e($b) ?></option><?php endforeach; ?>
                    </select></div>
                <div class="field col-3"><label>City</label><input class="input" data-p="city" maxlength="80"></div>
                <div class="field col-9"><label>Address</label><input class="input" data-p="address" maxlength="255"></div>
            </div>
        </div>
    </div>

    <?= View::render('visits/_clinical', ['config' => $config, 'showBilling' => true, 'showHistory' => true, 'stepStart' => 2], null) ?>

    <!-- Follow-up, fee & bill -->
    <div class="grid grid-main">
        <div class="card">
            <div class="card-header"><div class="card-title"><span class="step-no">8</span> Follow-up &amp; Consultation Fee</div></div>
            <div class="card-body">
                <div class="form-grid">
                    <div class="field col-4"><label>Follow-up Date</label>
                        <input class="input" type="date" id="follow-up-date" min="<?= today() ?>">
                        <div class="followup-quick">
                            <?php foreach ([3 => '3 days', 7 => '1 week', 14 => '2 weeks', 30 => '1 month'] as $d => $l): ?>
                                <button type="button" class="btn btn-sm btn-outline" data-follow="<?= $d ?>"><?= e($l) ?></button>
                            <?php endforeach; ?>
                            <button type="button" class="btn btn-sm btn-ghost" data-follow="0">None</button>
                        </div></div>
                    <div class="field col-8"><label>Follow-up Instructions</label>
                        <textarea class="input" id="follow-up-instructions" rows="2" maxlength="500" placeholder="e.g. Come fasting with CBC report"></textarea></div>
                    <div class="field col-4"><label>Consultation Fee (<?= e(setting('currency_code', 'PKR')) ?>)</label>
                        <input class="input" type="number" min="0" step="1" id="consultation-fee"></div>
                    <div class="field col-8"><label>Payment Status</label>
                        <div class="seg">
                            <?php foreach (['Paid', 'Pending', 'Free'] as $s): ?>
                                <input type="radio" name="payment" id="pay-<?= $s ?>" value="<?= $s ?>"<?= $s === 'Paid' ? ' checked' : '' ?>><label for="pay-<?= $s ?>"><?= $s ?></label>
                            <?php endforeach; ?>
                        </div></div>
                </div>
            </div>
        </div>
        <div class="card">
            <div class="card-header"><div class="card-title"><?= icon('money') ?> Bill Summary</div></div>
            <div class="card-body summary">
                <div class="row"><span>Medicines Total</span><strong id="sum-medicines">0</strong></div>
                <?php if ($config['settings']['discount']): ?>
                <div class="row"><span class="flex">Discount
                        <select class="input input-sm" id="discount-type" style="width:auto"><option value="amount"><?= e(setting('currency_symbol')) ?></option><option value="percent">%</option></select></span>
                    <input class="input" type="number" min="0" step="1" id="discount-value" value="0"></div>
                <div class="row muted"><span>Discount Amount</span><span id="sum-discount">0</span></div>
                <?php endif; ?>
                <?php if ($config['settings']['tax']): ?>
                <div class="row"><span>Tax (%)</span><input class="input" type="number" min="0" max="100" step="0.5" id="tax-percent" value="0"></div>
                <div class="row muted"><span>Tax Amount</span><span id="sum-tax">0</span></div>
                <?php endif; ?>
                <div class="row"><span>Medicine Net</span><strong id="sum-net">0</strong></div>
                <div class="row"><span>Consultation Fee</span><strong id="sum-fee">0</strong></div>
                <div class="row total"><span>Grand Total</span><span id="sum-grand">0</span></div>
            </div>
        </div>
    </div>
</div>

<div class="checkout-bar">
    <div style="min-width:0">
        <div class="strong" id="bar-patient" style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis">No patient selected</div>
        <div class="muted small" id="bar-meds">0 medicines</div>
    </div>
    <div class="spacer"></div>
    <div class="grand"><small>Grand Total</small><span id="bar-grand">0</span></div>
    <button type="button" class="btn btn-gold btn-lg" id="btn-checkout"><?= icon('check') ?> Checkout &amp; Print</button>
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
