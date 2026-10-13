<?php
declare(strict_types=1);

namespace App\Modules\Specialty;

use App\Core\DB;
use App\Core\Migrator;

/**
 * Tier 1 fields + light specialty tables on the one-page consultation.
 * What is shown AND what is accepted depends on the encounter's department
 * specialty — data for another specialty is ignored server-side.
 */
final class SpecialtyHooks
{
    /** module keys that have a light first-class form on the consult screen */
    public const FORMS = ['ophthalmology' => 'eye', 'pediatrics' => 'growth', 'cardiology' => 'cardiac', 'ent' => 'audiogram', 'dermatology' => 'bodymap', 'orthopedics' => 'bodymap'];

    public static function screen(array $ctx): array
    {
        if (!Migrator::tableExists('clinical_parameter_templates')) {
            return [];
        }
        $deptMap = [];
        $templates = [];
        foreach (SpecialtyContext::departments() as $id => $d) {
            $deptMap[$id] = ['specialty_id' => $d['specialty_id'], 'module' => $d['module_key'], 'name' => $d['specialty']];
            if ($d['specialty_id'] && !isset($templates[$d['specialty_id']])) {
                $templates[$d['specialty_id']] = array_map(static fn ($f) => ['id' => (int) $f['id'], 'label' => $f['label'], 'type' => $f['field_type'],
                    'options' => $f['options'], 'unit' => $f['unit'], 'required' => (bool) $f['is_required']], SpecialtyService::templates((int) $d['specialty_id']));
            }
        }
        return ['specialty' => [
            'view' => 'specialty/_consult', 'scripts' => ['js/specialty.js'],
            'deptMap' => $deptMap, 'templates' => $templates, 'forms' => self::FORMS,
            'bodyMaps' => SpecialtyService::bodyMaps(), 'findingTypes' => SpecialtyService::FINDING_TYPES, 'snellen' => SpecialtyService::SNELLEN,
            'cardiacTypes' => SpecialtyService::CARDIAC_TYPES, 'audioFreqs' => SpecialtyService::AUDIO_FREQS,
            'departments' => DB::pairs("SELECT id, name FROM departments WHERE deleted_at IS NULL AND is_active = 1 ORDER BY display_order, name"),
            'canRefer' => can('clinical.referral.create'), 'canNote' => can('clinical.notes.write') || can('visits.create'),
        ]];
    }

    public static function validate(array $payload, int $doctorId, int $patientId): array
    {
        if (!Migrator::tableExists('clinical_parameter_templates')) {
            return [];
        }
        $spec = SpecialtyContext::forDepartment((int) ($payload['department_id'] ?? 0));
        $m = (array) ($payload['modules']['specialty'] ?? []);
        $errors = $spec['specialty_id'] ? SpecialtyService::validateParameters((int) $spec['specialty_id'], (array) ($m['params'] ?? [])) : [];
        $ref = (array) ($m['referral'] ?? []);
        if (!empty($ref['reason']) && empty($ref['to_department_id']) && empty($ref['external_to'])) {
            $errors[] = 'Referral: choose the department or the outside facility.';
        }
        return $errors;
    }

    public static function afterCheckout(int $visitId, array $payload, array $ctx): void
    {
        if (!Migrator::tableExists('clinical_parameter_templates')) {
            return;
        }
        $m = (array) ($payload['modules']['specialty'] ?? []);
        if (!$m) {
            return;
        }
        $pid = (int) $ctx['patient_id'];
        $spec = SpecialtyContext::forDepartment((int) DB::value('SELECT department_id FROM visits WHERE id = ?', [$visitId]));
        if ($spec['specialty_id'] && !empty($m['params'])) {
            SpecialtyService::saveParameters($visitId, (int) $spec['specialty_id'], (array) $m['params']);
        }
        $form = self::FORMS[$spec['module_key']] ?? null;
        match ($form) {
            'eye' => SpecialtyService::saveEye($visitId, $pid, (array) ($m['eye'] ?? [])),
            'growth' => SpecialtyService::saveGrowth($pid, $visitId, (array) ($m['growth'] ?? [])),
            'cardiac' => SpecialtyService::saveCardiac($visitId, $pid, (array) ($m['cardiac'] ?? [])),
            'audiogram' => SpecialtyService::saveAudiogram($visitId, $pid, (array) ($m['audiogram'] ?? [])),
            'bodymap' => SpecialtyService::saveBodyMap($visitId, $pid, (array) ($m['bodymap'] ?? [])),
            default => null,
        };
        $note = (array) ($m['note'] ?? []);
        if (trim((string) ($note['text'] ?? '')) !== '' && (can('clinical.notes.write') || can('visits.create'))) {
            // Psychiatry and reproductive-health notes default to restricted (Doc 1 §6, Doc 8 §3.3).
            $restricted = !empty($note['restricted']) || in_array($spec['module_key'], ['psychiatry', 'obgyn'], true);
            SpecialtyService::addNote($pid, $visitId, (string) $note['text'], $restricted, 'consultation');
        }
        $ref = (array) ($m['referral'] ?? []);
        if (trim((string) ($ref['reason'] ?? '')) !== '' && can('clinical.referral.create')) {
            SpecialtyService::refer($ref + ['patient_id' => $pid, 'encounter_id' => $visitId, 'from_doctor_id' => $ctx['doctor_id'], 'source' => 'consultation']);
        }
    }

    public static function panel(array $visit): string
    {
        if (!Migrator::tableExists('clinical_parameter_templates')) {
            return '';
        }
        $id = (int) $visit['id'];
        $rows = [];
        foreach (SpecialtyService::parameters($id) as $p) {
            $rows[] = '<tr><td>' . e($p['label']) . '</td><td><strong>' . e($p['value']) . '</strong> ' . e($p['unit']) . '</td></tr>';
        }
        if ($eye = DB::one('SELECT * FROM eye_exams WHERE encounter_id = ?', [$id])) {
            $rows[] = '<tr><td>Visual acuity (OD / OS)</td><td>' . e(($eye['va_od_unaided'] ?: '—') . ' / ' . ($eye['va_os_unaided'] ?: '—'))
                . ($eye['va_od_corrected'] || $eye['va_os_corrected'] ? ' · corrected ' . e(($eye['va_od_corrected'] ?: '—') . ' / ' . ($eye['va_os_corrected'] ?: '—')) : '') . '</td></tr>';
            if ($eye['iop_od_mmhg'] !== null || $eye['iop_os_mmhg'] !== null) {
                $rows[] = '<tr><td>IOP (OD / OS)</td><td>' . e(num($eye['iop_od_mmhg']) . ' / ' . num($eye['iop_os_mmhg'])) . ' mmHg</td></tr>';
            }
            if ($eye['fundus_findings']) {
                $rows[] = '<tr><td>Fundus</td><td>' . e($eye['fundus_findings']) . '</td></tr>';
            }
        }
        if ($g = DB::one('SELECT * FROM growth_measurements WHERE encounter_id = ?', [$id])) {
            $pc = json_decode((string) $g['percentiles_json'], true) ?: [];
            $rows[] = '<tr><td>Growth</td><td>' . ($g['weight_kg'] !== null ? e(num($g['weight_kg'])) . ' kg' . (isset($pc['weight_pct']) ? ' (P' . e($pc['weight_pct']) . ')' : '') : '')
                . ($g['height_cm'] !== null ? ' · ' . e(num($g['height_cm'])) . ' cm' . (isset($pc['height_pct']) ? ' (P' . e($pc['height_pct']) . ')' : '') : '')
                . ($g['head_circumference_cm'] !== null ? ' · HC ' . e(num($g['head_circumference_cm'])) . ' cm' : '') . '</td></tr>';
        }
        foreach (DB::all('SELECT * FROM cardiac_investigations WHERE encounter_id = ?', [$id]) as $c) {
            $rows[] = '<tr><td>' . e(SpecialtyService::CARDIAC_TYPES[$c['type']] ?? $c['type']) . '</td><td>' . e(trim(($c['rhythm'] ?: '') . ($c['ef_percent'] !== null ? ' · EF ' . num($c['ef_percent']) . '%' : '') . ($c['findings'] ? ' · ' . $c['findings'] : ''), ' ·')) . '</td></tr>';
        }
        foreach (DB::all('SELECT * FROM audiograms WHERE encounter_id = ? ORDER BY ear', [$id]) as $a) {
            $rows[] = '<tr><td>Audiogram ' . ($a['ear'] === 'L' ? 'left' : 'right') . '</td><td>PTA ' . e(num($a['pta_db'])) . ' dB' . ($a['tympanometry_type'] ? ' · tymp ' . e($a['tympanometry_type']) : '') . '</td></tr>';
        }
        foreach (DB::all('SELECT f.*, r.label FROM encounter_body_map_findings f JOIN body_map_regions r ON r.id = f.region_id WHERE f.encounter_id = ?', [$id]) as $b) {
            $rows[] = '<tr><td>' . e($b['label']) . '</td><td>' . e($b['finding_type']) . ($b['severity'] ? ' · ' . e($b['severity']) : '') . ($b['size_cm'] !== null ? ' · ' . e(num($b['size_cm'])) . ' cm' : '')
                . ($b['description'] ? ' — ' . e($b['description']) : '') . '</td></tr>';
        }
        foreach (DB::all('SELECT r.*, d.name AS dept FROM referrals r LEFT JOIN departments d ON d.id = r.to_department_id WHERE r.encounter_id = ?', [$id]) as $r) {
            $rows[] = '<tr><td>Referral (' . e($r['urgency']) . ')</td><td>To ' . e($r['dept'] ?: $r['external_to']) . ' — ' . e($r['reason']) . ' ' . status_chip($r['status']) . '</td></tr>';
        }
        $notes = '';
        foreach (DB::all('SELECT * FROM clinical_notes WHERE encounter_id = ? ORDER BY id', [$id]) as $n) {
            $notes .= '<div class="mt-1"><span class="muted small">Note' . ($n['is_restricted'] ? ' ' . icon('lock') . ' restricted' : '') . ':</span> '
                . (!$n['is_restricted'] || SpecialtyContext::canSeeRestricted($n) ? nl2br(e($n['note_text'])) : '<em class="muted">Restricted — visible to the treating team only.</em>') . '</div>';
        }
        if (!$rows && !$notes) {
            return '';
        }
        return '<div class="card mt-2 module-card"><div class="card-header"><div class="card-title">' . icon('eye') . ' Specialty findings</div></div><div class="card-body">'
            . ($rows ? '<table class="table table-compact"><tbody>' . implode('', $rows) . '</tbody></table>' : '') . $notes . '</div></div>';
    }
}
