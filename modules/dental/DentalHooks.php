<?php
declare(strict_types=1);

namespace App\Modules\Dental;

use App\Core\DB;
use App\Core\Migrator;
use App\Modules\Specialty\SpecialtyContext;

/** Tooth chart on the consultation — only for encounters of a Dentistry department (Doc 8 §7 #1). */
final class DentalHooks
{
    public static function screen(array $ctx): array
    {
        if (!Migrator::tableExists('tooth_reference') || !can('dental.chart.view') || !SpecialtyContext::departmentsWith('dental')) {
            return [];
        }
        return ['dental' => [
            'view' => 'dental/_consult', 'scripts' => ['js/dental.js'],
            'teeth' => array_values(DentalService::teeth()), 'conditions' => DentalService::CONDITIONS,
            'procedures' => array_values(array_map(static fn ($p) => ['id' => (int) $p['id'], 'code' => $p['code'], 'name' => $p['name'], 'price' => (float) $p['price'],
                'requires_tooth' => (bool) $p['requires_tooth'], 'requires_surface' => (bool) $p['requires_surface'], 'can_complete' => DentalService::mayComplete($p)], DentalService::procedures())),
            'canEdit' => can('dental.chart.edit'), 'stateUrl' => url('dental/state'),
        ]];
    }

    public static function validate(array $payload, int $doctorId, int $patientId): array
    {
        $d = (array) ($payload['modules']['dental'] ?? []);
        if (!$d || !Migrator::tableExists('tooth_reference')) {
            return [];
        }
        if (SpecialtyContext::moduleOf((int) ($payload['department_id'] ?? 0)) !== 'dental') {
            return !empty($d['findings']) ? ['The tooth chart is available only for encounters of a Dentistry department.'] : [];
        }
        return DentalService::validate($d);
    }

    public static function afterCheckout(int $visitId, array $payload, array $ctx): void
    {
        $d = (array) ($payload['modules']['dental'] ?? []);
        if (!$d || empty($d['findings']) && trim((string) ($d['notes'] ?? '')) === '' || !Migrator::tableExists('tooth_reference')) {
            return;
        }
        $dept = (int) DB::value('SELECT department_id FROM visits WHERE id = ?', [$visitId]);
        if (SpecialtyContext::moduleOf($dept) !== 'dental') {
            return;
        }
        DentalService::saveChart($visitId, (int) $ctx['patient_id'], $d, $ctx + ['department_id' => $dept]);
    }

    public static function panel(array $visit): string
    {
        if (!Migrator::tableExists('dental_charts')) {
            return '';
        }
        $chart = DB::one('SELECT * FROM dental_charts WHERE encounter_id = ?', [$visit['id']]);
        if (!$chart) {
            return '';
        }
        $rows = '';
        foreach (DentalService::chartFindings((int) $chart['id']) as $f) {
            $rows .= '<tr><td><strong>' . e($f['tooth_number']) . '</strong></td><td>' . e(DentalService::CONDITIONS[$f['condition']] ?? $f['condition'])
                . ($f['surfaces'] ? ' <span class="muted">(' . e(implode('', array_map(static fn ($s) => strtoupper($s[0]), $f['surfaces']))) . ')</span>' : '') . '</td>'
                . '<td>' . e($f['procedure_name'] ?: '—') . '</td><td>' . ($f['procedure_status'] !== 'n_a' ? status_chip($f['procedure_status']) : '') . '</td>'
                . '<td class="small">' . ($f['cosigned_by_name'] ? 'Co-signed: ' . e($f['cosigned_by_name']) : '') . '</td></tr>';
        }
        $link = can('dental.chart.view') ? '<a class="btn btn-sm btn-outline" href="' . e(url('dental', ['patient_id' => $chart['patient_id']])) . '">Dental record</a>' : '';
        return '<div class="card mt-2 module-card"><div class="card-header"><div class="card-title">' . icon('tooth') . ' Dental chart — ' . e(ucfirst($chart['dentition_type'])) . '</div>' . $link . '</div>'
            . '<div class="card-body">' . ($chart['general_notes'] ? '<p>' . e($chart['general_notes']) . '</p>' : '')
            . '<table class="table table-compact"><thead><tr><th>Tooth</th><th>Condition</th><th>Procedure</th><th>Status</th><th></th></tr></thead><tbody>' . $rows . '</tbody></table></div></div>';
    }
}
