<?php
/** @var array $o */
$rep = $o['report'];
$released = array_filter($o['items'], static fn (array $i): bool => $i['status'] === 'released');
$pending = array_filter($o['items'], static fn (array $i): bool => !in_array($i['status'], ['released', 'cancelled', 'rejected_refunded'], true));
$fmt = static fn ($v): string => $v === null ? '' : rtrim(rtrim((string) $v, '0'), '.');
$sections = [];
foreach ($released as $i) {
    $sections[$i['section_name'] ?: 'Laboratory'][] = $i;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Lab report <?= e($rep['report_number'] ?? $o['order_number']) ?></title>
<style>
    @page { size: A4; margin: 12mm; }
    body { font-family: Arial, Helvetica, sans-serif; color: #000; font-size: 12px; max-width: 186mm; margin: 0 auto; }
    .head { display: flex; justify-content: space-between; border-bottom: 2px solid #000; padding-bottom: 6px; }
    h1 { font-size: 18px; margin: 0; } h2 { font-size: 13px; margin: 14px 0 4px; text-transform: uppercase; letter-spacing: .5px; border-bottom: 1px solid #999; }
    h3 { font-size: 12px; margin: 8px 0 2px; }
    table { width: 100%; border-collapse: collapse; } th, td { padding: 3px 4px; text-align: left; vertical-align: top; } th { border-bottom: 1px solid #000; font-size: 11px; }
    .pt td { padding: 1px 4px; } .r { text-align: right; } .muted { color: #555; } .ab { font-weight: 700; } .crit { font-weight: 700; text-decoration: underline; }
    .sign { margin-top: 30px; display: flex; justify-content: space-between; } .wm { color: #b00; font-weight: 700; }
    .noprint { margin: 10px 0; text-align: center; } @media print { .noprint { display: none; } }
</style>
</head>
<body>
<div class="noprint"><button onclick="window.print()">Print</button> <button onclick="window.close()">Close</button></div>
<div class="head">
    <div><h1><?= e(setting('org_name')) ?></h1><div><?= e(setting('org_address')) ?> · <?= e(setting('org_phone')) ?></div>
        <?php if (setting('org_phc_reg_no')): ?><div>PHC Reg. <?= e(setting('org_phc_reg_no')) ?></div><?php endif; ?></div>
    <div class="r"><strong>LABORATORY REPORT</strong><br><?= e($rep['report_number'] ?? '') ?><?= $rep && (int) $rep['version'] > 1 ? ' (amended v' . (int) $rep['version'] . ')' : '' ?>
        <?php if (!$rep): ?><div class="wm">PROVISIONAL — not all tests released</div><?php endif; ?></div>
</div>
<table class="pt">
    <tr><td>Patient</td><td><strong><?= e($o['patient_name']) ?></strong></td><td>MRN</td><td><?= e($o['mrn']) ?></td></tr>
    <tr><td>Age / Sex</td><td><?= e(age_text($o['dob'])) ?> / <?= e($o['gender']) ?></td><td>Order</td><td><?= e($o['order_number']) ?></td></tr>
    <tr><td>Referred by</td><td><?= e($o['doctor_name'] ?: ($o['referring_doctor'] ?: 'Self')) ?></td><td>Ordered</td><td><?= e(fmt_datetime($o['ordered_at'])) ?></td></tr>
    <?php if ($rep): ?><tr><td></td><td></td><td>Released</td><td><?= e(fmt_datetime($rep['released_at'])) ?></td></tr><?php endif; ?>
</table>
<?php foreach ($sections as $sec => $items): ?>
<h2><?= e($sec) ?></h2>
<?php foreach ($items as $i): ?>
<h3><?= e($i['test_name']) ?><?= $i['method'] ? ' <span class="muted">(' . e($i['method']) . ')</span>' : '' ?></h3>
<table>
    <tr><th style="width:38%">Test</th><th style="width:18%">Result</th><th style="width:12%">Unit</th><th>Reference range</th><th style="width:10%">Flag</th></tr>
    <?php foreach ($i['values'] as $v):
        $crit = str_starts_with($v['flag'], 'critical'); $ab = $v['flag'] !== 'normal'; ?>
    <tr><td><?= e($v['name']) ?></td><td class="<?= $crit ? 'crit' : ($ab ? 'ab' : '') ?>"><?= e($v['value_numeric'] !== null ? number_format((float) $v['value_numeric'], (int) $v['decimals']) : $v['value_text']) ?></td>
        <td><?= e($v['unit']) ?></td><td><?= $v['ref_low'] !== null || $v['ref_high'] !== null ? e($fmt($v['ref_low']) . ' – ' . $fmt($v['ref_high'])) : e($v['ref_text']) ?></td>
        <td class="<?= $crit ? 'crit' : 'ab' ?>"><?= ['low' => 'L', 'high' => 'H', 'critical_low' => 'LL', 'critical_high' => 'HH', 'abnormal' => '*'][$v['flag']] ?? '' ?></td></tr>
    <?php endforeach; ?>
</table>
<?php if ($i['comment']): ?><p><em><?= e($i['comment']) ?></em></p><?php endif; ?>
<?php if ($i['amend_reason']): ?><p class="wm">Amended: <?= e($i['amend_reason']) ?></p><?php endif; ?>
<div class="muted small">Sample: <?= e(fmt_datetime($i['sample_at'])) ?> · Verified by <?= e($i['verified_by_name']) ?>, <?= e(fmt_datetime($i['r_verified_at'])) ?></div>
<?php endforeach; ?>
<?php endforeach; ?>
<?php if ($pending): ?><p class="muted">Pending: <?= e(implode(', ', array_column($pending, 'test_name'))) ?></p><?php endif; ?>
<div class="sign">
    <div class="muted">L / H = below / above reference · LL / HH = critical</div>
    <div class="r"><br>______________________<br>Pathologist<?= $rep && $rep['released_by_name'] ? '<br>' . e($rep['released_by_name']) : '' ?></div>
</div>
<?php if (setting('lab_report_footer')): ?><p class="muted" style="margin-top:20px"><?= e(setting('lab_report_footer')) ?></p><?php endif; ?>
</body>
</html>
