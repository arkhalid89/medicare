<?php
declare(strict_types=1);

namespace App\Modules\Dental;

use App\Core\DB;
use App\Core\Migrator;

/** FDI tooth reference (32 permanent + 20 deciduous) and the dental procedure catalog with prices & consumables (idempotent). */
final class DentalSeeder
{
    private const PERM_NAMES = [1 => 'central incisor', 2 => 'lateral incisor', 3 => 'canine', 4 => 'first premolar', 5 => 'second premolar', 6 => 'first molar', 7 => 'second molar', 8 => 'third molar'];
    private const DEC_NAMES = [1 => 'central incisor', 2 => 'lateral incisor', 3 => 'canine', 4 => 'first molar', 5 => 'second molar'];
    private const QUAD = [1 => ['upper', 'right'], 2 => ['upper', 'left'], 3 => ['lower', 'left'], 4 => ['lower', 'right'], 5 => ['upper', 'right'], 6 => ['upper', 'left'], 7 => ['lower', 'left'], 8 => ['lower', 'right']];

    /**
     * code => [name, category, applies_to, requires_tooth, requires_surface, hygienist, lab case, resulting condition, price, consumables [[sku, qty]]]
     */
    public const PROCEDURES = [
        'EXAM'   => ['Oral examination & charting', 'diagnostic', 'full_mouth', 0, 0, 0, 0, null, 0, []],
        'PA'     => ['Periapical X-ray', 'diagnostic', 'tooth', 1, 0, 0, 0, null, 500, []],
        'OPG'    => ['OPG (panoramic X-ray)', 'diagnostic', 'full_mouth', 0, 0, 0, 0, null, 1500, []],
        'SCAL'   => ['Scaling & polishing', 'periodontal', 'full_mouth', 0, 0, 1, 0, null, 2500, []],
        'FLUOR'  => ['Fluoride application', 'preventive', 'full_mouth', 0, 0, 1, 0, null, 1000, []],
        'SEAL'   => ['Fissure sealant', 'preventive', 'tooth', 1, 0, 1, 0, null, 1500, [['DEN-COMP', 0.5]]],
        'FILL1'  => ['Composite filling (1 surface)', 'restorative', 'surface', 1, 1, 0, 0, 'filled', 3000, [['DEN-COMP', 1], ['DEN-BOND', 1]]],
        'FILL2'  => ['Composite filling (2 surfaces)', 'restorative', 'surface', 1, 1, 0, 0, 'filled', 3500, [['DEN-COMP', 1.5], ['DEN-BOND', 1]]],
        'FILL3'  => ['Composite filling (3+ surfaces)', 'restorative', 'surface', 1, 1, 0, 0, 'filled', 4500, [['DEN-COMP', 2], ['DEN-BOND', 1]]],
        'GIC'    => ['GIC / temporary filling', 'restorative', 'tooth', 1, 0, 0, 0, 'filled', 1500, []],
        'PULP'   => ['Pulpotomy (child)', 'endodontic', 'tooth', 1, 0, 0, 0, null, 3500, [['DEN-LIDO', 1]]],
        'RCT'    => ['Root canal treatment', 'endodontic', 'tooth', 1, 0, 0, 0, 'root_canal_done', 12000, [['DEN-LIDO', 2], ['DEN-GP', 3]]],
        'POST'   => ['Post & core', 'restorative', 'tooth', 1, 0, 0, 0, null, 4000, []],
        'CROWN'  => ['Crown (porcelain-fused-to-metal)', 'prosthetic', 'tooth', 1, 0, 0, 1, 'crown', 15000, []],
        'CROWNZ' => ['Crown (zirconia)', 'prosthetic', 'tooth', 1, 0, 0, 1, 'crown', 25000, []],
        'BRIDGE' => ['Bridge (per unit)', 'prosthetic', 'tooth', 1, 0, 0, 1, 'crown', 15000, []],
        'DENTP'  => ['Partial denture (acrylic)', 'prosthetic', 'arch', 0, 0, 0, 1, null, 12000, []],
        'DENTC'  => ['Complete denture (per arch)', 'prosthetic', 'arch', 0, 0, 0, 1, null, 25000, []],
        'EXT'    => ['Extraction (simple)', 'surgical', 'tooth', 1, 0, 0, 0, 'missing', 2500, [['DEN-LIDO', 1]]],
        'SURG'   => ['Surgical extraction / impaction', 'surgical', 'tooth', 1, 0, 0, 0, 'missing', 8000, [['DEN-LIDO', 2]]],
        'IMPL'   => ['Dental implant (fixture)', 'surgical', 'tooth', 1, 0, 0, 1, 'implant', 80000, [['DEN-LIDO', 2]]],
        'ORTHO'  => ['Orthodontic consultation / records', 'orthodontic', 'full_mouth', 0, 0, 0, 0, null, 3000, []],
        'BLEACH' => ['Teeth whitening', 'preventive', 'full_mouth', 0, 0, 0, 0, null, 15000, []],
    ];

    public static function run(): void
    {
        if (!Migrator::tableExists('tooth_reference')) {
            return;
        }
        if (!DB::value('SELECT 1 FROM tooth_reference')) {
            foreach (self::teeth() as $t) {
                DB::insert('tooth_reference', $t);
            }
        }
        $dept = DB::value("SELECT d.id FROM departments d JOIN specialties s ON s.id = d.specialty_id WHERE s.module_key = 'dental' AND d.deleted_at IS NULL ORDER BY d.id LIMIT 1") ?: null;
        $i = 0;
        foreach (self::PROCEDURES as $code => [$name, $cat, $applies, $reqTooth, $reqSurf, $hyg, $lab, $result, $price, $consume]) {
            $i++;
            if (DB::value('SELECT 1 FROM dental_procedure_catalog WHERE code = ?', [$code])) {
                continue;
            }
            $sid = DB::value('SELECT id FROM service_catalog WHERE code = ?', ['DEN-' . $code]);
            if (!$sid) {
                $sid = DB::insert('service_catalog', ['code' => 'DEN-' . $code, 'name' => $name, 'service_type' => 'dental_procedure', 'department_id' => $dept,
                    'default_price' => $price, 'is_active' => 1, 'display_order' => $i, 'created_at' => now()]);
                DB::insert('service_prices', ['service_id' => $sid, 'price_list_type' => 'cash', 'price' => $price, 'valid_from' => '2000-01-01', 'created_at' => now()]);
                foreach ($consume as [$sku, $qty]) {
                    if ($item = DB::value('SELECT id FROM items WHERE sku = ?', [$sku])) {
                        DB::insert('service_consumption_templates', ['service_id' => $sid, 'item_id' => $item, 'qty' => $qty, 'from_location_type' => 'dental_stock', 'is_mandatory' => 1]);
                    }
                }
            }
            DB::insert('dental_procedure_catalog', ['code' => $code, 'name' => $name, 'category' => $cat, 'applies_to' => $applies, 'requires_tooth' => $reqTooth,
                'requires_surface' => $reqSurf, 'hygienist_allowed' => $hyg, 'needs_lab_case' => $lab, 'result_condition' => $result, 'service_id' => $sid,
                'typical_visits' => in_array($code, ['RCT', 'CROWN', 'CROWNZ', 'BRIDGE', 'DENTC', 'DENTP', 'IMPL'], true) ? 2 : 1, 'is_active' => 1, 'display_order' => $i,
                'created_at' => now()]);
        }
    }

    /** 52 FDI teeth with names, surfaces and a position hint for the SVG map. */
    public static function teeth(): array
    {
        $out = [];
        foreach (self::QUAD as $q => [$arch, $side]) {
            $dec = $q >= 5;
            $count = $dec ? 5 : 8;
            for ($p = 1; $p <= $count; $p++) {
                $type = $p <= 2 ? 'incisor' : ($p === 3 ? 'canine' : ($dec || $p >= 6 ? 'molar' : 'premolar'));
                $anterior = $p <= 3;
                $surfaces = $anterior
                    ? ['mesial', 'distal', 'labial', $arch === 'upper' ? 'palatal' : 'lingual', 'incisal']
                    : ['mesial', 'distal', 'buccal', $arch === 'upper' ? 'palatal' : 'lingual', 'occlusal'];
                // x: 0 at the midline, increasing outwards; negative on the patient's right (viewer's left).
                $x = ($side === 'right' ? -1 : 1) * $p;
                $out[] = ['tooth_number' => $q . $p, 'name' => ($dec ? 'Deciduous ' : '') . ucfirst($arch) . ' ' . $side . ' ' . ($dec ? self::DEC_NAMES[$p] : self::PERM_NAMES[$p]),
                    'dentition' => $dec ? 'deciduous' : 'permanent', 'arch' => $arch, 'quadrant' => $q, 'tooth_type' => $type,
                    'valid_surfaces_json' => json_encode($surfaces), 'chart_x' => $x, 'chart_y' => $arch === 'upper' ? 0 : 1];
            }
        }
        return $out;
    }
}
