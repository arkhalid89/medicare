<?php
/** @var array $pending @var array $rooms @var array $slots @var string $date @var array $procedures */
use App\Core\View;
use App\Modules\Radiology\RadiologyService;

echo View::partial('page_header', [
    'title' => 'Radiology Reception', 'description' => 'Safety questionnaire (pregnancy for ionizing studies, allergy + creatinine for contrast) is mandatory before booking. Prep instructions are shown with each study.',
    'breadcrumbs' => [['Radiology'], ['Reception']],
    'actions' => '<a class="btn btn-outline" href="' . e(url('radiology/orders')) . '">' . icon('list') . ' All orders</a>',
]);
$roomOpts = static function (array $rooms, int $modality): string {
    $h = '';
    foreach ($rooms as $r) {
        if ((int) $r['modality_id'] === $modality) {
            $h .= '<option value="' . (int) $r['id'] . '">' . e($r['name']) . '</option>';
        }
    }
    return $h;
};
?>
<details class="card"<?= $pending ? '' : ' open' ?>><summary class="card-header"><span class="card-title"><?= icon('plus') ?> New walk-in / referral order</span></summary>
<form class="card-body" method="post" action="<?= e(url('radiology/reception')) ?>" id="rad-order"><?= csrf_field() ?><input type="hidden" name="do" value="order"><input type="hidden" name="patient_id" id="ro-pid">
    <div class="form-grid">
        <div class="field col-5"><label>Patient</label><div class="search-box"><?= icon('search') ?><input class="input" id="ro-patient" autocomplete="off"><div class="search-results"></div></div></div>
        <div class="field col-2"><label>Priority</label><select class="input" name="priority"><?php foreach (RadiologyService::PRIORITIES as $k => $l): ?><option value="<?= $k ?>"><?= $l ?></option><?php endforeach; ?></select></div>
        <div class="field col-2"><label>Pregnancy</label><select class="input" name="pregnancy_status"><option value="">Ask later</option><option value="not_pregnant">Not pregnant</option><option value="possible">Possibly</option><option value="pregnant">Pregnant</option></select></div>
        <div class="field col-3"><label>Referring doctor</label><input class="input" name="referring_doctor"></div>
        <div class="field col-9"><label>Clinical indication</label><input class="input" name="clinical_indication" maxlength="500"></div>
        <div class="field col-3"><label class="check"><input type="checkbox" name="contrast" value="1"> With contrast (where possible)</label></div>
    </div>
    <div class="grid grid-3 mt-2"><?php $g = []; foreach ($procedures as $p) { $g[$p['modality']][] = $p; } foreach ($g as $m => $list): ?><div><div class="section-title small"><?= e($m) ?></div>
        <?php foreach ($list as $p): ?><label class="check-row"><input type="checkbox" name="procedures[]" value="<?= (int) $p['id'] ?>"><span class="grow"><?= e($p['name']) ?><?= $p['uses_ionizing_radiation'] ? ' <span class="badge badge-warning" title="Ionizing radiation">☢</span>' : '' ?></span><span class="muted small"><?= e(money($p['price'])) ?></span></label><?php endforeach; ?></div><?php endforeach; ?></div>
    <div class="mt-2"><button class="btn btn-primary">Create order &amp; bill</button></div>
</form></details>
<script>APP.liveSearch(document.getElementById('ro-patient'), { minLength: 2,
    fetch: function (q) { return APP.ajax(APP.url('patients/search', { q: q })).then(function (d) { return d.items || []; }); },
    render: function (p) { return '<div class="grow"><div class="t">' + APP.esc(p.name) + '</div><div class="s">' + APP.esc(p.mrn) + ' · ' + APP.esc(p.gender || '') + '</div></div>'; },
    onSelect: function (p) { document.getElementById('ro-pid').value = p.id; document.getElementById('ro-patient').value = p.name + ' (' + p.mrn + ')'; } });</script>

<div class="card card-flush"><div class="card-header"><div class="card-title">Waiting to be screened / booked</div></div><div class="card-body table-wrap"><table class="table">
    <thead><tr><th>Study</th><th>Patient</th><th>Safety</th><th>Booking</th><th></th></tr></thead><tbody>
    <?php foreach ($pending as $o): ?><tr>
        <td><strong><?= e($o['procedure_name']) ?></strong> <?= $o['priority'] !== 'routine' ? '<span class="badge badge-danger">' . e(strtoupper($o['priority'])) . '</span>' : '' ?><?= $o['status'] === 'reprocess_requested' ? ' <span class="badge badge-warning">Repeat</span>' : '' ?>
            <div class="muted small"><?= e($o['order_number']) ?> · <?= e($o['doctor_name'] ?: ($o['referring_doctor'] ?: 'walk-in')) ?></div><?= $o['prep_instructions'] ? '<div class="small">Prep: ' . e($o['prep_instructions']) . '</div>' : '' ?></td>
        <td><?= e($o['patient_name']) ?><div class="muted small"><?= e($o['mrn']) ?> · <?= e($o['gender']) ?>, <?= e(age_text($o['dob'])) ?></div><?= $o['allergies'] ? '<span class="badge badge-danger">Allergy: ' . e($o['allergies']) . '</span>' : '' ?></td>
        <td><?php if ($o['issues']): ?><div class="text-danger small"><?= e(implode(' ', $o['issues'])) ?></div><?php else: ?><span class="badge badge-success">OK</span> <span class="small"><?= e(RadiologyService::PREGNANCY[$o['pregnancy_status']] ?? '') ?></span><?php endif; ?>
            <?php if ($o['pregnancy_status'] !== 'not_applicable' || $o['contrast_required']): ?><details class="small"><summary>Screening</summary>
            <form method="post" action="<?= e(url('radiology/reception')) ?>" class="form-grid"><?= csrf_field() ?><input type="hidden" name="do" value="screen"><input type="hidden" name="order_id" value="<?= (int) $o['id'] ?>">
                <?php if ($o['pregnancy_status'] !== 'not_applicable'): ?><div class="field col-6"><label>Pregnancy</label><select class="input input-sm" name="pregnancy_status"><?php foreach (['not_pregnant', 'possible', 'pregnant'] as $s): ?><option value="<?= $s ?>"<?= selected($s, $o['pregnancy_status']) ?>><?= RadiologyService::PREGNANCY[$s] ?></option><?php endforeach; ?></select></div>
                <div class="field col-6"><label>LMP</label><input class="input input-sm" type="date" name="lmp_date" value="<?= e($o['lmp_date']) ?>"></div>
                <div class="field col-12"><label>Justification if (possibly) pregnant</label><input class="input input-sm" name="justification"></div><?php endif; ?>
                <?php if ($o['contrast_required']): ?><div class="field col-6"><label class="check"><input type="checkbox" name="allergy_checked" value="1"<?= $o['allergy_checked'] ? ' checked' : '' ?>> Contrast allergy asked</label></div>
                <div class="field col-6"><label>Creatinine (mg/dL)</label><input class="input input-sm" type="number" step="0.01" name="creatinine_value" value="<?= e($o['creatinine_value']) ?>"></div><?php endif; ?>
                <div class="col-12"><button class="btn btn-sm btn-outline">Save screening</button></div></form></details><?php endif; ?></td>
        <td><?= $o['scheduled_at'] ? '<strong>' . e(fmt_datetime($o['scheduled_at'])) . '</strong><div class="muted small">' . e($o['room_name']) . '</div>' : '' ?>
            <?php if (!$o['issues']): ?><form method="post" action="<?= e(url('radiology/reception')) ?>" class="flex mt-1"><?= csrf_field() ?><input type="hidden" name="do" value="schedule"><input type="hidden" name="order_id" value="<?= (int) $o['id'] ?>">
                <select class="input input-sm" name="room_id"><?= $roomOpts($rooms, (int) $o['modality_id']) ?></select><input class="input input-sm" type="datetime-local" name="start" required value="<?= e(date('Y-m-d\TH:i', strtotime('+10 minutes'))) ?>"><button class="btn btn-sm btn-primary">Book</button></form><?php endif; ?></td>
        <td><?php if ($o['status'] !== 'reprocess_requested'): ?><form method="post" action="<?= e(url('radiology/reception')) ?>"><?= csrf_field() ?><input type="hidden" name="do" value="cancel"><input type="hidden" name="order_id" value="<?= (int) $o['id'] ?>"><button class="act danger" data-confirm="Cancel this study? The charge is voided / refunded.">Cancel</button></form><?php endif; ?></td></tr><?php endforeach; ?>
    <?php if (!$pending): ?><tr><td colspan="5" class="muted">Nothing waiting.</td></tr><?php endif; ?>
</tbody></table></div></div>

<div class="card card-flush"><div class="card-header"><div class="card-title">Room board</div><form method="get" action="<?= e(form_action('radiology/reception')) ?>" data-autosubmit><?= route_field('radiology/reception') ?><input class="input input-sm" type="date" name="date" value="<?= e($date) ?>"></form></div>
    <div class="card-body"><div class="grid grid-<?= max(1, min(4, count($rooms))) ?>"><?php foreach ($rooms as $r): ?><div><div class="section-title small"><?= e($r['name']) ?></div>
        <?php $any = false; foreach ($slots as $s): if ((int) $s['room_id'] !== (int) $r['id']) { continue; } $any = true; ?><div class="slot-row"><strong><?= e(date('H:i', strtotime($s['slot_start']))) ?></strong>–<?= e(date('H:i', strtotime($s['slot_end']))) ?> <?= e($s['patient_name']) ?> <span class="muted small"><?= e($s['procedure_name']) ?></span> <?= status_chip($s['order_status']) ?></div><?php endforeach; ?>
        <?php if (!$any): ?><p class="muted small">Free</p><?php endif; ?></div><?php endforeach; ?></div></div></div>
