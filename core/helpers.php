<?php
/**
 * Global helper functions used by controllers and views.
 */
declare(strict_types=1);

use App\Core\Auth;
use App\Core\Config;
use App\Core\Csrf;
use App\Core\HttpException;
use App\Core\Icons;
use App\Core\Session;
use App\Core\Settings;

// ---------------------------------------------------------------------------
//  Configuration & URLs
// ---------------------------------------------------------------------------

function config(string $key, mixed $default = null): mixed
{
    return Config::get($key, $default);
}

function is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
}

/**
 * The public base URL, from config('app.base_url').
 * If the configured host differs from the host the browser actually used
 * (e.g. 127.0.0.1 or a LAN IP instead of localhost) the path is kept but the
 * browser's host is used, so the login cookie always matches.
 */
function base_url(): string
{
    static $base = null;
    if ($base !== null) {
        return $base;
    }
    $configured = rtrim((string) config('app.base_url', ''), '/');
    $host = $_SERVER['HTTP_HOST'] ?? '';

    if ($configured === '') {
        if ($host === '') {
            return $base = '';
        }
        $dir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
        return $base = (is_https() ? 'https' : 'http') . '://' . $host . rtrim($dir, '/');
    }

    if ($host !== '') {
        $parts = parse_url($configured);
        $configuredHost = ($parts['host'] ?? '') . (isset($parts['port']) ? ':' . $parts['port'] : '');
        if (strcasecmp($configuredHost, $host) !== 0) {
            return $base = (is_https() ? 'https' : 'http') . '://' . $host . rtrim($parts['path'] ?? '', '/');
        }
    }
    return $base = $configured;
}

function url(string $route = '', array $params = []): string
{
    $params = array_filter($params, static fn ($v) => $v !== null && $v !== '' && $v !== []);
    $route = trim($route, '/');
    if (config('app.pretty_urls')) {
        $u = base_url() . '/' . $route;
        return $params ? $u . '?' . http_build_query($params) : $u;
    }
    $query = $route !== '' ? array_merge(['r' => $route], $params) : $params;
    return base_url() . '/index.php' . ($query ? '?' . http_build_query($query) : '');
}

/** URL of the current page with some query parameters replaced. */
function url_with(array $replace): string
{
    $query = array_merge($_GET, $replace);
    $route = (string) ($query['r'] ?? '');
    unset($query['r']);
    return url($route, $query);
}

function asset(string $path): string
{
    $path = ltrim($path, '/');
    $file = PUBLIC_PATH . '/assets/' . $path;
    $version = is_file($file) ? (string) filemtime($file) : '1';
    return base_url() . '/assets/' . $path . '?v=' . $version;
}

function upload_url(?string $path): string
{
    return $path ? base_url() . '/uploads/' . ltrim($path, '/') : '';
}

// ---------------------------------------------------------------------------
//  Request / response
// ---------------------------------------------------------------------------

function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

function is_ajax(): bool
{
    return PHP_SAPI !== 'cli' && (
        strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest'
        || str_contains(strtolower($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')
    );
}

/** Trimmed request value (POST first, then GET). */
function input(string $key, mixed $default = null): mixed
{
    $value = $_POST[$key] ?? $_GET[$key] ?? $default;
    return is_string($value) ? trim($value) : $value;
}

function input_int(string $key): ?int
{
    $v = input($key);
    return is_numeric($v) ? (int) $v : null;
}

/** Previously submitted value when a form is re-displayed after an error. */
function old(string $key, mixed $default = ''): mixed
{
    if (is_post() && array_key_exists($key, $_POST)) {
        return $_POST[$key];
    }
    return $default;
}

/** Decoded JSON request body (AJAX checkout / template save). */
function json_body(): array
{
    $raw = file_get_contents('php://input') ?: '';
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function redirect(string $route, array $params = []): void
{
    redirect_to(url($route, $params));
}

function redirect_to(string $url): void
{
    header('Location: ' . $url);
    exit;
}

function back(string $fallback = 'dashboard'): void
{
    $ref = $_SERVER['HTTP_REFERER'] ?? '';
    if ($ref !== '' && str_starts_with($ref, base_url())) {
        redirect_to($ref);
    }
    redirect($fallback);
}

function json_response(array $data, int $status = 200): void
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function abort(int $code, string $message = ''): void
{
    throw new HttpException($code, $message);
}

function flash(string $type, string $message): void
{
    Session::flash($type, $message);
}

function csrf_token(): string
{
    return Csrf::token();
}

function csrf_field(): string
{
    return '<input type="hidden" name="_token" value="' . e(Csrf::token()) . '">';
}

/**
 * Hidden route field for GET filter forms. Browsers drop the query string of
 * a GET form's action, so without pretty URLs the route travels as a field.
 */
function route_field(string $route): string
{
    return config('app.pretty_urls') ? '' : '<input type="hidden" name="r" value="' . e($route) . '">';
}

/** Action attribute for GET filter forms (pairs with route_field()). */
function form_action(string $route): string
{
    return config('app.pretty_urls') ? url($route) : base_url() . '/index.php';
}

// ---------------------------------------------------------------------------
//  Auth & settings
// ---------------------------------------------------------------------------

function auth(): ?array
{
    return Auth::user();
}

function can(string $permission): bool
{
    return Auth::can($permission);
}

function setting(string $key, mixed $default = null): mixed
{
    return Settings::get($key, $default);
}

/** "MediCare Clinic" => "MC" (logo placeholder). */
function org_initials(): string
{
    $words = preg_split('/\s+/', trim(preg_replace('/[^A-Za-z\s]/', '', (string) setting('org_name', 'MediCare Clinic')))) ?: [];
    $initials = '';
    foreach (array_slice(array_filter($words), 0, 2) as $w) {
        $initials .= strtoupper($w[0]);
    }
    return $initials ?: 'MC';
}

function role_label(?string $role): string
{
    return (string) config('permissions.roles.' . $role . '.label', ucfirst((string) $role));
}

// ---------------------------------------------------------------------------
//  Output & formatting
// ---------------------------------------------------------------------------

function e(mixed $value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function icon(string $name, string $class = ''): string
{
    return Icons::svg($name, $class);
}

function now(): string
{
    return date('Y-m-d H:i:s');
}

function today(): string
{
    return date('Y-m-d');
}

/** 1500 => "Rs. 1,500", 12.5 => "Rs. 12.50" */
function money(mixed $amount, bool $withSymbol = true): string
{
    $n = round((float) $amount, 2);
    $decimals = abs($n - round($n)) > 0.001 ? 2 : 0;
    $text = number_format($n, $decimals);
    return $withSymbol ? setting('currency_symbol', 'Rs.') . ' ' . $text : $text;
}

/** Number without trailing zeros: 2.50 => "2.5", 10.00 => "10" */
function num(mixed $n): string
{
    if ($n === null || $n === '') {
        return '';
    }
    $s = number_format((float) $n, 2, '.', '');
    return str_contains($s, '.') ? rtrim(rtrim($s, '0'), '.') : $s;
}

function fmt_date(?string $date): string
{
    if (!$date || str_starts_with($date, '0000')) {
        return '';
    }
    $ts = strtotime($date);
    return $ts ? date((string) setting('date_format', 'd-m-Y'), $ts) : '';
}

function fmt_datetime(?string $date): string
{
    if (!$date) {
        return '';
    }
    $ts = strtotime($date);
    return $ts ? date((string) setting('date_format', 'd-m-Y') . ' h:i A', $ts) : '';
}

/** Age text from date of birth: "34 Y", "7 M", "12 D". */
function age_text(?string $dob, ?string $at = null): string
{
    if (!$dob) {
        return '';
    }
    try {
        $diff = (new DateTime($dob))->diff(new DateTime($at ?? 'today'));
    } catch (\Exception $e) {
        return '';
    }
    if ($diff->invert) {
        return '';
    }
    if ($diff->y > 0) {
        return $diff->y . ' Y';
    }
    if ($diff->m > 0) {
        return $diff->m . ' M';
    }
    return $diff->d . ' D';
}

function age_years(?string $dob): ?int
{
    if (!$dob) {
        return null;
    }
    try {
        return (new DateTime($dob))->diff(new DateTime('today'))->y;
    } catch (\Exception $e) {
        return null;
    }
}

/** "35202-1234567-1" from any 13-digit input, or null if invalid. */
function normalize_cnic(?string $cnic): ?string
{
    $digits = preg_replace('/\D/', '', (string) $cnic);
    if (strlen($digits) !== 13) {
        return null;
    }
    return substr($digits, 0, 5) . '-' . substr($digits, 5, 7) . '-' . substr($digits, 12, 1);
}

/** Dashed CNIC prefix for LIKE searches from a partial digit string. */
function cnic_prefix(string $digits): string
{
    $digits = substr(preg_replace('/\D/', '', $digits), 0, 13);
    $out = substr($digits, 0, 5);
    if (strlen($digits) > 5) {
        $out .= '-' . substr($digits, 5, 7);
    }
    if (strlen($digits) > 12) {
        $out .= '-' . substr($digits, 12, 1);
    }
    return $out;
}

/** Digits only, 10–13 long ("0300-1234567" => "03001234567"), or null. */
function normalize_phone(?string $phone): ?string
{
    $digits = preg_replace('/\D/', '', (string) $phone);
    if (strlen($digits) < 10 || strlen($digits) > 13) {
        return null;
    }
    return $digits;
}

function selected(mixed $a, mixed $b): string
{
    return (string) $a === (string) $b ? ' selected' : '';
}

function checked(mixed $condition): string
{
    return $condition ? ' checked' : '';
}

/** Escaped value for use inside a LIKE pattern. */
function like_escape(string $value): string
{
    return addcslashes($value, '%_\\');
}

function status_badge(mixed $active): string
{
    return (int) $active === 1
        ? '<span class="badge badge-success">Active</span>'
        : '<span class="badge badge-muted">Inactive</span>';
}

function payment_badge(string $status): string
{
    $class = ['Paid' => 'badge-success', 'Pending' => 'badge-warning', 'Free' => 'badge-info'][$status] ?? 'badge-muted';
    return '<span class="badge ' . $class . '">' . e($status) . '</span>';
}
