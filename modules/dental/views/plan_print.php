<?php
/** @var array $plan */
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Treatment plan #<?= (int) $plan['id'] ?></title>
<style>
    @page { size: A4; margin: 14mm; }
    body { font-family: Arial, Helvetica, sans-serif; color: #000; font-size: 12px; max-width: 182mm; margin: 0 auto; }
    h1 { font-size: 18px; margin: 0; } table { width: 100%; border-collapse: collapse; margin-top: 10px; } th, td { padding: 4px; text-align: left; border-bottom: 1px solid #ccc; }
    .r { text-align: right; } .head { border-bottom: 2px solid #000; padding-bottom: 6px; display: flex; justify-content: space-between; }
    .sign { margin-top: 40px; display: flex; justify-content: space-between; } .noprint { margin: 10px 0; text-align: center; } @media print { .noprint { display: none; } }
</style>
</head>
<body>
<div class="noprint"><button onclick="window.print()">Print</button> <button onclick="window.close()">Close</button></div>
<div class="head"><div><h1><?= e(setting('org_name')) ?></h1><div><?= e(setting('org_address')) ?> · <?= e(setting('org_phone')) ?></div></div>
    <div class="r"><strong>DENTAL TREATMENT PLAN &amp; ESTIMATE</strong><br>Plan #<?= (int) $plan['id'] ?> · <?= e(fmt_date($plan['created_at'])) ?></div></div>
<p><strong><?= e($plan['patient_name']) ?></strong> · <?= e($plan['mrn']) ?> · <?= e($plan['gender']) ?>, <?= e(age_text($plan['dob'])) ?> · Dentist: <?= e($plan['dentist']) ?></p>
<table><tr><th>#</th><th>Tooth</th><th>Procedure</th><th>Visit</th><th>Status</th><th class="r">Estimate</th></tr>
    <?php foreach ($plan['items'] as $i): ?><tr><td><?= (int) $i['sequence_no'] ?></td><td><?= e($i['tooth_number'] ?: '—') ?></td><td><?= e($i['procedure_name']) ?></td><td><?= (int) $i['planned_visit_no'] ?></td><td><?= e($i['status']) ?></td><td class="r"><?= e(money($i['estimated_price'])) ?></td></tr><?php endforeach; ?>
    <tr><td colspan="5" class="r"><strong>Estimated total</strong></td><td class="r"><strong><?= e(money($plan['estimated_total'])) ?></strong></td></tr></table>
<p>Charges are billed as each step is completed. The estimate may change if the clinical situation changes; you will be informed before any additional treatment.</p>
<div class="sign"><div>Accepted by: <?= $plan['accepted_by_name'] ? '<strong>' . e($plan['accepted_by_name']) . '</strong> on ' . e(fmt_date($plan['accepted_at'])) : '______________________' ?></div><div>Dentist: ______________________</div></div>
</body>
</html>
