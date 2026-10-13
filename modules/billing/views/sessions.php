<?php
/** @var array $rows */
use App\Core\View;

echo View::partial('page_header', [
    'title'       => 'Cash Sessions',
    'description' => 'Shift close per cashier: expected cash (payments − cash refunds) against the counted cash.',
    'breadcrumbs' => [['Billing'], ['Cash Sessions']],
]);
?>
<div class="card card-flush"><div class="card-body table-wrap">
    <table class="table">
        <thead><tr><th>Cashier</th><th>Opened</th><th>Closed</th><th class="num">Cash in</th><th class="num">Cash refunds</th><th class="num">Other modes</th><th class="num">Expected</th><th class="num">Counted</th><th class="num">Variance</th><th>Status</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): $other = array_sum(array_diff_key($r['totals']['by_mode'], ['cash' => 0])); ?>
            <tr><td><?= e($r['user_name']) ?></td><td class="small"><?= e(fmt_datetime($r['opened_at'])) ?></td><td class="small"><?= e(fmt_datetime($r['closed_at'])) ?></td>
                <td class="num"><?= e(money($r['totals']['cash_in'])) ?></td><td class="num"><?= e(money($r['totals']['cash_out'])) ?></td><td class="num"><?= e(money($other)) ?></td>
                <td class="num"><?= e(money($r['expected_cash'] ?? $r['totals']['expected'])) ?></td><td class="num"><?= $r['counted_cash'] !== null ? e(money($r['counted_cash'])) : '' ?></td>
                <td class="num <?= (float) $r['variance'] < 0 ? 'text-danger' : '' ?>"><?= $r['variance'] !== null ? e(money($r['variance'])) : '' ?></td>
                <td><?= status_chip($r['status']) ?>
                    <?php if ($r['status'] === 'open' && ((int) $r['user_id'] === \App\Core\Auth::id() || can('billing.refund.approve'))): ?>
                        <form method="post" action="<?= e(url('billing/session-close')) ?>" class="flex mt-1"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                            <input class="input input-sm" type="number" name="counted" placeholder="Counted" required style="width:100px"><button class="btn btn-sm btn-outline">Close</button></form>
                    <?php endif; ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div></div>
