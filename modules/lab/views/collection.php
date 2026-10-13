<?php
/** @var array $groups @var string $labels */
use App\Core\View;

echo View::partial('page_header', [
    'title' => 'Sample Collection', 'description' => 'Patients waiting for phlebotomy. Each test gets its own barcode label; resamples are linked to the rejected sample and are not charged again.',
    'breadcrumbs' => [['Laboratory'], ['Collection']],
]);
?>
<?php if ($labels !== ''): ?>
<div class="alert alert-success flex"><?= icon('barcode') ?> Samples labelled. <a class="btn btn-sm btn-primary" target="_blank" href="<?= e(url('lab/labels', ['ids' => $labels])) ?>"><?= icon('printer') ?> Print labels</a></div>
<script>window.open(<?= json_encode(url('lab/labels', ['ids' => $labels])) ?>, '_blank');</script>
<?php endif; ?>
<?php foreach ($groups as $g): $o = $g['order']; ?>
<form class="card" method="post" action="<?= e(url('lab/collection')) ?>"><?= csrf_field() ?>
    <div class="card-header"><div class="card-title"><?= e($o['patient_name']) ?> <span class="muted small"><?= e($o['mrn']) ?> · <?= e($o['gender']) ?>, <?= e(age_text($o['dob'])) ?></span></div>
        <span class="muted small"><?= e($o['order_number']) ?> · <?= e(fmt_datetime($o['ordered_at'])) ?><?= $o['doctor_name'] ? ' · ' . e($o['doctor_name']) : '' ?></span></div>
    <div class="card-body"><table class="table table-compact"><tbody>
        <?php $fasting = false; foreach ($g['items'] as $i): $fasting = $fasting || $i['fasting_required']; ?><tr>
            <td style="width:30px"><input type="checkbox" name="items[]" value="<?= (int) $i['id'] ?>" checked></td>
            <td><strong><?= e($i['test_name']) ?></strong><?= $i['priority'] !== 'routine' ? ' <span class="badge badge-danger">' . e(strtoupper($i['priority'])) . '</span>' : '' ?>
                <?= $i['status'] === 'resample_pending' ? ' <span class="badge badge-warning">Resample</span>' : '' ?></td>
            <td class="small"><?= e($i['specimen_name']) ?></td>
            <td class="small"><span class="tube-dot" style="background:<?= e($i['color_code'] ?: '#ccc') ?>"></span> <?= e($i['container_name']) ?></td>
            <td class="small muted"><?= e($i['section_name']) ?></td></tr><?php endforeach; ?>
    </tbody></table></div>
    <div class="card-footer flex">
        <?php if ($fasting): ?><label class="flex small">Fasting? <select class="input input-sm" name="fasting"><option value="">Not asked</option><option value="1">Yes</option><option value="0">No</option></select></label><?php endif; ?>
        <input class="input input-sm grow" name="notes" maxlength="255" placeholder="Collection note (optional)">
        <button class="btn btn-primary"><?= icon('droplet') ?> Collected — print labels</button>
    </div>
</form>
<?php endforeach; ?>
<?php if (!$groups): ?><div class="card"><div class="card-body empty">No samples waiting to be collected.</div></div><?php endif; ?>
<?php if ($labels === ''): ?><script>setTimeout(function () { location.reload(); }, 45000);</script><?php endif; ?>
