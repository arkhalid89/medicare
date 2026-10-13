<?php
/** @var array $rows @var bool $approver */
use App\Core\Auth;
use App\Core\Scope;
use App\Core\View;

echo View::partial('page_header', [
    'title'       => 'Leave & Unavailability',
    'description' => 'Doctors request leave; the Department Head approves. Emergency unavailability lists the booked patients to call back.',
    'breadcrumbs' => [['Appointments', url('appointments')], ['Leave']],
]);
?>
<div class="grid grid-main">
    <div class="card card-flush"><div class="card-header"><div class="card-title"><?= icon('calendar') ?> Requests</div></div>
        <div class="card-body table-wrap"><table class="table"><thead><tr><th>Doctor</th><th>From</th><th>To</th><th>Reason</th><th>Status</th><th></th></tr></thead><tbody>
            <?php foreach ($rows as $r): $own = (int) $r['doctor_id'] === Auth::id(); ?>
                <tr><td><?= e($r['doctor_name']) ?></td><td class="small"><?= e(fmt_datetime($r['start_at'])) ?></td><td class="small"><?= e(fmt_datetime($r['end_at'])) ?></td>
                    <td class="small"><?= $own || $approver ? e($r['reason']) : '<span class="muted">—</span>' ?></td><td><?= status_chip($r['status']) ?><?= $r['approved_by_name'] ? '<div class="muted small">' . e($r['approved_by_name']) . '</div>' : '' ?></td>
                    <td><?php if ($approver && $r['status'] === 'pending' && !$own): ?>
                        <form method="post" action="<?= e(url('appointments/leave-decide')) ?>" class="flex"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                            <button class="btn btn-sm btn-success" name="decision" value="approve">Approve</button><button class="btn btn-sm btn-danger" name="decision" value="reject">Reject</button></form>
                    <?php endif; ?></td></tr>
                <?php if ($r['affected']): ?>
                    <tr><td colspan="6"><div class="alert alert-warning mb-0"><?= icon('phone') ?><div><strong>Call back <?= count($r['affected']) ?> booked patient(s):</strong>
                        <?php foreach ($r['affected'] as $a): ?><div><?= e(fmt_datetime($a['scheduled_at'])) ?> — <?= e($a['patient_name']) ?> (<?= e($a['mrn']) ?>) · <?= e($a['phone']) ?></div><?php endforeach; ?></div></div></td></tr>
                <?php endif; ?>
            <?php endforeach; ?>
            <?php if (!$rows): ?><tr><td colspan="6" class="muted">No leave requests.</td></tr><?php endif; ?>
        </tbody></table></div></div>
    <form class="card" method="post" action="<?= e(url('appointments/leave-request')) ?>">
        <?= csrf_field() ?>
        <div class="card-header"><div class="card-title"><?= icon('plus') ?> Request leave</div></div>
        <div class="card-body"><div class="form-grid">
            <?php if ($approver): ?><div class="field col-12"><label>Doctor</label><select class="input" name="doctor_id"><?php foreach (Scope::allDoctors() as $id => $n): ?><option value="<?= $id ?>"<?= selected($id, Auth::id()) ?>><?= e($n) ?></option><?php endforeach; ?></select></div><?php endif; ?>
            <div class="field col-6"><label>From</label><input class="input" type="datetime-local" name="start_at" required></div>
            <div class="field col-6"><label>To</label><input class="input" type="datetime-local" name="end_at" required></div>
            <div class="field col-12"><label>Reason (private)</label><input class="input" name="reason" maxlength="255"></div>
            <?php if ($approver): ?><div class="field col-12"><label class="check"><input type="checkbox" name="urgent" value="1"> Emergency — approve now</label></div><?php endif; ?>
        </div></div>
        <div class="card-footer"><button class="btn btn-primary">Submit</button></div>
    </form>
</div>
