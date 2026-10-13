<?php
/** @var array $o @var array $procs @var array|null $report @var array $addenda @var array $templates @var array|null $pregnancy */
use App\Core\View;

$final = $report && $report['status'] !== 'draft';
echo View::partial('page_header', [
    'title' => $o['procedure_name'], 'description' => $o['patient_name'] . ' · ' . $o['mrn'] . ' · ' . $o['gender'] . ', ' . age_text($o['dob']) . ' · ' . $o['order_number'],
    'breadcrumbs' => [['Radiology'], ['Radiologist', url('radiology/radiologist', ['tab' => 'report'])], ['Report']],
    'actions' => '<a class="btn btn-outline" href="' . e(url('radiology/order', ['id' => $o['id']])) . '">Order</a>' . ($final ? '<a class="btn btn-primary" target="_blank" href="' . e(url('radiology/print', ['id' => $o['id']])) . '">' . icon('printer') . ' Print</a>' : ''),
]);
$p = end($procs) ?: null;
?>
<div class="grid grid-main">
<div>
<?php if (!$final): ?>
<form class="card" method="post" action="<?= e(url('radiology/report', ['id' => $o['id']])) ?>"><?= csrf_field() ?>
    <div class="card-header"><div class="card-title"><?= icon('file-text') ?> Report</div>
        <select class="input input-sm" id="tpl" name="template_id"><option value="">Template…</option><?php foreach ($templates as $t): ?><option value="<?= (int) $t['id'] ?>" data-body="<?= e($t['body_json']) ?>"<?= selected($t['id'], $report['template_id'] ?? $o['default_report_template_id']) ?>><?= e($t['name']) ?></option><?php endforeach; ?></select></div>
    <div class="card-body">
        <div class="field"><label>Technique</label><textarea class="input" name="technique" rows="2" id="r-technique"><?= e($report['technique'] ?? '') ?></textarea></div>
        <div class="field"><label>Findings</label><textarea class="input" name="findings" rows="7" id="r-findings"><?= e($report['findings'] ?? '') ?></textarea></div>
        <div class="field"><label>Impression <span class="req">*</span></label><textarea class="input" name="impression" rows="3" id="r-impression"><?= e($report['impression'] ?? '') ?></textarea></div>
        <?php if ($pregnancy): ?><div class="section-title small">Obstetric biometry (saved to pregnancy #<?= (int) $pregnancy['pregnancy_no'] ?>)</div><div class="form-grid">
            <?php foreach (['crl' => 'CRL mm', 'bpd' => 'BPD mm', 'hc' => 'HC mm', 'ac' => 'AC mm', 'fl' => 'FL mm', 'ga_by_scan_weeks' => 'GA wk', 'afi_cm' => 'AFI cm', 'fhr_bpm' => 'FHR', 'number_of_fetuses' => 'Fetuses'] as $k => $l): ?>
                <div class="field col-2"><label><?= $l ?></label><input class="input" type="number" step="0.1" name="ob[<?= $k ?>]"></div><?php endforeach; ?>
            <div class="field col-3"><label>Placenta</label><input class="input" name="ob[placenta_location]"></div><div class="field col-3"><label>Presentation</label><input class="input" name="ob[fetal_presentation]"></div></div><?php endif; ?>
        <label class="check mt-2"><input type="checkbox" name="is_critical_finding" value="1"<?= !empty($report['is_critical_finding']) ? ' checked' : '' ?>> <?= icon('alert') ?> Critical finding — notify the ordering doctor</label>
    </div>
    <div class="card-footer flex"><button class="btn btn-outline" name="do" value="draft">Save draft</button><button class="btn btn-primary" name="do" value="finalize" data-confirm="Finalize? The report becomes read-only; later changes are addenda.">Finalize &amp; sign</button></div>
</form>
<script>
(function () {
    var sel = document.getElementById('tpl');
    function apply(force) {
        var o = sel.options[sel.selectedIndex]; if (!o || !o.dataset.body) return;
        var b = JSON.parse(o.dataset.body);
        ['technique', 'findings', 'impression'].forEach(function (k) { var t = document.getElementById('r-' + k); if (force || !t.value.trim()) t.value = b[k] || ''; });
    }
    sel.addEventListener('change', function () { apply(true); });
    apply(false);
})();
</script>
<?php else: ?>
<?= View::render('radiology/_report_body', ['report' => $report, 'addenda' => $addenda], null) ?>
<form class="card" method="post" action="<?= e(url('radiology/report', ['id' => $o['id']])) ?>"><?= csrf_field() ?><input type="hidden" name="do" value="addendum"><input type="hidden" name="report_id" value="<?= (int) $report['id'] ?>">
    <div class="card-header"><div class="card-title">Add addendum</div></div><div class="card-body"><textarea class="input" name="addendum_text" rows="3" required></textarea></div>
    <div class="card-footer"><button class="btn btn-primary">Sign addendum</button></div></form>
<?php endif; ?>
</div>
<div class="card"><div class="card-header"><div class="card-title">Study</div></div><div class="card-body small">
    <p><strong><?= e($o['modality_name']) ?></strong> · <?= e($o['body_part']) ?></p>
    <?= $o['clinical_indication'] ? '<p>Indication: ' . e($o['clinical_indication']) . '</p>' : '' ?>
    <?php if ($p): ?><p><?= (int) $p['image_count'] ?> image(s) · <?= e($p['tech_name']) ?> · <?= e($p['protocol_name']) ?></p>
        <?= $p['image_reference'] ? '<p>PACS: ' . e($p['image_reference']) . '</p>' : '' ?><?= $p['image_path'] ? '<p><a target="_blank" href="' . e(url('radiology/image', ['id' => $p['id']])) . '">' . icon('image') . ' Key image</a></p>' : '' ?>
        <?= $p['technical_notes'] ? '<p>' . e($p['technical_notes']) . '</p>' : '' ?><?php endif; ?>
    <p>Pregnancy: <?= e(\App\Modules\Radiology\RadiologyService::PREGNANCY[$o['pregnancy_status']] ?? $o['pregnancy_status']) ?></p>
</div></div>
</div>
