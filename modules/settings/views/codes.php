<?php
/** @var array $fieldErrors @var array $doctors @var array $previews */
use App\Core\View;

echo View::partial('page_header', [
    'title'       => 'Codes & Patterns',
    'description' => 'Custom patterns for employee codes, MRNs and visit numbers. Existing codes never change.',
    'breadcrumbs' => [['Settings'], ['Codes & Patterns']],
]);
$val = static fn (string $k) => old($k, setting($k));
$err = static fn (string $f): string => isset($fieldErrors[$f]) ? '<div class="error-text">' . e($fieldErrors[$f]) . '</div>' : '';
?>
<div class="alert alert-info"><?= icon('info') ?><div>
    Tokens: <code>{YYYY}</code> <code>{YY}</code> <code>{MM}</code> <code>{DD}</code> date parts · <code>{000001}</code> / <code>{0001}</code> running number (width = digits) ·
    employee codes also accept <code>{DEPT}</code> (first department code) and <code>{ROLE}</code> (SA / DA / DR / RP).
    Numbering restarts every year when the pattern contains a year (every month with <code>{MM}</code>).
</div></div>
<form method="post" action="<?= e(url('settings/codes')) ?>">
    <?= csrf_field() ?>
    <div class="grid grid-3">
        <div class="card"><div class="card-header"><div class="card-title"><?= icon('id-card') ?> Employee Code</div></div><div class="card-body">
            <div class="field"><label>Pattern</label><input class="input" name="employee_code_pattern" value="<?= e($val('employee_code_pattern')) ?>" maxlength="60"><?= $err('employee_code_pattern') ?>
                <div class="hint">Next: <code><?= e($previews['employee']) ?></code> · e.g. <code>{DEPT}-{ROLE}-{0001}</code></div></div>
        </div></div>
        <div class="card"><div class="card-header"><div class="card-title"><?= icon('users') ?> Default MRN</div></div><div class="card-body">
            <div class="field"><label>Pattern (doctors without their own)</label><input class="input" name="default_mrn_pattern" value="<?= e($val('default_mrn_pattern')) ?>" maxlength="60"><?= $err('default_mrn_pattern') ?>
                <div class="hint">e.g. <code>MR-{YYYY}-{000001}</code></div></div>
        </div></div>
        <div class="card"><div class="card-header"><div class="card-title"><?= icon('calendar') ?> Visit Number</div></div><div class="card-body">
            <div class="field"><label>Pattern</label><input class="input" name="visit_no_pattern" value="<?= e($val('visit_no_pattern')) ?>" maxlength="60"><?= $err('visit_no_pattern') ?>
                <div class="hint">Next: <code><?= e($previews['visit']) ?></code></div></div>
        </div></div>
    </div>

    <div class="card card-flush mt-2">
        <div class="card-header"><div class="card-title"><?= icon('stethoscope') ?> Doctor-specific MRN Patterns</div><span class="muted small">Leave blank to use the default MRN pattern</span></div>
        <div class="card-body table-wrap">
            <?php if (!$doctors): ?><div class="empty"><?= icon('user') ?><strong>No doctors yet</strong></div><?php else: ?>
            <table class="table">
                <thead><tr><th>Doctor</th><th>MRN Pattern</th><th>Next MRN</th><th>Status</th></tr></thead>
                <tbody>
                <?php foreach ($doctors as $d): ?>
                    <tr>
                        <td><strong><?= e($d['name']) ?></strong></td>
                        <td style="min-width:260px"><input class="input input-sm" name="mrn[<?= (int) $d['id'] ?>]" value="<?= e(old('mrn', [])[$d['id']] ?? $d['mrn_pattern']) ?>" placeholder="<?= e(setting('default_mrn_pattern')) ?>" maxlength="60"><?= $err('mrn_' . $d['id']) ?></td>
                        <td><code><?= e($d['next']) ?></code></td>
                        <td><?= status_badge($d['is_active']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
        <div class="card-footer"><button type="submit" class="btn btn-primary"><?= icon('save') ?> Save Patterns</button></div>
    </div>
</form>
