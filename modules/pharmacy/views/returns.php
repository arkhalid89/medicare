<?php
/** @var array|null $found @var string $q @var array $rows */
use App\Core\Auth;
use App\Core\View;

echo View::partial('page_header', [
    'title' => 'Pharmacy Returns', 'description' => 'Unopened medicines within policy. The chief pharmacist approves; resellable stock goes back to the shelf, the rest to quarantine; the refund is paid at the billing counter.',
    'breadcrumbs' => [['Pharmacy', url('pharmacy')], ['Returns']],
]);
?>
<?php if (can('pharmacy.return')): ?>
<div class="card"><div class="card-body"><form class="filters" method="get" action="<?= e(form_action('pharmacy/returns')) ?>"><?= route_field('pharmacy/returns') ?>
    <div class="field grow"><label>Sale no. or visit no.</label><input class="input" name="q" value="<?= e($q) ?>" placeholder="PS-2026-00012 or V-2026-000123"></div>
    <div class="btns"><button class="btn btn-primary"><?= icon('search') ?> Find</button></div></form></div></div>
<?php if ($found): ?>
<form class="card" method="post" action="<?= e(url('pharmacy/returns')) ?>">
    <?= csrf_field() ?><input type="hidden" name="source" value="<?= e($found['src']) ?>"><input type="hidden" name="source_id" value="<?= (int) $found['id'] ?>">
    <div class="card-body"><table class="table"><thead><tr><th>Item</th><th>Batch</th><th class="num">Given</th><th class="num">Returned before</th><th>Return now</th><th>Resellable</th></tr></thead><tbody>
        <?php foreach ($found['lines'] as $l): ?><tr><td><?= e($l['item_name']) ?></td><td><?= e($l['batch_no']) ?></td><td class="num"><?= e(num($l['qty'])) ?></td><td class="num"><?= e(num($l['returned_qty'])) ?></td>
            <td><input class="input input-sm" type="number" step="0.001" min="0" max="<?= e((string) ((float) $l['qty'] - (float) $l['returned_qty'])) ?>" name="ret[<?= (int) $l['id'] ?>][qty]" style="width:100px"></td>
            <td><input type="checkbox" name="ret[<?= (int) $l['id'] ?>][resellable]" value="1" checked></td></tr><?php endforeach; ?>
    </tbody></table>
    <div class="field mt-2"><label>Reason</label><input class="input" name="reason" maxlength="255" required></div></div>
    <div class="card-footer"><button class="btn btn-primary">Record Return</button></div>
</form>
<?php endif; ?>
<?php endif; ?>
<div class="card card-flush"><div class="card-body table-wrap"><table class="table">
    <thead><tr><th>Return</th><th>Patient</th><th>Reason</th><th class="num">Amount</th><th>Status</th><th></th></tr></thead><tbody>
    <?php foreach ($rows as $r): ?><tr><td><strong><?= e($r['return_number']) ?></strong><div class="muted small"><?= e(fmt_datetime($r['created_at'])) ?> · <?= e($r['requested_by_name']) ?></div></td>
        <td><?= e($r['patient_name'] ?: 'Walk-in') ?></td><td class="small"><?= e($r['reason']) ?></td><td class="num"><?= e(money($r['total_amount'])) ?></td>
        <td><?= status_chip($r['status']) ?><?= $r['approved_by_name'] ? '<div class="muted small">' . e($r['approved_by_name']) . '</div>' : '' ?></td>
        <td><?php if ($r['status'] === 'requested' && can('pharmacy.return.approve') && (int) $r['requested_by'] !== Auth::id()): ?>
            <form method="post" action="<?= e(url('pharmacy/returns')) ?>" class="flex"><?= csrf_field() ?><input type="hidden" name="decide" value="<?= (int) $r['id'] ?>">
                <button class="btn btn-sm btn-success" name="decision" value="approve">Approve</button><button class="btn btn-sm btn-danger" name="decision" value="reject">Reject</button></form><?php endif; ?></td></tr><?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="6" class="muted">No returns.</td></tr><?php endif; ?>
</tbody></table></div></div>
