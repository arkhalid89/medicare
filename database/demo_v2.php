<?php
/**
 * v2 hospital demo activity, created through the real services: opening stock
 * in every location, today's tokens, lab orders at every stage (with QC),
 * imaging orders at every stage, dental charts and plans, pregnancies with
 * ANC / delivery / newborn, immunizations, a pharmacy sale and payments.
 * Each block is independent — a failure is logged and the rest continues.
 */
declare(strict_types=1);

use App\Core\Auth;
use App\Core\DB;
use App\Core\Logger;
use App\Modules\Billing\BillingService;
use App\Modules\Dental\DentalService;
use App\Modules\Frontdesk\TokenService;
use App\Modules\Inventory\StockService;
use App\Modules\Lab\LabService;
use App\Modules\Obgyn\ObgynService;
use App\Modules\Patients\PatientService;
use App\Modules\Radiology\RadiologyService;
use App\Modules\Specialty\SpecialtyContext;
use App\Modules\Specialty\SpecialtyService;
use App\Modules\Visits\VisitService;

return static function (): void {
    mt_srand(2027);
    $as = static function (string $username): ?array {
        $u = DB::one('SELECT * FROM users WHERE username = ? AND deleted_at IS NULL', [$username]);
        Auth::actingAs($u ?: DB::one("SELECT * FROM users WHERE role = 'super_admin' ORDER BY id LIMIT 1"));
        return $u;
    };
    $block = static function (string $name, callable $fn): void {
        try {
            $fn();
        } catch (\Throwable $e) {
            Logger::error('Demo v2 (' . $name . '): ' . $e->getMessage());
        }
    };
    $patient = static function (string $name, string $gender, string $dob): int {
        [$data] = PatientService::validate(['name' => $name, 'gender' => $gender, 'dob' => $dob, 'phone' => '03' . mt_rand(10, 49) . mt_rand(1000000, 9999999), 'city' => 'Lahore']);
        return (int) PatientService::create($data, null)['id'];
    };
    $visit = static function (string $doctor, int $pid, ?int $dept, array $over = []) use ($as): ?int {
        $u = $as($doctor);
        if (!$u) {
            return null;
        }
        $p = DB::one('SELECT * FROM patients WHERE id = ?', [$pid]);
        $res = VisitService::checkout(array_replace([
            'token' => bin2hex(random_bytes(16)), 'doctor_id' => (int) $u['id'], 'department_id' => $dept,
            'patient' => ['id' => $pid, 'name' => $p['name'], 'gender' => $p['gender'], 'dob' => $p['dob'], 'phone' => $p['phone']],
            'vitals' => ['bp_systolic' => '120', 'bp_diastolic' => '80', 'pulse' => '78'], 'complaints' => [], 'symptoms' => [], 'diagnoses' => [],
            'investigations' => [], 'medicines' => [], 'follow_up_date' => '', 'consultation_fee' => '1000', 'payment_status' => 'Paid',
            'discount_type' => 'amount', 'discount_value' => '0',
        ], $over), $u);
        if (!$res['ok']) {
            throw new \RuntimeException(implode('; ', $res['errors'] ?? ['checkout failed']));
        }
        return (int) $res['visit_id'];
    };
    $inv = static fn (string $name): array => ['id' => (int) DB::value('SELECT id FROM investigations WHERE name = ?', [$name]), 'name' => $name];

    // ---------------------------------------------------------- opening stock
    $block('stock', static function () use ($as): void {
        $as('store');
        $byType = ['drug' => 'pharmacy_main', 'reagent' => 'lab_stock', 'radiology_consumable' => 'radiology_stock', 'dental_consumable' => 'dental_stock',
            'vaccine' => 'pharmacy_main', 'medical_consumable' => 'ward_stock'];
        foreach (DB::all('SELECT * FROM items WHERE is_active = 1 AND deleted_at IS NULL') as $it) {
            $loc = StockService::locationOfType($byType[$it['item_type']] ?? 'central_store');
            if (!$loc) {
                continue;
            }
            $qty = $it['item_type'] === 'drug' ? mt_rand(80, 400) : mt_rand(30, 150);
            $batch = StockService::batchFor((int) $it['id'], 'D' . date('y') . '-' . mt_rand(100, 999), date('Y-m-d', strtotime('+' . mt_rand(4, 24) . ' months')), ['unit_cost' => max(1, (float) $it['avg_cost'])]);
            StockService::move((int) $it['id'], $loc, $qty, 'opening', ['batch_id' => $batch, 'unit_cost' => max(1, (float) $it['avg_cost'])]);
            if ($it['item_type'] === 'drug' && mt_rand(1, 6) === 1) {
                // a short-dated batch so the expiry watch has something to show
                $b2 = StockService::batchFor((int) $it['id'], 'SD' . mt_rand(100, 999), date('Y-m-d', strtotime('+' . mt_rand(20, 80) . ' days')), ['unit_cost' => max(1, (float) $it['avg_cost'])]);
                StockService::move((int) $it['id'], $loc, mt_rand(10, 30), 'opening', ['batch_id' => $b2]);
            }
        }
    });

    // ------------------------------------------------------------- tokens
    $block('tokens', static function () use ($as): void {
        $as('reception');
        $doctor = DB::one("SELECT id FROM users WHERE username = 'doctor'");
        $queue = TokenService::queueForDoctor((int) $doctor['id']);
        $pids = DB::column('SELECT id FROM patients WHERE deleted_at IS NULL ORDER BY RAND(7) LIMIT 6');
        foreach ($pids as $i => $pid) {
            $t = TokenService::issue($queue, (int) $pid, ['priority' => $i === 4 ? 'elderly' : 'normal']);
            if ($i === 0) {
                TokenService::call((int) $t['id']);
                TokenService::start((int) $t['id']);
            }
        }
    });

    $opd = (int) DB::value("SELECT id FROM departments WHERE name = 'General OPD' AND deleted_at IS NULL") ?: null;

    // ------------------------------------------------------------ laboratory
    $block('lab', static function () use ($as, $patient, $visit, $inv, $opd): void {
        $tests = [['Complete Blood Count (CBC)'], ['Liver Function Tests (LFTs)', 'Renal Function Tests (RFTs)'], ['Lipid Profile'], ['Blood Sugar Fasting', 'HbA1c'],
            ['Serum Electrolytes'], ['Urine Routine Examination'], ['Typhidot'], ['Complete Blood Count (CBC)', 'C-Reactive Protein (CRP)']];
        $names = ['Tariq Mehmood', 'Nasreen Akhtar', 'Shahid Iqbal', 'Rubina Kausar', 'Aamir Sohail', 'Farzana Bibi', 'Junaid Akram', 'Samina Yasmin'];
        $as('labtech');
        foreach (DB::all('SELECT id FROM lab_qc_materials') as $m) {
            $mat = DB::one('SELECT * FROM lab_qc_materials WHERE id = ?', [$m['id']]);
            foreach ([0.4, -0.8, 1.1, 0.2] as $z) {
                LabService::qcRun((int) $m['id'], round((float) $mat['target_mean'] + $z * (float) $mat['target_sd'], 2));
            }
        }
        foreach ($tests as $i => $list) {
            $as('reception');
            $pid = $patient($names[$i], $i % 2 ? 'Female' : 'Male', date('Y-m-d', strtotime('-' . mt_rand(20, 70) . ' years')));
            $vid = $visit('doctor', $pid, $opd, ['investigations' => array_map($inv, $list), 'diagnoses' => []]);
            $order = (int) DB::value('SELECT id FROM lab_orders WHERE visit_id = ?', [$vid]);
            $items = DB::column('SELECT id FROM lab_order_items WHERE lab_order_id = ?', [$order]);
            $stage = $i % 5; // 0 ordered, 1 collected, 2 accepted, 3 entered, 4 released
            if ($stage >= 1) {
                $as('labtech');
                $samples = LabService::collect($items);
            }
            if ($stage >= 2) {
                $as('pathologist');
                foreach ($samples as $s) {
                    LabService::review($s, 'accept');
                }
            }
            if ($stage >= 3) {
                $as('labtech');
                foreach ($items as $it) {
                    $ws = LabService::worksheet((int) $it);
                    $vals = [];
                    foreach ($ws['params'] as $p) {
                        if ($p['data_type'] === 'select') {
                            $vals[$p['id']] = $p['options'][mt_rand(0, 3) === 0 ? 1 : 0] ?? '';
                        } else {
                            $r = $p['range'] ?? [];
                            $lo = (float) ($r['low'] ?? 1);
                            $hi = (float) ($r['high'] ?? ($lo * 2 + 1));
                            $vals[$p['id']] = (string) round($lo + ($hi - $lo) * (mt_rand(-15, 115) / 100), (int) $p['decimals']);
                        }
                    }
                    LabService::enterResults((int) $it, $vals);
                }
            }
            if ($stage >= 4) {
                $as('pathologist');
                foreach ($items as $it) {
                    LabService::verify((int) $it);
                }
            }
        }
    });

    // ------------------------------------------------------------- radiology
    $block('radiology', static function () use ($as, $patient): void {
        $codes = ['XR-CXR', 'US-ABD', 'XR-KNEE', 'US-KUB', 'XR-CXR', 'CT-HEAD'];
        $names = ['Ghulam Rasool', 'Parveen Akhtar', 'Sajid Hussain', 'Nighat Sultana', 'Arshad Mahmood', 'Bushra Naz'];
        foreach ($codes as $i => $code) {
            $as('reception');
            $female = $i % 2 === 1;
            $pid = $patient($names[$i], $female ? 'Female' : 'Male', date('Y-m-d', strtotime('-' . mt_rand(25, 65) . ' years')));
            $proc = (int) DB::value('SELECT id FROM radiology_procedure_catalog WHERE code = ?', [$code]);
            [$oid] = RadiologyService::order(['patient_id' => $pid, 'source' => 'walk_in', 'clinical_indication' => 'Demo — ' . ['cough', 'abdominal pain', 'knee pain', 'flank pain', 'fever', 'headache'][$i]],
                [$proc], ['pregnancy_status' => $female ? 'not_pregnant' : '']);
            $o = RadiologyService::find($oid);
            if ($o['pregnancy_status'] === 'unscreened') {
                RadiologyService::screen($oid, ['pregnancy_status' => 'not_pregnant']);
            }
            if ($i === 0) {
                continue; // waiting at reception
            }
            $as('radtech');
            $p = RadiologyService::start($oid);
            if ($i === 1) {
                continue; // in progress
            }
            RadiologyService::finish($p, ['image_count' => mt_rand(1, 3), 'kvp' => $code[0] === 'X' ? 110 : null, 'mas' => $code[0] === 'X' ? 4 : null, 'image_reference' => 'PACS-DEMO-' . $oid]);
            if ($i === 2) {
                continue; // awaiting the radiologist
            }
            $as('radiologist');
            RadiologyService::review($p, 'accept');
            if ($i === 3) {
                continue; // to report
            }
            $tpl = DB::one('SELECT t.body_json FROM radiology_report_templates t JOIN radiology_procedure_catalog c ON c.default_report_template_id = t.id WHERE c.id = ?', [$proc]);
            $b = json_decode((string) ($tpl['body_json'] ?? '{}'), true) ?: [];
            RadiologyService::saveReport($oid, ['technique' => $b['technique'] ?? '', 'findings' => $b['findings'] ?? 'No abnormality.', 'impression' => $b['impression'] ?? 'Normal study.'], true);
        }
    });

    // ------------------------------------------------------------- dentistry
    $block('dental', static function () use ($as, $patient, $visit): void {
        $dept = (int) (SpecialtyContext::departmentsWith('dental')[0] ?? 0);
        if (!$dept) {
            return;
        }
        $proc = DB::pairs('SELECT code, id FROM dental_procedure_catalog');
        $cases = [
            ['Kamran Akmal', 'Male', '-34 years', [['teeth' => ['26', '27'], 'condition' => 'caries', 'surfaces' => ['mesial', 'occlusal'], 'procedure_id' => $proc['FILL2'], 'status' => 'planned'],
                ['teeth' => ['36'], 'condition' => 'root_canal_needed', 'procedure_id' => $proc['RCT'], 'status' => 'planned'],
                ['teeth' => ['48'], 'condition' => 'impacted', 'procedure_id' => $proc['SURG'], 'status' => 'planned']]],
            ['Saima Riaz', 'Female', '-29 years', [['teeth' => [], 'condition' => 'healthy', 'procedure_id' => $proc['SCAL'], 'status' => 'completed'],
                ['teeth' => ['14'], 'condition' => 'caries', 'surfaces' => ['occlusal'], 'procedure_id' => $proc['FILL1'], 'status' => 'completed'],
                ['teeth' => ['11'], 'condition' => 'fracture', 'procedure_id' => $proc['CROWN'], 'status' => 'planned']]],
            ['Ali Hamza', 'Male', '-8 years', [['teeth' => ['85', '75'], 'condition' => 'caries', 'surfaces' => ['occlusal'], 'procedure_id' => $proc['PULP'], 'status' => 'planned'],
                ['teeth' => ['16', '26'], 'condition' => 'healthy', 'procedure_id' => $proc['SEAL'], 'status' => 'planned']]],
        ];
        foreach ($cases as [$name, $g, $age, $findings]) {
            $as('reception');
            $pid = $patient($name, $g, date('Y-m-d', strtotime($age)));
            $visit('dentist', $pid, $dept, ['consultation_fee' => '800', 'modules' => ['dental' => ['dentition' => str_contains($age, '-8') ? 'mixed' : 'permanent',
                'notes' => 'Oral hygiene fair.', 'findings' => $findings]]]);
        }
        $as('dentist');
        $plan = (int) DB::value("SELECT id FROM dental_treatment_plans WHERE status = 'proposed' ORDER BY id LIMIT 1");
        if ($plan) {
            DentalService::acceptPlan($plan, 'Kamran Akmal');
        }
        $crown = (int) DB::value("SELECT i.id FROM dental_treatment_plan_items i JOIN dental_procedure_catalog c ON c.id = i.procedure_id WHERE c.code = 'CROWN' LIMIT 1");
        if ($crown) {
            $pid = (int) DB::value('SELECT p.patient_id FROM dental_treatment_plan_items i JOIN dental_treatment_plans p ON p.id = i.plan_id WHERE i.id = ?', [$crown]);
            $case = DentalService::createLabCase(['patient_id' => $pid, 'treatment_plan_item_id' => $crown, 'tooth_numbers' => '11', 'case_type' => 'crown', 'shade' => 'A2',
                'material' => 'PFM', 'due_date' => date('Y-m-d', strtotime('+7 days'))]);
            DentalService::updateLabCase($case, 'sent');
        }
    });

    // ------------------------------------------------- gynecology / obstetrics
    $block('obgyn', static function () use ($as, $patient, $visit): void {
        $dept = (int) (SpecialtyContext::departmentsWith('obgyn')[0] ?? 0);
        if (!$dept) {
            return;
        }
        $mothers = [['Sobia Khan', '-26 years', 30, false], ['Tahira Batool', '-33 years', 37, true], ['Huma Saleem', '-22 years', 12, false], ['Asma Jabeen', '-38 years', 39, false]];
        foreach ($mothers as $i => [$name, $age, $weeks, $highBp]) {
            $as('reception');
            $pid = $patient($name, 'Female', date('Y-m-d', strtotime($age)));
            $as('gynae');
            if ($i === 1) {
                ObgynService::addPastPregnancy($pid, ['status' => 'delivered', 'outcome_date' => (string) (date('Y') - 3), 'mode' => 'C-section_emergency', 'outcome' => 'live_birth', 'ga_weeks' => 38]);
            }
            if ($i === 3) {
                ObgynService::addPastPregnancy($pid, ['status' => 'miscarriage', 'outcome_date' => (string) (date('Y') - 2)]);
            }
            $visit('gynae', $pid, $dept, ['consultation_fee' => '1500', 'modules' => ['obgyn' => [
                'gyn' => ['menarche_age' => 13, 'cycle_length_days' => 28, 'cycle_regularity' => 'regular'],
                'pregnancy' => ['lmp_date' => date('Y-m-d', strtotime('-' . $weeks . ' weeks')), 'edd_method' => 'lmp'],
                'anc' => ['bp_systolic' => $highBp ? 148 : 112, 'bp_diastolic' => $highBp ? 96 : 74, 'weight_kg' => 64, 'urine_protein_flag' => $highBp, 'hb_g_dl' => $highBp ? 9.6 : 11.8,
                    'fundal_height_cm' => $weeks > 20 ? $weeks : null, 'fetal_heart_rate_bpm' => $weeks > 12 ? 142 : null, 'next_visit_date' => date('Y-m-d', strtotime('+2 weeks'))],
            ]]]);
            if ($i === 3) {
                $preg = (int) DB::value("SELECT id FROM pregnancies WHERE patient_id = ? AND status = 'ongoing'", [$pid]);
                $as('midwife');
                $d = ObgynService::recordDelivery($preg, ['mode' => 'SVD', 'outcome' => 'live_birth', 'baby_sex' => 'Male', 'birth_weight_g' => 3150, 'apgar_1min' => 8, 'apgar_5min' => 9,
                    'delivery_date' => date('Y-m-d H:i', strtotime('-1 day'))], true);
                $as('gynae');
                ObgynService::confirmDelivery((int) $d['id']);
                $as('midwife');
                ObgynService::savePnc($preg, null, ['bp_systolic' => 118, 'bp_diastolic' => 76, 'lochia' => 'normal', 'breastfeeding_status' => 'exclusive', 'contraception_counselled' => 1]);
            }
        }
    });

    // ------------------------------------------------- paediatrics / EPI
    $block('pediatrics', static function () use ($as, $patient): void {
        foreach ([['Baby Areeba', 'Female', '-10 weeks'], ['Rayan Ahmed', 'Male', '-10 months']] as [$name, $g, $age]) {
            $as('reception');
            $pid = $patient($name, $g, date('Y-m-d', strtotime($age)));
            $as('doctor2');
            foreach (SpecialtyService::immunizationCard($pid) as $c) {
                if ($c['status'] === 'overdue' && $c['due'] < date('Y-m-d', strtotime('-7 days'))) {
                    SpecialtyService::immunize($pid, $c['vaccine_id'], $c['dose']);
                }
            }
            SpecialtyService::saveGrowth($pid, null, ['weight_kg' => str_contains($age, 'weeks') ? 5.1 : 8.9, 'height_cm' => str_contains($age, 'weeks') ? 58 : 72]);
        }
    });

    // ------------------------------------- e-Rx waiting at the hospital pharmacy
    $block('dispensing', static function () use ($as, $patient, $visit, $opd): void {
        $meds = DB::all('SELECT m.* FROM medicines m JOIN medicine_sources s ON s.id = m.source_id WHERE s.stock_location_id IS NOT NULL AND m.is_active = 1 AND m.deleted_at IS NULL
                          AND m.is_controlled = 0 ORDER BY m.id LIMIT 6');
        foreach (array_chunk($meds, 2) as $i => $pair) {
            $as('reception');
            $pid = $patient(['Rashid Minhas', 'Kaneez Fatima', 'Owais Qadri'][$i] ?? 'Demo Rx ' . $i, $i === 1 ? 'Female' : 'Male', date('Y-m-d', strtotime('-' . (30 + $i * 9) . ' years')));
            $visit('doctor', $pid, $opd, ['medicines' => array_map(static fn ($m) => ['medicine_id' => (int) $m['id'], 'dose' => $m['default_dose'] ?: 1, 'frequency_id' => $m['frequency_id'],
                'route_id' => $m['route_id'], 'duration_days' => $m['default_duration'] ?: 5, 'quantity' => 10, 'qty_manual' => 1, 'unit_price' => $m['price'], 'instructions' => 'After meal'], $pair)]);
        }
    });

    // ---------------------------------------------- pharmacy counter + billing
    $block('pharmacy', static function () use ($as): void {
        $as('pharmacist');
        $loc = (int) StockService::locationOfType('pharmacy_main');
        $items = DB::all("SELECT i.id, i.sale_price FROM items i JOIN stock_balances b ON b.item_id = i.id AND b.location_id = ? WHERE i.item_type = 'drug' AND i.is_controlled = 0 AND b.qty_on_hand > 20 GROUP BY i.id LIMIT 3", [$loc]);
        if ($items) {
            \App\Modules\Pharmacy\PharmacyService::counterSale($loc, array_map(static fn ($i) => ['item_id' => $i['id'], 'qty' => 2, 'unit_price' => $i['sale_price']], $items), null, 'Walk-in customer', 'cash');
        }
        $as('cashier');
        foreach (DB::all("SELECT id FROM invoices WHERE status IN ('open','partial') ORDER BY id DESC LIMIT 6") as $i => $row) {
            $bal = BillingService::balance((int) $row['id']);
            if ($bal > 0 && $i % 3 !== 2) {
                BillingService::collect((int) $row['id'], $bal, $i % 2 ? 'cash' : 'easypaisa');
            }
        }
    });

    Auth::actingAs(null);
};
