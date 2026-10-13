<?php
declare(strict_types=1);

namespace App\Modules\Obgyn;

use App\Core\DB;
use App\Core\Migrator;
use App\Modules\Specialty\SpecialtyContext;

/** Gynae history, pregnancy and ANC on the consultation — only for Gynecology / Obstetrics departments. */
final class ObgynHooks
{
    public static function screen(array $ctx): array
    {
        if (!Migrator::tableExists('pregnancies') || !can('obgyn.history.view') || !SpecialtyContext::departmentsWith('obgyn')) {
            return [];
        }
        return ['obgyn' => [
            'view' => 'obgyn/_consult', 'scripts' => ['js/obgyn.js'],
            'canEdit' => can('obgyn.history.edit'), 'canPregnancy' => can('obgyn.pregnancy.manage'), 'canAnc' => can('obgyn.anc.record'),
            'contraception' => ObgynService::CONTRACEPTION, 'menopause' => ObgynService::MENOPAUSE,
            'risk' => ['sys' => (int) setting('obgyn_risk_bp_systolic', 140), 'dia' => (int) setting('obgyn_risk_bp_diastolic', 90), 'hb' => (float) setting('obgyn_risk_hb', 10)],
        ]];
    }

    public static function validate(array $payload, int $doctorId, int $patientId): array
    {
        $d = (array) ($payload['modules']['obgyn'] ?? []);
        if (!$d || !Migrator::tableExists('pregnancies')) {
            return [];
        }
        if (SpecialtyContext::moduleOf((int) ($payload['department_id'] ?? 0)) !== 'obgyn') {
            return [];
        }
        $errors = [];
        if (($payload['patient']['gender'] ?? '') !== 'Female' && (!empty($d['anc']) || !empty($d['pregnancy']))) {
            $errors[] = 'Pregnancy and ANC records are for female patients.';
        }
        if (!empty($d['gyn']) && !can('obgyn.history.edit')) {
            $errors[] = 'You may not edit the gynae history.';
        }
        if (!empty($d['pregnancy']) && !can('obgyn.pregnancy.manage')) {
            $errors[] = 'Opening a pregnancy needs the gynecologist.';
        }
        if (!empty($d['pregnancy']) && empty($d['pregnancy']['lmp_date']) && empty($d['pregnancy']['edd_date'])) {
            $errors[] = 'New pregnancy: enter the LMP or the EDD.';
        }
        if (!empty($d['anc']) && !can('obgyn.anc.record')) {
            $errors[] = 'You may not record ANC visits.';
        }
        return $errors;
    }

    public static function afterCheckout(int $visitId, array $payload, array $ctx): void
    {
        $d = (array) ($payload['modules']['obgyn'] ?? []);
        if (!$d || !Migrator::tableExists('pregnancies')) {
            return;
        }
        if (SpecialtyContext::moduleOf((int) DB::value('SELECT department_id FROM visits WHERE id = ?', [$visitId])) !== 'obgyn') {
            return;
        }
        $pid = (int) $ctx['patient_id'];
        if (!empty($d['gyn']) && can('obgyn.history.edit')) {
            ObgynService::saveGynHistory($pid, (array) $d['gyn']);
        }
        $preg = ObgynService::ongoing($pid);
        if (!$preg && !empty($d['pregnancy'])) {
            ObgynService::openPregnancy($pid, (array) $d['pregnancy'], $visitId);
            $preg = ObgynService::ongoing($pid);
        }
        if ($preg && !empty($d['anc']) && can('obgyn.anc.record')) {
            ObgynService::saveAnc((int) $preg['id'], $visitId, (array) $d['anc']);
        }
        if ($preg && Migrator::tableExists('lab_orders')) {
            DB::run('UPDATE lab_orders SET pregnancy_id = ? WHERE visit_id = ? AND pregnancy_id IS NULL', [$preg['id'], $visitId]);
        }
    }

    public static function panel(array $visit): string
    {
        if (!Migrator::tableExists('antenatal_visits') || !can('obgyn.history.view')) {
            return '';
        }
        $a = DB::one('SELECT a.*, p.pregnancy_no, p.edd_date, p.risk_level FROM antenatal_visits a JOIN pregnancies p ON p.id = a.pregnancy_id WHERE a.encounter_id = ?', [$visit['id']]);
        if (!$a) {
            return '';
        }
        $flags = json_decode((string) $a['risk_flags_json'], true) ?: [];
        return '<div class="card mt-2 module-card"><div class="card-header"><div class="card-title">' . icon('heart') . ' Antenatal visit — pregnancy #' . (int) $a['pregnancy_no']
            . ' · EDD ' . e(fmt_date($a['edd_date'])) . '</div>' . ($a['risk_level'] === 'high' ? '<span class="badge badge-danger">High risk</span>' : '')
            . (can('obgyn.history.view') ? '<a class="btn btn-sm btn-outline" href="' . e(url('obgyn', ['patient_id' => $a['patient_id']])) . '">Obstetric record</a>' : '') . '</div>'
            . '<div class="card-body"><div class="kv-row">GA <strong>' . e(num($a['gestational_age_weeks'])) . ' wk</strong> · BP <strong>' . e($a['bp_systolic'] . '/' . $a['bp_diastolic']) . '</strong>'
            . ' · Wt ' . e(num($a['weight_kg'])) . ' kg · FH ' . e(num($a['fundal_height_cm'])) . ' cm · FHR ' . e((string) $a['fetal_heart_rate_bpm']) . ($a['hb_g_dl'] !== null ? ' · Hb ' . e(num($a['hb_g_dl'])) : '')
            . ($a['edema_flag'] ? ' · oedema' : '') . ($a['urine_protein_flag'] ? ' · proteinuria' : '') . '</div>'
            . ($flags ? '<div class="alert alert-danger mt-1">' . icon('alert') . ' ' . e(implode(' · ', $flags)) . '</div>' : '')
            . ($a['next_visit_date'] ? '<div class="muted small mt-1">Next ANC: ' . e(fmt_date($a['next_visit_date'])) . '</div>' : '') . '</div></div>';
    }
}
