<?php
/** @var array $rows @var string $q @var string $status @var string $from @var string $to */
use App\Core\View;

echo View::partial('page_header', [
    'title' => 'Lab Orders', 'description' => 'Search by patient, MRN, order number or sample barcode.',
    'breadcrumbs' => [['Laboratory'], ['Orders']],
    'actions' => '<a class="btn btn-excel" href="' . e(url_with(['export' => 'xlsx'])) . '">' . icon('excel') . ' Export</a>',
]);
?>
<div class="card card-flush">
    <div class="card-header"><form class="filters" method="get" action="<?= e(form_action('lab/orders')) ?>"><?= route_field('lab/orders') ?>
        <div class="field grow"><label>Search</label><input class="input" name="q" value="<?= e($q) ?>" placeholder="Patient, MRN, LO-…, barcode"></div>
        <div class="field"><label>Status</label><select class="input" name="status"><option value="">All</option>
            <?php foreach (['ordered', 'in_progress', 'completed', 'cancelled'] as $s): ?><option value="<?= $s ?>"<?= selected($s, $status) ?>><?= e(ucwords(str_replace('_', ' ', $s))) ?></option><?php endforeach; ?></select></div>
        <div class="field"><label>From</label><input class="input" type="date" name="from" value="<?= e($from) ?>"></div>
        <div class="field"><label>To</label><input class="input" type="date" name="to" value="<?= e($to) ?>"></div>
        <div class="btns"><button class="btn btn-primary"><?= icon('search') ?> Filter</button></div></form></div>
    <div class="card-body table-wrap"><table class="table">
        <thead><tr><th>Order</th><th>Patient</th><th>Tests</th><th>Ordered by</th><th>Status</th><th>Bill</th><th></th></tr></thead><tbody>
        <?php foreach ($rows as $r): ?><tr>
            <td><a href="<?= e(url('lab/order', ['id' => $r['id']])) ?>"><strong><?= e($r['order_number']) ?></strong></a><div class="muted small"><?= e(fmt_datetime($r['ordered_at'])) ?></div></td>
            <td><?= e($r['patient_name']) ?><div class="muted small"><?= e($r['mrn']) ?></div></td><td class="small"><?= e($r['tests']) ?></td>
            <td class="small"><?= e($r['doctor_name'] ?: ($r['referring_doctor'] ?: ucfirst(str_replace('_', ' ', $r['source'])))) ?></td>
            <td><?= status_chip($r['status']) ?></td><td><?= $r['bill_status'] ? status_chip($r['bill_status']) : '' ?></td>
            <td><?php if ($r['status'] === 'completed'): ?><a class="btn btn-sm btn-outline" target="_blank" href="<?= e(url('lab/report', ['id' => $r['id']])) ?>"><?= icon('printer') ?></a><?php endif; ?></td></tr><?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="7" class="muted">No orders found.</td></tr><?php endif; ?>
    </tbody></table></div>
</div>
