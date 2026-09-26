<?php
/**
 * ============================================================================
 *  MediCare Practice — CENTRAL CONFIGURATION
 * ============================================================================
 *  This is the ONLY file you need to edit when installing the application.
 *  Every link, asset, upload and redirect in the system is built from the
 *  values below, so nothing else in the code is hard-coded.
 *
 *  BASE URL
 *    The public URL of the "public" folder, WITHOUT a trailing slash.
 *      XAMPP example : http://localhost/medicare-practice/public
 *      Virtual host  : http://medicare.local
 *    Leave it empty ('') to let the application detect it automatically.
 *
 *  Values can also be supplied through environment variables (used by the
 *  Docker test stack); an environment variable always wins over this file.
 * ============================================================================
 */

$env = static function (string $key, $default) {
    $value = getenv($key);
    return ($value === false || $value === '') ? $default : $value;
};

return [

    'app' => [
        'name'            => $env('APP_NAME', 'MediCare Practice'),
        'short_name'      => 'MediCare',
        'version'         => '1.0.0',
        'base_url'        => $env('APP_BASE_URL', 'http://localhost/medicare-practice/public'),
        // true  => http://host/base/patients/edit?id=5   (needs mod_rewrite, .htaccess is supplied)
        // false => http://host/base/index.php?r=patients/edit&id=5   (works everywhere)
        'pretty_urls'     => false,
        'timezone'        => 'Asia/Karachi',
        // true shows technical error details on screen. Set false on the clinic PC.
        'debug'           => (bool) $env('APP_DEBUG', true),
        'session_name'    => 'MEDICARE_SID',
        'session_timeout' => 120,          // minutes of inactivity before auto logout
    ],

    'db' => [
        'host'     => $env('DB_HOST', '127.0.0.1'),
        'port'     => (int) $env('DB_PORT', 3306),
        'name'     => $env('DB_NAME', 'medicare_practice'),
        'user'     => $env('DB_USER', 'root'),
        'pass'     => $env('DB_PASS', ''),
        'charset'  => 'utf8mb4',
    ],

    'security' => [
        // 'plain'  => passwords are stored as typed (current requirement).
        // 'bcrypt' => passwords are hashed. Switching is safe: plain-text
        //             passwords keep working and are upgraded at next login.
        'password_mode'       => 'plain',
        'min_password_length' => 6,
    ],

    'uploads' => [
        'max_image_kb' => 2048,
        'max_restore_mb' => 512,
    ],
];
