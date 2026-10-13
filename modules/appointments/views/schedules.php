<?php
/** @var int $doctorId @var array $doctors @var array $rows */
use App\Core\View;
use App\Modules\Appointments\AppointmentService;

echo View::partial('page_header', [
    'title'       => 'Doctor Schedule',
    'description' => 'Weekly OPD sessions. Slots for booking are generated from these sessions.',
    'breadcrumbs' => [['Appointments', url('appointments')], ['Schedule']],
]);
?>
<?php if (count($doctors) > 1): ?>
<form class="card" method="get" action="<?= e(form_action('appointments/schedules')) ?>"><div class="card-body"><div class="filters">
    <?= route_field('appointments/schedules') ?>
    <div class="field grow"><label>Doctor</label><select class="input" name="doctor_id" onchange="this.form.submit()"><?php foreach ($doctors as $id => $n): ?><option value="<?= $id ?>"<?= selected($id, $doctorId) ?>><?= e($n) ?></option><?php endforeach; ?></select></div>
</div></div></form>
<?php endif; ?>
<div class="grid grid-main">
    <div class="card card-flush"><div class="card-header"><div class="card-title"><?= icon('clock') ?> Sessions</div></div>
        <div class="card-body"><table class="table"><thead><tr><th>Day</th><th>From</th><th>To</th><th>Slot</th><th></th></tr></thead><tbody>
            <?php foreach ($rows as $r): ?>
                <tr><td><?= e(AppointmentService::DAYS[(int) $r['day_of_week']]) ?></td><td><?= e(substr($r['start_time'], 0, 5)) ?></td><td><?= e(substr($r['end_time'], 0, 5)) ?></td><td><?= (int) $r['slot_minutes'] ?> min</td>
                    <td><form method="post" action="<?= e(url('appointments/schedule-save')) ?>" data-confirm="Remove this session?"><?= csrf_field() ?><input type="hidden" name="doctor_id" value="<?= $doctorId ?>"><input type="hidden" name="delete" value="<?= (int) $r['id'] ?>"><button class="act danger"><?= icon('trash') ?></button></form></td></tr>
            <?php endforeach; ?>
            <?php if (!$rows): ?><tr><td colspan="5" class="muted">No sessions published.</td></tr><?php endif; ?>
        </tbody></table></div></div>
    <form class="card" method="post" action="<?= e(url('appointments/schedule-save')) ?>">
        <?= csrf_field() ?><input type="hidden" name="doctor_id" value="<?= $doctorId ?>">
        <div class="card-header"><div class="card-title"><?= icon('plus') ?> Add session</div></div>
        <div class="card-body">
            <div class="check-list mb-2"><?php foreach (AppointmentService::DAYS as $d => $n): ?><label class="check"><input type="checkbox" name="days[]" value="<?= $d ?>"<?= $d >= 1 && $d <= 6 ? ' checked' : '' ?>> <?= e(substr($n, 0, 3)) ?></label><?php endforeach; ?></div>
            <div class="form-grid">
                <div class="field col-4"><label>From</label><input class="input" type="time" name="start_time" value="09:00" required></div>
                <div class="field col-4"><label>To</label><input class="input" type="time" name="end_time" value="13:00" required></div>
                <div class="field col-4"><label>Slot (min)</label><input class="input" type="number" min="5" max="120" name="slot_minutes" value="15"></div>
            </div>
        </div>
        <div class="card-footer"><button class="btn btn-primary"><?= icon('save') ?> Add</button></div>
    </form>
</div>
