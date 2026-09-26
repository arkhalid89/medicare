<?php
/**
 * Command-line installer.
 *
 *   php database/install.php            first install (default data only)
 *   php database/install.php --demo     also load demo patients and visits
 *   php database/install.php --force    reinstall over an existing install (ERASES DATA)
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit('Run this script from the command line.');
}
require dirname(__DIR__) . '/core/bootstrap.php';

use App\Core\Installer;

$args = array_slice($argv, 1);
$force = in_array('--force', $args, true);
$demo = in_array('--demo', $args, true);

if (Installer::isInstalled() && !$force) {
    fwrite(STDERR, "Already installed (storage/installed.lock exists). Use --force to reinstall — this erases all data.\n");
    exit(1);
}

echo 'Installing ' . config('app.name') . ' into database "' . config('db.name') . '"' . ($demo ? ' with demo data' : '') . "...\n";
$start = microtime(true);
Installer::install(['demo' => $demo]);
printf("Done in %.1f s.\n\nLogins: superadmin/admin123 · deptadmin/admin123 · doctor/doctor123 · doctor2/doctor123 · reporting/report123\n", microtime(true) - $start);
