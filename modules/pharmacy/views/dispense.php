<?php
/** @var array $d */
use App\Core\Auth;
use App\Core\View;

echo View::partial('page_header', [
    'title' => 'Dispense — ' . $d['patient_name'], 'description' => ($d['visit_no'] ?? '') . ' · Dr. ' . ltrim((string) $d['doctor_name'], 'Dr. ') . ' · ' . $d['location_name'],
    'breadcrumbs' => [['Pharmacy', url('pharmacy')], [$d['visit_no'] ?? 'Dispense']],
    'actions' => $d['visit_id'] && can('visits.print') ? '<a class="btn btn-outline" target="_blank" href="' . e(url('visits/print', ['id' => $d['visit_id']])) . '">' . icon('printer') . ' Prescription</a>' : '',
]);
$open = !in_array($d['status'], ['dispensed', 'cancelled'], true);
$major = array_filter($d['interactions'], static fn ($i) => in_array($i['severity'], ['major', 'contraindicated'], true));
?>
<div class="patient-banner mb-2">
    <div class="pb-main"><strong><?= e($d['patient_name']) ?></strong> <?= $d['token_label'] ? '<span class="tok-label">' . e($d['token_label']) . '</span>' : '' ?></div>
    <div class="pb-meta"><span class="badge badge-primary">MRN <?= e($d['mrn']) ?></span><span><?= e($d['gender']) ?> · <?= e(age_text($d['dob'])) ?></span><span><?= status_chip($d['status']) ?></span>
        <?php if ($d['allergies']): ?><span class="badge badge-danger"><?= icon('alert') ?> Allergy: <?= e($d['allergies']) ?></span><?php endif; ?></div>
</div>
<?php foreach ($d['interactions'] as $i): ?>
    <div class="alert <?= in_array($i['severity'], ['major', 'contraindicated'], true) ? 'alert-danger' : 'alert-warning' ?>"><?= icon('alert') ?><div><strong><?= e(ucfirst($i['severity'])) ?> interaction:</strong> <?= e($i['generic_a']) ?> + <?= e($i['generic_b']) ?> — <?= e($i['description']) ?></div></div>
<?php endforeach; ?>
<form class="card card-flush" method="post" action="<?= e(url('pharmacy/dispense', ['id' => $d['id']])) ?>">
    <?= csrf_field() ?>
    <div class="card-body table-wrap"><table class="table">
        <thead><tr><th>Medicine</th><th>Dose / duration</th><th class="num">Prescribed</th><th class="num">Given</th><th class="num">In stock</th><th>FEFO batches</th><th>Give now</th><th class="num">Price</th></tr></thead><tbody>
        <?php foreach ($d['lines'] as $l): ?>
            <tr>
                <td><strong><?= e($l['medicine_name']) ?></strong> <?= e($l['strength']) ?>
                    <?= (int) $l['is_controlled'] ? '<span class="badge badge-danger">Controlled</span>' : '' ?><?= (int) $l['is_high_alert'] ? ' <span class="badge badge-warning">High alert</span>' : '' ?><?= (int) $l['is_lasa'] ? ' <span class="badge badge-info">LASA</span>' : '' ?>
                    <div class="muted small"><?= e($l['generic_name']) ?></div>
                    <?php if ($l['allergy']): ?><div class="text-danger small"><strong><?= icon('alert') ?> <?= e($l['allergy']) ?></strong>
                        <?php if ($open && $l['remaining'] > 0): ?><label class="check"><input type="checkbox" name="give[<?= (int) $l['id'] ?>][ack_allergy]" value="1"> Doctor confirmed — give anyway</label><?php endif; ?></div><?php endif; ?></td>
                <td class="small"><?= e(num($l['dose'])) ?> <?= e($l['dose_unit']) ?> · <?= e($l['frequency_name']) ?><?= $l['duration_days'] ? ' · ' . (int) $l['duration_days'] . ' days' : '' ?><?= $l['instructions'] ? '<div class="muted">' . e($l['instructions']) . '</div>' : '' ?></td>
                <td class="num"><?= e(num($l['quantity'])) ?> <span class="muted small"><?= e($l['unit']) ?></span></td>
                <td class="num"><?= e(num($l['given'])) ?></td>
                <td class="num <?= $l['available'] < $l['remaining'] ? 'text-danger' : '' ?>"><?= e(num($l['available'])) ?></td>
                <td class="small"><?php foreach ($l['plan'] as $p): ?><div><?= e($p['batch_no'] ?: '—') ?> · exp <?= e(fmt_date($p['expiry_date'])) ?> × <?= e(num($p['qty'])) ?></div><?php endforeach; ?></td>
                <td><?php if ($open && $l['remaining'] > 0): ?>
                    <input class="input input-sm" type="number" step="0.001" min="0" max="<?= e((string) $l['remaining']) ?>" name="give[<?= (int) $l['id'] ?>][qty]"
                           value="<?= e(num(min($l['remaining'], $l['available']))) ?>" style="width:100px">
                    <?php if ($l['substitutes']): ?><select class="input input-sm mt-1" name="give[<?= (int) $l['id'] ?>][substitute_medicine_id]"><option value="">Same medicine</option>
                        <?php foreach ($l['substitutes'] as $s): ?><option value="<?= $s['medicine_id'] ?>">Substitute: <?= e($s['name']) ?> (<?= e(num($s['available'])) ?>)</option><?php endforeach; ?></select><?php endif; ?>
                    <?php elseif ($l['remaining'] <= 0): ?><span class="badge badge-success">Done</span><?php endif; ?></td>
                <td class="num"><?= e(money($l['unit_price'])) ?></td>
            </tr>
        <?php endforeach; ?>
    </tbody></table></div>
    <?php if ($open && $d['status'] !== 'awaiting_cosign'): ?>
    <div class="card-footer">
        <label class="check"><input type="checkbox" name="counselled" value="1"> Patient counselled (dose, timing, side effects)</label>
        <?php if ($major): ?><label class="check text-danger"><input type="checkbox" name="ack_major" value="1"> I reviewed the major interaction with the prescriber</label><?php endif; ?>
        <button class="btn btn-gold"><?= icon('check') ?> Dispense</button>
    </div>
    <?php endif; ?>
</form>
<?php if ($d['status'] === 'awaiting_cosign'): ?>
<div class="card"><div class="card-body flex"><?= icon('shield') ?> <strong>Controlled drugs are waiting for a second pharmacist's co-sign.</strong>
    <?php if (can('pharmacy.dispense.finalize') && (int) $d['dispensed_by'] !== Auth::id()): ?>
        <form method="post" action="<?= e(url('pharmacy/dispense', ['id' => $d['id']])) ?>" data-confirm="Co-sign and release the controlled drug(s)? You are recorded as the witness in the register." data-confirm-danger="false">
            <?= csrf_field() ?><input type="hidden" name="do" value="cosign"><button class="btn btn-primary"><?= icon('shield') ?> Co-sign &amp; release</button></form>
    <?php else: ?><span class="muted">Another pharmacist must co-sign.</span><?php endif; ?></div></div>
<?php endif; ?>
<?php if ($d['items']): ?>
<div class="card card-flush"><div class="card-header"><div class="card-title"><?= icon('list') ?> Given so far</div></div><div class="card-body"><table class="table table-compact">
    <thead><tr><th>Item</th><th>Batch</th><th>Expiry</th><th class="num">Qty</th><th class="num">Amount</th><th>Status</th></tr></thead><tbody>
    <?php foreach ($d['items'] as $i): ?><tr><td><?= e($i['item_name']) ?><?= $i['substituted_for_item_id'] ? ' <span class="badge badge-info">Substitute</span>' : '' ?></td><td><?= e($i['batch_no']) ?></td>
        <td><?= e(fmt_date($i['expiry_date'])) ?></td><td class="num"><?= e(num($i['qty'])) ?></td><td class="num"><?= e(money($i['amount'])) ?></td>
        <td><?= $i['ledger_id'] ? '<span class="badge badge-success">Given</span>' : '<span class="badge badge-warning">Awaiting co-sign</span>' ?></td></tr><?php endforeach; ?>
</tbody></table></div></div>
<?php endif; ?>
