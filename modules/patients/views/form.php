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
                <div class="field col-4"><label>Doctor <span class="req">*</span></label>
                    <select class="input<?= $cls('doctor_id') ?>" name="doctor_id" required>
                        <option value="">Select doctor…</option>
                        <?php foreach ($doctors as $id => $name): ?><option value="<?= $id ?>"<?= selected($id, old('doctor_id')) ?>><?= e($name) ?></option><?php endforeach; ?>
                    </select><?= $err('doctor_id') ?></div>
            <?php endif; ?>
            <div class="field col-4"><label>Patient Name <span class="req">*</span></label>
                <input class="input<?= $cls('name') ?>" name="name" value="<?= e($val('name')) ?>" required maxlength="150" autofocus><?= $err('name') ?></div>
            <div class="field col-4"><label>Father / Husband Name</label>
                <input class="input<?= $cls('guardian_name') ?>" name="guardian_name" value="<?= e($val('guardian_name')) ?>" maxlength="150"><?= $err('guardian_name') ?></div>
            <div class="field col-4"><label>Gender <span class="req">*</span></label>
                <div class="seg">
                    <?php foreach (PatientService::GENDERS as $g): ?>
                        <input type="radio" name="gender" id="g-<?= e($g) ?>" value="<?= e($g) ?>"<?= checked($gender === $g) ?> required><label for="g-<?= e($g) ?>"><?= e($g) ?></label>
                    <?php endforeach; ?>
                </div><?= $err('gender') ?></div>
            <div class="field col-3"><label>Date of Birth</label>
                <input class="input<?= $cls('dob') ?>" type="date" name="dob" id="dob" value="<?= e((int) ($patient['dob_estimated'] ?? 0) && !is_post() ? '' : $val('dob')) ?>" max="<?= today() ?>"><?= $err('dob') ?></div>
            <div class="field col-2"><label>or Age (years)</label>
                <input class="input<?= $cls('age') ?>" type="number" min="0" max="130" name="age" id="age" value="<?= e(old('age', (int) ($patient['dob_estimated'] ?? 0) ? age_years($patient['dob']) : '')) ?>"><?= $err('age') ?></div>
            <div class="field col-3"><label>CNIC</label>
                <input class="input<?= $cls('cnic') ?>" name="cnic" id="cnic" value="<?= e($val('cnic')) ?>" maxlength="15" placeholder="35202-1234567-1"><?= $err('cnic') ?></div>
            <div class="field col-4"><label>Phone</label>
                <input class="input<?= $cls('phone') ?>" name="phone" id="phone" value="<?= e($val('phone')) ?>" maxlength="20" placeholder="03001234567"><?= $err('phone') ?></div>
            <div class="field col-2"><label>Blood Group</label>
                <select class="input" name="blood_group"><option value="">—</option>
                    <?php foreach (PatientService::BLOOD_GROUPS as $b): ?><option<?= selected($b, $val('blood_group')) ?>><?= e($b) ?></option><?php endforeach; ?>
                </select></div>
            <div class="field col-3"><label>City</label>
                <input class="input" name="city" value="<?= e($val('city')) ?>" maxlength="80"></div>
            <div class="field col-7"><label>Address</label>
                <input class="input<?= $cls('address') ?>" name="address" value="<?= e($val('address')) ?>" maxlength="255"><?= $err('address') ?></div>
            <div class="field col-12"><label>Notes (allergies, chronic conditions)</label>
                <textarea class="input" name="notes" rows="2" maxlength="2000"><?= e($val('notes')) ?></textarea></div>
        </div>
        <div id="family-hint" class="alert alert-info mt-2 hidden"></div>
    </div>
    <div class="card-footer">
        <a class="btn btn-outline" href="<?= e($editing ? url('patients/view', ['id' => $patient['id']]) : url('patients')) ?>">Cancel</a>
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
    // DOB and age are alternatives.
    document.getElementById('dob').addEventListener('change', function () { if (this.value) document.getElementById('age').value = ''; });
    document.getElementById('age').addEventListener('input', function () { if (this.value) document.getElementById('dob').value = ''; });
})();
</script>
