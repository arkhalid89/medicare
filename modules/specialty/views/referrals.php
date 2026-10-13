<?php
/** @var array $inbox @var array $sent */
use App\Core\View;

echo View::partial('page_header', [
    'title' => 'Referrals', 'description' => 'Patients referred to your department or to you, and referrals you sent.',
    'breadcrumbs' => [['Clinical'], ['Referrals']],
]);
$row = static function (array $r, bool $inbox): string {
    $h = '<tr><td class="small nowrap">' . e(fmt_datetime($r['created_at'])) . '</td><td><a href="' . e(url('specialty', ['patient_id' => $r['patient_id']])) . '"><strong>' . e($r['patient_name']) . '</strong></a><div class="muted small">' . e($r['mrn']) . '</div></td>'
        . '<td>' . ($inbox ? 'From ' . e($r['from_name']) : 'To ' . e($r['to_name'] ?: ($r['dept'] ?: $r['external_to']))) . ($r['visit_no'] ? '<div class="muted small">' . e($r['visit_no']) . '</div>' : '') . '</td>'
        . '<td>' . ($r['urgency'] !== 'routine' ? '<span class="badge badge-danger">' . e($r['urgency']) . '</span> ' : '') . e($r['reason']) . '</td><td>' . status_chip($r['status']) . '</td><td class="nowrap">';
    if ($inbox && in_array($r['status'], ['open', 'accepted'], true)) {
        foreach ($r['status'] === 'open' ? ['accepted' => 'Accept', 'declined' => 'Decline'] : ['completed' => 'Complete'] as $s => $l) {
            $h .= '<form method="post" action="' . e(url('clinical/referrals')) . '" style="display:inline">' . csrf_field() . '<input type="hidden" name="id" value="' . (int) $r['id'] . '"><input type="hidden" name="status" value="' . $s . '"><button class="btn btn-sm ' . ($s === 'declined' ? 'btn-ghost' : 'btn-outline') . '">' . $l . '</button></form> ';
        }
    }
    return $h . '</td></tr>';
};
?>
<div class="card card-flush"><div class="card-header"><div class="card-title">Received</div></div><div class="card-body table-wrap"><table class="table"><tbody>
    <?= implode('', array_map(static fn ($r) => $row($r, true), $inbox)) ?: '<tr><td class="muted">No referrals.</td></tr>' ?>
</tbody></table></div></div>
<div class="card card-flush"><div class="card-header"><div class="card-title">Sent</div></div><div class="card-body table-wrap"><table class="table"><tbody>
    <?= implode('', array_map(static fn ($r) => $row($r, false), $sent)) ?: '<tr><td class="muted">None.</td></tr>' ?>
</tbody></table></div></div>
