<?php
declare(strict_types=1);

namespace App\Modules\Inventory;

use App\Core\DB;
use App\Core\Migrator;

/** Default stock data — idempotent (installer, migrator v6). */
final class InventorySeeder
{
    public const UOMS = [['Tablet', 'TAB'], ['Capsule', 'CAP'], ['Bottle', 'BTL'], ['Vial', 'VIAL'], ['Ampoule', 'AMP'], ['Tube', 'TUBE'], ['Sachet', 'SCH'],
        ['Inhaler', 'INH'], ['Piece', 'PCS'], ['Box', 'BOX'], ['Pack', 'PCK'], ['Pair', 'PAIR'], ['Strip', 'STRIP'], ['ml', 'ML'], ['Syringe', 'SYR'], ['Kit', 'KIT'], ['Film', 'FILM'], ['Dose', 'DOSE']];

    public const CATEGORIES = [['Drugs', 'drug'], ['Medical consumables', 'medical_consumable'], ['Surgical instruments', 'surgical_instrument'], ['Implants', 'implant'],
        ['Lab reagents & tubes', 'reagent'], ['Radiology consumables', 'radiology_consumable'], ['Dental consumables', 'dental_consumable'], ['Vaccines', 'vaccine'],
        ['Housekeeping', 'housekeeping'], ['Stationery', 'stationery'], ['Equipment', 'equipment']];

    /** name => [type, code] */
    public const LOCATIONS = [
        'Central Store' => ['central_store', 'CS'], 'Main Pharmacy (OPD)' => ['pharmacy_main', 'PH'], 'Laboratory Stock' => ['lab_stock', 'LAB'],
        'Radiology Stock' => ['radiology_stock', 'RAD'], 'Dental Stock' => ['dental_stock', 'DEN'], 'OPD / Ward Stock' => ['ward_stock', 'WARD'],
        'Emergency Crash Cart' => ['emergency_crash_cart', 'CART'], 'Quarantine' => ['quarantine', 'QUAR'], 'Expired Hold' => ['expired_hold', 'EXP'],
    ];

    /** sku => [name, category type, uom code, sale price, cost, batch?, expiry?, cold?] */
    public const CONSUMABLES = [
        'LAB-EDTA'  => ['EDTA tube (purple, 3 ml)', 'reagent', 'PCS', 0, 18, 1, 1, 0],
        'LAB-GEL'   => ['Gel / plain tube (yellow)', 'reagent', 'PCS', 0, 20, 1, 1, 0],
        'LAB-FLU'   => ['Fluoride tube (grey)', 'reagent', 'PCS', 0, 18, 1, 1, 0],
        'LAB-CIT'   => ['Citrate tube (blue)', 'reagent', 'PCS', 0, 22, 1, 1, 0],
        'LAB-URC'   => ['Urine container (sterile)', 'reagent', 'PCS', 0, 12, 1, 1, 0],
        'LAB-CBCR'  => ['Haematology reagent (per test)', 'reagent', 'ML', 0, 15, 1, 1, 0],
        'LAB-CHEM'  => ['Biochemistry reagent (per test)', 'reagent', 'ML', 0, 25, 1, 1, 1],
        'CON-SYR5'  => ['Disposable syringe 5 ml', 'medical_consumable', 'SYR', 15, 8, 1, 1, 0],
        'CON-GLOV'  => ['Examination gloves (pair)', 'medical_consumable', 'PAIR', 0, 12, 1, 1, 0],
        'CON-GAUZE' => ['Gauze swab (pack)', 'medical_consumable', 'PCK', 30, 15, 1, 1, 0],
        'CON-CANN'  => ['IV cannula 20G', 'medical_consumable', 'PCS', 120, 60, 1, 1, 0],
        'RAD-FILM'  => ['X-ray film 14×17', 'radiology_consumable', 'FILM', 0, 220, 1, 1, 0],
        'RAD-GEL'   => ['Ultrasound gel (250 ml)', 'radiology_consumable', 'BTL', 0, 180, 1, 1, 0],
        'RAD-OMNI'  => ['Iohexol contrast 350 (50 ml)', 'radiology_consumable', 'VIAL', 0, 2500, 1, 1, 0],
        'DEN-COMP'  => ['Composite resin (syringe, per use)', 'dental_consumable', 'PCS', 0, 350, 1, 1, 0],
        'DEN-BOND'  => ['Bonding agent (per use)', 'dental_consumable', 'PCS', 0, 120, 1, 1, 0],
        'DEN-LIDO'  => ['Lidocaine 2% dental cartridge', 'dental_consumable', 'PCS', 0, 45, 1, 1, 0],
        'DEN-GP'    => ['Gutta-percha points (per canal)', 'dental_consumable', 'PCS', 0, 60, 1, 1, 0],
        'VAC-BCG'   => ['BCG vaccine (dose)', 'vaccine', 'DOSE', 0, 0, 1, 1, 1],
        'VAC-OPV'   => ['Oral polio vaccine (dose)', 'vaccine', 'DOSE', 0, 0, 1, 1, 1],
        'VAC-PENTA' => ['Pentavalent vaccine (dose)', 'vaccine', 'DOSE', 0, 0, 1, 1, 1],
        'VAC-MR'    => ['Measles-Rubella vaccine (dose)', 'vaccine', 'DOSE', 0, 0, 1, 1, 1],
        'VAC-TT'    => ['Tetanus toxoid (dose)', 'vaccine', 'DOSE', 0, 0, 1, 1, 1],
    ];

    public static function run(): void
    {
        if (!Migrator::tableExists('items')) {
            return;
        }
        $now = now();
        foreach (self::UOMS as $i => [$name, $code]) {
            if (!DB::value('SELECT 1 FROM uoms WHERE code = ?', [$code])) {
                DB::insert('uoms', ['name' => $name, 'code' => $code, 'is_active' => 1, 'display_order' => $i + 1, 'created_at' => $now]);
            }
        }
        foreach (self::CATEGORIES as $i => [$name, $type]) {
            if (!DB::value('SELECT 1 FROM item_categories WHERE type = ? AND parent_id IS NULL', [$type])) {
                DB::insert('item_categories', ['name' => $name, 'type' => $type, 'is_active' => 1, 'display_order' => $i + 1, 'created_at' => $now]);
            }
        }
        $branch = (int) setting('default_branch_id', 0) ?: null;
        $order = 0;
        foreach (self::LOCATIONS as $name => [$type, $code]) {
            $order++;
            if (!DB::value('SELECT 1 FROM stock_locations WHERE location_type = ? AND deleted_at IS NULL', [$type])) {
                $dept = DB::value('SELECT id FROM departments WHERE department_type = ? AND deleted_at IS NULL ORDER BY id LIMIT 1',
                    [['central_store' => 'store', 'pharmacy_main' => 'pharmacy', 'lab_stock' => 'lab', 'radiology_stock' => 'radiology'][$type] ?? '']);
                DB::insert('stock_locations', ['branch_id' => $branch, 'code' => $code, 'name' => $name, 'location_type' => $type, 'department_id' => $dept ?: null,
                    'allow_negative' => $type === 'emergency_crash_cart' ? 1 : 0, 'cold_storage' => in_array($type, ['pharmacy_main', 'central_store', 'lab_stock'], true) ? 1 : 0,
                    'is_active' => 1, 'display_order' => $order, 'created_at' => $now]);
            }
        }
        // Distributors of the v1 medicine sources become vendors.
        if (!DB::value('SELECT 1 FROM vendors')) {
            foreach (DB::all("SELECT * FROM medicine_sources WHERE deleted_at IS NULL AND (name LIKE 'Distributor%' OR name LIKE '%Supplier%')") as $s) {
                DB::insert('vendors', ['name' => $s['name'], 'vendor_type' => 'supplier', 'contact_person' => $s['contact_person'], 'phone' => $s['contact_phone'],
                    'address' => $s['address'], 'payment_terms_days' => 30, 'is_active' => 1, 'display_order' => 1, 'created_at' => $now]);
            }
            DB::insert('vendors', ['name' => 'City Reference Laboratory', 'vendor_type' => 'external_lab', 'phone' => '042-111-000-111', 'payment_terms_days' => 30,
                'is_active' => 1, 'display_order' => 9, 'created_at' => $now]);
        }
        // The hospital-supplied source dispenses from the main pharmacy.
        $pharmacy = DB::value("SELECT id FROM stock_locations WHERE location_type = 'pharmacy_main' AND deleted_at IS NULL ORDER BY id LIMIT 1");
        if ($pharmacy) {
            DB::run("UPDATE medicine_sources SET stock_location_id = ? WHERE stock_location_id IS NULL AND (name LIKE '%Hospital%' OR short_code = 'HS')", [$pharmacy]);
            if (!setting('pharmacy_location_id')) {
                \App\Core\Settings::set(['pharmacy_location_id' => (string) $pharmacy]);
            }
        }
        foreach (DB::column('SELECT id FROM medicines WHERE item_id IS NULL') as $mid) {
            self::itemForMedicine((int) $mid);
        }
        // Pharmacy safety data for the default medicines (Chief Pharmacist edits it later).
        if (Migrator::tableExists('drug_interactions') && !DB::value('SELECT 1 FROM drug_interactions')) {
            foreach ([
                ['Warfarin', 'Aspirin', 'major', 'Bleeding risk — avoid or monitor INR closely.'],
                ['Ciprofloxacin', 'Antacid', 'moderate', 'Antacids reduce ciprofloxacin absorption — give 2 h apart.'],
                ['Metformin', 'Contrast', 'major', 'Hold metformin around iodinated contrast (lactic acidosis).'],
                ['Atorvastatin', 'Clarithromycin', 'major', 'Myopathy / rhabdomyolysis risk.'],
                ['Losartan', 'Potassium', 'moderate', 'Hyperkalaemia risk.'],
                ['Ibuprofen', 'Diclofenac', 'moderate', 'Two NSAIDs — GI bleeding and renal risk.'],
                ['Azithromycin', 'Domperidone', 'major', 'QT prolongation.'],
                ['Metronidazole', 'Warfarin', 'major', 'Raises INR — bleeding risk.'],
            ] as [$a, $b, $sev, $desc]) {
                DB::insert('drug_interactions', ['generic_a' => $a, 'generic_b' => $b, 'severity' => $sev, 'description' => $desc, 'is_active' => 1, 'created_at' => $now]);
            }
            foreach (['Amoxicillin' => 'Penicillin', 'Ceftriaxone' => 'Cephalosporin', 'Ciprofloxacin' => 'Quinolone', 'Ibuprofen' => 'NSAID', 'Diclofenac' => 'NSAID',
                      'Azithromycin' => 'Macrolide', 'Metronidazole' => 'Nitroimidazole'] as $generic => $class) {
                foreach (DB::column('SELECT id FROM medicines WHERE generic_name LIKE ?', ['%' . $generic . '%']) as $mid) {
                    DB::insert('drug_allergy_classes', ['medicine_id' => $mid, 'allergy_class' => $class]);
                }
            }
        }
        $cats = DB::pairs('SELECT type, id FROM item_categories WHERE parent_id IS NULL');
        $uoms = DB::pairs('SELECT code, id FROM uoms');
        foreach (self::CONSUMABLES as $sku => [$name, $type, $uom, $price, $cost, $batch, $expiry, $cold]) {
            if (!DB::value('SELECT 1 FROM items WHERE sku = ?', [$sku])) {
                DB::insert('items', ['sku' => $sku, 'name' => $name, 'category_id' => $cats[$type] ?? null, 'item_type' => $type, 'base_uom_id' => $uoms[$uom] ?? null,
                    'is_batch_tracked' => $batch, 'is_expiry_tracked' => $expiry, 'is_cold_chain' => $cold, 'standard_cost' => $cost, 'avg_cost' => $cost,
                    'sale_price' => $price, 'is_active' => 1, 'created_at' => $now]);
            }
        }
    }

    /** The stock item of a medicine (created on first use; name, price and flags follow the medicine). */
    public static function itemForMedicine(int $medicineId): ?int
    {
        $m = DB::one('SELECT m.*, f.name AS form_name FROM medicines m LEFT JOIN dosage_forms f ON f.id = m.dosage_form_id WHERE m.id = ?', [$medicineId]);
        if (!$m) {
            return null;
        }
        if ($m['item_id'] && DB::value('SELECT 1 FROM items WHERE id = ?', [$m['item_id']])) {
            return (int) $m['item_id'];
        }
        $existing = DB::value('SELECT id FROM items WHERE medicine_id = ?', [$medicineId]);
        if ($existing) {
            DB::update('medicines', ['item_id' => $existing], 'id = ?', [$medicineId]);
            return (int) $existing;
        }
        $unit = strtolower((string) $m['unit']);
        $uomCode = ['tablet' => 'TAB', 'capsule' => 'CAP', 'bottle' => 'BTL', 'vial' => 'VIAL', 'ampoule' => 'AMP', 'tube' => 'TUBE', 'sachet' => 'SCH', 'inhaler' => 'INH'][$unit] ?? 'PCS';
        $id = DB::insert('items', [
            'sku' => 'MED-' . str_pad((string) $medicineId, 5, '0', STR_PAD_LEFT), 'name' => trim($m['name'] . ' ' . ($m['strength'] ?? '')),
            'category_id' => DB::value("SELECT id FROM item_categories WHERE type = 'drug' AND parent_id IS NULL") ?: null, 'item_type' => 'drug',
            'base_uom_id' => DB::value('SELECT id FROM uoms WHERE code = ?', [$uomCode]) ?: null, 'medicine_id' => $medicineId,
            'is_batch_tracked' => 1, 'is_expiry_tracked' => 1, 'is_controlled' => (int) ($m['is_controlled'] ?? 0), 'is_cold_chain' => 0,
            'standard_cost' => round((float) $m['price'] * 0.8, 4), 'avg_cost' => round((float) $m['price'] * 0.8, 4), 'sale_price' => (float) $m['price'],
            'is_active' => (int) $m['is_active'], 'created_at' => now(),
        ]);
        DB::update('medicines', ['item_id' => $id], 'id = ?', [$medicineId]);
        return $id;
    }
}
