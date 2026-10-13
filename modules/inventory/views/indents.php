<?php
/** @var array $rows @var \App\Core\Paginator $pager @var string $status */
use App\Core\View;

echo View::partial('page_header', [
    'title' => 'Indents / Requisitions', 'description' => 'Departments request stock from the store; the store manager approves; the store keeper issues by FEFO; the department confirms receipt.',
    'breadcrumbs' => [['Store', url('inventory')], ['Indents']],
    'actions' => can('inventory.requisition.create') ? '<a class="btn btn-primary" href="' . e(url('inventory/indent-new')) . '">' . icon('plus') . ' New Indent</a>' : '',
]);
?>
<div class="tabs mb-2">
    <?php foreach (['' => 'All', 'submitted' => 'Waiting approval', 'approved' => 'To issue', 'issued' => 'Issued', 'draft' => 'Drafts', 'rejected' => 'Rejected'] as $k => $l): ?>
        <a class="<?= $status === $k ? 'active' : '' ?>" href="<?= e(url('inventory/indents', ['status' => $k])) ?>"><?= e($l) ?></a>
    <?php endforeach; ?>
</div>
<div class="card card-flush"><div class="card-body table-wrap"><table class="table">
    <thead><tr><th>Indent</th><th>From (department)</th><th>To (store)</th><th>Requested by</th><th>Priority</th><th class="num">Lines</th><th>Status</th></tr></thead><tbody>
    <?php foreach ($rows as $r): ?><tr><td><a href="<?= e(url('inventory/indent', ['id' => $r['id']])) ?>"><strong><?= e($r['req_number']) ?></strong></a><div class="muted small"><?= e(fmt_datetime($r['created_at'])) ?></div></td>
        <td><?= e($r['from_name']) ?></td><td><?= e($r['to_name']) ?></td><td class="small"><?= e($r['requested_by_name']) ?></td>
        <td><?= $r['priority'] === 'urgent' ? '<span class="badge badge-danger">Urgent</span>' : 'Normal' ?></td><td class="num"><?= (int) $r['line_count'] ?></td><td><?= status_chip($r['status']) ?></td></tr><?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="7" class="muted">No indents.</td></tr><?php endif; ?>
</tbody></table></div><?= View::partial('pagination', ['pager' => $pager]) ?></div>
