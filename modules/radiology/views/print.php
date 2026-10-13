<?php
/** @var array $o @var array|null $report @var array $addenda */
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Imaging report <?= e($o['order_number']) ?></title>
<style>
    @page { size: A4; margin: 14mm; }
    body { font-family: Arial, Helvetica, sans-serif; color: #000; font-size: 12.5px; max-width: 182mm; margin: 0 auto; }
    .head { display: flex; justify-content: space-between; border-bottom: 2px solid #000; padding-bottom: 6px; } h1 { font-size: 18px; margin: 0; }
    h4 { margin: 14px 0 4px; text-transform: uppercase; font-size: 12px; letter-spacing: .5px; } table { width: 100%; border-collapse: collapse; } td { padding: 2px 4px; }
    .r { text-align: right; } .crit { border: 2px solid #b00; padding: 4px 8px; color: #b00; font-weight: 700; margin-top: 8px; } .add { border-top: 1px dashed #000; margin-top: 12px; }
    .sign { margin-top: 36px; text-align: right; } .noprint { margin: 10px 0; text-align: center; } @media print { .noprint { display: none; } }
</style>
</head>
<body>
<div class="noprint"><button onclick="window.print()">Print</button> <button onclick="window.close()">Close</button></div>
<div class="head"><div><h1><?= e(setting('org_name')) ?></h1><div><?= e(setting('org_address')) ?> · <?= e(setting('org_phone')) ?></div></div>
    <div class="r"><strong>RADIOLOGY REPORT</strong><br><?= e($o['order_number']) ?><?= $report['status'] === 'addended' ? '<br>(with addendum)' : '' ?></div></div>
<table>
    <tr><td>Patient</td><td><strong><?= e($o['patient_name']) ?></strong></td><td>MRN</td><td><?= e($o['mrn']) ?></td></tr>
    <tr><td>Age / Sex</td><td><?= e(age_text($o['dob'])) ?> / <?= e($o['gender']) ?></td><td>Study</td><td><strong><?= e($o['procedure_name']) ?></strong></td></tr>
    <tr><td>Referred by</td><td><?= e($o['doctor_name'] ?: ($o['referring_doctor'] ?: 'Self')) ?></td><td>Reported</td><td><?= e(fmt_datetime($report['finalized_at'])) ?></td></tr>
</table>
<?php if ($o['clinical_indication']): ?><h4>Clinical indication</h4><p><?= e($o['clinical_indication']) ?></p><?php endif; ?>
<?php if ($report['technique']): ?><h4>Technique</h4><p><?= nl2br(e($report['technique'])) ?></p><?php endif; ?>
<?php if ($report['findings']): ?><h4>Findings</h4><p><?= nl2br(e($report['findings'])) ?></p><?php endif; ?>
<h4>Impression</h4><p><strong><?= nl2br(e($report['impression'])) ?></strong></p>
<?php if ($report['is_critical_finding']): ?><div class="crit">CRITICAL FINDING — communicated to the referring doctor.</div><?php endif; ?>
<?php foreach ($addenda as $a): ?><div class="add"><h4>Addendum — <?= e(fmt_datetime($a['added_at'])) ?></h4><p><?= nl2br(e($a['addendum_text'])) ?></p><p class="r"><?= e($a['added_by_name']) ?></p></div><?php endforeach; ?>
<div class="sign">______________________<br>Radiologist<br><?= e($report['reported_by_name']) ?></div>
</body>
</html>
