<?php
/** @var array $inv @var string $paper */
$thermal = $paper === 'thermal';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Receipt <?= e($inv['invoice_no']) ?></title>
<style>
    @page { size: <?= $thermal ? '80mm auto' : 'A5' ?>; margin: <?= $thermal ? '3mm' : '10mm' ?>; }
    body { font-family: Arial, Helvetica, sans-serif; color: #000; font-size: <?= $thermal ? '11px' : '12px' ?>; <?= $thermal ? 'width:74mm;' : 'max-width:128mm;' ?> margin: 0 auto; }
    .c { text-align: center; } .r { text-align: right; } .ur { font-family: 'Jameel Noori Nastaleeq', 'Noto Nastaliq Urdu', serif; direction: rtl; }
    h1 { font-size: <?= $thermal ? '15px' : '18px' ?>; margin: 0; } hr { border: 0; border-top: 1px dashed #000; margin: 6px 0; }
    table { width: 100%; border-collapse: collapse; } th, td { padding: 2px 0; text-align: left; vertical-align: top; } th { border-bottom: 1px solid #000; }
    .tot td { font-weight: 700; border-top: 1px solid #000; } .copy { font-size: 10px; }
    .noprint { margin: 10px 0; text-align: center; } @media print { .noprint { display: none; } }
</style>
</head>
<body>
<div class="noprint"><button onclick="window.print()">Print</button> <button onclick="window.close()">Close</button></div>
<div class="c">
    <h1><?= e(setting('org_name')) ?></h1>
    <div><?= e(setting('org_address')) ?> · <?= e(setting('org_phone')) ?></div>
    <?php if (setting('org_phc_reg_no')): ?><div>PHC Reg. <?= e(setting('org_phc_reg_no')) ?></div><?php endif; ?>
    <hr><strong><?= $inv['status'] === 'void' ? 'VOID — ' : '' ?>RECEIPT / INVOICE</strong> <span class="ur">رسید</span>
    <?php if ((int) $inv['print_count'] > 1): ?><div class="copy">Duplicate copy #<?= (int) $inv['print_count'] - 1 ?></div><?php endif; ?>
</div>
<table>
    <tr><td>Invoice No.</td><td class="r"><strong><?= e($inv['invoice_no']) ?></strong></td></tr>
    <tr><td>Date</td><td class="r"><?= e(fmt_datetime($inv['created_at'])) ?></td></tr>
    <tr><td>Patient</td><td class="r"><?= e($inv['patient_name'] ?: $inv['customer_name']) ?></td></tr>
    <?php if ($inv['mrn']): ?><tr><td>MRN</td><td class="r"><?= e($inv['mrn']) ?></td></tr><?php endif; ?>
    <?php if ($inv['cnic']): ?><tr><td>CNIC</td><td class="r"><?= e(cnic_mask($inv['cnic'])) ?></td></tr><?php endif; ?>
    <?php if ($inv['doctor_name']): ?><tr><td>Doctor</td><td class="r"><?= e($inv['doctor_name']) ?></td></tr><?php endif; ?>
    <?php if ($inv['department_name']): ?><tr><td>Department</td><td class="r"><?= e($inv['department_name']) ?></td></tr><?php endif; ?>
</table>
<hr>
<table>
    <tr><th>Service</th><th class="r">Qty</th><th class="r">Amount</th></tr>
    <?php foreach ($inv['items'] as $it): if ((int) $it['is_void']) continue; ?>
        <tr><td><?= e($it['description']) ?></td><td class="r"><?= e(num($it['quantity'])) ?></td><td class="r"><?= e(money($it['amount'])) ?></td></tr>
    <?php endforeach; ?>
    <tr><td colspan="2">Gross</td><td class="r"><?= e(money($inv['gross_amount'])) ?></td></tr>
    <?php if ((float) $inv['discount_amount'] > 0 && $inv['discount_status'] === 'approved'): ?><tr><td colspan="2">Discount</td><td class="r">-<?= e(money($inv['discount_amount'])) ?></td></tr><?php endif; ?>
    <?php if ((float) $inv['tax_amount'] > 0): ?><tr><td colspan="2"><?= e(setting('tax_label')) ?></td><td class="r"><?= e(money($inv['tax_amount'])) ?></td></tr><?php endif; ?>
    <tr class="tot"><td colspan="2">Net payable</td><td class="r"><?= e(money($inv['net_amount'])) ?></td></tr>
    <?php foreach ($inv['payments'] as $p): ?><tr><td colspan="2">Paid — <?= e(hospital('payment_modes')[$p['mode']] ?? $p['mode']) ?></td><td class="r"><?= e(money($p['amount'])) ?></td></tr><?php endforeach; ?>
    <?php foreach ($inv['refunds'] as $r): if ($r['status'] !== 'processed') continue; ?><tr><td colspan="2">Refunded</td><td class="r">-<?= e(money($r['amount'])) ?></td></tr><?php endforeach; ?>
    <tr><td colspan="2"><strong>Balance</strong></td><td class="r"><strong><?= e(money(max(0, $inv['balance']))) ?></strong></td></tr>
</table>
<?php if ($inv['waived']): ?><p>Fee waived: <?= e($inv['waiver_reason']) ?></p><?php endif; ?>
<hr>
<div class="c">Cashier: <?= e($inv['created_by_name']) ?><br><?= e(setting('receipt_footer')) ?><br><span class="ur"><?= e(setting('receipt_footer_ur')) ?></span></div>
<script>window.addEventListener('load', function () { setTimeout(function () { window.print(); }, 300); });</script>
</body>
</html>
