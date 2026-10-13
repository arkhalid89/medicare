<?php
/**
 * MediCare Practice — automated test suite (no framework needed).
 *
 *   php tests/run.php
 *
 * Runs against a SEPARATE database: the configured name + "_test"
 * (override with env TEST_DB_NAME). The database is rebuilt from scratch,
 * so the real clinic data is never touched. The DB user needs permission to
 * create that database (root on XAMPP).
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit('Run from the command line.');
}
require dirname(__DIR__) . '/core/bootstrap.php';

use App\Core\Auth;
use App\Core\Backup;
use App\Core\CodeGenerator;
use App\Core\Config;
use App\Core\DataPurger;
use App\Core\License;
use App\Core\Migrator;
use App\Core\Urdu;
use App\Core\DB;
use App\Core\Exporter;
use App\Core\Installer;
use App\Core\Password;
use App\Core\Router;
use App\Core\Scope;
use App\Core\Settings;
use App\Core\SqlSplitter;
use App\Core\Validator;
use App\Core\XlsxWriter;
use App\Modules\Patients\PatientService;
use App\Modules\Templates\TemplateService;
use App\Modules\Visits\ClinicalItems;
use App\Modules\Visits\Directions;
use App\Modules\Visits\PrescriptionMath;
use App\Modules\Visits\VisitService;

$_SESSION = [];
$testDb = getenv('TEST_DB_NAME') ?: config('db.name') . '_test';
Config::set('db.name', $testDb);

$passed = 0;
$failures = [];
$current = '';

function test(string $name, callable $fn): void
{
    global $current, $passed, $failures;
    $current = $name;
    try {
        $fn();
        $passed++;
        echo "  \033[32m✔\033[0m $name\n";
    } catch (Throwable $e) {
        $failures[] = [$name, $e];
        echo "  \033[31m✘ $name\033[0m\n     " . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ")\n";
    }
}

function eq(mixed $expected, mixed $actual, string $msg = ''): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(($msg ? $msg . ': ' : '') . 'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

function ok(bool $condition, string $msg): void
{
    if (!$condition) {
        throw new RuntimeException($msg);
    }
}

function section(string $title): void
{
    echo "\n\033[1m$title\033[0m\n";
}

function user(string $username): array
{
    return DB::one('SELECT u.*, dp.default_fee FROM users u LEFT JOIN doctor_profiles dp ON dp.user_id = u.id WHERE username = ?', [$username]);
}

function med(string $name): array
{
    return DB::one('SELECT * FROM medicines WHERE name = ?', [$name]);
}

function master(string $table, string $name): int
{
    return (int) DB::value('SELECT id FROM ' . $table . ' WHERE name = ?', [$name]);
}

function freq(string $code): int
{
    return (int) DB::value('SELECT id FROM frequencies WHERE code = ?', [$code]);
}

/** A valid checkout payload; override parts per test. */
function payload(array $over = []): array
{
    $base = [
        'token'   => bin2hex(random_bytes(16)),
        'patient' => ['name' => 'Test Patient', 'gender' => 'Male', 'age' => '40', 'cnic' => '35202-1111111-1', 'phone' => '03001111111', 'city' => 'Lahore'],
        'vitals'  => ['bp_systolic' => '120', 'bp_diastolic' => '80', 'pulse' => '72', 'weight' => '70', 'height' => '175'],
        'complaints'     => [['id' => master('presenting_complaints', 'Fever'), 'name' => 'Fever'], ['id' => null, 'name' => 'Feeling low since a week']],
        'symptoms'       => [['id' => master('symptoms', 'Fatigue'), 'name' => 'Fatigue']],
        'diagnoses'      => [['id' => master('diagnoses', 'Typhoid Fever'), 'name' => 'Typhoid Fever']],
        'investigations' => [['id' => master('investigations', 'Typhidot'), 'name' => 'Typhidot']],
        'medicines'      => [],
        'follow_up_date' => date('Y-m-d', strtotime('+7 days')),
        'consultation_fee' => '1500',
        'payment_status' => 'Paid',
        'discount_type'  => 'amount',
        'discount_value' => '0',
        'overall_instructions' => 'Rest well.',
    ];
    return array_replace($base, $over);
}

function line(string $medicine, array $over = []): array
{
    $m = med($medicine);
    return array_merge([
        'medicine_id' => (int) $m['id'], 'dose' => $m['default_dose'], 'frequency_id' => $m['frequency_id'], 'route_id' => $m['route_id'],
        'duration_days' => $m['default_duration'], 'quantity' => '999', 'qty_manual' => 0, 'unit_price' => $m['price'], 'instructions' => 'After meal',
    ], $over);
}

echo "\033[1mMediCare Practice — test suite\033[0m (database: $testDb)\n";

// ============================================================================
section('Unit: code patterns');
test('renders MRN pattern from the BRD', function () {
    $t = strtotime('2026-03-05');
    eq('DR01-2026-000001', CodeGenerator::render('DR01-{YYYY}-{000001}', 1, [], $t));
    eq('DOC2-2026-000123', CodeGenerator::render('DOC2-{YYYY}-{000001}', 123, [], $t));
    eq('V-26-03-0042', CodeGenerator::render('V-{YY}-{MM}-{0000}', 42, [], $t));
    eq('OPD-DR-0007', CodeGenerator::render('{DEPT}-{ROLE}-{0001}', 7, ['DEPT' => 'OPD', 'ROLE' => 'DR'], $t));
});
test('sequence wider than the token is not truncated', function () {
    eq('X-1234567', CodeGenerator::render('X-{0001}', 1234567));
});
test('validates patterns', function () {
    eq(null, CodeGenerator::validate('MR-{YYYY}-{000001}'));
    ok(CodeGenerator::validate('MR-{YYYY}') !== null, 'pattern without sequence must fail');
    ok(CodeGenerator::validate('MR-{FOO}-{0001}') !== null, 'unknown token must fail');
    ok(CodeGenerator::validate('MR {0001}') !== null, 'spaces must fail');
    eq(null, CodeGenerator::validate('{DEPT}-{0001}', ['DEPT']));
});
test('period key follows the date tokens', function () {
    $t = strtotime('2026-03-05');
    eq('2026', CodeGenerator::periodKey('A-{YYYY}-{01}', $t));
    eq('202603', CodeGenerator::periodKey('A-{YY}{MM}-{01}', $t));
    eq('20260305', CodeGenerator::periodKey('A-{DD}{MM}-{01}', $t));
    eq('all', CodeGenerator::periodKey('A-{0001}', $t));
});

// ============================================================================
section('Unit: quantity, billing and Rx split');
test('quantity = dose × doses/day × days (BRD §41)', function () {
    eq(10.0, PrescriptionMath::quantity(1, 2, 'daily', 5));
    eq(5.0, PrescriptionMath::quantity(0.5, 2, 'daily', 5));
    eq(45.0, PrescriptionMath::quantity(5, 3, 'daily', 3));
});
test('weekly frequency counts whole weeks', function () {
    eq(8.0, PrescriptionMath::quantity(1, 1, 'weekly', 56));
    eq(2.0, PrescriptionMath::quantity(1, 1, 'weekly', 8));
});
test('manual frequencies (SOS/PRN) have no automatic quantity', function () {
    eq(null, PrescriptionMath::quantity(2, 0, 'manual', 30));
    eq(null, PrescriptionMath::quantity(1, 2, 'daily', 0));
});
test('liquids convert to whole billing units', function () {
    eq(1.0, PrescriptionMath::quantity(5, 3, 'daily', 3, 60));    // 45 ml of a 60 ml bottle
    eq(2.0, PrescriptionMath::quantity(10, 3, 'daily', 5, 120));  // 150 ml → 2 × 120 ml
    eq(1.0, PrescriptionMath::quantity(10, 3, 'daily', 4, 120));  // exactly 120 ml
});
test('teaspoon / tablespoon doses convert to ml before billing bottles', function () {
    eq(1.0, PrescriptionMath::quantity(1, 3, 'daily', 3, 60, 5));     // 1 tsp TDS 3 days = 45 ml → 1 × 60 ml
    eq(3.0, PrescriptionMath::quantity(2, 2, 'daily', 5, 120, 15));   // 2 tbsp BD 5 days = 300 ml → 3 × 120 ml
    eq(2.0, PrescriptionMath::quantity(0.5, 2, 'daily', 2, 1, 1));    // ½ tablet BD 2 days
});
test('bill matches the BRD example (Rs. 3,850)', function () {
    $b = PrescriptionMath::bill([['quantity' => 1, 'unit_price' => 2450]], 'amount', 100, 0, 1500, 'Paid');
    eq(2450.0, $b['medicines_total']);
    eq(100.0, $b['discount_amount']);
    eq(2350.0, $b['medicine_net']);
    eq(3850.0, $b['grand_total']);
});
test('percentage discount, tax, cap and free consultation', function () {
    $lines = [['quantity' => 10, 'unit_price' => 3], ['quantity' => 2, 'unit_price' => 45.5]];
    $b = PrescriptionMath::bill($lines, 'percent', 10, 5, 1000, 'Free');
    eq(121.0, $b['medicines_total']);
    eq(12.1, $b['discount_amount']);
    eq(5.45, $b['tax_amount']);
    eq(0.0, $b['consultation_fee']);
    eq(114.35, $b['grand_total']);
    $capped = PrescriptionMath::bill($lines, 'amount', 5000, 0, 0, 'Paid');
    eq(121.0, $capped['discount_amount'], 'discount cannot exceed the medicines total');
    eq(0.0, $capped['grand_total']);
});
test('main Rx = exactly one source (order 1, not billed); every other source and no-source go below the cut', function () {
    $lines = [['source_id' => 3], ['source_id' => null], ['source_id' => 1], ['source_id' => 4], ['source_id' => 3]];
    $order = [1 => 1, 3 => 2, 4 => 5];
    $g = PrescriptionMath::assignGroups($lines, true, $order);
    eq([2, 4, 1, 3, 2], array_column($g, 'rx_group'), 'no-source medicines last on the slip');
    eq([0, 0, 1, 0, 0], array_column($g, 'is_main_rx'));
    eq([1, 1, 0, 1, 1], array_column($g, 'billable'));
    $chosen = PrescriptionMath::assignGroups($lines, true, $order, 3);
    eq([1, 4, 2, 3, 1], array_column($chosen, 'rx_group'), 'configured main source');
    // Prescription without the main source: its lowest-order source becomes the main Rx (never empty).
    $noMain = PrescriptionMath::assignGroups([['source_id' => 4], ['source_id' => 3], ['source_id' => null], ['source_id' => 9]], true, $order);
    eq([0, 1, 0, 0], array_column($noMain, 'is_main_rx'), 'source 3 (order 2) becomes main when source 1 is absent');
    eq([2, 1, 4, 3], array_column($noMain, 'rx_group'), 'inactive/unknown source 9 after active ones, no-source last');
    eq(null, PrescriptionMath::effectiveMain([['source_id' => null]], $order, null), 'no source = no main');
    $off = PrescriptionMath::assignGroups($lines, false, $order);
    eq([1, 1, 1, 1, 1], array_column($off, 'rx_group'), 'split disabled');
    eq([1, 1, 1, 1, 1], array_column($off, 'billable'), 'split disabled bills everything');
    $bill = PrescriptionMath::bill([['quantity' => 2, 'unit_price' => 100, 'billable' => 0], ['quantity' => 1, 'unit_price' => 50, 'billable' => 1]], 'amount', 0, 0, 500, 'Paid');
    eq(50.0, $bill['medicines_total'], 'main-Rx medicines are not billed');
    eq(550.0, $bill['grand_total']);
});

// ============================================================================
section('Unit: helpers, validator, password, SQL splitter, Excel');
test('CNIC and phone normalisation', function () {
    eq('35202-1234567-1', normalize_cnic('3520212345671'));
    eq('35202-1234567-1', normalize_cnic('35202-1234567-1'));
    eq(null, normalize_cnic('12345'));
    eq('03001234567', normalize_phone('0300-1234567'));
    eq(null, normalize_phone('12345'));
    eq('35202-12', cnic_prefix('3520212'));
});
test('money and number formatting', function () {
    eq('Rs. 1,500', money(1500));
    eq('Rs. 12.50', money(12.5));
    eq('2.5', num('2.50'));
    eq('10', num('10.00'));
});
test('age text from date of birth (months and days for infants)', function () {
    eq('10 Y', age_text(date('Y-m-d', strtotime('-10 years -2 days'))));
    eq('3 M 1 D', age_text(date('Y-m-d', strtotime('-3 months -1 day'))));
    eq('2 Y 4 M', age_text(date('Y-m-d', strtotime('-2 years -4 months'))));
});
test('age in years / months / days <-> date of birth', function () {
    $dob = PatientService::dobFromAge(0, 3, 10, '2026-09-26');
    eq('2026-06-16', $dob);
    eq([0, 3, 10], PatientService::ageParts($dob, '2026-09-26'));
    [$clean] = PatientService::validate(['name' => 'Baby', 'gender' => 'Female', 'age_years' => '0', 'age_months' => '5', 'age_days' => '2']);
    eq(1, $clean['dob_estimated']);
    eq(PatientService::dobFromAge(0, 5, 2), $clean['dob']);
});
test('Urdu fractions and units', function () {
    eq('½', Urdu::fraction(0.5));
    eq('1½', Urdu::fraction(1.5));
    eq('⅓', Urdu::fraction(0.333));
    eq('¼', Urdu::fraction(0.25));
    eq('2', Urdu::fraction(2));
    eq('tab', Urdu::unitKey('Tablet'));
    eq('solid', Urdu::guessDoseType('Tablet'));
    eq('liquid', Urdu::guessDoseType('Oral Suspension'));
});
test('validator rules', function () {
    $v = (new Validator(['a' => '', 'b' => 'x', 'c' => '5', 'd' => '2026-02-30', 'e' => 'bad']))
        ->required('a', 'A')->numeric('b', 'B')->integer('c', 'C', 1, 3)->date('d', 'D')->email('e', 'E');
    eq(['a', 'b', 'c', 'd', 'e'], array_keys($v->errors()));
});
test('plain-text passwords and bcrypt upgrade path', function () {
    $mode = config('security.password_mode');
    Config::set('security.password_mode', 'plain');
    eq('admin123', Password::hash('admin123'));
    ok(Password::verify('admin123', 'admin123'), 'plain verify');
    ok(!Password::verify('wrong', 'admin123'), 'plain reject');
    ok(Password::verify('admin123', password_hash('admin123', PASSWORD_BCRYPT)), 'hashed passwords still verify');
    Config::set('security.password_mode', 'bcrypt');
    ok(Password::needsUpgrade('admin123'), 'plain password is upgraded in bcrypt mode');
    ok(Password::isHash(Password::hash('x123456')), 'bcrypt mode hashes');
    Config::set('security.password_mode', $mode);
});
test('SQL splitter respects quotes and comments', function () {
    $sql = "-- comment\nINSERT INTO t VALUES ('a;b', 'it''s', 'x\\'y;');\nSELECT 1; -- trailing\nSELECT \"q;\";";
    $parts = SqlSplitter::fromString($sql);
    eq(3, count($parts));
    eq("INSERT INTO t VALUES ('a;b', 'it''s', 'x\\'y;')", $parts[0]);
});
test('xlsx writer produces a valid workbook', function () {
    eq('A', XlsxWriter::col(0));
    eq('Z', XlsxWriter::col(25));
    eq('AA', XlsxWriter::col(26));
    $bin = Exporter::build('Test <Report> & Co', ['name' => 'Name', 'amount' => 'Amount', 'phone' => 'Phone'], [
        ['name' => 'Ali & Sons', 'amount' => '1500.50', 'phone' => '03001234567'],
    ]);
    eq("PK\x03\x04", substr($bin, 0, 4), 'zip signature');
    if (class_exists('ZipArchive')) {
        $tmp = tempnam(sys_get_temp_dir(), 'xl');
        file_put_contents($tmp, $bin);
        $zip = new ZipArchive();
        eq(true, $zip->open($tmp) === true, 'zip opens');
        $sheet = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
        ok(str_contains($sheet, 'Ali &amp; Sons'), 'text escaped');
        ok(str_contains($sheet, '<v>1500.5</v>'), 'amount stored as number');
        ok(str_contains($sheet, '03001234567</t>'), 'phone kept as text');
        eq(8, $zip->numFiles);
        $zip->close();
        unlink($tmp);
    }
});

// ============================================================================
section('Integration: installation');
test('fresh install into the test database', function () {
    Installer::install(['demo' => false]);
    DB::disconnect();
    Settings::flush();
    eq(1 + count(\App\Core\Seeder::SAMPLE_USERS), (int) DB::value('SELECT COUNT(*) FROM users'));
    eq(28, (int) DB::value('SELECT COUNT(*) FROM medicines'));
    eq(4 * (int) DB::value("SELECT COUNT(*) FROM users WHERE role = 'doctor'"), (int) DB::value('SELECT COUNT(*) FROM print_designs'), '4 paper designs per doctor');
    eq(10, (int) DB::value('SELECT COUNT(*) FROM frequencies'));
    eq(Migrator::LATEST, Migrator::current(), 'fresh install is at the latest schema');
    ok(Migrator::tableExists('license_issued') && Migrator::tableExists('license_activations'), 'licence log tables');
    eq('دن میں تین بار', DB::value("SELECT name_ur FROM frequencies WHERE code = 'TDS'"));
});
test('migration upgrades an older database without losing data', function () {
    DB::run('ALTER TABLE visits DROP COLUMN findings');
    DB::run('ALTER TABLE dosage_forms DROP COLUMN dose_type');
    DB::run("UPDATE frequencies SET name_ur = NULL");
    DB::run('DROP TABLE license_activations');
    \App\Core\Settings::set(['schema_version' => '1']);
    ok(!Migrator::columnExists('visits', 'findings'), 'column removed for the test');
    Migrator::runIfNeeded();
    \App\Core\Settings::flush();
    ok(Migrator::columnExists('visits', 'findings'), 'column restored');
    eq('solid', DB::value("SELECT dose_type FROM dosage_forms WHERE name = 'Tablet'"));
    eq('دن میں دو بار', DB::value("SELECT name_ur FROM frequencies WHERE code = 'BD'"));
    ok(Migrator::tableExists('license_activations'), 'v3 table restored');
    eq(Migrator::LATEST, Migrator::current());
});
test('employee codes follow the pattern', function () {
    eq('EMP-' . date('Y') . '-0001', user('superadmin')['employee_code']);
    eq('EMP-' . date('Y') . '-0003', user('doctor')['employee_code']);
});
test('every route points to an existing controller method', function () {
    foreach (Router::routes() as $route => [$methods, $handler, $perm]) {
        ok(method_exists($handler[0], $handler[1]), "route $route → {$handler[0]}::{$handler[1]} missing");
        foreach ($perm === null || $perm === 'auth' ? [] : explode('|', $perm) as $p) {
            ok(isset(config('permissions.permissions')[$p]), "route $route uses unknown permission $p");
        }
    }
    ok(count(Router::routes()) > 60, 'expected more than 60 routes');
    foreach (glob(BASE_PATH . '/modules/*/routes.php') as $file) {
        foreach (array_keys((static fn () => require $file)()) as $route) {
            ok(isset(Router::routes()[$route]), "route $route from " . basename(dirname($file)) . ' is not registered');
        }
    }
});
test('role permission matrix', function () {
    $expect = [
        ['super_admin', 'records.delete', true], ['super_admin', 'db.reset', true], ['super_admin', 'templates.manage', false],
        ['super_admin', 'users.manage', true], ['super_admin', 'backup.restore', true],
        ['dept_admin', 'backup.create', true], ['dept_admin', 'backup.restore', false], ['dept_admin', 'masters.manage', true],
        ['dept_admin', 'records.delete', false], ['dept_admin', 'users.manage', false], ['dept_admin', 'departments.manage', false],
        ['doctor', 'visits.create', true], ['doctor', 'templates.manage', true], ['doctor', 'users.view', false],
        ['doctor', 'masters.manage', false], ['doctor', 'db.reset', false], ['doctor', 'records.delete', false],
        ['reporting', 'reports.view', true], ['reporting', 'visits.create', false], ['reporting', 'patients.create', false],
        ['reporting', 'masters.view', false], ['reporting', 'backup.create', false],
    ];
    foreach ($expect as [$role, $perm, $allowed]) {
        eq($allowed, Auth::roleCan($role, $perm), "$role / $perm");
    }
});

// ============================================================================
section('Integration: patients and MRN');
test('MRNs follow each doctor\'s own pattern and never repeat', function () {
    Auth::actingAs(user('doctor'));
    [$data] = PatientService::validate(['name' => 'ali raza', 'gender' => 'Male', 'age' => '30']);
    $a = PatientService::create($data, user('doctor')['id']);
    $b = PatientService::create($data, user('doctor')['id']);
    $c = PatientService::create($data, user('doctor2')['id']);
    eq('DR01-' . date('Y') . '-000001', $a['mrn']);
    eq('DR01-' . date('Y') . '-000002', $b['mrn']);
    eq('DOC2-' . date('Y') . '-000001', $c['mrn']);
    eq('Ali Raza', $a['name'], 'names are title-cased');
    eq(1, (int) $a['dob_estimated'], 'age-only DOB is marked estimated');
});
test('MRN generator skips a code that already exists', function () {
    Auth::actingAs(user('doctor'));
    DB::update('doctor_profiles', ['mrn_pattern' => 'X-{0001}'], 'user_id = ?', [user('doctor')['id']]);
    DB::insert('patients', ['mrn' => 'X-0001', 'doctor_id' => user('doctor')['id'], 'name' => 'Manual', 'gender' => 'Male', 'created_at' => now()]);
    [$data] = PatientService::validate(['name' => 'Next', 'gender' => 'Female']);
    eq('X-0002', PatientService::create($data, user('doctor')['id'])['mrn']);
    DB::update('doctor_profiles', ['mrn_pattern' => 'DR01-{YYYY}-{000001}'], 'user_id = ?', [user('doctor')['id']]);
});
test('family members may share CNIC and phone (BRD §31)', function () {
    Auth::actingAs(user('doctor'));
    [$d1] = PatientService::validate(['name' => 'Father', 'gender' => 'Male', 'cnic' => '3520299999991', 'phone' => '0300-9999999']);
    [$d2] = PatientService::validate(['name' => 'Son', 'gender' => 'Male', 'cnic' => '35202-9999999-1', 'phone' => '03009999999']);
    $p1 = PatientService::create($d1, user('doctor')['id']);
    $p2 = PatientService::create($d2, user('doctor')['id']);
    ok($p1['mrn'] !== $p2['mrn'], 'distinct MRNs');
    eq(2, count(PatientService::duplicates('35202-9999999-1', null)));
    eq(1, count(PatientService::duplicates(null, '03009999999', (int) $p1['id'])));
});
test('live search by MRN, name, CNIC digits and phone', function () {
    ok(count(PatientService::search('DR01-')) >= 2, 'MRN prefix');
    ok(count(PatientService::search('Fath')) >= 1, 'name prefix');
    ok(count(PatientService::search('352029999')) >= 2, 'CNIC digits');
    ok(count(PatientService::search('0300999')) >= 2, 'phone digits');
    eq([], PatientService::search('a'), 'single character ignored');
});
test('patient validation rejects bad input', function () {
    [, $errors] = PatientService::validate(['name' => '', 'gender' => 'X', 'cnic' => '123', 'phone' => '12', 'dob' => '2999-01-01']);
    eq(['name', 'gender', 'cnic', 'phone', 'dob'], array_keys($errors));
});

// ============================================================================
section('Integration: checkout (one-page consultation)');
$visitId = 0;
test('checkout saves patient, visit, clinical lists, medicines and bill', function () use (&$visitId) {
    $doctor = user('doctor');
    Auth::actingAs($doctor);
    $p = payload(['medicines' => [
        line('Panadol'),                                               // TDS × 5 days → 15
        line('Augmentin', ['unit_price' => '40']),                     // price override
        line('Ventolin Inhaler', ['qty_manual' => 1, 'quantity' => '1']), // SOS → manual
        line('Calpol Syrup'),                                          // 45 ml → 1 bottle
    ], 'discount_value' => '50']);
    $r = VisitService::checkout($p, $doctor);
    ok($r['ok'], 'checkout failed: ' . implode(' ', $r['errors'] ?? []));
    $visitId = $r['visit_id'];
    $v = VisitService::load($visitId);
    eq('V-' . date('Y') . '-000001', $v['visit_no']);
    eq(22.9, (float) $v['bmi']);
    $q = array_column($v['medicines'], 'quantity', 'medicine_name');
    eq('15.00', $q['Panadol'], 'server recalculates, ignoring client 999');
    eq('10.00', $q['Augmentin']);
    eq('1.00', $q['Ventolin Inhaler']);
    eq('1.00', $q['Calpol Syrup']);
    $prices = array_column($v['medicines'], 'unit_price', 'medicine_name');
    eq('40.00', $prices['Augmentin'], 'price override kept');
    // Main Rx (Local Pharmacy: Panadol, Calpol) is bought outside — not billed.
    // Billed: Augmentin 10×40 + Ventolin 1×450 = 850; −50 = 800; +1500 = 2300
    eq('850.00', $v['medicines_total']);
    eq('800.00', $v['medicine_net']);
    eq('2300.00', $v['grand_total']);
    $billable = array_column($v['medicines'], 'billable', 'medicine_name');
    eq(0, (int) $billable['Panadol']);
    eq(1, (int) $billable['Augmentin']);
    eq('tsp', array_column($v['medicines'], 'dose_unit', 'medicine_name')['Calpol Syrup']);
    eq(2, count($v['items']['complaints']), 'master + free-text complaint');
    eq(null, $v['items']['complaints'][1]['id'], 'free text has no master id');
    eq(1, count($v['items']['diagnoses']));
    eq(3, count($v['groups']), 'main Rx, Hospital Stock, Distributor B');
    eq(true, $v['groups'][0]['is_main']);
    eq('Hospital Stock', $v['groups'][1]['source_name']);
    eq(1, $v['visit_number']);
    eq(2300.0, $v['day_cumulative']);
    eq(1, $v['day_count'], 'first prescription of the day');
    eq(0, (int) $v['refills'], 'new prescription = refill 0');
});
test('the same checkout token never creates a second visit', function () {
    $doctor = user('doctor');
    Auth::actingAs($doctor);
    $p = payload(['medicines' => [line('Panadol')]]);
    $r1 = VisitService::checkout($p, $doctor);
    $before = (int) DB::value('SELECT COUNT(*) FROM visits');
    $r2 = VisitService::checkout($p, $doctor);
    eq(true, $r2['duplicate'] ?? false);
    eq($r1['visit_id'], $r2['visit_id']);
    eq($before, (int) DB::value('SELECT COUNT(*) FROM visits'));
});
test('checkout rejects invalid data with friendly messages', function () {
    $doctor = user('doctor');
    Auth::actingAs($doctor);
    $bad = VisitService::checkout(payload([
        'patient'   => ['name' => '', 'gender' => ''],
        'vitals'    => ['bp_systolic' => '80', 'bp_diastolic' => 'abc'],
        'diagnoses' => [['id' => null, 'name' => 'Made up']],
        'medicines' => [line('Panadol'), line('Panadol'), line('Ventolin Inhaler', ['qty_manual' => 1, 'quantity' => '0'])],
        'follow_up_date' => date('Y-m-d', strtotime('-1 day')),
    ]), $doctor);
    eq(false, $bad['ok']);
    $text = implode(' | ', $bad['errors']);
    foreach (['Patient name is required', 'Gender is required', 'BP diastolic must be a number', 'must be selected from the list', 'Medicine already added', 'Invalid quantity', 'Follow-up date'] as $needle) {
        ok(str_contains($text, $needle), "missing error: $needle in [$text]");
    }
});
test('unusual vitals are saved and returned as warnings, never blocked', function () {
    $doctor = user('doctor');
    Auth::actingAs($doctor);
    $r = VisitService::checkout(payload(['vitals' => ['pulse' => '300', 'temperature' => '37', 'spo2' => '150', 'bp_systolic' => '80', 'bp_diastolic' => '120'],
        'allergies' => 'Penicillin', 'findings' => 'Chest clear', 'refills' => '2']), $doctor);
    ok($r['ok'], 'saved: ' . implode(' ', $r['errors'] ?? []));
    eq(4, count($r['warnings']), 'pulse, temperature, SpO2 and BP order');
    $v = VisitService::load($r['visit_id']);
    eq(300, (int) $v['pulse']);
    eq('37.0', $v['temperature']);
    eq('Penicillin', $v['allergies']);
    eq(0, (int) $v['refills'], 'refill cannot be set by hand');
    ok($v['day_count'] >= 2, 'day prescription count grows');
    eq('Penicillin', DB::value('SELECT allergies FROM patients WHERE id = ?', [$v['patient_id']]), 'allergies copied to the patient');
});
test('inactive medicines are excluded from new prescriptions', function () {
    $doctor = user('doctor');
    Auth::actingAs($doctor);
    DB::update('medicines', ['is_active' => 0], 'name = ?', ['Brufen']);
    $r = VisitService::checkout(payload(['medicines' => [line('Brufen')]]), $doctor);
    eq(false, $r['ok']);
    ok(str_contains($r['message'], 'inactive'), 'message mentions inactive');
    DB::update('medicines', ['is_active' => 1], 'name = ?', ['Brufen']);
});
test('non-doctor checkout needs a doctor', function () {
    $admin = user('superadmin');
    Auth::actingAs($admin);
    $r = VisitService::checkout(payload(), $admin);
    eq(false, $r['ok']);
    $r = VisitService::checkout(payload(['doctor_id' => user('doctor2')['id']]), $admin);
    ok($r['ok'], 'with doctor_id it saves');
    eq((int) user('doctor2')['id'], (int) DB::value('SELECT doctor_id FROM visits WHERE id = ?', [$r['visit_id']]));
});

// ============================================================================
section('Integration: history integrity');
test('master price/source changes never alter saved prescriptions', function () use (&$visitId) {
    $before = DB::one("SELECT unit_price, source_name, medicine_name FROM visit_medicines WHERE visit_id = ? AND medicine_name = 'Panadol'", [$visitId]);
    DB::update('medicines', ['price' => 99, 'source_id' => master('medicine_sources', 'Distributor A'), 'name' => 'Panadol Extra'], 'name = ?', ['Panadol']);
    $after = DB::one("SELECT unit_price, source_name, medicine_name FROM visit_medicines WHERE visit_id = ? AND medicine_name = 'Panadol'", [$visitId]);
    eq($before, $after);
    DB::update('medicines', ['price' => 3, 'source_id' => master('medicine_sources', 'Local Pharmacy'), 'name' => 'Panadol'], 'name = ?', ['Panadol Extra']);
});
test('noskha wording in English and Urdu', function () use (&$visitId) {
    Directions::flush();
    $m = DB::one("SELECT * FROM visit_medicines WHERE visit_id = ? AND medicine_name = 'Panadol'", [$visitId]);
    eq('1 tablet, three times a day (TDS), for 5 days', Directions::english($m));
    eq('1 گولی، دن میں تین بار، کھانے کے بعد، 5 دن تک', Directions::urdu($m));
    eq('Tablet Panadol (Paracetamol) 500mg', Directions::line1($m));
    $c = DB::one("SELECT * FROM visit_medicines WHERE visit_id = ? AND medicine_name = 'Calpol Syrup'", [$visitId]);
    ok(str_contains(Directions::urdu($c), 'چھوٹا چمچ'), 'teaspoon in Urdu');
});
test('previous visit refill brings everything back, the old visit unchanged', function () use (&$visitId) {
    $data = VisitService::refillData($visitId);
    eq(2, count($data['complaints']));
    eq(1, count($data['diagnoses']));
    eq(4, count($data['medicines']));
    eq('Rest well.', $data['overall_instructions']);
    ok(count(VisitService::history((int) DB::value('SELECT patient_id FROM visits WHERE id = ?', [$visitId]))) >= 1, 'history lists visits');
});
test('refill counts repeats automatically: original 0, repeat 1, repeat of repeat 2', function () use (&$visitId) {
    $doctor = user('doctor');
    Auth::actingAs($doctor);
    $r1 = VisitService::checkout(payload(['repeated_from_visit_id' => $visitId, 'medicines' => [line('Panadol')]]), $doctor);
    eq(1, (int) DB::value('SELECT refills FROM visits WHERE id = ?', [$r1['visit_id']]));
    $r2 = VisitService::checkout(payload(['repeated_from_visit_id' => $r1['visit_id'], 'medicines' => [line('Panadol')]]), $doctor);
    eq(2, (int) DB::value('SELECT refills FROM visits WHERE id = ?', [$r2['visit_id']]));
});
test('migration v4 repairs saved prescriptions whose main Rx was empty', function () use (&$visitId) {
    DB::run('UPDATE visit_medicines SET is_main_rx = 0, billable = 1 WHERE visit_id = ?', [$visitId]);
    DB::run('UPDATE visits SET grand_total = 99999 WHERE id = ?', [$visitId]);
    \App\Core\Settings::set(['schema_version' => '3']);
    Migrator::runIfNeeded();
    \App\Core\Settings::flush();
    ok((int) DB::value('SELECT COUNT(*) FROM visit_medicines WHERE visit_id = ? AND is_main_rx = 1', [$visitId]) > 0, 'main Rx restored');
    eq('2300.00', DB::value('SELECT grand_total FROM visits WHERE id = ?', [$visitId]), 'bill recalculated');
    eq(Migrator::LATEST, Migrator::current());
    ok((bool) DB::value("SELECT 1 FROM activity_logs WHERE action = 'Prescription regrouped' AND record_id = ?", [(string) $visitId]), 'repair logged');
});
test('trending lists the doctor\'s most used items first', function () {
    $t = ClinicalItems::trending((int) user('doctor')['id']);
    $top = array_slice(array_column($t['complaints'], 'name'), 0, 2);
    ok(in_array('Fever', $top, true), 'most used complaint first (ties by name)');
    ok($t['complaints'][0]['count'] >= $t['complaints'][1]['count'], 'sorted by count');
    ok($t['complaints'][0]['count'] >= 1, 'has a count');
    ok(count($t['diagnoses']) <= 20 && count($t['diagnoses']) >= 10, 'topped up from the master list');
});
test('repeat uses current prices, skips inactive medicines, keeps original', function () use (&$visitId) {
    DB::update('medicines', ['price' => 5], 'name = ?', ['Panadol']);
    DB::update('medicines', ['is_active' => 0], 'name = ?', ['Calpol Syrup']);
    $rep = VisitService::repeatLines($visitId);
    $names = array_column($rep['medicines'], 'name');
    ok(!in_array('Calpol Syrup', $names, true), 'inactive skipped');
    eq(['Calpol Syrup'], $rep['skipped']);
    $panadol = array_values(array_filter($rep['medicines'], static fn ($l) => $l['name'] === 'Panadol'))[0];
    eq(5.0, $panadol['unit_price']);
    eq(45.0, array_values(array_filter($rep['medicines'], static fn ($l) => $l['name'] === 'Augmentin'))[0]['unit_price'], 'repeat uses the current master price, not the old override');
    eq('3.00', DB::value("SELECT unit_price FROM visit_medicines WHERE visit_id = ? AND medicine_name = 'Panadol'", [$visitId]), 'original unchanged');
    DB::update('medicines', ['price' => 3], 'name = ?', ['Panadol']);
    DB::update('medicines', ['is_active' => 1], 'name = ?', ['Calpol Syrup']);
});

// ============================================================================
section('Integration: templates');
test('templates are doctor-specific and loading never changes them', function () {
    $a = user('doctor');
    $b = user('doctor2');
    Auth::actingAs($a);
    $r = TemplateService::save([
        'name' => 'Typhoid adult', 'diagnoses' => [['id' => master('diagnoses', 'Typhoid Fever')]],
        'complaints' => [['name' => 'Fever']], 'medicines' => [line('Azomax')], 'overall_instructions' => 'Boiled water only', 'follow_up_days' => '5',
    ], (int) $a['id']);
    ok($r['ok'], 'save failed: ' . implode(' ', $r['errors'] ?? []));
    eq(null, TemplateService::find($r['id'], (int) $b['id']), 'doctor 2 cannot see it');
    eq(null, TemplateService::loadForScreen($r['id'], (int) $b['id']));
    $loaded = TemplateService::loadForScreen($r['id'], (int) $a['id']);
    eq('Typhoid adult', $loaded['name']);
    eq(1, count($loaded['medicines']));
    eq(date('Y-m-d', strtotime('+5 days')), $loaded['follow_up_date']);
    $dup = TemplateService::save(['name' => 'Typhoid adult', 'overall_instructions' => 'x'], (int) $a['id']);
    eq(false, $dup['ok'], 'duplicate template name rejected');
    $v = VisitService::checkout(payload(['template_id' => $r['id'], 'medicines' => [line('Azomax')]]), $a);
    ok($v['ok'], 'visit from template');
    eq(1, (int) DB::value('SELECT usage_count FROM templates WHERE id = ?', [$r['id']]));
    eq(1, (int) DB::value('SELECT COUNT(*) FROM template_medicines WHERE template_id = ?', [$r['id']]), 'template unchanged');
});

// ============================================================================
section('Integration: scope, soft delete, codes');
test('department admin sees only doctors of assigned departments', function () {
    Auth::actingAs(user('deptadmin'));
    eq([(int) user('doctor')['id']], Scope::doctorIds());
    Auth::actingAs(user('doctor2'));
    eq([(int) user('doctor2')['id']], Scope::doctorIds());
    Auth::actingAs(user('reporting'));
    eq(null, Scope::doctorIds());
});
test('soft-deleted patients disappear from search and can be restored', function () {
    $id = (int) DB::value("SELECT id FROM patients WHERE name = 'Father'");
    DB::update('patients', ['deleted_at' => now()], 'id = ?', [$id]);
    eq([], array_filter(PatientService::search('Father'), static fn ($p) => $p['id'] === $id));
    eq(null, PatientService::find($id));
    DB::update('patients', ['deleted_at' => null], 'id = ?', [$id]);
    ok(PatientService::find($id) !== null, 'restored');
});
test('visit numbers are unique under repeated generation', function () {
    $codes = [];
    for ($i = 0; $i < 25; $i++) {
        $codes[] = CodeGenerator::next('unit', 'U-{0001}', static fn ($c) => false);
    }
    eq(25, count(array_unique($codes)));
    eq('U-0025', end($codes));
});

// ============================================================================
section('Integration: date-wise delete and subscription licence');
test('delete by table and date range: preview matches, other data stays', function () {
    $today = today();
    $before = (int) DB::value('SELECT COUNT(*) FROM visits');
    DB::run("UPDATE visits SET visit_date = '2020-01-15 10:00:00' WHERE id = (SELECT id FROM (SELECT MIN(id) AS id FROM visits) x)");
    $preview = DataPurger::preview(['visits', 'activity_logs'], '2020-01-01', '2020-01-31');
    eq(1, $preview['visits']);
    $done = DataPurger::purge(['visits'], '2020-01-01', '2020-01-31');
    eq(1, $done['visits']);
    eq($before - 1, (int) DB::value('SELECT COUNT(*) FROM visits'));
    eq(0, (int) DB::value('SELECT COUNT(*) FROM visit_medicines vm LEFT JOIN visits v ON v.id = vm.visit_id WHERE v.id IS NULL'), 'no orphan medicines');
    $logs = DataPurger::preview(['activity_logs'], $today, $today)['activity_logs'];
    ok($logs > 0, 'today has log entries');
    eq($logs, DataPurger::purge(['activity_logs'], $today, $today)['activity_logs']);
});
test('subscription keys: signed, bound to phone, expiry and clock checks', function () {
    $kp = sodium_crypto_sign_keypair();
    $sk = base64_encode(sodium_crypto_sign_secretkey($kp));
    License::usePublicKey(base64_encode(sodium_crypto_sign_publickey($kp)));
    \App\Core\Settings::set(['license_key' => '', 'license_phone' => '', 'license_last_seen' => '', 'license_trial_start' => '']);
    License::flush();
    eq('trial', License::status()['state'], 'new install starts a trial');
    ok(License::allowsLogin(), 'trial allows login');
    \App\Core\Settings::set(['license_trial_start' => date('Y-m-d', strtotime('-30 days'))]);
    License::flush();
    eq('expired', License::status()['state'], 'trial over');
    ok(!License::allowsLogin(), 'expired trial blocks login');

    $key = License::issue($sk, '0300-1234567', date('Y-m-d', strtotime('-10 days')), date('Y-m-d', strtotime('+365 days')));
    ok(str_starts_with($key, 'MCP1-'), 'key prefix');
    eq('03001234567', License::read($key)['phone'], 'leading zero kept');
    eq(null, License::read(substr($key, 0, -3) . 'ABC'), 'tampered key rejected');
    eq(false, License::activate('03009999999', $key)['ok'], 'other phone rejected');
    ok(License::activate('03001234567', $key)['ok'], 'activated');
    eq('active', License::status()['state']);

    $soon = License::issue($sk, '03001234567', date('Y-m-d', strtotime('-300 days')), date('Y-m-d', strtotime('+5 days')));
    ok(License::activate('03001234567', $soon)['ok'], 'renewal key accepted');
    eq('expiring', License::status()['state'], 'warns before expiry');
    eq(6, License::status()['days_left']);

    $old = License::issue($sk, '03001234567', '2025-01-01', '2025-12-31');
    eq(false, License::activate('03001234567', $old)['ok'], 'already expired key refused');
    \App\Core\Settings::set(['license_key' => $old]);
    License::flush();
    eq('expired', License::status()['state']);

    \App\Core\Settings::set(['license_key' => $key, 'license_last_seen' => date('Y-m-d', strtotime('+10 days'))]);
    License::flush();
    eq('clock', License::status()['state'], 'clock moved back is detected');
    ok(!License::allowsLogin(), 'clock tampering blocks login');

    $acts = License::activationLog();
    eq(2, count($acts), 'every successful activation is logged (the refused expired key is not)');
    $byKey = array_column($acts, 'status', 'license_key');
    ok(str_starts_with($byKey[$key], 'In use'), 'the key in settings is marked in use');
    eq('Replaced', $byKey[$soon], 'the other key is marked replaced');

    // Vendor generator with its own key file + issued log with status.
    $file = sys_get_temp_dir() . '/mc_test_vendor.key';
    file_put_contents($file, $sk);
    License::useVendorKeyFile($file);
    ok(License::canIssue(), 'vendor key matches public key');
    Auth::actingAs(user('superadmin'));
    $g = License::generate(['phone' => '0321-5550000', 'clinic_name' => 'Test Clinic', 'from' => today(), 'to' => date('Y-m-d', strtotime('+30 days')), 'amount' => '5000', 'payment_ref' => 'TID1']);
    ok($g['ok'], 'generated: ' . $g['message']);
    eq('03215550000', License::read($g['key'])['phone']);
    eq(false, License::generate(['phone' => '123', 'from' => today(), 'to' => today()])['ok'], 'bad phone rejected');
    $log = License::issuedLog();
    eq('Test Clinic', $log[0]['clinic_name']);
    eq('Active', $log[0]['status']);
    eq('Scheduled', License::periodStatus(date('Y-m-d', strtotime('+3 days')), date('Y-m-d', strtotime('+90 days'))));
    License::useVendorKeyFile(sys_get_temp_dir() . '/no-such.key');
    ok(!License::canIssue(), 'clinic installs (no private key) cannot issue');
    License::useVendorKeyFile(null);
    @unlink($file);

    License::usePublicKey(null);
    \App\Core\Settings::set(['license_key' => $key, 'license_last_seen' => today()]);
    License::flush();
    eq('invalid', License::status()['state'], 'key signed by another vendor key is invalid');
    \App\Core\Settings::set(['license_key' => '', 'license_phone' => '', 'license_trial_start' => '']);
    License::flush();
});

// ============================================================================
require __DIR__ . '/v2.php';

// ============================================================================
section('Integration: backup, restore and reset');
test('backup → change → restore returns the earlier data', function () {
    Auth::actingAs(user('superadmin'));
    $patients = (int) DB::value('SELECT COUNT(*) FROM patients');
    $name = Backup::create('manual');
    ok(Backup::isValidFile(Backup::dir() . '/' . $name), 'backup has signature');
    DB::insert('patients', ['mrn' => 'TMP-1', 'doctor_id' => user('doctor')['id'], 'name' => 'Temporary', 'gender' => 'Male', 'created_at' => now()]);
    $archive = Backup::restore(Backup::dir() . '/' . $name);
    eq($patients, (int) DB::value('SELECT COUNT(*) FROM patients'));
    ok(is_file(Backup::dir() . '/' . $archive), 'pre-restore archive exists');
    @unlink(Backup::dir() . '/' . $name);
    @unlink(Backup::dir() . '/' . $archive);
});
test('restore refuses files that are not MediCare backups', function () {
    $tmp = Backup::dir() . '/not_a_backup.sql';
    file_put_contents($tmp, "DROP TABLE users;\n");
    try {
        Backup::restore($tmp);
        throw new RuntimeException('restore should have failed');
    } catch (RuntimeException $e) {
        ok(str_contains($e->getMessage(), 'not a valid'), 'friendly error');
    } finally {
        @unlink($tmp);
    }
    ok(DB::value('SELECT COUNT(*) FROM users') > 0, 'users intact');
});
test('clinical reset clears patient data and restarts numbering', function () {
    $sa = user('superadmin');
    Auth::actingAs($sa);
    $users = (int) DB::value('SELECT COUNT(*) FROM users');
    $backup = Installer::reset('clinical', $sa);
    eq(0, (int) DB::value('SELECT COUNT(*) FROM patients'));
    eq(0, (int) DB::value('SELECT COUNT(*) FROM visits'));
    eq($users, (int) DB::value('SELECT COUNT(*) FROM users'), 'users kept');
    eq(28, (int) DB::value('SELECT COUNT(*) FROM medicines'), 'masters kept');
    [$data] = PatientService::validate(['name' => 'First Again', 'gender' => 'Male']);
    Auth::actingAs(user('doctor'));
    eq('DR01-' . date('Y') . '-000001', PatientService::create($data, user('doctor')['id'])['mrn']);
    @unlink(Backup::dir() . '/' . $backup);
});
test('factory reset keeps only the super admin with the same login', function () {
    $sa = user('superadmin');
    Auth::actingAs($sa);
    $backup = Installer::reset('factory', $sa);
    eq(1, (int) DB::value('SELECT COUNT(*) FROM users'));
    ok(Password::verify('admin123', (string) DB::value("SELECT password FROM users WHERE username = 'superadmin'")), 'same super-admin password');
    eq(0, (int) DB::value('SELECT COUNT(*) FROM patients'));
    eq(28, (int) DB::value('SELECT COUNT(*) FROM medicines'), 'masters reloaded');
    @unlink(Backup::dir() . '/' . $backup);
});

// ============================================================================
echo "\n" . ($failures ? "\033[31m" : "\033[32m") . "$passed passed, " . count($failures) . " failed\033[0m\n";
exit($failures ? 1 : 0);
