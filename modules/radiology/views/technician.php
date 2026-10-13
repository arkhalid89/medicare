<?php
/** @var array $rows @var array $equipment @var array $protocols @var array $contrast */
use App\Core\View;

echo View::partial('page_header', [
    'title' => 'Imaging Worklist', 'description' => 'Studies of your modalities. Start, then finish with images / PACS reference, exposure (dose log) and any contrast — drawn from radiology stock.',
    'breadcrumbs' => [['Radiology'], ['Worklist']],
]);
$opts = static function (array $list, int $modality, ?int $sel = null): string {
    $h = '<option value="">—</option>';
    foreach ($list as $x) {
        if ((int) $x['modality_id'] === $modality) {
            $h .= '<option value="' . (int) $x['id'] . '"' . ((int) $x['id'] === (int) $sel ? ' selected' : '') . '>' . e($x['name']) . '</option>';
        }
    }
    return $h;
};
?>
<?php foreach ($rows as $o): ?>
<div class="card<?= $o['status'] === 'in_progress' ? ' module-card' : '' ?>">
    <div class="card-header"><div class="card-title"><?= e($o['modality_code']) ?> · <?= e($o['procedure_name']) ?> <?= $o['priority'] !== 'routine' ? '<span class="badge badge-danger">' . e(strtoupper($o['priority'])) . '</span>' : '' ?> <?= status_chip($o['status']) ?></div>
        <span class="small"><?= e($o['patient_name']) ?> · <?= e($o['mrn']) ?> · <?= e($o['gender']) ?>, <?= e(age_text($o['dob'])) ?><?= $o['scheduled_at'] ? ' · ' . e(fmt_datetime($o['scheduled_at'])) . ' ' . e($o['room_name']) : '' ?></span></div>
    <div class="card-body">
        <?php if ($o['clinical_indication']): ?><p class="small">Indication: <?= e($o['clinical_indication']) ?></p><?php endif; ?>
        <?php if ($o['allergies']): ?><span class="badge badge-danger">Allergy: <?= e($o['allergies']) ?></span><?php endif; ?>
        <?php if ($o['last_review']): ?><div class="alert alert-warning small">Repeat requested: <?= e($o['last_review']) ?></div><?php endif; ?>
        <?php if ($o['issues']): ?><div class="alert alert-danger small"><?= e(implode(' ', $o['issues'])) ?> Ask the reception to complete the screening.</div>
        <?php elseif ($o['status'] !== 'in_progress'): ?>
            <form method="post" action="<?= e(url('radiology/technician')) ?>" class="flex flex-wrap"><?= csrf_field() ?><input type="hidden" name="do" value="start"><input type="hidden" name="order_id" value="<?= (int) $o['id'] ?>">
                <label class="small">Equipment</label><select class="input input-sm" name="equipment_id"><?= $opts($equipment, (int) $o['modality_id']) ?></select>
                <label class="small">Protocol</label><select class="input input-sm" name="protocol_id"><?= $opts($protocols, (int) $o['modality_id']) ?></select>
                <button class="btn btn-sm btn-primary"><?= icon('zap') ?> Start study</button></form>
        <?php else: $p = $o['procedure']; ?>
            <form method="post" enctype="multipart/form-data" action="<?= e(url('radiology/technician')) ?>" class="form-grid"><?= csrf_field() ?><input type="hidden" name="do" value="finish"><input type="hidden" name="procedure_id" value="<?= (int) $p['id'] ?>">
                <div class="field col-2"><label>Images</label><input class="input" type="number" min="0" name="image_count" value="1"></div>
                <div class="field col-4"><label>PACS / image reference</label><input class="input" name="image_reference"></div>
                <div class="field col-6"><label>Key image (optional)</label><input type="file" name="image" accept="image/*,application/pdf"></div>
                <?php if ($o['uses_ionizing_radiation']): ?>
                <div class="field col-2"><label>kVp</label><input class="input" type="number" step="0.1" name="kvp"></div><div class="field col-2"><label>mAs</label><input class="input" type="number" step="0.01" name="mas"></div>
                <div class="field col-2"><label>DAP</label><input class="input" type="number" step="0.001" name="dap_value"></div><div class="field col-2"><label>CTDIvol</label><input class="input" type="number" step="0.001" name="ctdi_vol"></div>
                <div class="field col-2"><label>DLP</label><input class="input" type="number" step="0.001" name="dlp"></div><div class="field col-2"><label>Exposures</label><input class="input" type="number" min="1" name="exposure_count" value="1"></div>
                <?php endif; ?>
                <?php if ($o['contrast_possible']): ?>
                <div class="field col-4"><label>Contrast<?= $o['contrast_required'] ? ' <span class="req">*</span>' : '' ?></label><select class="input" name="contrast_item_id"><option value="">None</option><?php foreach ($contrast as $id => $n): ?><option value="<?= $id ?>"><?= e($n) ?></option><?php endforeach; ?></select></div>
                <div class="field col-2"><label>Volume ml</label><input class="input" type="number" step="0.1" name="contrast_volume_ml"></div><div class="field col-2"><label>Units used</label><input class="input" type="number" step="1" min="1" name="contrast_units" value="1"></div>
                <div class="field col-4"><label>Reaction</label><input class="input" name="contrast_reaction" placeholder="None"></div>
                <?php endif; ?>
                <div class="field col-12"><label>Technical notes</label><input class="input" name="technical_notes"></div>
                <div class="col-12"><button class="btn btn-success"><?= icon('check') ?> Finish — send for review</button> <span class="muted small">Started <?= e(fmt_datetime($p['started_at'])) ?></span></div>
            </form>
        <?php endif; ?>
    </div>
</div>
<?php endforeach; ?>
<?php if (!$rows): ?><div class="card"><div class="card-body empty">No studies waiting.</div></div><?php endif; ?>
