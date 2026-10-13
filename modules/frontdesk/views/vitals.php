<?php
/** @var array $tokens @var array|null $selected @var array|null $last @var array $extra */
use App\Core\View;

echo View::partial('page_header', [
    'title'       => 'Vitals & Triage',
    'description' => 'Record vitals against the patient\'s token before the consultation; the doctor sees them pre-filled. A red triage level moves the patient to emergency priority.',
    'breadcrumbs' => [['Nursing'], ['Vitals & Triage']],
]);
$triage = ['red' => 'Red — immediate', 'orange' => 'Orange — very urgent', 'yellow' => 'Yellow — urgent', 'green' => 'Green — standard', 'blue' => 'Blue — non-urgent'];
?>
<div class="grid grid-main">
    <div class="card card-flush">
        <div class="card-header"><div class="card-title"><?= icon('list') ?> Waiting patients</div><a class="btn btn-sm btn-ghost" href="<?= e(url('frontdesk/vitals')) ?>"><?= icon('refresh') ?> Refresh</a></div>
        <div class="card-body table-wrap">
            <?php if (!$tokens): ?><div class="empty"><?= icon('activity') ?><strong>Nobody waiting</strong>Tokens issued at the registration desk appear here.</div><?php else: ?>
            <table class="table">
                <thead><tr><th>Token</th><th>Patient</th><th>Queue</th><th>Priority</th><th>Vitals</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($tokens as $t): ?>
                    <tr class="<?= $selected && (int) $selected['id'] === (int) $t['id'] ? 'row-active' : '' ?>">
                        <td><strong><?= e($t['token_label']) ?></strong><div class="muted small"><?= e(date('h:i A', strtotime($t['issued_at']))) ?></div></td>
                        <td><strong><?= e($t['name']) ?></strong><div class="muted small"><?= e($t['mrn']) ?> · <?= e($t['gender']) ?> <?= e(age_text($t['dob'])) ?></div></td>
                        <td class="small"><?= e($t['queue_name']) ?></td>
                        <td><?= priority_badge($t['priority']) ?></td>
                        <td><?= $t['vitals_id'] ? '<span class="badge badge-success">Recorded</span>' : '<span class="badge badge-warning">Pending</span>' ?></td>
                        <td><a class="btn btn-sm btn-outline" href="<?= e(url('frontdesk/vitals', ['token_id' => $t['id']])) ?>"><?= icon('activity') ?> <?= $t['vitals_id'] ? 'Update' : 'Record' ?></a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
    </div>
    <div>
        <?php if ($selected): ?>
        <form class="card" method="post" action="<?= e(url('frontdesk/vitals-save')) ?>">
            <?= csrf_field() ?><input type="hidden" name="token_id" value="<?= (int) $selected['id'] ?>">
            <div class="card-header"><div class="card-title"><?= icon('activity') ?> <?= e($selected['token_label']) ?> · <?= e($selected['name']) ?></div></div>
            <div class="card-body">
                <?php if ($selected['allergies']): ?><div class="alert alert-danger"><?= icon('alert') ?><div>Allergy: <?= e($selected['allergies']) ?></div></div><?php endif; ?>
                <div class="form-grid">
                    <div class="field col-6"><label>BP (systolic / diastolic)</label><div class="bp-pair">
                        <input class="input" type="number" name="bp_systolic" value="<?= e($last['bp_systolic'] ?? '') ?>" placeholder="120"> /
                        <input class="input" type="number" name="bp_diastolic" value="<?= e($last['bp_diastolic'] ?? '') ?>" placeholder="80"></div></div>
                    <div class="field col-6"><label>Pulse (/min)</label><input class="input" type="number" name="pulse" value="<?= e($last['pulse'] ?? '') ?>"></div>
                    <div class="field col-6"><label>Temperature (°F)</label><input class="input" type="number" step="0.1" name="temperature" value="<?= e($last['temperature'] ?? '') ?>"></div>
                    <div class="field col-6"><label>SpO₂ (%)</label><input class="input" type="number" name="spo2" value="<?= e($last['spo2'] ?? '') ?>"></div>
                    <div class="field col-6"><label>Weight (kg)</label><input class="input" type="number" step="0.1" name="weight" value="<?= e($last['weight'] ?? '') ?>"></div>
                    <div class="field col-6"><label>Height (cm)</label><input class="input" type="number" step="0.1" name="height" value="<?= e($last['height'] ?? '') ?>"></div>
                    <div class="field col-6"><label>Respiratory rate</label><input class="input" type="number" name="resp_rate" value="<?= e($last['resp_rate'] ?? '') ?>"></div>
                    <?php foreach ($extra as $label): ?>
                        <div class="field col-6"><label><?= e($label) ?></label><input class="input" name="other[<?= e($label) ?>]" maxlength="50"></div>
                    <?php endforeach; ?>
                    <div class="field col-12"><label>Triage level (ER)</label>
                        <select class="input" name="triage_level"><option value="">— not triaged —</option>
                            <?php foreach ($triage as $k => $l): ?><option value="<?= $k ?>"<?= selected($k, $last['triage_level'] ?? '') ?>><?= e($l) ?></option><?php endforeach; ?>
                        </select></div>
                    <div class="field col-12"><label>Nurse notes</label><input class="input" name="notes" maxlength="255" value="<?= e($last['notes'] ?? '') ?>"></div>
                </div>
            </div>
            <div class="card-footer"><button class="btn btn-primary"><?= icon('save') ?> Save Vitals</button></div>
        </form>
        <?php else: ?>
            <div class="card"><div class="card-body"><div class="empty"><?= icon('activity') ?><strong>Select a patient</strong>Choose a token on the left to record vitals.</div></div></div>
        <?php endif; ?>
    </div>
</div>
