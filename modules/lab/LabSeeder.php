<?php
declare(strict_types=1);

namespace App\Modules\Lab;

use App\Core\DB;
use App\Core\Migrator;

/** Default laboratory catalog (idempotent). Ranges are adult/child defaults — the lab manager edits them. */
final class LabSeeder
{
    public const SECTIONS = ['HEM' => 'Haematology', 'BIO' => 'Biochemistry', 'SER' => 'Serology', 'URI' => 'Urine & Stool', 'MIC' => 'Microbiology', 'HIS' => 'Histopathology'];

    /** name => [storage, max hold hours] */
    public const SPECIMENS = ['EDTA whole blood' => ['2–8 °C', 24], 'Serum' => ['2–8 °C', 48], 'Fluoride plasma' => ['Room temp', 8], 'Citrate plasma' => ['Room temp', 4],
        'Urine' => ['2–8 °C', 2], 'Stool' => ['Room temp', 2], 'Swab' => ['Transport medium', 24]];

    /** name => [cap colour, item sku] */
    public const CONTAINERS = ['EDTA tube' => ['purple', 'LAB-EDTA'], 'Gel / plain tube' => ['yellow', 'LAB-GEL'], 'Fluoride tube' => ['grey', 'LAB-FLU'],
        'Citrate tube' => ['blue', 'LAB-CIT'], 'Urine container' => ['white', 'LAB-URC']];

    private const ADULT = 4380; // 12 years in days

    /**
     * code => [name, section, specimen, container, price, investigation name, fasting, tat hours, result type, params, consumables]
     * param: [code, name, unit, decimals, critical low, critical high, ranges | options]
     *   ranges: [[sex A|M|F, min age days, max age days, low, high], …]; options: ['select' => [...]]
     */
    public static function tests(): array
    {
        $a = self::ADULT;
        return [
            'CBC' => ['Complete Blood Count (CBC)', 'HEM', 'EDTA whole blood', 'EDTA tube', 800, 'Complete Blood Count (CBC)', 0, 4, 'numeric', [
                ['HB', 'Haemoglobin', 'g/dL', 1, 7, 20, [['M', $a, 54750, 13, 17], ['F', $a, 54750, 12, 15], ['A', 0, $a - 1, 11, 14.5]]],
                ['TLC', 'Total leucocyte count', '×10⁹/L', 1, 2, 30, [['A', 0, 54750, 4, 11]]],
                ['PLT', 'Platelets', '×10⁹/L', 0, 50, 1000, [['A', 0, 54750, 150, 400]]],
                ['RBC', 'Red cell count', '×10¹²/L', 2, null, null, [['M', $a, 54750, 4.5, 5.5], ['F', $a, 54750, 3.8, 4.8], ['A', 0, $a - 1, 4.0, 5.2]]],
                ['HCT', 'Haematocrit', '%', 1, null, null, [['M', $a, 54750, 40, 50], ['F', $a, 54750, 36, 46], ['A', 0, $a - 1, 33, 43]]],
                ['MCV', 'MCV', 'fL', 1, null, null, [['A', 0, 54750, 80, 100]]],
                ['MCH', 'MCH', 'pg', 1, null, null, [['A', 0, 54750, 27, 32]]],
                ['NEU', 'Neutrophils', '%', 0, null, null, [['A', 0, 54750, 40, 75]]],
                ['LYM', 'Lymphocytes', '%', 0, null, null, [['A', 0, 54750, 20, 45]]],
            ], [['LAB-EDTA', 1], ['LAB-CBCR', 1]]],
            'ESR' => ['ESR', 'HEM', 'EDTA whole blood', 'EDTA tube', 300, 'ESR', 0, 4, 'numeric', [
                ['ESR', 'ESR', 'mm/1st hr', 0, null, null, [['M', 0, 54750, 0, 15], ['F', 0, 54750, 0, 20]]],
            ], [['LAB-EDTA', 1]]],
            'MP' => ['Malaria Parasite (MP) Smear', 'HEM', 'EDTA whole blood', 'EDTA tube', 400, 'Malaria Parasite (MP) Smear', 0, 2, 'select', [
                ['MP', 'Malaria parasite', '', 0, null, null, ['select' => ['Not seen', 'P. vivax seen', 'P. falciparum seen', 'Mixed infection']]],
            ], [['LAB-EDTA', 1]]],
            'CRP' => ['C-Reactive Protein (CRP)', 'BIO', 'Serum', 'Gel / plain tube', 900, 'C-Reactive Protein (CRP)', 0, 6, 'numeric', [
                ['CRP', 'CRP', 'mg/L', 1, null, null, [['A', 0, 54750, 0, 5]]],
            ], [['LAB-GEL', 1], ['LAB-CHEM', 1]]],
            'BSF' => ['Blood Sugar Fasting', 'BIO', 'Fluoride plasma', 'Fluoride tube', 200, 'Blood Sugar Fasting', 1, 2, 'numeric', [
                ['GLU', 'Glucose (fasting)', 'mg/dL', 0, 40, 500, [['A', 0, 54750, 70, 100]]],
            ], [['LAB-FLU', 1], ['LAB-CHEM', 1]]],
            'BSR' => ['Blood Sugar Random', 'BIO', 'Fluoride plasma', 'Fluoride tube', 200, 'Blood Sugar Random', 0, 2, 'numeric', [
                ['GLU', 'Glucose (random)', 'mg/dL', 0, 40, 500, [['A', 0, 54750, 70, 140]]],
            ], [['LAB-FLU', 1], ['LAB-CHEM', 1]]],
            'HBA1C' => ['HbA1c', 'BIO', 'EDTA whole blood', 'EDTA tube', 1800, 'HbA1c', 0, 24, 'numeric', [
                ['A1C', 'HbA1c', '%', 1, null, null, [['A', 0, 54750, 4.0, 5.6]]],
            ], [['LAB-EDTA', 1], ['LAB-CHEM', 1]]],
            'LFT' => ['Liver Function Tests (LFTs)', 'BIO', 'Serum', 'Gel / plain tube', 1500, 'Liver Function Tests (LFTs)', 0, 8, 'numeric', [
                ['TBIL', 'Bilirubin (total)', 'mg/dL', 2, null, 15, [['A', 30, 54750, 0.1, 1.2]]],
                ['ALT', 'ALT (SGPT)', 'U/L', 0, null, null, [['A', 0, 54750, 7, 56]]],
                ['AST', 'AST (SGOT)', 'U/L', 0, null, null, [['A', 0, 54750, 10, 40]]],
                ['ALP', 'Alkaline phosphatase', 'U/L', 0, null, null, [['A', $a, 54750, 44, 147], ['A', 0, $a - 1, 100, 400]]],
                ['ALB', 'Albumin', 'g/dL', 1, null, null, [['A', 0, 54750, 3.5, 5.0]]],
            ], [['LAB-GEL', 1], ['LAB-CHEM', 3]]],
            'RFT' => ['Renal Function Tests (RFTs)', 'BIO', 'Serum', 'Gel / plain tube', 1200, 'Renal Function Tests (RFTs)', 0, 8, 'numeric', [
                ['UREA', 'Urea', 'mg/dL', 0, null, 200, [['A', 0, 54750, 15, 45]]],
                ['CREA', 'Creatinine', 'mg/dL', 2, null, 10, [['M', $a, 54750, 0.7, 1.3], ['F', $a, 54750, 0.6, 1.1], ['A', 0, $a - 1, 0.3, 0.8]]],
                ['URIC', 'Uric acid', 'mg/dL', 1, null, null, [['M', 0, 54750, 3.4, 7.0], ['F', 0, 54750, 2.4, 6.0]]],
            ], [['LAB-GEL', 1], ['LAB-CHEM', 2]]],
            'LIPID' => ['Lipid Profile', 'BIO', 'Serum', 'Gel / plain tube', 1800, 'Lipid Profile', 1, 8, 'numeric', [
                ['CHOL', 'Total cholesterol', 'mg/dL', 0, null, null, [['A', 0, 54750, 0, 200]]],
                ['TG', 'Triglycerides', 'mg/dL', 0, null, null, [['A', 0, 54750, 0, 150]]],
                ['HDL', 'HDL cholesterol', 'mg/dL', 0, null, null, [['A', 0, 54750, 40, 80]]],
                ['LDL', 'LDL cholesterol', 'mg/dL', 0, null, null, [['A', 0, 54750, 0, 130]]],
            ], [['LAB-GEL', 1], ['LAB-CHEM', 3]]],
            'ELEC' => ['Serum Electrolytes', 'BIO', 'Serum', 'Gel / plain tube', 1200, 'Serum Electrolytes', 0, 4, 'numeric', [
                ['NA', 'Sodium', 'mmol/L', 0, 120, 160, [['A', 0, 54750, 135, 145]]],
                ['K', 'Potassium', 'mmol/L', 1, 2.8, 6.2, [['A', 0, 54750, 3.5, 5.1]]],
                ['CL', 'Chloride', 'mmol/L', 0, null, null, [['A', 0, 54750, 98, 107]]],
            ], [['LAB-GEL', 1], ['LAB-CHEM', 2]]],
            'TSH' => ['TSH', 'BIO', 'Serum', 'Gel / plain tube', 1500, 'TSH', 0, 24, 'numeric', [
                ['TSH', 'TSH', 'µIU/mL', 2, null, null, [['A', 0, 54750, 0.4, 4.0]]],
            ], [['LAB-GEL', 1], ['LAB-CHEM', 1]]],
            'URE' => ['Urine Routine Examination', 'URI', 'Urine', 'Urine container', 400, 'Urine Routine Examination', 0, 4, 'structured', [
                ['COL', 'Colour', '', 0, null, null, ['select' => ['Pale yellow', 'Yellow', 'Dark yellow', 'Amber', 'Red', 'Turbid']]],
                ['PH', 'pH', '', 1, null, null, [['A', 0, 54750, 4.5, 8.0]]],
                ['SG', 'Specific gravity', '', 3, null, null, [['A', 0, 54750, 1.005, 1.030]]],
                ['PRO', 'Protein', '', 0, null, null, ['select' => ['Nil', 'Trace', '+', '++', '+++']]],
                ['UGLU', 'Glucose', '', 0, null, null, ['select' => ['Nil', 'Trace', '+', '++', '+++']]],
                ['PUS', 'Pus cells', '/HPF', 0, null, null, [['A', 0, 54750, 0, 5]]],
                ['URBC', 'Red cells', '/HPF', 0, null, null, [['A', 0, 54750, 0, 2]]],
            ], [['LAB-URC', 1]]],
            'TYPHI' => ['Typhidot', 'SER', 'Serum', 'Gel / plain tube', 1200, 'Typhidot', 0, 4, 'select', [
                ['TYP', 'Typhidot', '', 0, null, null, ['select' => ['Negative', 'IgM positive', 'IgG positive', 'IgM & IgG positive']]],
            ], [['LAB-GEL', 1]]],
            'DENG' => ['Dengue NS1 Antigen', 'SER', 'Serum', 'Gel / plain tube', 2000, 'Dengue NS1 Antigen', 0, 4, 'select', [
                ['NS1', 'Dengue NS1 antigen', '', 0, null, null, ['select' => ['Negative', 'Positive']]],
            ], [['LAB-GEL', 1]]],
        ];
    }

    public static function run(): void
    {
        if (!Migrator::tableExists('lab_tests')) {
            return;
        }
        $now = now();
        $branch = (int) setting('default_branch_id', 0) ?: null;
        $i = 0;
        foreach (self::SECTIONS as $code => $name) {
            $i++;
            if (!DB::value('SELECT 1 FROM lab_sections WHERE code = ?', [$code])) {
                DB::insert('lab_sections', ['branch_id' => $branch, 'code' => $code, 'name' => $name, 'is_active' => 1, 'display_order' => $i, 'created_at' => $now]);
            }
        }
        $i = 0;
        foreach (self::SPECIMENS as $name => [$store, $hold]) {
            $i++;
            if (!DB::value('SELECT 1 FROM lab_specimen_types WHERE name = ?', [$name])) {
                DB::insert('lab_specimen_types', ['name' => $name, 'storage_condition' => $store, 'max_hold_hours' => $hold, 'is_active' => 1, 'display_order' => $i, 'created_at' => $now]);
            }
        }
        $i = 0;
        foreach (self::CONTAINERS as $name => [$colour, $sku]) {
            $i++;
            if (!DB::value('SELECT 1 FROM lab_containers WHERE name = ?', [$name])) {
                DB::insert('lab_containers', ['name' => $name, 'color_code' => $colour, 'item_id' => DB::value('SELECT id FROM items WHERE sku = ?', [$sku]) ?: null,
                    'is_active' => 1, 'display_order' => $i, 'created_at' => $now]);
            }
        }
        foreach (['Haematology', 'Biochemistry', 'Serology', 'Urine analysis'] as $j => $c) {
            if (!DB::value('SELECT 1 FROM lab_test_categories WHERE name = ?', [$c])) {
                DB::insert('lab_test_categories', ['name' => $c, 'is_active' => 1, 'display_order' => $j + 1, 'created_at' => $now]);
            }
        }
        $sections = DB::pairs('SELECT code, id FROM lab_sections');
        $specimens = DB::pairs('SELECT name, id FROM lab_specimen_types');
        $containers = DB::pairs('SELECT name, id FROM lab_containers');
        $labDept = DB::value("SELECT id FROM departments WHERE department_type = 'lab' AND deleted_at IS NULL ORDER BY id LIMIT 1") ?: null;
        $order = 0;
        foreach (self::tests() as $code => [$name, $sec, $spec, $cont, $price, $inv, $fasting, $tat, $type, $params, $consume]) {
            $order++;
            if (DB::value('SELECT 1 FROM lab_tests WHERE code = ?', [$code])) {
                continue;
            }
            $serviceId = DB::value('SELECT id FROM service_catalog WHERE code = ?', ['LAB-' . $code]);
            if (!$serviceId) {
                $serviceId = DB::insert('service_catalog', ['code' => 'LAB-' . $code, 'name' => $name, 'service_type' => 'lab_test', 'department_id' => $labDept,
                    'default_price' => $price, 'is_active' => 1, 'display_order' => $order, 'created_at' => $now]);
                DB::insert('service_prices', ['service_id' => $serviceId, 'price_list_type' => 'cash', 'price' => $price, 'valid_from' => '2000-01-01', 'created_at' => $now]);
            }
            $testId = DB::insert('lab_tests', [
                'code' => $code, 'name' => $name, 'section_id' => $sections[$sec] ?? null, 'specimen_type_id' => $specimens[$spec] ?? null, 'container_id' => $containers[$cont] ?? null,
                'service_id' => $serviceId, 'investigation_id' => DB::value('SELECT id FROM investigations WHERE name = ? AND deleted_at IS NULL', [$inv]) ?: null,
                'result_type' => $type, 'fasting_required' => $fasting, 'turnaround_hours' => $tat, 'is_active' => 1, 'display_order' => $order, 'created_at' => $now,
                'category_id' => DB::value('SELECT id FROM lab_test_categories WHERE name = ?', [['HEM' => 'Haematology', 'BIO' => 'Biochemistry', 'SER' => 'Serology', 'URI' => 'Urine analysis'][$sec] ?? '']) ?: null,
            ]);
            foreach ($params as $p => [$pcode, $pname, $unit, $dec, $cl, $ch, $ranges]) {
                $isSelect = isset($ranges['select']);
                $pid = DB::insert('lab_test_parameters', ['lab_test_id' => $testId, 'code' => $pcode, 'name' => $pname, 'unit' => $unit ?: null,
                    'data_type' => $isSelect ? 'select' : 'numeric', 'options_json' => $isSelect ? json_encode($ranges['select'], JSON_UNESCAPED_UNICODE) : null,
                    'decimals' => $dec, 'critical_low' => $cl, 'critical_high' => $ch, 'display_order' => $p + 1, 'is_active' => 1]);
                if (!$isSelect) {
                    foreach ($ranges as [$sex, $min, $max, $low, $high]) {
                        DB::insert('lab_reference_ranges', ['parameter_id' => $pid, 'sex' => $sex, 'age_min_days' => $min, 'age_max_days' => $max, 'low' => $low, 'high' => $high]);
                    }
                } else {
                    DB::insert('lab_reference_ranges', ['parameter_id' => $pid, 'sex' => 'A', 'age_min_days' => 0, 'age_max_days' => 54750, 'text_range' => $ranges['select'][0]]);
                }
            }
            foreach ($consume as [$sku, $qty]) {
                $item = DB::value('SELECT id FROM items WHERE sku = ?', [$sku]);
                if ($item) {
                    DB::insert('service_consumption_templates', ['service_id' => $serviceId, 'item_id' => $item, 'qty' => $qty, 'from_location_type' => 'lab_stock', 'is_mandatory' => 1]);
                }
            }
        }
        // QC materials for the two most common analytes.
        if (!DB::value('SELECT 1 FROM lab_qc_materials')) {
            foreach ([['Haematology control L1', 'HB', 'CBC', 12.5, 0.3], ['Chemistry control N', 'GLU', 'BSF', 100, 3]] as [$n, $pc, $tc, $mean, $sd]) {
                $pid = DB::value('SELECT p.id FROM lab_test_parameters p JOIN lab_tests t ON t.id = p.lab_test_id WHERE p.code = ? AND t.code = ?', [$pc, $tc]);
                if ($pid) {
                    DB::insert('lab_qc_materials', ['name' => $n, 'lot_no' => 'LOT-' . date('Y') . '-01', 'level' => 'Normal', 'expiry_date' => date('Y-m-d', strtotime('+1 year')),
                        'section_id' => DB::value('SELECT section_id FROM lab_tests WHERE code = ?', [$tc]), 'parameter_id' => $pid, 'target_mean' => $mean, 'target_sd' => $sd,
                        'is_active' => 1, 'created_at' => $now]);
                }
            }
        }
    }
}
