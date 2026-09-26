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
    check("login $username redirects", $r['status'] === 302 && str_contains($r['headers'], 'r=dashboard'), 'status ' . $r['status']);
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
];
$exportable = ['patients', 'visits', 'users', 'templates', 'reports/visits', 'reports/fees', 'system/logs', 'system/recycle', 'masters/mapping'];
foreach (array_keys(config('masters')) as $k) {
    $exportable[] = 'masters/' . $k;
}

$roles = [
    'superadmin' => ['admin123', 'super_admin'],
    'deptadmin'  => ['admin123', 'dept_admin'],
    'doctor'     => ['doctor123', 'doctor'],
    'reporting'  => ['report123', 'reporting'],
];

echo "\033[1mSmoke test\033[0m against $base\n";
$r = http('GET', $base . '/', sys_get_temp_dir() . '/mc_anon.txt');
check('index redirects guests to login', in_array($r['status'], [200, 302], true));
$r = http('GET', $base . '/index.php?r=patients', sys_get_temp_dir() . '/mc_anon.txt');
check('protected page redirects guests', $r['status'] === 302 && str_contains($r['headers'], 'r=login'));
$r = http('GET', $base . '/index.php?r=nope', sys_get_temp_dir() . '/mc_anon.txt');
check('unknown route is 404', $r['status'] === 404, 'status ' . $r['status']);

foreach ($roles as $username => [$password, $role]) {
    echo "  role: $username\n";
    $jar = sys_get_temp_dir() . "/mc_$username.txt";
    $token = login($base, $username, $password, $jar);

    foreach (Router::routes() as $route => [$methods, $handler, $perm]) {
        if (!in_array('GET', explode('|', $methods), true) || $route === 'login') {
            continue;
        }
        if ($route === 'templates/edit' || $route === 'templates/load') {
            if ($username !== 'doctor') {
                continue;
            }
        }
        $allowed = $perm === null || $perm === 'auth' || Auth::roleCan($role, $perm);
        if ($route === 'masters/departments') {
            $allowed = Auth::roleCan($role, 'departments.manage');
        }
        if ($route === 'users/view' && $role === 'dept_admin') {
            $allowed = true; // doctor shares a department with the dept admin
        }
        if ($route === 'masters/form') {
            $allowed = Auth::roleCan($role, 'masters.manage');
        }
        $url = $base . '/index.php?' . http_build_query(['r' => $route] + ($params[$route] ?? []));
        $res = http('GET', $url, $jar, ['headers' => str_contains($route, 'search') && $route !== 'search' || in_array($route, ['patients/duplicates', 'visits/lookup', 'visits/medicines', 'templates/load'], true) ? ['X-Requested-With: XMLHttpRequest'] : []]);
        $expected = $allowed ? 200 : 403;
        $problem = clean($res['body']);
        check("$username GET $route → $expected", $res['status'] === $expected && $problem === null, 'got ' . $res['status'] . ($problem ? " · $problem" : ''));

        if ($allowed && in_array($route, $exportable, true)) {
            $x = http('GET', $url . '&export=xlsx', $jar);
            check("$username export $route", $x['status'] === 200 && str_starts_with($x['body'], "PK\x03\x04") && str_contains($x['headers'], 'spreadsheetml'), 'status ' . $x['status']);
        }
    }

    // Print every paper size
    if (Auth::roleCan($role, 'visits.print')) {
        foreach (['a4', 'a5', 'thermal', 'legal'] as $paper) {
            $p = http('GET', $base . '/index.php?r=visits/print&id=' . $visitId . '&paper=' . $paper, $jar);
            check("$username print $paper", $p['status'] === 200 && str_contains($p['body'], '℞') && clean($p['body']) === null, clean($p['body']) ?? 'status ' . $p['status']);
        }
    }

    // Unauthorised POSTs are blocked
    if (!Auth::roleCan($role, 'records.delete')) {
        $d = http('POST', $base . '/index.php?r=patients/delete', $jar, ['form' => ['_token' => $token, 'id' => $patientId]]);
        check("$username cannot delete patients", $d['status'] === 403, 'status ' . $d['status']);
    }
    if (!Auth::roleCan($role, 'db.reset')) {
        $d = http('POST', $base . '/index.php?r=system/reset', $jar, ['form' => ['_token' => $token, 'mode' => 'clinical', 'confirm' => 'RESET', 'password' => $password]]);
        check("$username cannot reset database", $d['status'] === 403, 'status ' . $d['status']);
    }
    $csrf = http('POST', $base . '/index.php?r=profile', $jar, ['form' => ['_token' => 'bad', 'name' => 'x']]);
    check("$username POST without valid CSRF token is rejected", $csrf['status'] === 403 && str_contains($csrf['body'], 'form token has expired'), 'status ' . $csrf['status']);
}

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
exit($failed ? 1 : 0);
