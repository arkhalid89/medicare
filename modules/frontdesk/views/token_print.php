<?php
/** @var array $t @var array|null $invoice @var int $ahead @var int $wait */
$p = hospital('priorities')[$t['priority']] ?? ['Normal', 0, 'عام'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Token <?= e($t['token_label']) ?></title>
<style>
    @page { size: 80mm auto; margin: 3mm; }
    body { font-family: Arial, Helvetica, sans-serif; width: 74mm; margin: 0 auto; color: #000; font-size: 11px; }
    .c { text-align: center; } .ur { font-family: 'Jameel Noori Nastaleeq', 'Noto Nastaliq Urdu', serif; direction: rtl; }
    h1 { font-size: 15px; margin: 2px 0; } .tok { font-size: 44px; font-weight: 800; letter-spacing: 2px; margin: 4px 0; }
    hr { border: 0; border-top: 1px dashed #000; margin: 6px 0; }
    table { width: 100%; border-collapse: collapse; } td { padding: 1px 0; vertical-align: top; } .r { text-align: right; }
    .tot td { font-weight: 700; border-top: 1px solid #000; }
    .noprint { margin: 10px 0; text-align: center; } @media print { .noprint { display: none; } }
    button { padding: 6px 14px; font-size: 13px; }
</style>
</head>
<body>
<div class="noprint"><button onclick="window.print()">Print</button> <button onclick="window.close()">Close</button></div>
<div class="c">
    <h1><?= e(setting('org_name')) ?></h1>
    <div><?= e(setting('org_address')) ?> · <?= e(setting('org_phone')) ?></div>
    <hr>
    <div>TOKEN / <span class="ur">ٹوکن نمبر</span></div>
    <div class="tok"><?= e($t['token_label']) ?></div>
    <div><strong><?= e($t['doctor_name'] ?: $t['queue_name']) ?></strong><?= $t['room'] ? ' · ' . e($t['room']) : '' ?></div>
    <?php if ($t['priority'] !== 'normal'): ?><div><strong><?= e($p[0]) ?></strong> <span class="ur"><?= e($p[2]) ?></span></div><?php endif; ?>
    <div><?= (int) $ahead ?> ahead · approx. <?= (int) $wait ?> min <span class="ur">· آپ سے پہلے <?= (int) $ahead ?> مریض</span></div>
    <hr>
</div>
<table>
    <tr><td>Patient</td><td class="r"><strong><?= e($t['patient_name']) ?></strong></td></tr>
    <tr><td>MRN</td><td class="r"><?= e($t['mrn']) ?></td></tr>
    <tr><td>Issued</td><td class="r"><?= e(fmt_datetime($t['issued_at'])) ?></td></tr>
</table>
<?php if ($invoice): ?>
<hr>
<div class="c"><strong>RECEIPT / <span class="ur">رسید</span></strong> · <?= e($invoice['invoice_no']) ?></div>
<table>
    <?php foreach ($invoice['items'] as $i): if ((int) $i['is_void']) continue; ?>
        <tr><td><?= e($i['description']) ?></td><td class="r"><?= e(money($i['amount'])) ?></td></tr>
    <?php endforeach; ?>
    <?php if ((float) $invoice['discount_amount'] > 0 && $invoice['discount_status'] === 'approved'): ?><tr><td>Discount</td><td class="r">-<?= e(money($invoice['discount_amount'])) ?></td></tr><?php endif; ?>
    <tr class="tot"><td>Total</td><td class="r"><?= e(money($invoice['net_amount'])) ?></td></tr>
    <?php if ($invoice['waived']): ?><tr><td colspan="2">Fee waived: <?= e($invoice['waiver_reason']) ?></td></tr><?php endif; ?>
    <?php foreach ($invoice['payments'] as $pm): ?><tr><td>Paid (<?= e(hospital('payment_modes')[$pm['mode']] ?? $pm['mode']) ?>)</td><td class="r"><?= e(money($pm['amount'])) ?></td></tr><?php endforeach; ?>
    <tr><td>Balance</td><td class="r"><?= e(money(max(0, $invoice['balance']))) ?></td></tr>
    <tr><td>Cashier</td><td class="r"><?= e($invoice['created_by_name']) ?></td></tr>
</table>
<?php endif; ?>
<hr>
<div class="c"><?= e(setting('receipt_footer')) ?><br><span class="ur"><?= e(setting('receipt_footer_ur')) ?></span></div>
<script>window.addEventListener('load', function () { setTimeout(function () { window.print(); }, 300); });</script>
</body>
</html>
