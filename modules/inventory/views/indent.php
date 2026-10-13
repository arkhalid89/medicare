<?php
/** @var array $r @var array $lines @var array $issues */
use App\Core\View;

echo View::partial('page_header', [
    'title' => 'Indent ' . $r['req_number'], 'description' => $r['from_name'] . ' ← ' . $r['to_name'] . ' · requested by ' . $r['requested_by_name'] . ' on ' . fmt_datetime($r['created_at']),
    'breadcrumbs' => [['Store', url('inventory')], ['Indents', url('inventory/indents')], [$r['req_number']]],
]);
$canApprove = $r['status'] === 'submitted' && can('inventory.requisition.approve') && (int) $r['requested_by'] !== \App\Core\Auth::id();
$canIssue = in_array($r['status'], ['approved', 'partially_issued'], true) && can('inventory.issue');
?>
<div class="flex mb-2"><?= status_chip($r['status']) ?><?= $r['priority'] === 'urgent' ? ' <span class="badge badge-danger">Urgent</span>' : '' ?>
    <?php if ($r['notes']): ?><span class="muted"><?= e($r['notes']) ?></span><?php endif; ?>
    <?php if ($r['approved_by_name']): ?><span class="muted small"><?= $r['status'] === 'rejected' ? 'Rejected' : 'Approved' ?> by <?= e($r['approved_by_name']) ?><?= $r['rejected_reason'] ? ': ' . e($r['rejected_reason']) : '' ?></span><?php endif; ?></div>
<form class="card card-flush" method="post" action="<?= e(url('inventory/indent-action')) ?>">
    <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
    <div class="card-body table-wrap"><table class="table">
        <thead><tr><th>Item</th><th class="num">Requested</th><th class="num">Approved</th><th class="num">Issued</th><th class="num">In store</th><?php if ($canApprove): ?><th>Approve qty</th><?php endif; ?><?php if ($canIssue): ?><th>Issue now</th><?php endif; ?></tr></thead><tbody>
        <?php foreach ($lines as $l): $left = (float) ($l['qty_approved'] ?? $l['qty_requested']) - (float) $l['qty_issued']; ?>
            <tr><td><strong><?= e($l['item_name']) ?></strong><div class="muted small"><?= e($l['sku']) ?><?= $l['note'] ? ' · ' . e($l['note']) : '' ?></div></td>
                <td class="num"><?= e(num($l['qty_requested'])) ?></td><td class="num"><?= $l['qty_approved'] !== null ? e(num($l['qty_approved'])) : '—' ?></td>
                <td class="num"><?= e(num($l['qty_issued'])) ?></td><td class="num <?= $l['store_qty'] < $left ? 'text-danger' : '' ?>"><?= e(num($l['store_qty'])) ?></td>
                <?php if ($canApprove): ?><td><input class="input input-sm" type="number" step="0.001" min="0" name="approved[<?= (int) $l['id'] ?>]" value="<?= e(num($l['qty_requested'])) ?>"></td><?php endif; ?>
                <?php if ($canIssue): ?><td><input class="input input-sm" type="number" step="0.001" min="0" name="issue[<?= (int) $l['id'] ?>]" value="<?= e(num(max(0, min($left, $l['store_qty'])))) ?>"></td><?php endif; ?></tr>
        <?php endforeach; ?>
    </tbody></table></div>
    <div class="card-footer">
        <?php if ($r['status'] === 'draft' && (int) $r['requested_by'] === \App\Core\Auth::id()): ?><button class="btn btn-primary" name="do" value="submit">Send to Store</button><?php endif; ?>
        <?php if ($canApprove): ?><input class="input" name="reason" placeholder="Reason (if rejecting)" style="max-width:260px"><button class="btn btn-danger" name="do" value="reject">Reject</button><button class="btn btn-success" name="do" value="approve"><?= icon('check') ?> Approve</button><?php endif; ?>
        <?php if ($canIssue): ?><button class="btn btn-gold" name="do" value="issue"><?= icon('box') ?> Issue (FEFO batches)</button><?php endif; ?>
    </div>
</form>
<?php if ($issues): ?>
<div class="card card-flush"><div class="card-header"><div class="card-title"><?= icon('truck') ?> Issues</div></div><div class="card-body"><ul class="list">
    <?php foreach ($issues as $i): ?><li><div class="grow"><div class="title"><?= e($i['issue_number']) ?></div><div class="meta">Issued <?= e(fmt_datetime($i['issued_at'])) ?> by <?= e($i['issued_by_name']) ?><?= $i['received_at'] ? ' · received ' . e(fmt_datetime($i['received_at'])) . ' by ' . e($i['received_by_name']) : '' ?></div></div>
        <?php if (!$i['received_at'] && can('inventory.requisition.create')): ?><form method="post" action="<?= e(url('inventory/indent-action')) ?>"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><input type="hidden" name="issue_id" value="<?= (int) $i['id'] ?>"><button class="btn btn-sm btn-outline" name="do" value="receive">Confirm receipt</button></form><?php endif; ?></li><?php endforeach; ?>
</ul></div></div>
<?php endif; ?>
