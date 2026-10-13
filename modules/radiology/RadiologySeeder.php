<?php
declare(strict_types=1);

namespace App\Modules\Radiology;

use App\Core\DB;
use App\Core\Migrator;

/** Modalities, rooms, equipment, protocols, report templates and the procedure catalog with prices (idempotent). */
final class RadiologySeeder
{
    /** code => [name, ionizing] */
    public const MODALITIES = ['XR' => ['X-ray', 1], 'US' => ['Ultrasound', 0], 'CT' => ['CT scan', 1], 'MR' => ['MRI', 0], 'MG' => ['Mammography', 1],
        'DEXA' => ['DEXA (bone density)', 1], 'FLUORO' => ['Fluoroscopy', 1], 'OPG' => ['Dental OPG / periapical', 1]];

    /** name => [modality, slot minutes] */
    public const ROOMS = ['X-ray Room 1' => ['XR', 10], 'Ultrasound Room' => ['US', 20], 'CT Suite' => ['CT', 20], 'Dental X-ray' => ['OPG', 10]];

    /**
     * code => [name, modality, body part, price, investigation name|null, prep|null, minutes, contrast possible, obstetric, template key, consumables [[sku, qty]]]
     */
    public const PROCEDURES = [
        'XR-CXR'  => ['X-Ray Chest PA View', 'XR', 'Chest', 800, 'X-Ray Chest PA View', null, 10, 0, 0, 'cxr', [['RAD-FILM', 1]]],
        'XR-KUB'  => ['X-Ray KUB', 'XR', 'Abdomen', 900, null, 'Empty bladder; laxative the night before if advised.', 10, 0, 0, 'xr', [['RAD-FILM', 1]]],
        'XR-LSS'  => ['X-Ray Lumbosacral Spine AP/Lat', 'XR', 'Spine', 1400, null, null, 15, 0, 0, 'xr', [['RAD-FILM', 2]]],
        'XR-KNEE' => ['X-Ray Knee AP/Lat', 'XR', 'Knee', 1200, null, null, 10, 0, 0, 'xr', [['RAD-FILM', 1]]],
        'XR-HAND' => ['X-Ray Hand / Wrist', 'XR', 'Hand', 900, null, null, 10, 0, 0, 'xr', [['RAD-FILM', 1]]],
        'US-ABD'  => ['Ultrasound Abdomen', 'US', 'Abdomen', 1800, 'Ultrasound Abdomen', 'Fast 6 hours before the scan.', 20, 0, 0, 'usabd', [['RAD-GEL', 0.1]]],
        'US-PELV' => ['Ultrasound Pelvis', 'US', 'Pelvis', 1800, null, 'Full bladder: drink 1 litre of water 1 hour before.', 20, 0, 0, 'us', [['RAD-GEL', 0.1]]],
        'US-OB'   => ['Obstetric Ultrasound', 'US', 'Pregnancy', 2000, null, null, 20, 0, 1, 'usob', [['RAD-GEL', 0.1]]],
        'US-KUB'  => ['Ultrasound KUB', 'US', 'Renal', 1500, null, 'Full bladder.', 15, 0, 0, 'us', [['RAD-GEL', 0.1]]],
        'CT-HEAD' => ['CT Head (plain)', 'CT', 'Head', 6000, null, null, 15, 0, 0, 'ct', []],
        'CT-ABDC' => ['CT Abdomen with contrast', 'CT', 'Abdomen', 14000, null, 'Fast 4 hours; creatinine report needed.', 30, 1, 0, 'ct', []],
        'OPG'     => ['OPG (panoramic dental X-ray)', 'OPG', 'Teeth', 1500, null, null, 10, 0, 0, 'xr', []],
        'PA-DENT' => ['Dental periapical X-ray', 'OPG', 'Tooth', 500, null, null, 5, 0, 0, 'xr', []],
    ];

    public const TEMPLATES = [
        'cxr'   => ['Chest X-ray — normal', 'Single PA erect view.', 'Lungs are clear. No focal consolidation, effusion or pneumothorax. Cardiac silhouette is normal in size. Mediastinum and hila are unremarkable. Bony thorax intact.', 'Normal chest radiograph.'],
        'xr'    => ['Plain radiograph — normal', 'Standard views.', 'No fracture or dislocation. Joint spaces preserved. No focal bony lesion. Soft tissues unremarkable.', 'No significant abnormality.'],
        'usabd' => ['US abdomen — normal', 'Real-time grey-scale ultrasound of the abdomen.', 'Liver normal in size and echotexture, no focal lesion. Gallbladder normal, no calculi. CBD not dilated. Pancreas, spleen and both kidneys normal. No free fluid.', 'Normal abdominal ultrasound.'],
        'us'    => ['Ultrasound — normal', 'Real-time grey-scale ultrasound.', 'No abnormality detected in the examined region.', 'Normal study.'],
        'usob'  => ['Obstetric ultrasound', 'Trans-abdominal obstetric ultrasound.', 'Single live intrauterine fetus. Cardiac activity present. Liquor adequate. Placenta — . Presentation — .', 'Single live intrauterine pregnancy corresponding to the stated gestation.'],
        'ct'    => ['CT — normal', 'Axial sections acquired; multiplanar reformats reviewed.', 'No acute abnormality.', 'No significant abnormality.'],
    ];

    public static function run(): void
    {
        if (!Migrator::tableExists('radiology_modalities')) {
            return;
        }
        $now = now();
        $i = 0;
        foreach (self::MODALITIES as $code => [$name, $ion]) {
            $i++;
            if (!DB::value('SELECT 1 FROM radiology_modalities WHERE code = ?', [$code])) {
                DB::insert('radiology_modalities', ['code' => $code, 'name' => $name, 'is_ionizing' => $ion, 'is_active' => 1, 'display_order' => $i, 'created_at' => $now]);
            }
        }
        $mods = DB::pairs('SELECT code, id FROM radiology_modalities');
        $branch = (int) setting('default_branch_id', 0) ?: null;
        if (!DB::value('SELECT 1 FROM radiology_rooms')) {
            $i = 0;
            foreach (self::ROOMS as $name => [$m, $slot]) {
                $i++;
                $room = DB::insert('radiology_rooms', ['branch_id' => $branch, 'modality_id' => $mods[$m], 'name' => $name, 'slot_minutes' => $slot, 'is_active' => 1, 'display_order' => $i, 'created_at' => $now]);
                DB::insert('radiology_equipment', ['room_id' => $room, 'modality_id' => $mods[$m], 'name' => $name . ' unit', 'status' => 'active', 'created_at' => $now]);
                DB::insert('radiology_protocols', ['modality_id' => $mods[$m], 'name' => 'Standard ' . self::MODALITIES[$m][0],
                    'technical_parameters_json' => json_encode($m === 'XR' ? ['kVp' => 110, 'mAs' => 4] : ($m === 'CT' ? ['kV' => 120, 'slice_mm' => 5] : [])), 'contrast_default' => 0, 'is_active' => 1, 'created_at' => $now]);
            }
        }
        $tpl = [];
        foreach (self::TEMPLATES as $key => [$name, $tech, $find, $imp]) {
            $tpl[$key] = DB::value('SELECT id FROM radiology_report_templates WHERE name = ?', [$name])
                ?: DB::insert('radiology_report_templates', ['name' => $name, 'body_json' => json_encode(['technique' => $tech, 'findings' => $find, 'impression' => $imp]), 'is_active' => 1, 'created_at' => $now]);
        }
        $dept = DB::value("SELECT id FROM departments WHERE department_type = 'radiology' AND deleted_at IS NULL ORDER BY id LIMIT 1") ?: null;
        $i = 0;
        foreach (self::PROCEDURES as $code => [$name, $m, $part, $price, $inv, $prep, $mins, $contrast, $ob, $t, $consume]) {
            $i++;
            if (DB::value('SELECT 1 FROM radiology_procedure_catalog WHERE code = ?', [$code])) {
                continue;
            }
            $sid = DB::value('SELECT id FROM service_catalog WHERE code = ?', ['RAD-' . $code]);
            if (!$sid) {
                $sid = DB::insert('service_catalog', ['code' => 'RAD-' . $code, 'name' => $name, 'service_type' => 'radiology', 'department_id' => $dept,
                    'default_price' => $price, 'is_active' => 1, 'display_order' => $i, 'created_at' => $now]);
                DB::insert('service_prices', ['service_id' => $sid, 'price_list_type' => 'cash', 'price' => $price, 'valid_from' => '2000-01-01', 'created_at' => $now]);
                foreach ($consume as [$sku, $qty]) {
                    if ($item = DB::value('SELECT id FROM items WHERE sku = ?', [$sku])) {
                        DB::insert('service_consumption_templates', ['service_id' => $sid, 'item_id' => $item, 'qty' => $qty, 'from_location_type' => 'radiology_stock', 'is_mandatory' => 1]);
                    }
                }
            }
            DB::insert('radiology_procedure_catalog', ['code' => $code, 'modality_id' => $mods[$m], 'name' => $name, 'body_part' => $part, 'service_id' => $sid,
                'investigation_id' => $inv ? (DB::value('SELECT id FROM investigations WHERE name = ? AND deleted_at IS NULL', [$inv]) ?: null) : null,
                'default_protocol_id' => DB::value('SELECT id FROM radiology_protocols WHERE modality_id = ? ORDER BY id LIMIT 1', [$mods[$m]]) ?: null,
                'default_report_template_id' => $tpl[$t] ?? null, 'requires_prep' => $prep ? 1 : 0, 'prep_instructions' => $prep, 'est_duration_min' => $mins,
                'uses_ionizing_radiation' => self::MODALITIES[$m][1], 'contrast_possible' => $contrast, 'is_obstetric' => $ob, 'is_active' => 1, 'display_order' => $i, 'created_at' => $now]);
        }
    }
}
