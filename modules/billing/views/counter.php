<?php
/** @var string $q @var array $found @var array $open @var array $refunds @var array|null $session @var array|null $totals */
use App\Core\View;

$actions = can('billing.invoice.create') ? '<a class="btn btn-primary" href="' . e(url('billing/new')) . '">' . icon('plus') . ' New Invoice</a>' : '';
echo View::partial('page_header', [
    'title'       => 'Billing Counter',
    'description' => 'Collect balances, add services, pay out approved refunds and close your cash session.',
    'breadcrumbs' => [['Billing'], ['Counter']],
    'actions'     => $actions,
]);
$list = static function (array $rows): string {
    ob_start(); ?>
    <table class="table table-compact">
        <thead><tr><th>Invoice</th><th>Patient / Customer</th><th>Date</th><th>Status</th><th class="num">Net</th><th class="num">Balance</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($rows as $i): $bal = (float) $i['net_amount'] - (float) $i['paid_amount'] + (float) $i['refunded_amount']; ?>
            <tr><td><a href="<?= e(url('billing/invoice', ['id' => $i['id']])) ?>"><strong><?= e($i['invoice_no']) ?></strong></a> <span class="muted small"><?= e(strtoupper($i['invoice_type'])) ?></span></td>
                <td><?= e($i['patient_name'] ?: $i['customer_name']) ?><div class="muted small"><?= e($i['mrn'] ?? '') ?></div></td>
                <td class="small nowrap"><?= e(fmt_datetime($i['created_at'])) ?></td><td><?= status_chip($i['status']) ?></td>
                <td class="num"><?= e(money($i['net_amount'])) ?></td><td class="num"><strong><?= e(money($bal)) ?></strong></td>
                <td><a class="btn btn-sm btn-outline" href="<?= e(url('billing/invoice', ['id' => $i['id']])) ?>">Open</a></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php return (string) ob_get_clean();
};
?>
<div class="grid grid-main">
    <div>
        <div class="card">
            <div class="card-body">
                <form class="filters" method="get" action="<?= e(form_action('billing')) ?>">
                    <?= route_field('billing') ?>
                    <div class="field grow"><label>Find invoice</label><input class="input" type="search" name="q" value="<?= e($q) ?>" placeholder="Invoice no., MRN, patient name or phone…" autofocus></div>
                    <div class="btns"><button class="btn btn-primary"><?= icon('search') ?> Search</button></div>
                </form>
            </div>
        </div>
        <?php if ($q !== ''): ?>
        <div class="card card-flush"><div class="card-header"><div class="card-title"><?= icon('search') ?> Results</div></div>
            <div class="card-body table-wrap"><?= $found ? $list($found) : '<div class="empty"><strong>No invoice found</strong></div>' ?></div></div>
        <?php endif; ?>
        <div class="card card-flush"><div class="card-header"><div class="card-title"><?= icon('receipt') ?> Open balances (last 7 days)</div></div>
            <div class="card-body table-wrap"><?= $open ? $list($open) : '<div class="empty"><strong>No open balances</strong>Everything is settled.</div>' ?></div></div>
    </div>
    <div>
        <?php if ($session && $totals): ?>
        <div class="card">
            <div class="card-header"><div class="card-title"><?= icon('money') ?> My cash session</div><span class="muted small">since <?= e(fmt_datetime($session['opened_at'])) ?></span></div>
            <div class="card-body">
                <dl class="kv">
                    <?php foreach ($totals['by_mode'] as $mode => $amt): ?><dt><?= e(hospital('payment_modes')[$mode] ?? $mode) ?></dt><dd><?= e(money($amt)) ?></dd><?php endforeach; ?>
                    <dt>Cash refunds</dt><dd>-<?= e(money($totals['cash_out'])) ?></dd>
                    <dt><strong>Expected cash</strong></dt><dd><strong><?= e(money($totals['expected'])) ?></strong></dd>
                </dl>
                <?php if (can('billing.cash.close')): ?>
                <form method="post" action="<?= e(url('billing/session-close')) ?>" class="mt-2" data-confirm="Close this cash session (shift close)?" data-confirm-danger="false">
                    <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $session['id'] ?>">
                    <div class="field mb-1"><label>Counted cash</label><input class="input" type="number" step="1" min="0" name="counted" required></div>
                    <div class="field mb-1"><label>Notes</label><input class="input" name="notes" maxlength="500"></div>
                    <button class="btn btn-outline"><?= icon('lock') ?> Close Shift</button>
                </form>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
        <?php if ($refunds): ?>
        <div class="card card-flush">
            <div class="card-header"><div class="card-title"><?= icon('money') ?> Approved refunds to pay</div></div>
            <div class="card-body"><ul class="list">
                <?php foreach ($refunds as $r): ?>
                    <li><div class="grow"><div class="title"><?= e(money($r['amount'])) ?> · <?= e($r['patient_name']) ?></div>
                        <div class="meta"><?= e($r['invoice_no']) ?> · <?= e($r['reason']) ?><?= $r['source_module'] !== 'billing' ? ' · ' . e(ucfirst($r['source_module'])) . ' rejection' : '' ?></div></div>
                        <form method="post" action="<?= e(url('billing/refund-process')) ?>" data-confirm="Pay out <?= e(money($r['amount'])) ?> in cash?" data-confirm-danger="false">
                            <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><input type="hidden" name="mode" value="cash">
                            <button class="btn btn-sm btn-gold">Pay out</button></form></li>
                <?php endforeach; ?>
            </ul></div>
        </div>
        <?php endif; ?>
    </div>
</div>
