<?php /** @var array $summary */ ?>
<div class="grid grid-4 mb-2">
    <div class="stat"><div class="stat-icon"><?= icon('users') ?></div><div><p class="label">Patients / Visits</p><div class="value"><?= number_format((int) $summary['patients']) ?> <span class="muted" style="font-size:1rem">/ <?= number_format((int) $summary['visits']) ?></span></div><div class="sub"><?= (int) $summary['free_visits'] ?> free visit(s)</div></div></div>
    <div class="stat"><div class="stat-icon green"><?= icon('money') ?></div><div><p class="label">Consultation Fee</p><div class="value"><?= e(money($summary['fee'])) ?></div><div class="sub">Paid <?= e(money($summary['paid'])) ?> · Pending <?= e(money($summary['pending'])) ?></div></div></div>
    <div class="stat"><div class="stat-icon gold"><?= icon('pill') ?></div><div><p class="label">Medicine Cost</p><div class="value"><?= e(money($summary['medicine_net'])) ?></div><div class="sub">Gross <?= e(money($summary['medicines_total'])) ?> · Discount <?= e(money($summary['discount'])) ?></div></div></div>
    <div class="stat"><div class="stat-icon orange"><?= icon('chart') ?></div><div><p class="label">Grand Total</p><div class="value"><?= e(money($summary['grand'])) ?></div><div class="sub">Fee + medicines</div></div></div>
</div>
