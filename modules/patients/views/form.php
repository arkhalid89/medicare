<?php
/**
 * @var array|null $patient @var array $fieldErrors @var array $duplicates @var array $doctors
 */
use App\Core\View;
use App\Modules\Patients\PatientService;

$editing = $patient !== null;
echo View::partial('page_header', [
    'title'       => $editing ? 'Edit Patient — ' . $patient['mrn'] : 'Register Patient',
    'description' => $editing ? 'Update demographic details. The MRN never changes.' : 'A unique MRN is generated automatically from the doctor\'s pattern.',
    'breadcrumbs' => [['Patients', url('patients')], [$editing ? 'Edit' : 'Register']],
]);
$val = static fn (string $f, $d = '') => old($f, $patient[$f] ?? $d);
$err = static fn (string $f): string => isset($fieldErrors[$f]) ? '<div class="error-text">' . e($fieldErrors[$f]) . '</div>' : '';
$cls = static fn (string $f): string => isset($fieldErrors[$f]) ? ' is-invalid' : '';
$gender = (string) $val('gender');
?>
<?php if ($duplicates): ?>
    <div class="alert alert-warning">
        <?= icon('users') ?>
        <div style="flex:1">
            <strong>Existing patients share this CNIC or phone number.</strong> Family members may share them — choose an existing record or confirm this is a new patient.
            <div class="table-wrap mt-1"><table class="table table-compact" style="background:#fff;border-radius:8px">
                <thead><tr><th>MRN</th><th>Name</th><th>Father / Husband</th><th>Gender / Age</th><th>CNIC</th><th>Phone</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($duplicates as $d): ?>
                    <tr><td><?= e($d['mrn']) ?></td><td><strong><?= e($d['name']) ?></strong></td><td><?= e($d['guardian_name']) ?></td><td><?= e($d['gender']) ?> <?= e($d['age']) ?></td><td><?= e($d['cnic']) ?></td><td><?= e($d['phone']) ?></td>
                        <td class="nowrap"><a class="btn btn-sm btn-outline" href="<?= e(url('patients/view', ['id' => $d['id']])) ?>">Open</a>
                        <?php if (can('visits.create')): ?><a class="btn btn-sm btn-primary" href="<?= e(url('visits/new', ['patient_id' => $d['id']])) ?>">Start visit</a><?php endif; ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        </div>
    </div>
<?php endif; ?>

<form method="post" class="card" style="max-width:1080px" id="patient-form"
      action="<?= e($editing ? url('patients/edit', ['id' => $patient['id']]) : url('patients/create')) ?>" data-patient-id="<?= (int) ($patient['id'] ?? 0) ?>">
    <?= csrf_field() ?>
    <?php if ($duplicates): ?><input type="hidden" name="confirm_duplicate" value="1"><?php endif; ?>
    <div class="card-header"><div class="card-title"><?= icon('user') ?> Patient Information</div><?php if ($editing): ?><span class="badge badge-primary">MRN <?= e($patient['mrn']) ?></span><?php endif; ?></div>
    <div class="card-body">
        <div class="form-grid">
            <?php if (!$editing && $doctors): ?>
                <div class="field col-4"><label>Registered for doctor <span class="muted">(optional — blank uses the hospital MRN)</span></label>
                    <select class="input<?= $cls('doctor_id') ?>" name="doctor_id">
                        <option value="">Hospital patient (no doctor)</option>
                        <?php foreach ($doctors as $id => $name): ?><option value="<?= $id ?>"<?= selected($id, old('doctor_id')) ?>><?= e($name) ?></option><?php endforeach; ?>
                    </select><?= $err('doctor_id') ?></div>
            <?php endif; ?>
            <?php [$ay, $am, $ad] = PatientService::ageParts($patient['dob'] ?? null); ?>
            <div class="field col-3"><label>Phone</label>
                <input class="input<?= $cls('phone') ?>" name="phone" id="phone" value="<?= e($val('phone')) ?>" maxlength="20" inputmode="tel" placeholder="03001234567" autofocus><?= $err('phone') ?></div>
            <div class="field col-3"><label>CNIC</label>
                <input class="input<?= $cls('cnic') ?>" name="cnic" id="cnic" value="<?= e($val('cnic')) ?>" maxlength="15" inputmode="numeric" placeholder="35202-1234567-1"><?= $err('cnic') ?></div>
            <div class="field col-3"><label>Patient Name <span class="req">*</span></label>
                <input class="input<?= $cls('name') ?>" name="name" value="<?= e($val('name')) ?>" required maxlength="150"><?= $err('name') ?></div>
            <div class="field col-3"><label>Father / Husband Name</label>
                <input class="input<?= $cls('guardian_name') ?>" name="guardian_name" value="<?= e($val('guardian_name')) ?>" maxlength="150"><?= $err('guardian_name') ?></div>
            <div class="field col-4"><label>Gender <span class="req">*</span></label>
                <div class="seg">
                    <?php foreach (PatientService::GENDERS as $g): ?>
                        <input type="radio" name="gender" id="g-<?= e($g) ?>" value="<?= e($g) ?>"<?= checked($gender === $g) ?> required><label for="g-<?= e($g) ?>"><?= e($g) ?></label>
                    <?php endforeach; ?>
                </div><?= $err('gender') ?></div>
            <div class="field col-3"><label>Date of Birth</label>
                <input class="input<?= $cls('dob') ?>" type="date" name="dob" id="dob" value="<?= e($val('dob')) ?>" max="<?= today() ?>"><?= $err('dob') ?>
                <input type="hidden" name="dob_estimated" id="dob_estimated" value="<?= e(old('dob_estimated', (int) ($patient['dob_estimated'] ?? 0))) ?>"></div>
            <div class="field col-5"><label>Age <span class="muted">(years · months · days — calculated both ways)</span></label>
                <div class="age-trio">
                    <div class="input-group"><input class="input<?= $cls('age_years') ?>" type="number" min="0" max="130" name="age_years" id="age_years" inputmode="numeric" value="<?= e(old('age_years', $ay ?? '')) ?>"><span class="addon">Y</span></div>
                    <div class="input-group"><input class="input<?= $cls('age_months') ?>" type="number" min="0" max="11" name="age_months" id="age_months" inputmode="numeric" value="<?= e(old('age_months', $am ?? '')) ?>"><span class="addon">M</span></div>
                    <div class="input-group"><input class="input<?= $cls('age_days') ?>" type="number" min="0" max="31" name="age_days" id="age_days" inputmode="numeric" value="<?= e(old('age_days', $ad ?? '')) ?>"><span class="addon">D</span></div>
                </div><?= $err('age_years') . $err('age_months') . $err('age_days') ?></div>
            <div class="field col-6"><label>Address</label>
                <input class="input<?= $cls('address') ?>" name="address" value="<?= e($val('address')) ?>" maxlength="255"><?= $err('address') ?></div>
            <div class="field col-3"><label>City</label>
                <input class="input" name="city" value="<?= e($val('city')) ?>" maxlength="80"></div>
            <div class="field col-3"><label>Blood Group</label>
                <select class="input" name="blood_group"><option value="">—</option>
                    <?php foreach (PatientService::BLOOD_GROUPS as $b): ?><option<?= selected($b, $val('blood_group')) ?>><?= e($b) ?></option><?php endforeach; ?>
                </select></div>
            <div class="field col-4"><label>Occupation</label>
                <input class="input" name="occupation" value="<?= e($val('occupation')) ?>" maxlength="100"></div>
            <div class="field col-4"><label>Marital Status</label>
                <select class="input" name="marital_status"><option value="">—</option>
                    <?php foreach (hospital('marital_statuses') as $k => $l): ?><option value="<?= e($k) ?>"<?= selected($k, $val('marital_status')) ?>><?= e($l) ?></option><?php endforeach; ?>
                </select></div>
            <div class="field col-4"><label>Preferred Language</label>
                <select class="input" name="preferred_language"><option value="">—</option>
                    <?php foreach (hospital('languages') as $k => $l): ?><option value="<?= e($k) ?>"<?= selected($k, $val('preferred_language')) ?>><?= e($l) ?></option><?php endforeach; ?>
                </select></div>
            <div class="field col-12"><label><?= icon('alert') ?> Allergies</label>
                <input class="input allergy-input" name="allergies" value="<?= e($val('allergies')) ?>" maxlength="500" placeholder="e.g. Penicillin — leave empty if none known"></div>
            <div class="field col-12"><label>Notes (chronic conditions, other remarks)</label>
                <textarea class="input" name="notes" rows="2" maxlength="2000"><?= e($val('notes')) ?></textarea></div>
        </div>
        <div id="family-hint" class="alert alert-info mt-2 hidden"></div>
    </div>
    <div class="card-footer">
        <a class="btn btn-outline" href="<?= e($editing ? url('patients/view', ['id' => $patient['id']]) : url('patients')) ?>">Cancel</a>
        <?php if (!$editing && can('frontdesk.token.issue')): ?>
            <button type="submit" name="then" value="token" class="btn btn-outline"><?= icon('list') ?> Save &amp; Issue Token</button>
        <?php endif; ?>
        <?php if (!$editing && can('visits.create')): ?>
            <button type="submit" name="then" value="visit" class="btn btn-outline"><?= icon('stethoscope') ?> <?= $duplicates ? 'Confirm New &amp; Start Visit' : 'Save &amp; Start Visit' ?></button>
        <?php endif; ?>
        <button type="submit" class="btn btn-primary"><?= icon('save') ?> <?= $duplicates ? 'Confirm — Register as New Patient' : 'Save Patient' ?></button>
    </div>
</form>
<script>
(function () {
    // Live family/duplicate hint while typing CNIC or phone (BRD §31).
    var form = document.getElementById('patient-form');
    var hint = document.getElementById('family-hint');
    var check = APP.debounce(function () {
        var cnic = document.getElementById('cnic').value, phone = document.getElementById('phone').value;
        if (cnic.replace(/\D/g, '').length !== 13 && phone.replace(/\D/g, '').length < 10) { hint.classList.add('hidden'); return; }
        APP.ajax(APP.url('patients/duplicates', { cnic: cnic, phone: phone, exclude: form.dataset.patientId })).then(function (d) {
            if (!d.items || !d.items.length) { hint.classList.add('hidden'); return; }
            hint.innerHTML = '<div><strong>' + d.items.length + ' existing patient(s) share this CNIC/phone:</strong> ' +
                d.items.map(function (p) { return '<a href="' + APP.url('patients/view', { id: p.id }) + '">' + APP.esc(p.name) + ' (' + APP.esc(p.mrn) + ')</a>'; }).join(', ') +
                '. Family members may share these — you can still register a new patient.</div>';
            hint.classList.remove('hidden');
        });
    }, 400);
    ['cnic', 'phone'].forEach(function (id) { document.getElementById(id).addEventListener('input', check); });
    // Date of birth <-> age in years / months / days (infants included).
    var dob = document.getElementById('dob'), est = document.getElementById('dob_estimated');
    var y = document.getElementById('age_years'), m = document.getElementById('age_months'), d = document.getElementById('age_days');
    function pad(n) { return String(n).padStart(2, '0'); }
    dob.addEventListener('change', function () {
        est.value = 0;
        if (!dob.value) { y.value = m.value = d.value = ''; return; }
        var b = new Date(dob.value + 'T00:00:00'), t = new Date(new Date().toDateString());
        var yy = t.getFullYear() - b.getFullYear(), mm = t.getMonth() - b.getMonth(), dd = t.getDate() - b.getDate();
        if (dd < 0) { mm--; dd += new Date(t.getFullYear(), t.getMonth(), 0).getDate(); }
        if (mm < 0) { yy--; mm += 12; }
        if (yy < 0) { y.value = m.value = d.value = ''; return; }
        y.value = yy; m.value = mm; d.value = dd;
    });
    [y, m, d].forEach(function (el) {
        el.addEventListener('input', function () {
            if (!y.value && !m.value && !d.value) { dob.value = ''; return; }
            var t = new Date(new Date().toDateString());
            t.setFullYear(t.getFullYear() - (parseInt(y.value, 10) || 0));
            t.setMonth(t.getMonth() - (parseInt(m.value, 10) || 0));
            t.setDate(t.getDate() - (parseInt(d.value, 10) || 0));
            dob.value = t.getFullYear() + '-' + pad(t.getMonth() + 1) + '-' + pad(t.getDate());
            est.value = 1;
        });
    });
})();
</script>
