<?php
/** @var array $rows @var int $itemId @var array $items */
use App\Core\View;

echo View::partial('page_header', [
    'title' => 'Controlled Drug Register', 'description' => 'Append-only register: every movement of a controlled drug with the balance after it, the issuer and the co-signing witness.',
    'breadcrumbs' => [['Pharmacy', url('pharmacy')], ['Controlled drugs']],
    'actions' => '<a class="btn btn-excel" href="' . e(url_with(['export' => 'xlsx'])) . '">' . icon('excel') . ' Export</a>',
]);
?>
<div class="card card-flush">
    <div class="card-header"><form class="filters" method="get" action="<?= e(form_action('pharmacy/controlled')) ?>" data-autosubmit><?= route_field('pharmacy/controlled') ?>
        <div class="field grow"><label>Drug</label><select class="input" name="item_id"><option value="">All controlled drugs</option><?php foreach ($items as $id => $n): ?><option value="<?= $id ?>"<?= selected($id, $itemId) ?>><?= e($n) ?></option><?php endforeach; ?></select></div></form></div>
    <div class="card-body table-wrap"><table class="table table-compact">
        <thead><tr><th>Date</th><th>Drug</th><th>Batch</th><th>Location</th><th>Entry</th><th class="num">Qty</th><th class="num">Balance</th><th>Patient</th><th>Issued by</th><th>Witness</th></tr></thead><tbody>
        <?php foreach ($rows as $r): ?><tr><td class="small nowrap"><?= e(fmt_datetime($r['occurred_at'])) ?></td><td><?= e($r['item_name']) ?></td><td class="small"><?= e($r['batch_no']) ?></td>
            <td class="small"><?= e($r['location_name']) ?></td><td class="small"><?= e(str_replace('_', ' ', $r['entry_type'])) ?></td>
            <td class="num <?= (float) $r['qty'] < 0 ? 'text-danger' : '' ?>"><?= e(num($r['qty'])) ?></td><td class="num"><strong><?= e(num($r['balance_after'])) ?></strong></td>
            <td class="small"><?= e($r['patient_name']) ?><?= $r['mrn'] ? '<div class="muted">' . e($r['mrn']) . '</div>' : '' ?></td><td class="small"><?= e($r['issued_by_name']) ?></td>
            <td class="small"><?= $r['witness_name'] ? e($r['witness_name']) : '<span class="text-danger">—</span>' ?></td></tr><?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="10" class="muted">No controlled-drug movements.</td></tr><?php endif; ?>
    </tbody></table></div>
</div>
