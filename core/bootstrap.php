<?php
/**
 * Application bootstrap — loaded by public/index.php, the installer, the CLI
 * scripts and the test runner. It never starts output and never starts the
 * session (the web front controller does that).
 */
declare(strict_types=1);

if (PHP_VERSION_ID < 80000) {
    http_response_code(500);
    exit('MediCare Practice requires PHP 8.0 or newer. You are running PHP ' . PHP_VERSION . '.');
}

define('BASE_PATH', dirname(__DIR__));
define('STORAGE_PATH', BASE_PATH . '/storage');
define('PUBLIC_PATH', BASE_PATH . '/public');

/*
 * Autoloader
 *   App\Core\X                 => core/X.php
 *   App\Modules\Patients\X     => modules/patients/X.php
 */
spl_autoload_register(static function (string $class): void {
    if (strncmp($class, 'App\\', 4) !== 0) {
        return;
    }
    $parts = explode('\\', substr($class, 4));
    if ($parts[0] === 'Core') {
        $file = BASE_PATH . '/core/' . implode('/', array_slice($parts, 1)) . '.php';
    } elseif ($parts[0] === 'Modules' && count($parts) >= 3) {
        $file = BASE_PATH . '/modules/' . strtolower($parts[1]) . '/' . implode('/', array_slice($parts, 2)) . '.php';
    } else {
        return;
    }
    if (is_file($file)) {
        require $file;
    }
});

require BASE_PATH . '/core/helpers.php';

App\Core\Config::load(BASE_PATH . '/config');
date_default_timezone_set((string) config('app.timezone', 'Asia/Karachi'));
App\Core\ErrorHandler::register();

foreach (['logs', 'backups', 'exports'] as $dir) {
    if (!is_dir(STORAGE_PATH . '/' . $dir)) {
        @mkdir(STORAGE_PATH . '/' . $dir, 0775, true);
    }
}
