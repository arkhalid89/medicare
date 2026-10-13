<?php
/**
 * End-to-end HTTP smoke test against the running application.
 *
 *   php tests/smoke.php [base-url]
 *   e.g. php tests/smoke.php http://localhost/medicare-practice/public
 *
 * Needs an installed database with the sample users (demo data recommended).
 * For every role it logs in, opens EVERY GET route (expecting 200 when the
 * role holds the permission and 403 when it does not), downloads every Excel
 * export, and checks that no page contains a PHP warning or error. It then
 * performs a real checkout, prints it in all four paper sizes and verifies
 * that unauthorised POSTs are blocked. It creates one visit and one template.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit('Run from the command line.');
}
require dirname(__DIR__) . '/core/bootstrap.php';

use App\Core\Auth;
use App\Core\Backup;
use App\Core\DB;
use App\Core\Router;

$base = rtrim($argv[1] ?? (getenv('SMOKE_URL') ?: 'http://localhost/medicare-practice/public'), '/');
$passed = 0;
$failed = [];

function http(string $method, string $url, string $jar, array $opts = []): array
{
    $ch = curl_init($url);
    $headers = $opts['headers'] ?? [];
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_FOLLOWLOCATION => $opts['follow'] ?? false,
        CURLOPT_COOKIEJAR      => $jar,
        CURLOPT_COOKIEFILE     => $jar,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_TIMEOUT        => 60,
    ]);
    if (isset($opts['form'])) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($opts['form']));
    }
    if (isset($opts['json'])) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($opts['json']));
        $headers[] = 'Content-Type: application/json';
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    $raw = (string) curl_exec($ch);
    $size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return ['status' => $status, 'headers' => substr($raw, 0, $size), 'body' => substr($raw, $size)];
}

function check(string $name, bool $ok, string $detail = ''): void
{
    global $passed, $failed;
    if ($ok) {
        $passed++;
    } else {
        $failed[] = $name . ($detail ? ' — ' . $detail : '');
        echo "  \033[31m✘ $name\033[0m $detail\n";
    }
}

function clean(string $body): ?string
{
    foreach (['Fatal error', 'Warning:', 'Notice:', 'Deprecated:', 'Stack trace', 'We could not complete', 'Parse error', 'Uncaught'] as $needle) {
        if (str_contains($body, $needle)) {
            $pos = strpos($body, $needle);
            return trim(strip_tags(substr($body, $pos, 200)));
        }
    }
    return null;
}

function csrf(string $body): string
{
    return preg_match('/name="_token" value="([a-f0-9]+)"/', $body, $m) ? $m[1] : '';
}

function login(string $base, string $username, string $password, string $jar): string
{
    @unlink($jar);
    $page = http('GET', $base . '/index.php?r=login', $jar);
    $r = http('POST', $base . '/index.php?r=login', $jar, ['form' => ['_token' => csrf($page['body']), 'username' => $username, 'password' => $password]]);
    check("login $username redirects", $r['status'] === 302 && !str_contains($r['headers'], 'r=login'), 'status ' . $r['status']);
    $dash = http('GET', $base . '/index.php?r=dashboard', $jar);
    check("dashboard $username", $dash['status'] === 200 && clean($dash['body']) === null, clean($dash['body']) ?? 'status ' . $dash['status']);
    return csrf($dash['body']);
}

// ---------------------------------------------------------------- fixtures
$patientId = (int) DB::value('SELECT id FROM patients WHERE deleted_at IS NULL ORDER BY id LIMIT 1');
$visitId = (int) DB::value('SELECT id FROM visits WHERE deleted_at IS NULL ORDER BY id DESC LIMIT 1');
$doctor = DB::one("SELECT * FROM users WHERE username = 'doctor'");
$medicineId = (int) DB::value('SELECT id FROM medicines ORDER BY id LIMIT 1');
$userId = (int) $doctor['id'];
if (!$patientId || !$visitId) {
    exit("Install with demo data first: php database/install.php --demo --force\n");
}
Auth::actingAs(DB::one("SELECT * FROM users WHERE username = 'superadmin'"));
$backupFile = Backup::create('manual');
Auth::actingAs(null);
$templateId = (int) DB::value('SELECT id FROM templates WHERE doctor_id = ? AND deleted_at IS NULL LIMIT 1', [$userId]);
if (!$templateId) {
    $templateId = DB::insert('templates', ['doctor_id' => $userId, 'name' => 'Smoke template', 'overall_instructions' => 'Rest', 'created_at' => now()]);
}

$params = [
    'patients/view'          => ['id' => $patientId],
    'patients/edit'          => ['id' => $patientId],
    'visits/view'            => ['id' => $visitId],
    'visits/print'           => ['id' => $visitId],
    'users/view'             => ['id' => $userId],
    'users/form'             => ['id' => $userId],
    'masters/form'           => ['type' => 'medicines', 'id' => $medicineId],
    'templates/edit'         => ['id' => $templateId],
    'templates/load'         => ['id' => $templateId],
    'system/backup/download' => ['file' => $backupFile],
    'search'                 => ['q' => 'DR01'],
    'search/quick'           => ['q' => 'DR01'],
    'patients/search'        => ['q' => 'DR01'],
    'patients/duplicates'    => ['phone' => '03001234567'],
    'visits/lookup'          => ['type' => 'complaint', 'q' => 'fe'],
    'visits/medicines'       => ['q' => 'pan'],
    'visits/history'         => ['patient_id' => $patientId],
    'visits/refill'          => ['id' => $visitId],
    'system/logs/preview'    => ['from' => date('Y-m-01'), 'to' => today()],
    'system/purge/preview'   => ['from' => '2020-01-01', 'to' => '2020-01-31', 'datasets' => ['visits', 'activity_logs']],
];
$exportable = ['patients', 'visits', 'users', 'templates', 'reports/visits', 'reports/fees', 'system/logs', 'system/recycle', 'masters/mapping'];
foreach (array_keys(config('masters')) as $k) {
    $exportable[] = 'masters/' . $k;
}

// Every sample user (v2: one per department role) — expectations come from the live RBAC.
$roles = ['superadmin' => ['admin123', 'super_admin']];
foreach (\App\Core\Seeder::SAMPLE_USERS as $u) {
    $roles[$u[1]] = [$u[2], (string) DB::value('SELECT role FROM users WHERE username = ?', [$u[1]])];
}
$skipped = [];
// v2 fixtures for pages that need an id
$fx = static fn (string $sql) => (int) DB::value($sql);
$params += array_filter([
    'billing/invoice'   => ['id' => $fx('SELECT id FROM invoices ORDER BY id DESC LIMIT 1')],
    'billing/print'     => ['id' => $fx('SELECT id FROM invoices ORDER BY id DESC LIMIT 1')],
    'lab/order'         => ['id' => $fx('SELECT id FROM lab_orders ORDER BY id DESC LIMIT 1')],
    'lab/report'        => ['id' => $fx('SELECT id FROM lab_orders ORDER BY id DESC LIMIT 1')],
    'lab/result'        => ['id' => $fx('SELECT id FROM lab_order_items ORDER BY id DESC LIMIT 1')],
    'lab/labels'        => ['ids' => (string) $fx('SELECT id FROM lab_samples ORDER BY id DESC LIMIT 1')],
    'radiology/order'   => ['id' => $fx('SELECT id FROM radiology_orders ORDER BY id DESC LIMIT 1')],
    'radiology/report'  => ['id' => $fx('SELECT id FROM radiology_orders ORDER BY id DESC LIMIT 1')],
    'dental'            => ['patient_id' => $fx('SELECT patient_id FROM dental_charts ORDER BY id DESC LIMIT 1')],
    'dental/plan-print' => ['id' => $fx('SELECT id FROM dental_treatment_plans ORDER BY id DESC LIMIT 1')],
    'obgyn'             => ['patient_id' => $fx('SELECT patient_id FROM pregnancies ORDER BY id DESC LIMIT 1')],
    'obgyn/summary'     => ['patient_id' => $fx('SELECT patient_id FROM pregnancies ORDER BY id DESC LIMIT 1')],
    'pharmacy/dispense' => ['id' => $fx('SELECT id FROM dispenses ORDER BY id DESC LIMIT 1')],
], static fn ($p) => !in_array(0, $p, true) && !in_array('0', $p, true));
$params += array_filter([
    'frontdesk/token-print' => ['id' => $fx('SELECT id FROM tokens ORDER BY id DESC LIMIT 1')],
    'inventory/card'        => ['item_id' => $fx("SELECT item_id FROM stock_balances ORDER BY item_id LIMIT 1")],
    'radiology/print'       => ['id' => $fx("SELECT radiology_order_id FROM radiology_reports WHERE status = 'final' LIMIT 1")],
    'roles/matrix'          => ['id' => $fx("SELECT id FROM roles WHERE code = 'CD'")],
], static fn ($p) => !in_array(0, $p, true));
$boardKey = (string) DB::value('SELECT access_key FROM display_boards WHERE is_active = 1 LIMIT 1');
if ($boardKey !== '') {
    $params['board'] = $params['board/data'] = ['key' => $boardKey];
}
$params['dental/state'] = ['patient_id' => $patientId];
$params['specialty'] = ['patient_id' => $patientId];

echo "\033[1mSmoke test\033[0m against $base\n";
$r = http('GET', $base . '/', sys_get_temp_dir() . '/mc_anon.txt');
check('index redirects guests to login', in_array($r['status'], [200, 302], true));
$r = http('GET', $base . '/index.php?r=patients', sys_get_temp_dir() . '/mc_anon.txt');
check('protected page redirects guests', $r['status'] === 302 && str_contains($r['headers'], 'r=login'));
$r = http('GET', $base . '/index.php?r=nope', sys_get_temp_dir() . '/mc_anon.txt');
check('unknown route is 404', $r['status'] === 404, 'status ' . $r['status']);

// Subscription: public page, bad key refused, real key (vendor tool) activates.
$anon = sys_get_temp_dir() . '/mc_anon.txt';
$lp = http('GET', $base . '/index.php?r=license', $anon);
check('licence page is public', $lp['status'] === 200 && str_contains($lp['body'], 'Subscription key'), 'status ' . $lp['status']);
$bad = http('POST', $base . '/index.php?r=license/activate', $anon, ['form' => ['_token' => csrf($lp['body']), 'phone' => '03001234567', 'key' => 'MCP1-FAKE-KEY']]);
check('forged key is refused', $bad['status'] === 200 && str_contains($bad['body'], 'not valid'), 'status ' . $bad['status']);
$privateKey = dirname(__DIR__) . '/tools/license-private.key';
if (is_file($privateKey)) {
    $key = \App\Core\License::issue(trim((string) file_get_contents($privateKey)), '03001234567', date('Y-m-d', strtotime('-1 day')), date('Y-m-d', strtotime('+365 days')));
    $lp = http('GET', $base . '/index.php?r=license', $anon);
    $ok = http('POST', $base . '/index.php?r=license/activate', $anon, ['form' => ['_token' => csrf($lp['body']), 'phone' => '0300-1234567', 'key' => $key]]);
    check('vendor key activates the subscription', $ok['status'] === 302 && str_contains($ok['headers'], 'r=login'), 'status ' . $ok['status']);
    \App\Core\Settings::flush();
    \App\Core\License::flush();
    check('subscription is active', \App\Core\License::status()['state'] === 'active', \App\Core\License::status()['message']);
}

foreach ($roles as $username => [$password, $role]) {
    echo "  role: $username\n";
    $jar = sys_get_temp_dir() . "/mc_$username.txt";
    $token = login($base, $username, $password, $jar);

    foreach (Router::routes() as $route => [$methods, $handler, $perm]) {
        if (!in_array('GET', explode('|', $methods), true) || $route === 'login' || $route === 'login/2fa') {
            continue;
        }
        if ($route === 'templates/edit' || $route === 'templates/load') {
            if ($username !== 'doctor') {
                continue;
            }
        }
        Auth::actingAs(DB::one('SELECT * FROM users WHERE username = ?', [$username]));
        $allowed = $perm === null || $perm === 'auth' || Router::allowed($perm);
        if ($route === 'masters/departments') {
            $allowed = Auth::can('departments.manage');
        }
        if ($route === 'users/view' && $role === 'dept_admin') {
            $allowed = true; // doctor shares a department with the dept admin
        }
        if ($route === 'masters/form') {
            $allowed = Auth::can('masters.manage');
        }
        // Record-level scope (v2): another doctor's visit goes to break-glass (302); others' orders are 403.
        $scopeRedirect = false;
        if ($allowed && in_array($route, ['visits/view', 'visits/print'], true) && !\App\Core\Scope::visitAllowed(DB::one('SELECT * FROM visits WHERE id = ?', [$visitId]))) {
            $scopeRedirect = true;
        }
        if ($allowed && in_array($route, ['lab/order', 'lab/report'], true) && !Auth::can('lab.view')) {
            $allowed = (int) DB::value('SELECT ordered_by FROM lab_orders WHERE id = ?', [$params[$route]['id'] ?? 0]) === Auth::id();
        }
        if ($allowed && in_array($route, ['radiology/order', 'radiology/print'], true) && !Auth::can('radiology.view')) {
            $allowed = (int) DB::value('SELECT ordered_by FROM radiology_orders WHERE id = ?', [$params[$route]['id'] ?? 0]) === Auth::id();
        }
        if ($allowed && $route === 'patients/edit') {
            $scopeRedirect = !\App\Modules\Patients\PatientService::canEdit($patientId);
        }
        $url = $base . '/index.php?' . http_build_query(['r' => $route] + ($params[$route] ?? []));
        $jsonRoutes = ['patients/duplicates', 'visits/lookup', 'visits/medicines', 'templates/load', 'visits/history', 'visits/refill', 'system/logs/preview', 'system/purge/preview'];
        $res = http('GET', $url, $jar, ['headers' => str_contains($route, 'search') && $route !== 'search' || in_array($route, $jsonRoutes, true) ? ['X-Requested-With: XMLHttpRequest'] : []]);
        $expected = $scopeRedirect ? 302 : ($allowed ? 200 : 403);
        $problem = clean($res['body']);
        if ($allowed && $res['status'] === 404 && !isset($params[$route])) {
            $skipped[$route] = true; // page needs an id we have no fixture for
            continue;
        }
        check("$username GET $route → $expected", $res['status'] === $expected && $problem === null, 'got ' . $res['status'] . ($problem ? " · $problem" : ''));

        if ($allowed && in_array($route, $exportable, true)) {
            $x = http('GET', $url . '&export=xlsx', $jar);
            check("$username export $route", $x['status'] === 200 && str_starts_with($x['body'], "PK\x03\x04") && str_contains($x['headers'], 'spreadsheetml'), 'status ' . $x['status']);
        }
    }

    // Print every paper size
    if (Auth::can('visits.print') && \App\Core\Scope::visitAllowed(DB::one('SELECT * FROM visits WHERE id = ?', [$visitId]))) {
        foreach (['a4', 'a5', 'thermal', 'legal'] as $paper) {
            $p = http('GET', $base . '/index.php?r=visits/print&id=' . $visitId . '&paper=' . $paper, $jar);
            check("$username print $paper", $p['status'] === 200 && str_contains($p['body'], 'class="rx-mark"') && str_contains($p['body'], 'phc-foot') && clean($p['body']) === null, clean($p['body']) ?? 'status ' . $p['status']);
        }
    }

    // Unauthorised POSTs are blocked
    if (!Auth::can('records.delete')) {
        $d = http('POST', $base . '/index.php?r=patients/delete', $jar, ['form' => ['_token' => $token, 'id' => $patientId]]);
        check("$username cannot delete patients", $d['status'] === 403, 'status ' . $d['status']);
    }
    if (!Auth::can('db.reset')) {
        $d = http('POST', $base . '/index.php?r=system/reset', $jar, ['form' => ['_token' => $token, 'mode' => 'clinical', 'confirm' => 'RESET', 'password' => $password]]);
        check("$username cannot reset database", $d['status'] === 403, 'status ' . $d['status']);
    }
    $csrf = http('POST', $base . '/index.php?r=profile', $jar, ['form' => ['_token' => 'bad', 'name' => 'x']]);
    check("$username POST without valid CSRF token is rejected", $csrf['status'] === 403 && str_contains($csrf['body'], 'form token has expired'), 'status ' . $csrf['status']);
}

// ------------------------------------------------- super admin key generator
echo "  flows: subscription key generation\n";
$jarSa = sys_get_temp_dir() . '/mc_superadmin.txt';
$tokenSa = login($base, 'superadmin', 'admin123', $jarSa);
$page = http('GET', $base . '/index.php?r=license', $jarSa);
check('subscription page shows logs', $page['status'] === 200 && str_contains($page['body'], 'Activation history'), 'status ' . $page['status']);
if (is_file(dirname(__DIR__) . '/tools/license-private.key')) {
    $gen = http('POST', $base . '/index.php?r=license/generate', $jarSa, ['form' => ['_token' => $tokenSa, 'phone' => '03334445556', 'clinic_name' => 'Smoke Clinic',
        'from' => today(), 'to' => date('Y-m-d', strtotime('+1 year')), 'amount' => '12000', 'payment_ref' => 'SMOKE']]);
    check('super admin generates a key', $gen['status'] === 302, 'status ' . $gen['status']);
    $after = http('GET', $base . '/index.php?r=license', $jarSa);
    check('new key is shown and logged', str_contains($after['body'], 'id="new-key"') && str_contains($after['body'], 'Smoke Clinic'), 'missing');
    $x = http('GET', $base . '/index.php?r=license/export&type=issued', $jarSa);
    check('issued keys export', str_starts_with($x['body'], "PK\x03\x04"), 'status ' . $x['status']);
}
$jarDoc = sys_get_temp_dir() . '/mc_doctor.txt';
login($base, 'doctor', 'doctor123', $jarDoc);
$gen = http('POST', $base . '/index.php?r=license/generate', $jarDoc, ['form' => ['_token' => csrf(http('GET', $base . '/index.php?r=dashboard', $jarDoc)['body']), 'phone' => '03001112223']]);
check('doctor cannot generate keys', $gen['status'] === 403, 'status ' . $gen['status']);

// ------------------------------------------------------------ doctor flows
echo "  flows: doctor checkout, template, print tracking\n";
$jar = sys_get_temp_dir() . '/mc_doctor.txt';
$token = login($base, 'doctor', 'doctor123', $jar);
$new = http('GET', $base . '/index.php?r=visits/new&patient_id=' . $patientId, $jar);
preg_match('/"token":"([a-f0-9]{32})"/', $new['body'], $m);
check('consultation page has a checkout token', isset($m[1]));
$ajax = ['X-Requested-With: XMLHttpRequest', 'X-CSRF-Token: ' . $token, 'Accept: application/json'];
$med = json_decode(http('GET', $base . '/index.php?r=visits/medicines&q=Panadol', $jar, ['headers' => $ajax])['body'], true);
check('medicine search returns Panadol', ($med['items'][0]['name'] ?? '') === 'Panadol');
$line = $med['items'][0];
$body = [
    'token' => $m[1] ?? '', 'patient' => DB::one('SELECT id, name, gender, dob, dob_estimated, cnic, phone, city, address FROM patients WHERE id = ?', [$patientId]),
    'vitals' => ['bp_systolic' => '130', 'bp_diastolic' => '85', 'temperature' => '99.1'],
    'complaints' => [['id' => null, 'name' => 'Smoke test complaint']], 'symptoms' => [], 'diagnoses' => [], 'investigations' => [],
    'medicines' => [['medicine_id' => $line['medicine_id'], 'dose' => 1, 'frequency_id' => $line['frequency_id'], 'route_id' => $line['route_id'],
        'duration_days' => 5, 'quantity' => 15, 'qty_manual' => 0, 'unit_price' => $line['unit_price'], 'instructions' => 'After meal']],
    'follow_up_date' => date('Y-m-d', strtotime('+3 days')), 'consultation_fee' => '1500', 'payment_status' => 'Paid', 'discount_type' => 'amount', 'discount_value' => '0',
];
$co = http('POST', $base . '/index.php?r=visits/checkout', $jar, ['headers' => $ajax, 'json' => $body]);
$res = json_decode($co['body'], true);
check('checkout via HTTP succeeds', $co['status'] === 200 && ($res['ok'] ?? false), $co['body']);
$again = json_decode(http('POST', $base . '/index.php?r=visits/checkout', $jar, ['headers' => $ajax, 'json' => $body])['body'], true);
check('double submit returns the same visit', ($again['duplicate'] ?? false) && ($again['visit_id'] ?? 0) === ($res['visit_id'] ?? -1));
$pr = http('POST', $base . '/index.php?r=visits/printed', $jar, ['headers' => $ajax, 'json' => ['id' => $res['visit_id'] ?? 0, 'paper' => 'a5']]);
check('print is recorded', $pr['status'] === 200 && (int) DB::value('SELECT print_count FROM visits WHERE id = ?', [$res['visit_id'] ?? 0]) === 1);
$bad = http('POST', $base . '/index.php?r=visits/checkout', $jar, ['headers' => $ajax, 'json' => ['token' => bin2hex(random_bytes(16)), 'patient' => ['name' => '']]]);
check('invalid checkout returns 422 with messages', $bad['status'] === 422 && str_contains($bad['body'], 'Patient name is required'), $bad['body']);
$tpl = http('POST', $base . '/index.php?r=templates/save', $jar, ['headers' => $ajax, 'json' => ['name' => 'Smoke ' . time(), 'complaints' => [['name' => 'Fever']], 'medicines' => $body['medicines']]]);
check('save template via HTTP', $tpl['status'] === 200 && str_contains($tpl['body'], '"ok":true'), $tpl['body']);
foreach (['repeat' => $res['visit_id'] ?? 0, 'template_id' => $templateId] as $k => $v) {
    $page = http('GET', $base . '/index.php?r=visits/new&' . $k . '=' . $v, $jar);
    check("new visit with $k", $page['status'] === 200 && clean($page['body']) === null, clean($page['body']) ?? '');
}
$other = login($base, 'doctor2', 'doctor123', sys_get_temp_dir() . '/mc_doctor2.txt');
$leak = http('GET', $base . '/index.php?r=templates/load&id=' . $templateId, sys_get_temp_dir() . '/mc_doctor2.txt', ['headers' => $ajax]);
check("another doctor cannot load the template", $leak['status'] === 404, 'status ' . $leak['status']);

@unlink(Backup::dir() . '/' . $backupFile);
echo "\n" . ($failed ? "\033[31m" : "\033[32m") . "$passed checks passed, " . count($failed) . " failed\033[0m\n";
foreach ($failed as $f) {
    echo "  - $f\n";
}
if ($skipped) {
    echo "Pages without an id fixture (404, not counted): " . implode(', ', array_keys($skipped)) . "\n";
}
exit($failed ? 1 : 0);
