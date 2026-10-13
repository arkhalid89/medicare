<?php
declare(strict_types=1);

namespace App\Modules\Specialty;

use App\Core\DB;
use App\Core\Migrator;

/** Doc 6 §7 starter fields per specialty, front / back body maps and the EPI vaccine schedule (idempotent). */
final class SpecialtySeeder
{
    /** specialty code => [[key, label, type, options|null, unit, required], ...] */
    public const FIELDS = [
        'CARD' => [
            ['nyha', 'NYHA functional class', 'select', ['I', 'II', 'III', 'IV'], null, 0],
            ['ecg_finding', 'ECG finding', 'select', ['Normal', 'Atrial fibrillation', 'ST changes', 'LVH', 'Bundle branch block', 'Other'], null, 0],
            ['cardiac_history', 'Known cardiac history', 'multiselect', ['MI', 'CABG', 'Stent / PCI', 'Heart failure', 'Valve disease', 'Arrhythmia'], null, 0],
            ['chest_pain', 'Chest pain character', 'select', ['None', 'Typical angina', 'Atypical', 'Non-cardiac'], null, 0],
        ],
        'ENT' => [
            ['side', 'Affected side', 'select', ['Left', 'Right', 'Bilateral'], null, 0],
            ['tm', 'Tympanic membrane', 'select', ['Intact', 'Perforated', 'Retracted', 'Bulging', 'Not visualised'], null, 0],
            ['throat', 'Throat examination', 'text', null, null, 0],
            ['nose', 'Nasal examination', 'text', null, null, 0],
        ],
        'OPHT' => [
            ['eye', 'Affected eye', 'select', ['Right (OD)', 'Left (OS)', 'Both (OU)'], null, 0],
            ['red_eye', 'Red eye', 'boolean', null, null, 0],
        ],
        'ORTH' => [
            ['site', 'Affected site', 'select', ['Shoulder', 'Elbow', 'Wrist / hand', 'Spine — cervical', 'Spine — lumbar', 'Hip', 'Knee', 'Ankle / foot', 'Long bone'], null, 0],
            ['rom', 'Range of motion', 'number', null, 'degrees', 0],
            ['fracture', 'Fracture type', 'select', ['None', 'Simple / closed', 'Compound / open', 'Comminuted', 'Greenstick', 'Stress'], null, 0],
            ['imaging_ref', 'Imaging reference', 'text', null, null, 0],
        ],
        'PEDS' => [
            ['milestones', 'Developmental milestones', 'select', ['Age-appropriate', 'Delayed — motor', 'Delayed — speech', 'Delayed — global'], null, 0],
            ['feeding', 'Feeding', 'select', ['Exclusive breastfeeding', 'Mixed feeding', 'Formula', 'Family diet'], null, 0],
            ['epi_up_to_date', 'Immunization up to date', 'boolean', null, null, 0],
        ],
        'DERM' => [
            ['lesion_type', 'Lesion type', 'select', ['Macule', 'Papule', 'Plaque', 'Nodule', 'Vesicle', 'Pustule', 'Ulcer', 'Scale'], null, 0],
            ['duration', 'Duration', 'text', null, null, 0],
            ['itch', 'Itching', 'boolean', null, null, 0],
        ],
        'PSYC' => [
            ['mood', 'Mood', 'select', ['Euthymic', 'Depressed', 'Anxious', 'Elevated', 'Irritable'], null, 0],
            ['affect', 'Affect', 'select', ['Appropriate', 'Flat', 'Blunted', 'Labile', 'Incongruent'], null, 0],
            ['thought', 'Thought process', 'select', ['Logical', 'Tangential', 'Circumstantial', 'Flight of ideas', 'Disorganised'], null, 0],
            ['risk', 'Risk screen (details in restricted note)', 'select', ['No risk identified', 'Low', 'Moderate', 'High'], null, 1],
        ],
        'UROL' => [
            ['side', 'Affected side', 'select', ['Left', 'Right', 'Bilateral', 'N/A'], null, 0],
            ['history', 'Relevant history', 'multiselect', ['Stones', 'BPH', 'UTI', 'Hematuria', 'Stricture'], null, 0],
            ['psa', 'PSA', 'number', null, 'ng/mL', 0],
        ],
    ];

    /** view code => [name, [[code, label, shape], ...]] — shapes on a 200 × 440 canvas. */
    public static function bodyMaps(): array
    {
        $limbs = static function (string $side): array {
            // Viewer-left is the patient's right on the front view.
            $front = $side === 'front';
            $r = $front ? 0 : 1;
            $x = static fn (int $a, int $b) => $r ? $b : $a;
            return [
                ['shoulder_r', 'Right shoulder', ['t' => 'e', 'cx' => $x(58, 142), 'cy' => 88, 'rx' => 12, 'ry' => 12]],
                ['upper_arm_r', 'Right upper arm', ['t' => 'r', 'x' => $x(40, 142), 'y' => 98, 'w' => 18, 'h' => 58]],
                ['forearm_r', 'Right forearm', ['t' => 'r', 'x' => $x(34, 150), 'y' => 158, 'w' => 16, 'h' => 56]],
                ['hand_r', 'Right hand', ['t' => 'e', 'cx' => $x(42, 158), 'cy' => 228, 'rx' => 10, 'ry' => 14]],
                ['shoulder_l', 'Left shoulder', ['t' => 'e', 'cx' => $x(142, 58), 'cy' => 88, 'rx' => 12, 'ry' => 12]],
                ['upper_arm_l', 'Left upper arm', ['t' => 'r', 'x' => $x(142, 40), 'y' => 98, 'w' => 18, 'h' => 58]],
                ['forearm_l', 'Left forearm', ['t' => 'r', 'x' => $x(150, 34), 'y' => 158, 'w' => 16, 'h' => 56]],
                ['hand_l', 'Left hand', ['t' => 'e', 'cx' => $x(158, 42), 'cy' => 228, 'rx' => 10, 'ry' => 14]],
                ['thigh_r', 'Right thigh', ['t' => 'r', 'x' => $x(72, 102), 'y' => 220, 'w' => 26, 'h' => 78]],
                ['knee_r', 'Right knee', ['t' => 'e', 'cx' => $x(85, 115), 'cy' => 306, 'rx' => 13, 'ry' => 10]],
                ['leg_r', 'Right leg', ['t' => 'r', 'x' => $x(74, 104), 'y' => 316, 'w' => 22, 'h' => 80]],
                ['foot_r', 'Right foot', ['t' => 'e', 'cx' => $x(84, 116), 'cy' => 410, 'rx' => 14, 'ry' => 10]],
                ['thigh_l', 'Left thigh', ['t' => 'r', 'x' => $x(102, 72), 'y' => 220, 'w' => 26, 'h' => 78]],
                ['knee_l', 'Left knee', ['t' => 'e', 'cx' => $x(115, 85), 'cy' => 306, 'rx' => 13, 'ry' => 10]],
                ['leg_l', 'Left leg', ['t' => 'r', 'x' => $x(104, 74), 'y' => 316, 'w' => 22, 'h' => 80]],
                ['foot_l', 'Left foot', ['t' => 'e', 'cx' => $x(116, 84), 'cy' => 410, 'rx' => 14, 'ry' => 10]],
            ];
        };
        return [
            'BODY_F' => ['Body — front', array_merge([
                ['head', 'Head / face', ['t' => 'e', 'cx' => 100, 'cy' => 36, 'rx' => 24, 'ry' => 30]],
                ['neck', 'Neck', ['t' => 'r', 'x' => 90, 'y' => 64, 'w' => 20, 'h' => 14]],
                ['chest', 'Chest', ['t' => 'r', 'x' => 68, 'y' => 78, 'w' => 64, 'h' => 60]],
                ['abdomen', 'Abdomen', ['t' => 'r', 'x' => 70, 'y' => 138, 'w' => 60, 'h' => 50]],
                ['pelvis', 'Groin / pelvis', ['t' => 'r', 'x' => 70, 'y' => 188, 'w' => 60, 'h' => 32]],
            ], $limbs('front'))],
            'BODY_B' => ['Body — back', array_merge([
                ['head_back', 'Scalp / back of head', ['t' => 'e', 'cx' => 100, 'cy' => 36, 'rx' => 24, 'ry' => 30]],
                ['neck_back', 'Back of neck', ['t' => 'r', 'x' => 90, 'y' => 64, 'w' => 20, 'h' => 14]],
                ['upper_back', 'Upper back', ['t' => 'r', 'x' => 68, 'y' => 78, 'w' => 64, 'h' => 60]],
                ['lower_back', 'Lower back', ['t' => 'r', 'x' => 70, 'y' => 138, 'w' => 60, 'h' => 50]],
                ['buttocks', 'Buttocks', ['t' => 'r', 'x' => 70, 'y' => 188, 'w' => 60, 'h' => 32]],
            ], $limbs('back'))],
        ];
    }

    /** code => [name, item sku, [[dose, age weeks, label], ...]] — Pakistan EPI. */
    public const VACCINES = [
        'BCG'   => ['BCG', 'VAC-BCG', [[1, 0, 'At birth']]],
        'OPV'   => ['Oral polio (OPV)', 'VAC-OPV', [[1, 0, 'OPV-0 at birth'], [2, 6, '6 weeks'], [3, 10, '10 weeks'], [4, 14, '14 weeks']]],
        'PENTA' => ['Pentavalent (DTP-HepB-Hib)', 'VAC-PENTA', [[1, 6, '6 weeks'], [2, 10, '10 weeks'], [3, 14, '14 weeks']]],
        'PCV'   => ['Pneumococcal (PCV10)', null, [[1, 6, '6 weeks'], [2, 10, '10 weeks'], [3, 14, '14 weeks']]],
        'ROTA'  => ['Rotavirus', null, [[1, 6, '6 weeks'], [2, 10, '10 weeks']]],
        'IPV'   => ['Inactivated polio (IPV)', null, [[1, 14, '14 weeks'], [2, 39, '9 months']]],
        'MR'    => ['Measles-Rubella (MR)', 'VAC-MR', [[1, 39, '9 months'], [2, 65, '15 months']]],
        'TCV'   => ['Typhoid conjugate (TCV)', null, [[1, 39, '9 months']]],
    ];

    public static function run(): void
    {
        if (!Migrator::tableExists('clinical_parameter_templates')) {
            return;
        }
        $specs = DB::pairs('SELECT code, id FROM specialties WHERE deleted_at IS NULL');
        foreach (self::FIELDS as $code => $fields) {
            if (empty($specs[$code])) {
                continue;
            }
            foreach ($fields as $i => [$key, $label, $type, $options, $unit, $req]) {
                if (!DB::value('SELECT 1 FROM clinical_parameter_templates WHERE specialty_id = ? AND field_key = ?', [$specs[$code], $key])) {
                    DB::insert('clinical_parameter_templates', ['specialty_id' => $specs[$code], 'field_key' => $key, 'label' => $label, 'field_type' => $type,
                        'options_json' => $options ? json_encode($options, JSON_UNESCAPED_UNICODE) : null, 'unit' => $unit, 'is_required' => $req,
                        'display_order' => $i + 1, 'is_active' => 1, 'created_at' => now()]);
                }
            }
        }
        foreach (self::bodyMaps() as $view => [$name, $regions]) {
            $tid = DB::value('SELECT id FROM body_map_templates WHERE view_code = ?', [$view]);
            if (!$tid) {
                $tid = DB::insert('body_map_templates', ['specialty_id' => null, 'name' => $name, 'view_code' => $view, 'is_active' => 1]);
                foreach ($regions as $i => [$code, $label, $shape]) {
                    DB::insert('body_map_regions', ['template_id' => $tid, 'region_code' => $code, 'label' => $label, 'svg_element_id' => strtolower($view) . '-' . $code,
                        'shape_json' => json_encode($shape), 'display_order' => $i + 1]);
                }
            }
        }
        $i = 0;
        foreach (self::VACCINES as $code => [$name, $sku, $schedule]) {
            $i++;
            if (!DB::value('SELECT 1 FROM vaccines WHERE code = ?', [$code])) {
                DB::insert('vaccines', ['code' => $code, 'name' => $name, 'item_id' => $sku ? (DB::value('SELECT id FROM items WHERE sku = ?', [$sku]) ?: null) : null,
                    'schedule_json' => json_encode(array_map(static fn ($s) => ['dose' => $s[0], 'age_weeks' => $s[1], 'label' => $s[2]], $schedule)),
                    'is_epi' => 1, 'is_active' => 1, 'display_order' => $i]);
            }
        }
    }
}
