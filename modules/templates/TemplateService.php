<?php
declare(strict_types=1);

namespace App\Modules\Templates;

use App\Core\ActivityLog;
use App\Core\Auth;
use App\Core\DB;
use App\Core\Validator;
use App\Modules\Visits\ClinicalItems;
use App\Modules\Visits\VisitService;

/**
 * Doctor-specific favourite templates (BRD §55–56, §81).
 * Every query is keyed on the owning doctor, so Doctor A can never read,
 * load, edit or delete Doctor B's templates.
 */
final class TemplateService
{
    /** @return array{ok:bool, id?:int, message?:string, errors?:string[]} */
    public static function save(array $payload, int $doctorId, ?int $id = null): array
    {
        $v = new Validator($payload);
        $v->required('name', 'Template name')->max('name', 'Template name', 150)
            ->max('description', 'Description', 255)
            ->max('overall_instructions', 'Overall instructions', 5000)
            ->integer('follow_up_days', 'Follow-up days', 0, 3650);
        $errors = array_values($v->errors());

        if ($id !== null && !self::find($id, $doctorId)) {
            return ['ok' => false, 'errors' => ['Template not found.'], 'message' => 'Template not found.'];
        }
        $name = trim((string) ($payload['name'] ?? ''));
        if ($name !== '' && DB::value(
            'SELECT 1 FROM templates WHERE doctor_id = ? AND name = ? AND deleted_at IS NULL AND id <> ?',
            [$doctorId, $name, $id ?? 0]
        )) {
            $errors[] = 'You already have a template named "' . $name . '".';
        }

        [$items, $itemErrors] = ClinicalItems::resolve($payload);
        [$vitals, $vitalErrors] = VisitService::validateVitals(is_array($payload['vitals'] ?? null) ? $payload['vitals'] : []);
        [$lines, $medicineErrors] = VisitService::resolveMedicines(is_array($payload['medicines'] ?? null) ? $payload['medicines'] : []);
        $errors = array_merge($errors, $itemErrors, $vitalErrors, $medicineErrors);

        if (!$items && !$lines && trim((string) ($payload['overall_instructions'] ?? '')) === '') {
            $errors[] = 'A template needs at least one complaint, symptom, diagnosis, investigation, medicine or instruction.';
        }
        if ($errors) {
            return ['ok' => false, 'errors' => array_values(array_unique($errors)), 'message' => $errors[0]];
        }

        unset($vitals['bmi']);
        $vitalsJson = array_filter($vitals, static fn ($x) => $x !== null && $x !== '');

        $id = DB::transaction(static function () use ($payload, $doctorId, $id, $name, $items, $lines, $vitalsJson): int {
            $row = [
                'name'                 => $name,
                'description'          => trim((string) ($payload['description'] ?? '')) ?: null,
                'is_favourite'         => array_key_exists('is_favourite', $payload) ? (int) (bool) $payload['is_favourite'] : 1,
                'vitals'               => $vitalsJson ? json_encode($vitalsJson, JSON_UNESCAPED_UNICODE) : null,
                'overall_instructions' => trim((string) ($payload['overall_instructions'] ?? '')) ?: null,
                'follow_up_days'       => ($payload['follow_up_days'] ?? '') === '' ? null : (int) $payload['follow_up_days'],
            ];
            if ($id === null) {
                $id = DB::insert('templates', $row + ['doctor_id' => $doctorId, 'created_at' => now(), 'created_by' => Auth::id()]);
                ActivityLog::record('Template created', 'templates', (string) $id, 'Created template "' . $name . '"');
            } else {
                DB::update('templates', $row + ['updated_at' => now(), 'updated_by' => Auth::id()], 'id = ? AND doctor_id = ?', [$id, $doctorId]);
                DB::run('DELETE FROM template_clinical_items WHERE template_id = ?', [$id]);
                DB::run('DELETE FROM template_medicines WHERE template_id = ?', [$id]);
                ActivityLog::record('Template updated', 'templates', (string) $id, 'Updated template "' . $name . '"');
            }
            ClinicalItems::save('template_clinical_items', 'template_id', $id, $items);
            foreach ($lines as $i => $l) {
                DB::insert('template_medicines', [
                    'template_id'   => $id,
                    'medicine_id'   => $l['medicine_id'],
                    'medicine_name' => $l['medicine_name'],
                    'dose'          => $l['dose'],
                    'dose_unit'     => $l['dose_unit'],
                    'frequency_id'  => $l['frequency_id'],
                    'route_id'      => $l['route_id'],
                    'duration_days' => $l['duration_days'],
                    'quantity'      => $l['quantity'],
                    'qty_manual'    => $l['qty_manual'],
                    'unit_price'    => $l['unit_price'],
                    'source_id'     => $l['source_id'],
                    'instructions'  => $l['instructions'],
                    'sort_order'    => $i + 1,
                ]);
            }
            return $id;
        });

        return ['ok' => true, 'id' => $id, 'message' => 'Template saved successfully.'];
    }

    public static function find(int $id, int $doctorId): ?array
    {
        return DB::one('SELECT * FROM templates WHERE id = ? AND doctor_id = ? AND deleted_at IS NULL', [$id, $doctorId]);
    }

    /**
     * Template content for the consultation or template editor screen.
     * Loading never modifies the stored template (BRD §56).
     */
    public static function loadForScreen(int $id, int $doctorId): ?array
    {
        $t = self::find($id, $doctorId);
        if (!$t) {
            return null;
        }
        $rows = DB::all('SELECT * FROM template_medicines WHERE template_id = ? ORDER BY sort_order, id', [$id]);
        $meds = VisitService::linesFrom($rows);
        $followUp = $t['follow_up_days'] !== null ? date('Y-m-d', strtotime('+' . (int) $t['follow_up_days'] . ' days')) : '';
        return array_merge(ClinicalItems::load('template_clinical_items', 'template_id', $id), [
            'template_id'          => (int) $t['id'],
            'name'                 => $t['name'],
            'description'          => $t['description'] ?? '',
            'is_favourite'         => (int) $t['is_favourite'],
            'vitals'               => json_decode((string) ($t['vitals'] ?? ''), true) ?: new \stdClass(),
            'overall_instructions' => $t['overall_instructions'] ?? '',
            'follow_up_days'       => $t['follow_up_days'],
            'follow_up_date'       => $followUp,
            'medicines'            => $meds['medicines'],
            'skipped'              => $meds['skipped'],
        ]);
    }

    public static function forDoctor(int $doctorId): array
    {
        return DB::all(
            'SELECT id, name, is_favourite, usage_count FROM templates WHERE doctor_id = ? AND deleted_at IS NULL
              ORDER BY is_favourite DESC, usage_count DESC, name',
            [$doctorId]
        );
    }
}
