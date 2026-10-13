<?php
/** @var array $errors @var array $s */
use App\Core\View;

echo View::partial('page_header', [
    'title'       => 'Hospital & Billing Settings',
    'description' => 'Registration numbering, token rules, invoice numbering, receipts, security and department module options.',
    'breadcrumbs' => [['Settings'], ['Hospital & Billing']],
]);
$chk = static fn (string $k): string => checked(($s[$k] ?? '0') === '1');
$moduleKeys = array_filter(array_keys($s), static fn ($k) => preg_match('/^(lab|pharmacy|dental|obgyn|radiology|stock)_/', $k));
$labels = [
    'lab_report_footer' => 'Lab report footer', 'lab_tat_alert_hours' => 'Lab turnaround alert (hours)', 'lab_barcode_prefix' => 'Lab sample barcode prefix',
    'pharmacy_allow_partial' => 'Pharmacy: allow partial dispensing (1/0)', 'pharmacy_location_id' => 'Default pharmacy stock location id',
    'stock_negative_locations' => 'Locations allowed to go negative (ids, comma separated)', 'stock_adjust_limit' => 'Adjustment value needing HA approval (PKR)',
    'stock_expiry_alert_days' => 'Expiry alert days', 'dental_billing_mode' => 'Dental billing (per_procedure / plan)',
    'obgyn_risk_bp_systolic' => 'ANC risk: systolic BP ≥', 'obgyn_risk_bp_diastolic' => 'ANC risk: diastolic BP ≥', 'obgyn_risk_hb' => 'ANC risk: Hb below (g/dL)',
    'radiology_pregnancy_age_min' => 'Pregnancy screen from age', 'radiology_pregnancy_age_max' => 'Pregnancy screen to age',
];
?>
<?php if ($errors): ?><div class="alert alert-danger"><?= icon('alert') ?><div><?= implode('<br>', array_map('e', $errors)) ?></div></div><?php endif; ?>
<form method="post" action="<?= e(url('settings/hospital')) ?>">
    <?= csrf_field() ?>
    <div class="grid grid-2">
        <div class="card">
            <div class="card-header"><div class="card-title"><?= icon('id-card') ?> Registration &amp; MRN</div></div>
            <div class="card-body"><div class="form-grid">
                <div class="field col-12"><label>MRN mode</label>
                    <label class="check"><input type="radio" name="mrn_mode" value="doctor"<?= checked(($s['mrn_mode'] ?? 'doctor') === 'doctor') ?>> Per doctor (v1 — each doctor's own pattern)</label>
                    <label class="check"><input type="radio" name="mrn_mode" value="chain"<?= checked(($s['mrn_mode'] ?? '') === 'chain') ?>> One hospital-wide MRN for everyone (recommended for a hospital)</label></div>
                <div class="field col-6"><label>Hospital MRN pattern</label><input class="input" name="chain_mrn_pattern" value="<?= e($s['chain_mrn_pattern']) ?>"></div>
                <div class="field col-6"><label>Invoice number pattern</label><input class="input" name="invoice_no_pattern" value="<?= e($s['invoice_no_pattern']) ?>">
                    <div class="hint">Sequential and never reused (FBR-style).</div></div>
            </div></div>
        </div>
        <div class="card">
            <div class="card-header"><div class="card-title"><?= icon('list') ?> Tokens &amp; billing</div></div>
            <div class="card-body"><div class="form-grid">
                <div class="field col-12"><label class="check"><input type="checkbox" name="token_requires_payment" value="1"<?= $chk('token_requires_payment') ?>> No token without a receipt or an authorised waiver</label></div>
                <div class="field col-12"><label class="check"><input type="checkbox" name="registration_fee_new" value="1"<?= $chk('registration_fee_new') ?>> Charge the registration card fee to new patients</label></div>
                <div class="field col-12"><label>Hospital medicines are billed</label>
                    <select class="input" name="billing_medicines_at">
                        <option value="checkout"<?= selected('checkout', $s['billing_medicines_at']) ?>>At consultation checkout (clinic mode, v1)</option>
                        <option value="dispense"<?= selected('dispense', $s['billing_medicines_at']) ?>>When the pharmacy dispenses (hospital pharmacy)</option>
                    </select></div>
                <div class="field col-6"><label>Tax label</label><input class="input" name="tax_label" value="<?= e($s['tax_label']) ?>"></div>
                <div class="field col-6"><label class="check" style="margin-top:1.6rem"><input type="checkbox" name="board_chime" value="1"<?= $chk('board_chime') ?>> Chime on display boards</label></div>
                <div class="field col-12"><label>Receipt footer</label><input class="input" name="receipt_footer" value="<?= e($s['receipt_footer']) ?>"></div>
                <div class="field col-12"><label>Receipt footer (Urdu)</label><input class="input urdu" dir="rtl" lang="ur" name="receipt_footer_ur" value="<?= e($s['receipt_footer_ur']) ?>"></div>
            </div></div>
        </div>
        <div class="card">
            <div class="card-header"><div class="card-title"><?= icon('lock') ?> Security</div></div>
            <div class="card-body">
                <label class="check"><input type="checkbox" name="enforce_2fa" value="1"<?= $chk('enforce_2fa') ?>> Enforce two-factor login for roles flagged "2FA" (SA, ITA, HA, Billing, Chief Pharmacist …)</label>
                <p class="hint">Users without an authenticator are sent to set one up at their next sign in. Passwords are stored hashed (bcrypt).</p>
            </div>
        </div>
        <?php if ($moduleKeys): ?>
        <div class="card">
            <div class="card-header"><div class="card-title"><?= icon('layers') ?> Department modules</div></div>
            <div class="card-body"><div class="form-grid">
                <?php foreach ($moduleKeys as $k): ?>
                    <div class="field col-6"><label><?= e($labels[$k] ?? ucfirst(str_replace('_', ' ', $k))) ?></label><input class="input" name="<?= e($k) ?>" value="<?= e((string) $s[$k]) ?>"></div>
                <?php endforeach; ?>
            </div></div>
        </div>
        <?php endif; ?>
    </div>
    <div class="card mt-2"><div class="card-footer" style="border-top:0"><button class="btn btn-primary"><?= icon('save') ?> Save Settings</button></div></div>
</form>
