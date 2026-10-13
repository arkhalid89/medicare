<?php
/** @var string $tab @var array $samples @var array $results @var array $counts */
use App\Core\View;
use App\Modules\Lab\LabService;

echo View::partial('page_header', [
    'title' => 'Pathologist Desk', 'description' => 'Gate 1: accept each sample, or reject it with exactly one resolution — resample or refund. Gate 2: verify results (blocked while QC of a parameter has failed).',
    'breadcrumbs' => [['Laboratory'], ['Pathologist']],
]);
?>
<div class="tabs mb-2">
    <?php if (can('lab.sample.review')): ?><a class="<?= $tab === 'gate' ? 'active' : '' ?>" href="<?= e(url('lab/pathologist')) ?>">Samples to accept<?= $counts['gate'] ? ' <span class="badge badge-warning">' . $counts['gate'] . '</span>' : '' ?></a><?php endif; ?>
    <?php if (can('lab.result.verify')): ?><a class="<?= $tab === 'verify' ? 'active' : '' ?>" href="<?= e(url('lab/pathologist', ['tab' => 'verify'])) ?>">Results to verify<?= $counts['verify'] ? ' <span class="badge badge-warning">' . $counts['verify'] . '</span>' : '' ?></a><?php endif; ?>
</div>

<?php if ($tab === 'gate'): ?>
<div class="card card-flush"><div class="card-body table-wrap"><table class="table">
    <thead><tr><th>Barcode</th><th>Patient</th><th>Test / specimen</th><th>Collected</th><th>Decision</th></tr></thead><tbody>
    <?php foreach ($samples as $s):
        $age = (time() - strtotime($s['collected_at'])) / 3600; $stale = $s['max_hold_hours'] && $age > (float) $s['max_hold_hours']; ?><tr>
        <td><strong class="tok-label"><?= e($s['barcode']) ?></strong><?= $s['resample_of_sample_id'] ? '<div><span class="badge badge-warning">Resample</span></div>' : '' ?></td>
        <td><?= e($s['patient_name']) ?><div class="muted small"><?= e($s['mrn']) ?></div></td>
        <td><strong><?= e($s['test_name']) ?></strong><?= $s['priority'] !== 'routine' ? ' <span class="badge badge-danger">' . e(strtoupper($s['priority'])) . '</span>' : '' ?>
            <div class="muted small"><?= e($s['specimen_name']) ?> · <?= e($s['container_name']) ?><?= $s['fasting'] !== null ? ' · ' . ($s['fasting'] ? 'fasting' : 'non-fasting') : '' ?></div></td>
        <td class="small"><?= e(fmt_datetime($s['collected_at'])) ?><div class="muted"><?= e($s['collected_by_name']) ?></div><?= $stale ? '<span class="badge badge-danger">Over hold time</span>' : '' ?></td>
        <td><form method="post" action="<?= e(url('lab/pathologist')) ?>" class="review-form"><?= csrf_field() ?><input type="hidden" name="do" value="review"><input type="hidden" name="sample_id" value="<?= (int) $s['id'] ?>">
            <div class="flex flex-wrap">
                <button class="btn btn-sm btn-success" name="decision" value="accept"><?= icon('check') ?> Accept</button>
                <select class="input input-sm" name="reason"><option value="">Reject reason…</option><?php foreach (LabService::REJECT_REASONS as $r): ?><option><?= e($r) ?></option><?php endforeach; ?></select>
                <select class="input input-sm" name="resolution"><option value="">Resolution…</option><option value="resample">Resample (no charge)</option><option value="refund">Refund the test</option></select>
                <button class="btn btn-sm btn-danger" name="decision" value="reject" data-confirm="Reject this sample?"><?= icon('x') ?> Reject</button>
            </div></form></td></tr><?php endforeach; ?>
    <?php if (!$samples): ?><tr><td colspan="5" class="muted">No samples waiting.</td></tr><?php endif; ?>
</tbody></table></div></div>
<?php else: ?>
<form method="post" action="<?= e(url('lab/pathologist', ['tab' => 'verify'])) ?>" id="verify-form"><?= csrf_field() ?><input type="hidden" name="do" value="verify">
<?php foreach ($results as $r): ?>
<div class="card">
    <div class="card-header"><div class="card-title">
        <?php if (!$r['qc_blocked']): ?><input type="checkbox" name="items[]" value="<?= (int) $r['id'] ?>"><?php endif; ?>
        <?= e($r['test_name']) ?> — <?= e($r['patient_name']) ?> <span class="muted small"><?= e($r['mrn']) ?> · <?= e(substr($r['gender'], 0, 1)) ?>/<?= e(age_text($r['dob'])) ?></span>
        <?= $r['priority'] !== 'routine' ? '<span class="badge badge-danger">' . e(strtoupper($r['priority'])) . '</span>' : '' ?>
        <?= $r['result']['is_critical'] ?? 0 ? '<span class="badge badge-danger">CRITICAL</span>' : '' ?>
        <?= ($r['result']['version'] ?? 1) > 1 ? '<span class="badge badge-warning">Amendment v' . (int) $r['result']['version'] . '</span>' : '' ?></div>
        <span class="muted small"><?= e($r['barcode']) ?> · entered by <?= e($r['result']['entered_by_name'] ?? '') ?></span></div>
    <div class="card-body"><table class="table table-compact"><tbody>
        <?php foreach ($r['values'] as $v): ?><tr><td><?= e($v['name']) ?></td>
            <td><strong><?= e($v['value_numeric'] !== null ? rtrim(rtrim((string) $v['value_numeric'], '0'), '.') : $v['value_text']) ?></strong> <?= e($v['unit']) ?></td>
            <td class="small muted"><?= $v['ref_low'] !== null || $v['ref_high'] !== null ? e(rtrim(rtrim((string) $v['ref_low'], '0'), '.') . ' – ' . rtrim(rtrim((string) $v['ref_high'], '0'), '.')) : e($v['ref_text']) ?></td>
            <td><span class="flag flag-<?= e($v['flag']) ?>"><?= e(str_replace('_', ' ', $v['flag'])) ?></span></td></tr><?php endforeach; ?>
    </tbody></table>
    <?php if (!empty($r['result']['comment'])): ?><p class="small"><?= e($r['result']['comment']) ?></p><?php endif; ?>
    <?php if (!empty($r['result']['amend_reason'])): ?><p class="small text-danger">Amendment: <?= e($r['result']['amend_reason']) ?></p><?php endif; ?>
    <?php if ($r['qc_blocked']): ?><div class="alert alert-danger small"><?= icon('alert') ?> Latest QC failed for a parameter of this test — verification is blocked. <a href="<?= e(url('lab/qc')) ?>">Open QC</a></div><?php endif; ?></div>
    <div class="card-footer flex">
        <?php if (!$r['qc_blocked']): ?><button class="btn btn-sm btn-success" name="item_id" value="<?= (int) $r['id'] ?>" formaction="<?= e(url('lab/pathologist', ['tab' => 'verify'])) ?>" onclick="this.form.querySelectorAll('input[name=&quot;items[]&quot;]').forEach(function(c){c.checked=false;}); this.closest('.card').querySelector('input[name=&quot;items[]&quot;]').checked=true;"><?= icon('check') ?> Verify &amp; release</button><?php endif; ?>
        <a class="btn btn-sm btn-outline" href="<?= e(url('lab/result', ['id' => $r['id']])) ?>">Open</a>
        <button type="button" class="btn btn-sm btn-ghost send-back" data-id="<?= (int) $r['id'] ?>">Send back to bench</button>
    </div>
</div>
<?php endforeach; ?>
<?php if ($results): ?><div class="checkout-bar"><button class="btn btn-primary"><?= icon('check') ?> Verify selected</button></div><?php endif; ?>
</form>
<?php if (!$results): ?><div class="card"><div class="card-body empty">No results waiting for verification.</div></div><?php endif; ?>
<form method="post" action="<?= e(url('lab/pathologist', ['tab' => 'verify'])) ?>" id="sendback-form" class="hidden"><?= csrf_field() ?><input type="hidden" name="do" value="sendback"><input type="hidden" name="item_id"><input type="hidden" name="note"></form>
<script>
document.querySelectorAll('.send-back').forEach(function (b) {
    b.addEventListener('click', function () {
        var note = prompt('Why is this result sent back?', 'Please recheck');
        if (!note) return;
        var f = document.getElementById('sendback-form'); f.item_id.value = b.dataset.id; f.note.value = note; f.submit();
    });
});
</script>
<?php endif; ?>
<script>(function () { var dirty = false; document.addEventListener('change', function () { dirty = true; });
    setTimeout(function () { if (!dirty) location.reload(); }, 60000); })();</script>
