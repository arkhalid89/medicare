<?php
/** @var array $ws @var array $history */
use App\Core\View;

$it = $ws['item'];
$amend = $it['status'] === 'released';
$editable = in_array($it['status'], ['accepted', 'result_entered'], true) || ($amend && can('lab.result.verify'));
echo View::partial('page_header', [
    'title' => $it['test_name'], 'description' => $it['patient_name'] . ' · ' . $it['mrn'] . ' · ' . $it['gender'] . ', ' . age_text($it['dob']) . ' · ' . $it['order_number'],
    'breadcrumbs' => [['Laboratory'], ['Bench', url('lab/bench')], [$it['test_name']]],
    'actions' => '<a class="btn btn-outline" href="' . e(url('lab/order', ['id' => $it['lab_order_id']])) . '">Order</a>',
]);
$fmtRange = static function (?array $r, array $p): string {
    if (!$r) {
        return '—';
    }
    if ($r['text_range'] !== null && $r['low'] === null && $r['high'] === null) {
        return e($r['text_range']);
    }
    $d = (int) $p['decimals'];
    return e(($r['low'] !== null ? number_format((float) $r['low'], $d) : '') . ' – ' . ($r['high'] !== null ? number_format((float) $r['high'], $d) : ''));
};
?>
<form class="card" method="post" action="<?= e(url('lab/result', ['id' => $it['id']])) ?>"><?= csrf_field() ?>
    <?php if ($amend): ?><input type="hidden" name="do" value="amend"><?php endif; ?><input type="hidden" name="next" value="bench">
    <div class="card-header"><div class="card-title"><?= icon('flask') ?> <?= $amend ? 'Amend released result' : 'Results' ?> <?= status_chip($it['status']) ?></div>
        <span class="muted small"><?= e($it['section_name']) ?></span></div>
    <div class="card-body table-wrap"><table class="table">
        <thead><tr><th>Parameter</th><th style="width:200px">Result</th><th>Unit</th><th>Reference</th><th>Flag</th></tr></thead><tbody>
        <?php foreach ($ws['params'] as $p): $v = $p['value']; $val = $v ? ($v['value_numeric'] !== null ? rtrim(rtrim((string) $v['value_numeric'], '0'), '.') : $v['value_text']) : ''; ?><tr>
            <td><strong><?= e($p['name']) ?></strong><?= $p['qc_blocked'] ? ' <span class="badge badge-danger" title="Latest QC failed">QC fail</span>' : '' ?>
                <?php if ($p['critical_low'] !== null || $p['critical_high'] !== null): ?><div class="muted small">Critical: <?= e($p['critical_low'] ?? '') ?> / <?= e($p['critical_high'] ?? '') ?></div><?php endif; ?></td>
            <td><?php if ($p['data_type'] === 'select'): ?>
                <select class="input lab-val" name="v[<?= (int) $p['id'] ?>]"<?= $editable ? '' : ' disabled' ?>><option value=""></option>
                    <?php foreach ($p['options'] as $opt): ?><option<?= $opt === $val ? ' selected' : '' ?>><?= e($opt) ?></option><?php endforeach; ?></select>
            <?php else: ?>
                <input class="input lab-val" name="v[<?= (int) $p['id'] ?>]" value="<?= e($val) ?>" <?= $p['data_type'] === 'numeric' ? 'inputmode="decimal"' : '' ?>
                    data-low="<?= e($p['range']['low'] ?? '') ?>" data-high="<?= e($p['range']['high'] ?? '') ?>" data-cl="<?= e($p['critical_low'] ?? '') ?>" data-ch="<?= e($p['critical_high'] ?? '') ?>"<?= $editable ? '' : ' disabled' ?>>
            <?php endif; ?></td>
            <td class="small"><?= e($p['unit']) ?></td><td class="small"><?= $fmtRange($p['range'], $p) ?></td>
            <td class="lab-flag"><?= $v ? '<span class="flag flag-' . e($v['flag']) . '">' . e(str_replace('_', ' ', $v['flag'])) . '</span>' : '' ?></td></tr><?php endforeach; ?>
    </tbody></table>
    <div class="form-grid mt-2">
        <div class="field col-12"><label>Comment / interpretation</label><textarea class="input" name="comment" rows="2" maxlength="1000"<?= $editable ? '' : ' disabled' ?>><?= e($ws['result']['comment'] ?? '') ?></textarea></div>
        <?php if ($amend): ?><div class="field col-12"><label>Reason for amendment <span class="req">*</span></label><input class="input" name="amend_reason" required maxlength="255"></div><?php endif; ?>
    </div></div>
    <?php if ($editable): ?><div class="card-footer"><button class="btn btn-primary"><?= $amend ? 'Save amended version' : 'Save results' ?></button></div><?php endif; ?>
</form>
<?php if ($history): ?>
<div class="card card-flush"><div class="card-header"><div class="card-title">Versions</div></div><div class="card-body"><table class="table table-compact"><tbody>
    <?php foreach ($history as $h): ?><tr><td>v<?= (int) $h['version'] ?><?= $h['is_current'] ? ' <span class="badge badge-success">current</span>' : '' ?></td>
        <td class="small">Entered <?= e($h['entered_by_name']) ?>, <?= e(fmt_datetime($h['entered_at'])) ?></td>
        <td class="small"><?= $h['verified_at'] ? 'Verified ' . e($h['verified_by_name']) . ', ' . e(fmt_datetime($h['verified_at'])) : '<span class="muted">Not verified</span>' ?></td>
        <td class="small"><?= e($h['amend_reason']) ?></td></tr><?php endforeach; ?>
</tbody></table></div></div>
<?php endif; ?>
<script>
document.querySelectorAll('input.lab-val').forEach(function (inp) {
    inp.addEventListener('input', function () {
        var v = parseFloat(inp.value), d = inp.dataset, f = '', cell = inp.closest('tr').querySelector('.lab-flag');
        if (!isNaN(v)) {
            if (d.cl !== '' && v < +d.cl) f = 'critical_low'; else if (d.ch !== '' && v > +d.ch) f = 'critical_high';
            else if (d.low !== '' && v < +d.low) f = 'low'; else if (d.high !== '' && v > +d.high) f = 'high'; else f = 'normal';
        }
        cell.innerHTML = f ? '<span class="flag flag-' + f + '">' + f.replace('_', ' ') + '</span>' : '';
    });
});
</script>
