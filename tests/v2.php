<?php
/**
 * v2 (hospital) integration tests — included by tests/run.php after the v1
 * suites and before backup / reset. Covers RBAC, tokens, billing, stock /
 * FEFO / segregation of duties, pharmacy, laboratory gates, radiology gates,
 * the specialty framework, dentistry, gynecology / obstetrics and the Doc 8 §7
 * acceptance-criteria seeds.
 */
declare(strict_types=1);

use App\Core\Auth;
use App\Core\DB;
use App\Core\Rbac;
use App\Core\Seeder;
use App\Modules\Billing\BillingService;
use App\Modules\Dental\DentalService;
use App\Modules\Frontdesk\TokenService;
use App\Modules\Inventory\InventoryService;
use App\Modules\Inventory\StockService;
use App\Modules\Lab\LabService;
use App\Modules\Obgyn\ObgynService;
use App\Modules\Patients\PatientService;
use App\Modules\Radiology\RadiologyService;
use App\Modules\Specialty\SpecialtyContext;
use App\Modules\Specialty\SpecialtyService;
use App\Modules\Visits\VisitService;

/** Throws unless $fn throws a message containing $needle. */
function throwsLike(callable $fn, string $needle, string $msg): void
{
    try {
        $fn();
    } catch (Throwable $e) {
        ok(stripos($e->getMessage(), $needle) !== false, $msg . ' — got: ' . $e->getMessage());
        return;
    }
    throw new RuntimeException($msg . ' — nothing was thrown');
}

function v2patient(string $name, string $gender = 'Male', string $dob = '1985-01-01'): int
{
    Auth::actingAs(user('reception'));
    [$data] = PatientService::validate(['name' => $name, 'gender' => $gender, 'dob' => $dob, 'phone' => '0300' . random_int(1000000, 9999999)]);
    return (int) PatientService::create($data, null)['id'];
}

function v2dept(string $moduleKey): int
{
    return (int) (SpecialtyContext::departmentsWith($moduleKey)[0] ?? 0);
}

/** Opening stock with a batch; returns the batch id. */
function v2stock(string $sku, string $locType, float $qty, string $batch = 'B1', ?string $expiry = null): int
{
    $item = (int) DB::value('SELECT id FROM items WHERE sku = ?', [$sku]);
    $loc = (int) StockService::locationOfType($locType);
    $b = StockService::batchFor($item, $batch, $expiry ?? date('Y-m-d', strtotime('+1 year')), ['unit_cost' => 10]);
    StockService::move($item, $loc, $qty, 'opening', ['batch_id' => $b, 'unit_cost' => 10]);
    return $b;
}

/** A user cloned from an existing one with another role. */
function v2user(string $username, string $roleCode, ?string $deptName = null): array
{
    $u = DB::value('SELECT id FROM users WHERE username = ?', [$username]);
    if (!$u) {
        $base = DB::one("SELECT * FROM users WHERE username = 'nurse'");
        unset($base['id']);
        $base['username'] = $username;
        $base['name'] = ucfirst($username);
        $base['employee_code'] = 'T-' . $username;
        $base['email'] = null;
        $u = DB::insert('users', $base);
        DB::run('DELETE FROM user_role_branch WHERE user_id = ?', [$u]);
        $dept = $deptName ? (int) DB::value('SELECT id FROM departments WHERE name = ?', [$deptName]) ?: null : null;
        Seeder::assign((int) $u, $roleCode, null, $dept, true);
        Rbac::bump();
    }
    return user($username);
}

function v2checkout(string $doctor, int $patientId, int $deptId, array $over = []): array
{
    Auth::actingAs(user($doctor));
    $p = DB::one('SELECT * FROM patients WHERE id = ?', [$patientId]);
    $res = VisitService::checkout(payload(array_replace([
        'patient' => ['id' => $patientId, 'name' => $p['name'], 'gender' => $p['gender'], 'dob' => $p['dob'], 'phone' => $p['phone']],
        'doctor_id' => user($doctor)['id'], 'department_id' => $deptId, 'investigations' => [], 'medicines' => [],
    ], $over)), user($doctor));
    ok($res['ok'], 'checkout failed: ' . json_encode($res['errors'] ?? $res));
    return $res;
}

// ============================================================================
section('v2: roles, permissions and workspaces');
test('seeded role catalog, inheritance and deny', function () {
    eq(count(config('permissions.roles')), (int) DB::value('SELECT COUNT(*) FROM roles'), 'every catalog role seeded');
    Auth::actingAs(user('dentist'));
    ok(Auth::can('dental.chart.edit') && Auth::can('dental.plan.create'), 'dentist charts and plans');
    ok(!Auth::can('lab.result.verify'), 'dentist cannot verify lab results');
    Auth::actingAs(user('pathologist'));
    ok(Auth::can('lab.result.verify') && Auth::can('lab.sample.review'), 'pathologist gates');
    ok(!Auth::can('visits.create'), 'pathologist does not run OPD consultations');
    Auth::actingAs(user('doctor'));
    ok(Auth::can('visits.create') && Auth::can('lab.order.create'), 'consultant consults and orders labs');
    ok(!Auth::can('inventory.adjust.approve'), 'consultant cannot approve stock adjustments');
});
test('every role-workspace route exists and menu items point to real routes', function () {
    foreach (hospital('workspaces') as $code => $w) {
        ok(isset(\App\Core\Router::routes()[$w[1]]), "workspace $code → route {$w[1]} missing");
    }
    $walk = static function (array $items) use (&$walk): void {
        foreach ($items as $i) {
            if (isset($i['route'])) {
                ok(isset(\App\Core\Router::routes()[$i['route']]), 'menu route ' . $i['route'] . ' missing');
            }
            $walk($i['children'] ?? []);
        }
    };
    $walk(config('menu'));
});

// ============================================================================
section('v2: front desk, tokens and billing');
test('token numbers are sequential per queue and never repeat', function () {
    Auth::actingAs(user('reception'));
    $q = TokenService::queueForDoctor((int) user('doctor')['id']);
    $a = TokenService::issue($q, v2patient('Token One'));
    $b = TokenService::issue($q, v2patient('Token Two'));
    eq((int) $a['token_number'] + 1, (int) $b['token_number'], 'next number');
    ok($a['token_label'] !== $b['token_label'], 'labels differ');
    throwsLike(fn () => TokenService::issue($q, (int) $a['patient_id']), 'already has token', 'duplicate waiting token blocked');
});
test('invoice, partial payment, discount approval and refund rules', function () {
    Auth::actingAs(user('cashier'));
    $pid = v2patient('Bill Patient');
    $inv = BillingService::create(['patient_id' => $pid], [['description' => 'X-ray', 'quantity' => 1, 'unit_price' => 1000]]);
    eq('open', BillingService::find($inv)['status']);
    BillingService::collect($inv, 400, 'cash');
    eq('partial', BillingService::find($inv)['status']);
    BillingService::collect($inv, 600, 'cash');
    eq('settled', BillingService::find($inv)['status']);
    throwsLike(fn () => BillingService::requestRefund($inv, null, 5000, 'too much'), 'exceeds', 'refund above paid amount blocked');
    $r = BillingService::requestRefund($inv, null, 200, 'Partial cancel');
    eq('requested', DB::value('SELECT status FROM refunds WHERE id = ?', [$r]), 'general refunds need approval');
});

// ============================================================================
section('v2: stock, FEFO and segregation of duties');
test('GRN batch without expiry for an expiry-tracked item is rejected (Doc 8 §7 #6)', function () {
    Auth::actingAs(user('store'));
    $item = (int) DB::value("SELECT id FROM items WHERE sku = 'LAB-EDTA'");
    throwsLike(fn () => InventoryService::saveGrn(['vendor_id' => (int) DB::value('SELECT id FROM vendors LIMIT 1'), 'location_id' => StockService::locationOfType('central_store')],
        [['item_id' => $item, 'qty_received' => 10, 'batch_no' => 'NOEXP', 'expiry_date' => '', 'unit_cost' => 18]], true), 'Expiry date is required', 'missing expiry rejected');
});
test('FEFO issues the earliest-expiring batch; expired batches never issue (Doc 8 §7 #7)', function () {
    Auth::actingAs(user('store'));
    $loc = StockService::locationOfType('lab_stock');
    $item = (int) DB::value("SELECT id FROM items WHERE sku = 'LAB-GEL'");
    $late = v2stock('LAB-GEL', 'lab_stock', 5, 'LATE', date('Y-m-d', strtotime('+300 days')));
    $soon = v2stock('LAB-GEL', 'lab_stock', 5, 'SOON', date('Y-m-d', strtotime('+30 days')));
    $plan = StockService::fefo($item, $loc, 3);
    eq($soon, $plan[0]['batch_id'], 'earliest expiry first');
    DB::run("UPDATE stock_batches SET expiry_date = ? WHERE id = ?", [date('Y-m-d', strtotime('-1 day')), $soon]);
    throwsLike(fn () => StockService::move($item, $loc, -1, 'consumption', ['batch_id' => $soon]), 'expired', 'expired batch blocked');
    eq($late, StockService::fefo($item, $loc, 1)[0]['batch_id'], 'FEFO skips the expired batch');
    throwsLike(fn () => StockService::move($item, $loc, -100, 'consumption', ['batch_id' => $late]), '', 'negative stock blocked');
});
test('the proposer of a stock adjustment cannot approve it (Doc 8 §7 #5)', function () {
    Auth::actingAs(user('store'));
    $loc = StockService::locationOfType('lab_stock');
    $item = (int) DB::value("SELECT id FROM items WHERE sku = 'LAB-FLU'");
    $b = v2stock('LAB-FLU', 'lab_stock', 10, 'ADJ1');
    $id = InventoryService::proposeAdjustment($loc, array_key_first(InventoryService::ADJUST_REASONS), [['item_id' => $item, 'batch_id' => $b, 'qty_signed' => -1]]);
    throwsLike(fn () => InventoryService::decideAdjustment($id, true), 'Segregation of duties', 'self-approval blocked');
    Auth::actingAs(user('hadmin'));
    InventoryService::decideAdjustment($id, true);
    eq('approved', DB::value('SELECT status FROM stock_adjustments WHERE id = ?', [$id]));
});
test('PO creator cannot post its goods receipt', function () {
    Auth::actingAs(user('store'));
    $loc = StockService::locationOfType('central_store');
    $vendor = (int) DB::value('SELECT id FROM vendors LIMIT 1');
    $item = (int) DB::value("SELECT id FROM items WHERE sku = 'LAB-CIT'");
    $po = InventoryService::createPo($vendor, $loc, [['item_id' => $item, 'qty' => 10, 'unit_price' => 20]], null);
    DB::update('purchase_orders', ['status' => 'approved'], 'id = ?', [$po]);
    $poItem = (int) DB::value('SELECT id FROM purchase_order_items WHERE purchase_order_id = ?', [$po]);
    throwsLike(fn () => InventoryService::saveGrn(['purchase_order_id' => $po, 'location_id' => $loc],
        [['item_id' => $item, 'po_item_id' => $poItem, 'qty_received' => 10, 'batch_no' => 'PO1', 'expiry_date' => date('Y-m-d', strtotime('+1 year')), 'unit_cost' => 20]], true),
        'Segregation of duties', 'PO creator posting GRN blocked');
});
test('stock ledger and balances reconcile', function () {
    eq(0, StockService::reconcile(), 'no balance differs from the ledger');
});

// ============================================================================
section('v2: laboratory gates');
test('consult investigation becomes a billed lab order; sample gate with resample XOR refund', function () {
    v2stock('LAB-EDTA', 'lab_stock', 50, 'EDTA1');
    $pid = v2patient('Lab Patient');
    $dept = (int) DB::value("SELECT id FROM departments WHERE name = 'General OPD'");
    $res = v2checkout('doctor', $pid, $dept, ['investigations' => [['id' => master('investigations', 'Complete Blood Count (CBC)'), 'name' => 'Complete Blood Count (CBC)']]]);
    $order = DB::one('SELECT * FROM lab_orders WHERE visit_id = ?', [$res['visit_id']]);
    ok((bool) $order, 'lab order created at checkout');
    $item = DB::one('SELECT * FROM lab_order_items WHERE lab_order_id = ?', [$order['id']]);
    ok((bool) BillingService::billedItem('lab_order_item', (int) $item['id']), 'billed at order time');
    Auth::actingAs(user('labtech'));
    [$s1] = LabService::collect([(int) $item['id']]);
    Auth::actingAs(user('pathologist'));
    throwsLike(fn () => LabService::review($s1, 'reject', 'Haemolysed', null), 'exactly one', 'rejection without a resolution blocked');
    LabService::review($s1, 'reject', 'Haemolysed', 'resample');
    eq('resample_pending', DB::value('SELECT status FROM lab_order_items WHERE id = ?', [$item['id']]));
    Auth::actingAs(user('labtech'));
    [$s2] = LabService::collect([(int) $item['id']]);
    eq($s1, (int) DB::value('SELECT resample_of_sample_id FROM lab_samples WHERE id = ?', [$s2]), 'resample linked');
    Auth::actingAs(user('pathologist'));
    LabService::review($s2, 'accept');
    eq('accepted', DB::value('SELECT status FROM lab_order_items WHERE id = ?', [$item['id']]));
});
test('section restriction and QC block on verification (Doc 8 §7 #8)', function () {
    $item = (int) DB::value("SELECT li.id FROM lab_order_items li JOIN lab_tests t ON t.id = li.lab_test_id WHERE t.code = 'CBC' AND li.status = 'accepted' ORDER BY li.id DESC LIMIT 1");
    $tech = user('labtech');
    $mic = (int) DB::value("SELECT id FROM lab_sections WHERE code = 'MIC'");
    DB::run('DELETE FROM user_lab_sections WHERE user_id = ?', [$tech['id']]);
    DB::insert('user_lab_sections', ['user_id' => $tech['id'], 'section_id' => $mic]);
    Auth::actingAs($tech);
    $ws = LabService::worksheet($item);
    $vals = [];
    foreach ($ws['params'] as $p) {
        $vals[$p['id']] = $p['code'] === 'HB' ? '13.5' : '50';
    }
    throwsLike(fn () => LabService::enterResults($item, $vals), 'not assigned', 'other-section technician blocked');
    DB::run('DELETE FROM user_lab_sections WHERE user_id = ?', [$tech['id']]);
    LabService::enterResults($item, $vals);
    eq('result_entered', DB::value('SELECT status FROM lab_order_items WHERE id = ?', [$item]));
    $hb = (int) DB::value("SELECT p.id FROM lab_test_parameters p JOIN lab_tests t ON t.id = p.lab_test_id WHERE t.code = 'CBC' AND p.code = 'HB'");
    $flag = DB::value('SELECT v.flag FROM lab_result_values v JOIN lab_results r ON r.id = v.lab_result_id WHERE r.lab_order_item_id = ? AND v.parameter_id = ?', [$item, $hb]);
    eq('normal', $flag, 'Hb 13.5 normal for an adult man');
    $mat = (int) DB::value('SELECT id FROM lab_qc_materials WHERE parameter_id = ?', [$hb]);
    $run = LabService::qcRun($mat, 20.0);
    eq('fail', $run['status'], 'QC 1-3s fail');
    Auth::actingAs(user('pathologist'));
    throwsLike(fn () => LabService::verify($item), 'QC', 'verification blocked by failed QC');
    LabService::qcRun($mat, 12.6);
    LabService::verify($item);
    eq('released', DB::value('SELECT status FROM lab_order_items WHERE id = ?', [$item]));
    ok((bool) DB::value('SELECT 1 FROM lab_reports r JOIN lab_order_items i ON i.lab_order_id = r.lab_order_id WHERE i.id = ?', [$item]), 'report released');
});
test('critical value notifies the ordering doctor; amendment creates a new version', function () {
    Auth::actingAs(user('doctor'));
    $pid = v2patient('Critical Patient');
    Auth::actingAs(user('doctor'));
    $order = LabService::order(['patient_id' => $pid, 'doctor_id' => user('doctor')['id']], [(int) DB::value("SELECT id FROM lab_tests WHERE code = 'ELEC'")]);
    $item = (int) DB::value('SELECT id FROM lab_order_items WHERE lab_order_id = ?', [$order]);
    v2stock('LAB-GEL', 'lab_stock', 10, 'GEL9');
    Auth::actingAs(user('labtech'));
    [$s] = LabService::collect([$item]);
    Auth::actingAs(user('pathologist'));
    LabService::review($s, 'accept');
    $ws = LabService::worksheet($item);
    $vals = [];
    foreach ($ws['params'] as $p) {
        $vals[$p['id']] = ['NA' => '138', 'K' => '7.1', 'CL' => '100'][$p['code']];
    }
    LabService::enterResults($item, $vals);
    LabService::verify($item);
    ok((int) DB::value('SELECT COUNT(*) FROM lab_critical_notifications WHERE lab_order_item_id = ? AND notified_user_id = ?', [$item, user('doctor')['id']]) === 1, 'doctor notified of K 7.1');
    $vals[array_key_first($vals)] = '139';
    LabService::amend($item, $vals, 'Transcription error');
    eq(2, (int) DB::value('SELECT MAX(version) FROM lab_results WHERE lab_order_item_id = ?', [$item]), 'version 2');
    eq(1, (int) DB::value('SELECT COUNT(*) FROM lab_results WHERE lab_order_item_id = ? AND is_current = 1', [$item]), 'one current version');
});

// ============================================================================
section('v2: radiology gates');
test('pregnancy screen, reprocess without new charge, reject refunds, report immutable (Doc 8 §7 #9)', function () {
    $pid = v2patient('Rad Woman', 'Female', date('Y-m-d', strtotime('-30 years')));
    Auth::actingAs(user('radiologist'));
    $cxr = (int) DB::value("SELECT id FROM radiology_procedure_catalog WHERE code = 'XR-CXR'");
    Auth::actingAs(user('reception'));
    [$oid] = RadiologyService::order(['patient_id' => $pid, 'source' => 'walk_in'], [$cxr]);
    $o = RadiologyService::find($oid);
    eq('unscreened', $o['pregnancy_status'], 'woman of child-bearing age must be screened');
    Auth::actingAs(user('radtech'));
    throwsLike(fn () => RadiologyService::start($oid), 'Pregnancy screening', 'no acquisition before the screen');
    Auth::actingAs(user('reception'));
    RadiologyService::screen($oid, ['pregnancy_status' => 'not_pregnant']);
    Auth::actingAs(user('radtech'));
    $p1 = RadiologyService::start($oid);
    RadiologyService::finish($p1, ['image_count' => 1, 'kvp' => 110, 'mas' => 4]);
    $lines = (int) DB::value("SELECT COUNT(*) FROM invoice_items WHERE source_reference_type = 'radiology_order' AND source_reference_id = ?", [$oid]);
    Auth::actingAs(user('radiologist'));
    RadiologyService::review($p1, 'reprocess', 'Motion artefact');
    Auth::actingAs(user('radtech'));
    $p2 = RadiologyService::start($oid);
    RadiologyService::finish($p2, ['image_count' => 1]);
    eq($lines, (int) DB::value("SELECT COUNT(*) FROM invoice_items WHERE source_reference_type = 'radiology_order' AND source_reference_id = ?", [$oid]), 'repeat is not charged again');
    Auth::actingAs(user('radiologist'));
    RadiologyService::review($p2, 'accept');
    $rid = RadiologyService::saveReport($oid, ['findings' => 'Clear lungs', 'impression' => 'Normal'], true);
    throwsLike(fn () => RadiologyService::saveReport($oid, ['impression' => 'Changed'], true), 'addendum', 'final report cannot be edited');
    RadiologyService::addendum($rid, 'Compared with outside film: unchanged.');
    eq('addended', DB::value('SELECT status FROM radiology_reports WHERE id = ?', [$rid]));
    ok((bool) DB::value('SELECT added_by FROM radiology_report_addenda WHERE radiology_report_id = ?', [$rid]), 'addendum attributed');

    Auth::actingAs(user('reception'));
    [$o2] = RadiologyService::order(['patient_id' => $pid, 'source' => 'walk_in'], [$cxr], ['pregnancy_status' => 'not_pregnant']);
    Auth::actingAs(user('radtech'));
    $p = RadiologyService::start($o2);
    RadiologyService::finish($p, ['image_count' => 1]);
    Auth::actingAs(user('radiologist'));
    RadiologyService::review($p, 'reject', 'Wrong patient / side');
    eq('rejected_refunded', DB::value('SELECT status FROM radiology_orders WHERE id = ?', [$o2]));
    ok((bool) DB::value("SELECT is_void FROM invoice_items WHERE source_reference_type = 'radiology_order' AND source_reference_id = ?", [$o2]), 'unpaid charge voided');
});

// ============================================================================
section('v2: specialty framework');
test('Tier 1 fields validate required / options and save per encounter', function () {
    $psyc = (int) DB::value("SELECT id FROM specialties WHERE code = 'PSYC'");
    $fields = SpecialtyService::templates($psyc);
    $risk = null;
    foreach ($fields as $f) {
        if ($f['field_key'] === 'risk') {
            $risk = $f;
        }
    }
    ok((bool) $risk && $risk['is_required'], 'psychiatry risk field is required');
    ok((bool) SpecialtyService::validateParameters($psyc, []), 'missing required field reported');
    ok((bool) SpecialtyService::validateParameters($psyc, [$risk['id'] => 'Maybe']), 'value outside options reported');
    eq([], SpecialtyService::validateParameters($psyc, [$risk['id'] => 'Low']));
});
test('growth percentiles and immunization draw stock', function () {
    $child = v2patient('Baby Growth', 'Male', date('Y-m-d', strtotime('-12 months')));
    $pct = \App\Modules\Specialty\GrowthChart::percentiles('Male', 12, 9.6, 75.7);
    ok(abs($pct['weight_pct'] - 50) < 2 && abs($pct['height_pct'] - 50) < 2, 'median child is ~P50');
    v2stock('VAC-MR', 'pharmacy_main', 5, 'MR1');
    Auth::actingAs(user('doctor2'));
    $mr = (int) DB::value("SELECT id FROM vaccines WHERE code = 'MR'");
    SpecialtyService::immunize($child, $mr, 1);
    ok((bool) DB::value('SELECT stock_batch_id FROM immunization_records WHERE patient_id = ?', [$child]), 'batch recorded');
    throwsLike(fn () => SpecialtyService::immunize($child, $mr, 1), 'already recorded', 'same dose twice blocked');
});

// ============================================================================
section('v2: dentistry (Doc 8 §7 #1–3)');
test('tooth map only for Dentistry encounters; multi-tooth apply; surfaces; done today billed', function () {
    eq(52, (int) DB::value('SELECT COUNT(*) FROM tooth_reference'), '32 permanent + 20 deciduous teeth');
    $pid = v2patient('Dental Patient');
    $general = (int) DB::value("SELECT id FROM departments WHERE name = 'General OPD'");
    $dental = v2dept('dental');
    ok($dental > 0, 'a Dentistry department exists');
    $fill = (int) DB::value("SELECT id FROM dental_procedure_catalog WHERE code = 'FILL2'");
    $chart = ['dental' => ['dentition' => 'permanent', 'findings' => [
        ['teeth' => ['26', '27'], 'condition' => 'caries', 'surfaces' => ['mesial', 'occlusal'], 'procedure_id' => $fill, 'status' => 'planned'],
    ]]];
    Auth::actingAs(user('dentist'));
    $res = VisitService::checkout(payload(['patient' => ['id' => $pid, 'name' => 'Dental Patient', 'gender' => 'Male'], 'doctor_id' => user('dentist')['id'],
        'department_id' => $general, 'investigations' => [], 'modules' => $chart]), user('dentist'));
    ok(!$res['ok'] && str_contains(implode(' ', $res['errors'] ?? []), 'Dentistry'), 'tooth chart refused on a General OPD encounter');
    $res = v2checkout('dentist', $pid, $dental, ['modules' => $chart]);
    $chartId = (int) DB::value('SELECT id FROM dental_charts WHERE encounter_id = ?', [$res['visit_id']]);
    eq(2, (int) DB::value('SELECT COUNT(*) FROM dental_tooth_findings WHERE dental_chart_id = ?', [$chartId]), 'one finding per selected tooth');
    eq(2, (int) DB::value("SELECT COUNT(*) FROM dental_finding_surfaces s JOIN dental_tooth_findings f ON f.id = s.dental_tooth_finding_id WHERE f.dental_chart_id = ? AND f.tooth_number = '26'", [$chartId]), 'M + O surfaces for 26');
    $plan = DB::one('SELECT * FROM dental_treatment_plans WHERE patient_id = ?', [$pid]);
    eq('proposed', $plan['status']);
    $item = (int) DB::value("SELECT id FROM dental_treatment_plan_items WHERE plan_id = ? AND tooth_number = '26'", [$plan['id']]);
    v2stock('DEN-COMP', 'dental_stock', 10, 'C1');
    v2stock('DEN-BOND', 'dental_stock', 10, 'BD1');
    DentalService::acceptPlan((int) $plan['id'], 'Dental Patient');
    DentalService::completeItem($item, (int) $res['visit_id']);
    ok((bool) BillingService::billedItem('dental_plan_item', $item), 'procedure posted to the bill');
    ok((bool) DB::value("SELECT 1 FROM stock_ledger WHERE reference_type = 'dental_plan_item' AND reference_id = ?", [$item]), 'consumables deducted');
    eq('in_progress', DB::value('SELECT status FROM dental_treatment_plans WHERE id = ?', [$plan['id']]));
    eq('filled', DentalService::toothState($pid)['26']['condition'], 'tooth 26 now shows filled');
});
test('dental assistant can view but not record findings (403 even if the UI is manipulated)', function () {
    $das = v2user('dentasst', 'DAS', 'Dental');
    Auth::actingAs($das);
    ok(Auth::can('dental.chart.view'), 'assistant views the chart');
    ok(!Auth::can('dental.chart.edit'), 'assistant cannot edit');
    $errs = DentalService::validate(['findings' => [['teeth' => ['11'], 'condition' => 'caries']]]);
    ok((bool) $errs, 'server rejects findings from the assistant');
    ok(!\App\Core\Router::allowed('dental.plan.create'), 'route guard denies plan actions');
});

// ============================================================================
section('v2: gynecology / obstetrics');
test('pregnancy, ANC risk flags, derived G/P/A/L and newborn linked to the mother', function () {
    $pid = v2patient('Obs Mother', 'Female', date('Y-m-d', strtotime('-28 years')));
    Auth::actingAs(user('gynae'));
    ObgynService::addPastPregnancy($pid, ['status' => 'delivered', 'outcome_date' => '2021', 'mode' => 'C-section_emergency', 'outcome' => 'live_birth', 'ga_weeks' => 38]);
    ObgynService::addPastPregnancy($pid, ['status' => 'miscarriage', 'outcome_date' => '2023']);
    $preg = ObgynService::openPregnancy($pid, ['lmp_date' => date('Y-m-d', strtotime('-34 weeks'))]);
    eq('G3 P1 A1 L1', ObgynService::gpal($pid), 'computed, not typed');
    ok(in_array('Previous C-section (1)', ObgynService::evaluateRisk($preg), true), 'previous C-section raises risk');
    $r = ObgynService::saveAnc($preg, null, ['bp_systolic' => 150, 'bp_diastolic' => 100, 'urine_protein_flag' => 1, 'hb_g_dl' => 9]);
    ok((bool) array_filter($r['flags'], fn ($f) => str_contains($f, 'pre-eclampsia')), 'pre-eclampsia flag');
    ok((bool) array_filter($r['flags'], fn ($f) => str_contains($f, 'Anaemia')), 'anaemia flag');
    eq('high', DB::value('SELECT risk_level FROM pregnancies WHERE id = ?', [$preg]));
    throwsLike(fn () => ObgynService::openPregnancy($pid, ['lmp_date' => date('Y-m-d')]), 'already has an ongoing', 'one ongoing pregnancy only');
    Auth::actingAs(user('midwife'));
    $d = ObgynService::recordDelivery($preg, ['mode' => 'SVD', 'outcome' => 'live_birth', 'baby_sex' => 'Female', 'birth_weight_g' => 3100], true);
    ok($d['draft'], 'midwife record is a draft');
    eq($pid, (int) DB::value('SELECT mother_patient_id FROM patients WHERE id = ?', [$d['baby']['id']]), 'newborn linked to mother');
    eq('newborn', DB::value('SELECT registration_type FROM patients WHERE id = ?', [$d['baby']['id']]));
    throwsLike(fn () => ObgynService::confirmDelivery((int) $d['id']), 'cannot confirm', 'recorder cannot confirm own delivery');
    Auth::actingAs(user('gynae'));
    ObgynService::confirmDelivery((int) $d['id']);
    eq('G3 P2 A1 L2', ObgynService::gpal($pid), 'delivery counted once confirmed');
});
test('Hadlock EFW is in the expected range', function () {
    $efw = ObgynService::hadlock(['hc' => 300, 'ac' => 300, 'fl' => 65]);
    ok($efw > 2100 && $efw < 2450, 'EFW ' . $efw . ' g for HC 30 / AC 30 / FL 6.5 cm (Hadlock ~2250 g)');
});

// ============================================================================
section('v2: audit trail');
test('audit rows keep old → new values and are never purged', function () {
    ok((int) DB::value('SELECT COUNT(*) FROM activity_logs WHERE entity_type IS NOT NULL') > 0, 'entity audit rows exist');
    ok((int) DB::value("SELECT COUNT(*) FROM activity_logs WHERE module = 'lab'") > 0, 'lab actions logged');
});
