<?php
/**
 * Default data: departments, users, and every master list with realistic
 * Pakistani clinic values (sources, complaints, symptoms, ICD-10 diagnoses,
 * investigations, routes, dosage forms, frequencies, instructions, medicines).
 *
 * Options
 *   sample_users => true  : superadmin / deptadmin / doctor / doctor2 / reporting
 *   super_admin  => [...] : used instead of the sample users (factory reset)
 */
declare(strict_types=1);

use App\Core\DB;
use App\Modules\Users\UserService;

return static function (array $options = []): void {
    $now = now();

    $insertAll = static function (string $table, array $columns, array $rows) use ($now): array {
        $ids = [];
        foreach ($rows as $i => $values) {
            $data = array_combine($columns, $values);
            $data['display_order'] = $data['display_order'] ?? ($i + 1);
            $data['is_active'] = $data['is_active'] ?? 1;
            $data['created_at'] = $now;
            $ids[$data['name']] = DB::insert($table, $data);
        }
        return $ids;
    };

    DB::transaction(static function () use ($options, $insertAll): void {
        // ---- Departments -------------------------------------------------
        $dept = $insertAll('departments', ['name', 'code', 'description'], [
            ['General OPD', 'OPD', 'General outpatient consultations'],
            ['Internal Medicine', 'MED', 'Adult medicine and chronic disease clinic'],
            ['Paediatrics', 'PED', 'Child health clinic'],
        ]);

        // ---- Users ---------------------------------------------------------
        $users = [];
        if (!empty($options['super_admin'])) {
            $sa = $options['super_admin'];
            $users[] = ['name' => $sa['name'], 'username' => $sa['username'], 'password' => $sa['password'], 'role' => 'super_admin',
                'mobile' => $sa['mobile'] ?? null, 'email' => $sa['email'] ?? null, 'depts' => []];
        } else {
            $users[] = ['name' => 'System Administrator', 'username' => 'superadmin', 'password' => 'admin123', 'role' => 'super_admin', 'mobile' => '03000000001', 'email' => 'admin@medicare.local', 'depts' => []];
        }
        if (($options['sample_users'] ?? true) && empty($options['super_admin'])) {
            $users[] = ['name' => 'Ayesha Siddiqui', 'username' => 'deptadmin', 'password' => 'admin123', 'role' => 'dept_admin', 'mobile' => '03000000002', 'email' => null, 'depts' => ['General OPD', 'Internal Medicine']];
            $users[] = ['name' => 'Dr. Ahmed Raza', 'username' => 'doctor', 'password' => 'doctor123', 'role' => 'doctor', 'mobile' => '03000000003', 'email' => null, 'depts' => ['General OPD', 'Internal Medicine'],
                'profile' => ['pmdc_registration' => '12345-P', 'qualification' => 'MBBS, FCPS (Medicine)', 'specialization' => 'Consultant Physician', 'mrn_pattern' => 'DR01-{YYYY}-{000001}', 'default_fee' => 1500, 'signature_text' => 'Dr. Ahmed Raza, MBBS, FCPS']];
            $users[] = ['name' => 'Dr. Sana Malik', 'username' => 'doctor2', 'password' => 'doctor123', 'role' => 'doctor', 'mobile' => '03000000004', 'email' => null, 'depts' => ['Paediatrics'],
                'profile' => ['pmdc_registration' => '67890-P', 'qualification' => 'MBBS, DCH', 'specialization' => 'Paediatrician', 'mrn_pattern' => 'DOC2-{YYYY}-{000001}', 'default_fee' => 1200, 'signature_text' => 'Dr. Sana Malik, MBBS, DCH']];
            $users[] = ['name' => 'Bilal Ahmad', 'username' => 'reporting', 'password' => 'report123', 'role' => 'reporting', 'mobile' => '03000000005', 'email' => null, 'depts' => []];
        }
        foreach ($users as $u) {
            $deptIds = array_map(static fn ($n) => $dept[$n], $u['depts']);
            $id = DB::insert('users', [
                'employee_code' => UserService::nextEmployeeCode($u['role'], $deptIds),
                'name'          => $u['name'],
                'username'      => $u['username'],
                'password'      => $u['password'],
                'mobile'        => $u['mobile'],
                'email'         => $u['email'],
                'role'          => $u['role'],
                'is_active'     => 1,
                'created_at'    => now(),
            ]);
            foreach ($deptIds as $d) {
                DB::insert('user_departments', ['user_id' => $id, 'department_id' => $d]);
            }
            if ($u['role'] === 'doctor') {
                DB::insert('doctor_profiles', array_merge(['user_id' => $id, 'updated_at' => now()], $u['profile']));
                UserService::ensureDoctorSetup($id);
            }
        }

        // ---- Masters ---------------------------------------------------------
        $sources = $insertAll('medicine_sources', ['name', 'short_code', 'contact_person', 'contact_phone', 'address'], [
            ['Local Pharmacy', 'LP', 'Imran Khan', '042-35000001', 'Main Bazaar'],
            ['Hospital Stock', 'HS', 'Store In-charge', '042-35000002', 'Clinic store room'],
            ['Distributor A', 'DA', 'Kamran Ali', '0300-1112233', 'Hall Road, Lahore'],
            ['Distributor B', 'DB', 'Usman Tariq', '0321-4445566', 'Urdu Bazaar, Lahore'],
            ['Sample Stock', 'SS', null, null, 'Company samples'],
            ['Company Provided', 'CP', null, null, 'Supplied free by companies'],
        ]);

        $insertAll('presenting_complaints', ['name', 'description'], array_map(static fn ($n) => [$n, null], [
            'Fever', 'Cough', 'Headache', 'Chest Pain', 'Abdominal Pain', 'Shortness of Breath', 'Body Aches', 'Vomiting',
            'Diarrhea', 'Dizziness', 'Sore Throat', 'Runny Nose', 'Back Pain', 'Joint Pain', 'Burning Micturition', 'Skin Rash',
        ]));

        $insertAll('symptoms', ['name', 'category', 'description'], [
            ['Nausea', 'Gastrointestinal', null], ['Fatigue', 'General', null], ['Loss of Appetite', 'Gastrointestinal', null],
            ['Insomnia', 'Neurological', null], ['Blurred Vision', 'Eye', null], ['Palpitations', 'Cardiovascular', null],
            ['Night Sweats', 'General', null], ['Weight Loss', 'General', null], ['Chills', 'General', null],
            ['Sneezing', 'Respiratory', null], ['Constipation', 'Gastrointestinal', null], ['Itching', 'Skin', null],
        ]);

        $insertAll('diagnoses', ['name', 'code', 'description'], [
            ['Acute Upper Respiratory Tract Infection', 'J06.9', null], ['Essential Hypertension', 'I10', null],
            ['Type 2 Diabetes Mellitus', 'E11.9', null], ['Acute Gastroenteritis', 'A09', null], ['Typhoid Fever', 'A01.0', null],
            ['Dengue Fever', 'A90', null], ['Malaria, unspecified', 'B54', null], ['Urinary Tract Infection', 'N39.0', null],
            ['Migraine', 'G43.9', null], ['Iron Deficiency Anaemia', 'D50.9', null], ['Acute Pharyngitis', 'J02.9', null],
            ['Bronchial Asthma', 'J45.9', null], ['Gastro-oesophageal Reflux Disease', 'K21.9', null], ['Tension-type Headache', 'G44.2', null],
            ['Allergic Rhinitis', 'J30.4', null], ['Osteoarthritis', 'M19.9', null], ['Low Back Pain', 'M54.5', null], ['Acute Bronchitis', 'J20.9', null],
        ]);

        $insertAll('investigations', ['name', 'category', 'description'], [
            ['Complete Blood Count (CBC)', 'Haematology', null], ['ESR', 'Haematology', null], ['C-Reactive Protein (CRP)', 'Biochemistry', null],
            ['Blood Sugar Fasting', 'Biochemistry', null], ['Blood Sugar Random', 'Biochemistry', null], ['HbA1c', 'Biochemistry', null],
            ['Liver Function Tests (LFTs)', 'Biochemistry', null], ['Renal Function Tests (RFTs)', 'Biochemistry', null],
            ['Lipid Profile', 'Biochemistry', null], ['Serum Electrolytes', 'Biochemistry', null], ['Urine Routine Examination', 'Urine', null],
            ['Typhidot', 'Serology', null], ['Dengue NS1 Antigen', 'Serology', null], ['Malaria Parasite (MP) Smear', 'Haematology', null],
            ['X-Ray Chest PA View', 'Radiology', null], ['Ultrasound Abdomen', 'Radiology', null], ['ECG', 'Cardiology', null], ['TSH', 'Endocrinology', null],
        ]);

        $routes = $insertAll('routes', ['name', 'short_name'], [
            ['Oral', 'PO'], ['Intravenous', 'IV'], ['Intramuscular', 'IM'], ['Subcutaneous', 'SC'], ['Topical', 'TOP'],
            ['Inhalation', 'INH'], ['Sublingual', 'SL'], ['Rectal', 'PR'], ['Nasal', 'NAS'], ['Ophthalmic', 'OPH'],
        ]);

        $forms = $insertAll('dosage_forms', ['name', 'short_name'], [
            ['Tablet', 'Tab'], ['Capsule', 'Cap'], ['Syrup', 'Syp'], ['Injection', 'Inj'], ['Cream', 'Crm'], ['Ointment', 'Oint'],
            ['Drops', 'Drops'], ['Suspension', 'Susp'], ['Sachet', 'Sachet'], ['Inhaler', 'Inh'], ['Gel', 'Gel'],
        ]);

        $freq = $insertAll('frequencies', ['name', 'code', 'doses_per_day', 'calc_mode', 'description'], [
            ['Once a day', 'OD', 1, 'daily', 'One dose daily'],
            ['Twice a day', 'BD', 2, 'daily', 'Every 12 hours'],
            ['Three times a day', 'TDS', 3, 'daily', 'Every 8 hours'],
            ['Four times a day', 'QID', 4, 'daily', 'Every 6 hours'],
            ['At bedtime', 'HS', 1, 'daily', 'Once at night'],
            ['When required', 'SOS', 0, 'manual', 'Only when needed — quantity entered by the doctor'],
            ['As needed', 'PRN', 0, 'manual', 'Pro re nata — quantity entered by the doctor'],
            ['Once a week', 'Weekly', 1, 'weekly', 'One dose per week'],
            ['Immediately (single dose)', 'STAT', 0, 'manual', 'Single immediate dose'],
            ['Custom', 'Custom', 0, 'manual', 'Custom schedule — quantity entered by the doctor'],
        ]);

        $instr = $insertAll('instructions', ['name', 'description'], array_map(static fn ($n) => [$n, null], [
            'After meal', 'Before meal', 'With water', 'At bedtime', 'On empty stomach', 'Apply locally', 'As directed',
            'With milk', 'Shake well before use', 'Dissolve in water',
        ]));

        // name, generic, brand, strength, form, route, freq, instruction, dose, dose unit, days, billing unit, doses per unit, price, source
        $medicines = [
            ['Panadol', 'Paracetamol', 'Panadol (GSK)', '500mg', 'Tablet', 'Oral', 'TDS', 'After meal', 1, 'tab', 5, 'Tablet', 1, 3, 'Local Pharmacy'],
            ['Brufen', 'Ibuprofen', 'Brufen (Abbott)', '400mg', 'Tablet', 'Oral', 'BD', 'After meal', 1, 'tab', 5, 'Tablet', 1, 6, 'Local Pharmacy'],
            ['Augmentin', 'Amoxicillin + Clavulanic Acid', 'Augmentin (GSK)', '625mg', 'Tablet', 'Oral', 'BD', 'After meal', 1, 'tab', 5, 'Tablet', 1, 45, 'Hospital Stock'],
            ['Risek', 'Omeprazole', 'Risek (Getz)', '40mg', 'Capsule', 'Oral', 'OD', 'Before meal', 1, 'cap', 14, 'Capsule', 1, 25, 'Hospital Stock'],
            ['Norvasc', 'Amlodipine', 'Norvasc (Pfizer)', '5mg', 'Tablet', 'Oral', 'OD', 'After meal', 1, 'tab', 30, 'Tablet', 1, 20, 'Distributor A'],
            ['Glucophage', 'Metformin', 'Glucophage (Merck)', '500mg', 'Tablet', 'Oral', 'BD', 'After meal', 1, 'tab', 30, 'Tablet', 1, 5, 'Distributor A'],
            ['Flagyl', 'Metronidazole', 'Flagyl (Sanofi)', '400mg', 'Tablet', 'Oral', 'TDS', 'After meal', 1, 'tab', 5, 'Tablet', 1, 4, 'Local Pharmacy'],
            ['Ciproxin', 'Ciprofloxacin', 'Ciproxin (Bayer)', '500mg', 'Tablet', 'Oral', 'BD', 'After meal', 1, 'tab', 5, 'Tablet', 1, 30, 'Hospital Stock'],
            ['Zyrtec', 'Cetirizine', 'Zyrtec (GSK)', '10mg', 'Tablet', 'Oral', 'HS', 'At bedtime', 1, 'tab', 5, 'Tablet', 1, 8, 'Local Pharmacy'],
            ['Ventolin Inhaler', 'Salbutamol', 'Ventolin (GSK)', '100mcg', 'Inhaler', 'Inhalation', 'SOS', 'As directed', 2, 'puff', 30, 'Inhaler', 200, 450, 'Distributor B'],
            ['Calpol Syrup', 'Paracetamol', 'Calpol (GSK)', '120mg/5ml', 'Syrup', 'Oral', 'TDS', 'After meal', 5, 'ml', 3, 'Bottle', 60, 120, 'Local Pharmacy'],
            ['Motilium', 'Domperidone', 'Motilium (Janssen)', '10mg', 'Tablet', 'Oral', 'TDS', 'Before meal', 1, 'tab', 3, 'Tablet', 1, 4, 'Local Pharmacy'],
            ['ORS Sachet', 'Oral Rehydration Salts', 'Peditral', '20.5g', 'Sachet', 'Oral', 'SOS', 'Dissolve in water', 1, 'sachet', 3, 'Sachet', 1, 25, 'Local Pharmacy'],
            ['Rocephin Injection', 'Ceftriaxone', 'Rocephin (Roche)', '1g', 'Injection', 'Intravenous', 'OD', 'As directed', 1, 'vial', 3, 'Vial', 1, 350, 'Hospital Stock'],
            ['Decadron Injection', 'Dexamethasone', 'Decadron', '4mg', 'Injection', 'Intramuscular', 'STAT', 'As directed', 1, 'amp', 1, 'Ampoule', 1, 60, 'Hospital Stock'],
            ['Voltral', 'Diclofenac Sodium', 'Voltral (Novartis)', '50mg', 'Tablet', 'Oral', 'BD', 'After meal', 1, 'tab', 5, 'Tablet', 1, 5, 'Local Pharmacy'],
            ['Voltral Emulgel', 'Diclofenac Diethylamine', 'Voltral Emulgel', '1%', 'Gel', 'Topical', 'TDS', 'Apply locally', 1, 'application', 7, 'Tube', 30, 280, 'Distributor B'],
            ['Cozaar', 'Losartan', 'Cozaar (MSD)', '50mg', 'Tablet', 'Oral', 'OD', 'After meal', 1, 'tab', 30, 'Tablet', 1, 18, 'Distributor A'],
            ['Lipiget', 'Atorvastatin', 'Lipiget (Getz)', '20mg', 'Tablet', 'Oral', 'HS', 'At bedtime', 1, 'tab', 30, 'Tablet', 1, 22, 'Distributor A'],
            ['Montiget', 'Montelukast', 'Montiget (Getz)', '10mg', 'Tablet', 'Oral', 'HS', 'At bedtime', 1, 'tab', 30, 'Tablet', 1, 30, 'Distributor B'],
            ['Azomax', 'Azithromycin', 'Azomax (Novartis)', '500mg', 'Tablet', 'Oral', 'OD', 'On empty stomach', 1, 'tab', 3, 'Tablet', 1, 90, 'Hospital Stock'],
            ['Nexum', 'Esomeprazole', 'Nexum (Getz)', '40mg', 'Capsule', 'Oral', 'OD', 'Before meal', 1, 'cap', 14, 'Capsule', 1, 28, 'Distributor B'],
            ['Sunny D', 'Cholecalciferol (Vitamin D3)', 'Sunny D', '200000 IU', 'Capsule', 'Oral', 'Weekly', 'With milk', 1, 'cap', 56, 'Capsule', 1, 180, 'Company Provided'],
            ['Iberet', 'Ferrous Sulphate + Vitamins', 'Iberet (Abbott)', '525mg', 'Tablet', 'Oral', 'OD', 'After meal', 1, 'tab', 30, 'Tablet', 1, 12, 'Sample Stock'],
            ['Folic Acid', 'Folic Acid', 'Folic Acid', '5mg', 'Tablet', 'Oral', 'OD', 'After meal', 1, 'tab', 30, 'Tablet', 1, 2, 'Sample Stock'],
            ['Vermox', 'Mebendazole', 'Vermox (Janssen)', '100mg', 'Tablet', 'Oral', 'BD', 'After meal', 1, 'tab', 3, 'Tablet', 1, 10, 'Local Pharmacy'],
            ['Gaviscon Syrup', 'Sodium Alginate + Antacid', 'Gaviscon (Reckitt)', '120ml', 'Syrup', 'Oral', 'TDS', 'After meal', 10, 'ml', 5, 'Bottle', 120, 280, 'Distributor B'],
            ['Moxiget Eye Drops', 'Moxifloxacin', 'Moxiget (Getz)', '0.5%', 'Drops', 'Ophthalmic', 'QID', 'As directed', 1, 'drop', 7, 'Bottle', 100, 220, 'Distributor A'],
        ];
        $freqByCode = ['OD' => 'Once a day', 'BD' => 'Twice a day', 'TDS' => 'Three times a day', 'QID' => 'Four times a day', 'HS' => 'At bedtime',
            'SOS' => 'When required', 'PRN' => 'As needed', 'Weekly' => 'Once a week', 'STAT' => 'Immediately (single dose)', 'Custom' => 'Custom'];
        foreach ($medicines as $i => $m) {
            DB::insert('medicines', [
                'name'             => $m[0],
                'generic_name'     => $m[1],
                'brand_name'       => $m[2],
                'strength'         => $m[3],
                'dosage_form_id'   => $forms[$m[4]],
                'route_id'         => $routes[$m[5]],
                'frequency_id'     => $freq[$freqByCode[$m[6]]],
                'instruction_id'   => $instr[$m[7]],
                'default_dose'     => $m[8],
                'dose_unit'        => $m[9],
                'default_duration' => $m[10],
                'unit'             => $m[11],
                'pack_size'        => $m[12],
                'price'            => $m[13],
                'source_id'        => $sources[$m[14]],
                'is_active'        => 1,
                'display_order'    => $i + 1,
                'created_at'       => now(),
            ]);
        }
    });
};
