<?php
/**
 * Demo patients and visits for evaluation (about 6 months of activity).
 * Every visit goes through the real checkout service, so demo data follows
 * exactly the same rules as data entered on screen.
 */
declare(strict_types=1);

use App\Core\Auth;
use App\Core\DB;
use App\Modules\Visits\VisitService;

return static function (int $visitCount = 240): void {
    mt_srand(2026);
    $pick = static fn (array $a) => $a[mt_rand(0, count($a) - 1)];
    $some = static function (array $a, int $min, int $max): array {
        shuffle($a);
        return array_slice($a, 0, mt_rand($min, min($max, count($a))));
    };

    $doctors = DB::all("SELECT u.*, dp.default_fee FROM users u JOIN doctor_profiles dp ON dp.user_id = u.id WHERE u.role = 'doctor' AND u.deleted_at IS NULL ORDER BY u.id");
    if (!$doctors) {
        return;
    }
    $master = static fn (string $table): array => DB::all('SELECT id, name FROM ' . $table . ' WHERE deleted_at IS NULL AND is_active = 1');
    $complaints = $master('presenting_complaints');
    $symptoms = $master('symptoms');
    $diagnoses = $master('diagnoses');
    $investigations = $master('investigations');
    $medicines = DB::all('SELECT m.id, m.price, m.frequency_id, m.route_id, m.default_dose, m.default_duration, m.pack_size, f.calc_mode
                            FROM medicines m LEFT JOIN frequencies f ON f.id = m.frequency_id WHERE m.deleted_at IS NULL AND m.is_active = 1');

    $male = ['Muhammad Ali', 'Ahmed Hassan', 'Usman Tariq', 'Bilal Khan', 'Hamza Iqbal', 'Zain Abbas', 'Faisal Mehmood', 'Imran Shah', 'Asad Rehman', 'Kashif Nawaz', 'Tahir Aslam', 'Waqas Ahmad', 'Noman Javed', 'Adeel Butt', 'Saad Qureshi'];
    $female = ['Ayesha Bibi', 'Fatima Noor', 'Zainab Akhtar', 'Hina Rafiq', 'Sadia Parveen', 'Maryam Aslam', 'Nabila Yousaf', 'Rabia Khalid', 'Sana Tariq', 'Amna Riaz', 'Kiran Shahzad', 'Iqra Naveed', 'Mehwish Anwar', 'Shazia Latif', 'Uzma Farooq'];
    $fathers = ['Muhammad Aslam', 'Abdul Rasheed', 'Ghulam Hussain', 'Muhammad Iqbal', 'Nazir Ahmad', 'Khalid Mehmood', 'Bashir Ahmad', 'Manzoor Hussain'];
    $cities = ['Lahore', 'Lahore', 'Lahore', 'Nankana Sahib', 'Sheikhupura', 'Kasur', 'Gujranwala', 'Faisalabad'];
    $areas = ['Model Town', 'Johar Town', 'Gulberg', 'Iqbal Town', 'Township', 'Samanabad', 'Shadman', 'Wapda Town', 'Allama Iqbal Road'];

    $patients = [];    // [id, doctor_id]
    $households = [];  // shared CNIC/phone for family members

    for ($n = 0; $n < $visitCount; $n++) {
        // Spread over the last 180 days, busier recently, with a few visits today.
        $daysAgo = $n < 6 ? 0 : (int) floor(180 * (mt_rand(0, 1000) / 1000) ** 1.6);
        $visitDate = date('Y-m-d', strtotime("-$daysAgo days")) . ' ' . sprintf('%02d:%02d:00', mt_rand(9, 20), mt_rand(0, 59));
        if ($visitDate > now()) {
            $visitDate = now();
        }

        $doctor = mt_rand(1, 100) <= 70 ? $doctors[0] : $pick($doctors);
        Auth::actingAs($doctor);

        $existing = $patients && mt_rand(1, 100) <= 35 ? $pick($patients) : null;
        if ($existing) {
            $p = DB::one('SELECT * FROM patients WHERE id = ?', [$existing]);
            $patientPayload = ['id' => $p['id'], 'name' => $p['name'], 'guardian_name' => $p['guardian_name'], 'gender' => $p['gender'], 'dob' => $p['dob'],
                'dob_estimated' => $p['dob_estimated'], 'cnic' => $p['cnic'], 'phone' => $p['phone'], 'city' => $p['city'], 'address' => $p['address'], 'blood_group' => $p['blood_group']];
        } else {
            $gender = mt_rand(0, 1) ? 'Male' : 'Female';
            $family = $households && mt_rand(1, 100) <= 20 ? $pick($households) : null;
            $cnic = $family['cnic'] ?? sprintf('35202-%07d-%d', mt_rand(1000000, 9999999), mt_rand(1, 9));
            $phone = $family['phone'] ?? '03' . mt_rand(0, 4) . mt_rand(0, 9) . sprintf('%07d', mt_rand(0, 9999999));
            if (!$family) {
                $households[] = ['cnic' => $cnic, 'phone' => $phone];
            }
            $age = $doctor['username'] === 'doctor2' ? mt_rand(1, 14) : mt_rand(16, 78);
            $patientPayload = [
                'name'          => $pick($gender === 'Male' ? $male : $female),
                'guardian_name' => $pick($fathers),
                'gender'        => $gender,
                'age'           => $age,
                'cnic'          => $age >= 18 ? $cnic : '',
                'phone'         => $phone,
                'city'          => $pick($cities),
                'address'       => 'House ' . mt_rand(1, 400) . ', ' . $pick($areas),
                'blood_group'   => mt_rand(1, 100) <= 40 ? $pick(['A+', 'B+', 'O+', 'AB+', 'A-', 'O-']) : '',
            ];
        }

        $lines = [];
        foreach ($some($medicines, 1, 4) as $m) {
            $line = ['medicine_id' => $m['id'], 'dose' => $m['default_dose'], 'frequency_id' => $m['frequency_id'], 'route_id' => $m['route_id'],
                'duration_days' => $m['default_duration'], 'unit_price' => $m['price'], 'qty_manual' => 0, 'quantity' => 1, 'instructions' => ''];
            if ($m['calc_mode'] === 'manual') {
                $line['qty_manual'] = 1;
                $line['quantity'] = mt_rand(1, 3);
            }
            $lines[] = $line;
        }
        $toItems = static fn (array $rows): array => array_map(static fn ($r) => ['id' => (int) $r['id'], 'name' => $r['name']], $rows);
        $systolic = mt_rand(105, 165);
        $status = mt_rand(1, 100);

        $result = VisitService::checkout([
            'token'                  => bin2hex(random_bytes(16)),
            'patient'                => $patientPayload,
            'vitals'                 => [
                'bp_systolic'  => (string) $systolic,
                'bp_diastolic' => (string) mt_rand(65, min(105, $systolic - 20)),
                'pulse'        => (string) mt_rand(62, 110),
                'temperature'  => (string) (mt_rand(975, 1024) / 10),
                'spo2'         => (string) mt_rand(94, 100),
                'weight'       => (string) mt_rand(12, 95),
                'height'       => mt_rand(0, 1) ? (string) mt_rand(95, 182) : '',
            ],
            'complaints'             => $toItems($some($complaints, 1, 3)),
            'symptoms'               => $toItems($some($symptoms, 0, 2)),
            'diagnoses'              => $toItems($some($diagnoses, 1, 2)),
            'investigations'         => $toItems($some($investigations, 0, 2)),
            'medicines'              => $lines,
            'follow_up_date'         => mt_rand(0, 1) ? date('Y-m-d', strtotime(substr($visitDate, 0, 10) . ' +' . $pick([3, 7, 14, 30]) . ' days')) : '',
            'follow_up_instructions' => '',
            'consultation_fee'       => (string) $doctor['default_fee'],
            'payment_status'         => $status <= 82 ? 'Paid' : ($status <= 95 ? 'Pending' : 'Free'),
            'discount_type'          => 'amount',
            'discount_value'         => mt_rand(1, 100) <= 15 ? (string) $pick([50, 100, 200]) : '0',
            'tax_percent'            => '0',
            'overall_instructions'   => $pick(['Drink plenty of water and take rest.', 'Avoid oily and spicy food.', 'Return immediately if fever persists beyond 3 days.', '']),
        ], $doctor, $visitDate);

        if (!$result['ok']) {
            throw new RuntimeException('Demo visit failed: ' . implode(' ', $result['errors'] ?? []));
        }
        if (!$existing) {
            // Registration date = first visit date (for the registration trend chart).
            DB::update('patients', ['created_at' => $visitDate], 'id = ?', [$result['patient_id']]);
            $patients[] = (int) $result['patient_id'];
        }
        if (mt_rand(1, 100) <= 60) {
            DB::update('visits', ['print_count' => 1, 'last_printed_at' => $visitDate], 'id = ?', [$result['visit_id']]);
        }
    }
    // Keep the activity log readable: demo generation is summarised in one line.
    DB::run("DELETE FROM activity_logs WHERE action IN ('Visit created', 'Prescription created', 'Patient created', 'Patient updated')");
    Auth::actingAs(null);
    \App\Core\ActivityLog::record('Demo data loaded', 'system', null, $visitCount . ' demo visits generated', null, 'installer');
};
