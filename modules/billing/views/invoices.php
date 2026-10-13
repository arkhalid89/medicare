<?php
/** @var array $rows @var \App\Core\Paginator $pager @var array $totals @var string $from @var string $to */
use App\Core\View;

echo View::partial('page_header', [
    'title'       => 'Invoices',
    'description' => 'Every OPD, pharmacy, laboratory, radiology, dental and miscellaneous bill with its payments and refunds.',
    'breadcrumbs' => [['Billing'], ['Invoices']],
    'actions'     => '<a class="btn btn-excel" href="' . e(url_with(['export' => 'xlsx', 'page' => null])) . '">' . icon('excel') . ' Export to Excel</a>',
]);
?>
<div class="grid grid-3 mb-2">
    <div class="stat"><div class="stat-icon"><?= icon('receipt') ?></div><div><p class="label">Billed (net)</p><div class="value"><?= e(money($totals['net'])) ?></div></div></div>
    <div class="stat"><div class="stat-icon green"><?= icon('money') ?></div><div><p class="label">Collected</p><div class="value"><?= e(money($totals['paid'])) ?></div></div></div>
    <div class="stat"><div class="stat-icon gold"><?= icon('repeat') ?></div><div><p class="label">Refunded</p><div class="value"><?= e(money($totals['refunded'])) ?></div></div></div>
</div>
<div class="card card-flush">
    <div class="card-header">
        <form class="filters" method="get" action="<?= e(form_action('billing/invoices')) ?>">
            <?= route_field('billing/invoices') ?>
            <div class="field grow"><label>Search</label><input class="input" type="search" name="q" value="<?= e(input('q', '')) ?>" placeholder="Invoice, MRN, name…"></div>
            <div class="field"><label>Type</label><select class="input" name="type"><option value="">All</option><?php foreach (hospital('invoice_types') as $k => $l): ?><option value="<?= e($k) ?>"<?= selected($k, input('type', '')) ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
            <div class="field"><label>Status</label><select class="input" name="status"><option value="">All</option>
                <?php foreach (['open', 'partial', 'settled', 'refunded', 'void'] as $s): ?><option<?= selected($s, input('status', '')) ?>><?= $s ?></option><?php endforeach; ?></select></div>
            <div class="field"><label>From</label><input class="input" type="date" name="from" value="<?= e($from) ?>"></div>
            <div class="field"><label>To</label><input class="input" type="date" name="to" value="<?= e($to) ?>"></div>
            <div class="btns"><button class="btn btn-primary"><?= icon('filter') ?> Filter</button></div>
        </form>
    </div>
    <div class="card-body table-wrap">
        <?php if (!$rows): ?><div class="empty"><?= icon('receipt') ?><strong>No invoices</strong></div><?php else: ?>
        <table class="table">
            <thead><tr><th>Invoice</th><th>Date</th><th>Patient</th><th>Doctor</th><th>Status</th><th class="num">Net</th><th class="num">Paid</th><th class="num">Balance</th><th>Cashier</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $i): ?>
                <tr><td><a href="<?= e(url('billing/invoice', ['id' => $i['id']])) ?>"><strong><?= e($i['invoice_no']) ?></strong></a><div class="muted small"><?= e(hospital('invoice_types')[$i['invoice_type']] ?? $i['invoice_type']) ?></div></td>
                    <td class="small nowrap"><?= e(fmt_datetime($i['created_at'])) ?></td>
                    <td><?= e($i['patient_name'] ?: $i['customer_name']) ?><div class="muted small"><?= e($i['mrn']) ?></div></td>
                    <td class="small"><?= e($i['doctor_name']) ?></td><td><?= status_chip($i['status']) ?><?= $i['waived'] ? ' <span class="badge badge-info">Waived</span>' : '' ?></td>
                    <td class="num"><?= e(money($i['net_amount'])) ?></td><td class="num"><?= e(money($i['paid_amount'])) ?></td>
                    <td class="num"><?= e(money((float) $i['net_amount'] - (float) $i['paid_amount'] + (float) $i['refunded_amount'])) ?></td><td class="small"><?= e($i['created_by_name']) ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
    <?= View::partial('pagination', ['pager' => $pager]) ?>
</div>
