<?php
/**
 * Database reset script for the Super Admin (command-line version of
 * Administration → Database Reset). A full safety backup is always taken first.
 *
 *   php database/reset.php --mode=clinical --user=superadmin --yes
 *       Removes patients, visits, prescriptions, templates and activity logs.
 *       Keeps users, master data and settings.
 *
 *   php database/reset.php --mode=factory --user=superadmin --yes
 *       Rebuilds all tables, reloads default master data and keeps only the
 *       given Super Admin account (same username and password).
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit('Run this script from the command line.');
}
require dirname(__DIR__) . '/core/bootstrap.php';

use App\Core\DB;
use App\Core\Installer;

$opts = getopt('', ['mode:', 'user:', 'yes']);
$mode = $opts['mode'] ?? '';
$username = $opts['user'] ?? '';

if (!in_array($mode, ['clinical', 'factory'], true) || $username === '') {
    fwrite(STDERR, "Usage: php database/reset.php --mode=clinical|factory --user=<super admin username> --yes\n");
    exit(1);
}
$admin = DB::one("SELECT * FROM users WHERE username = ? AND role = 'super_admin' AND deleted_at IS NULL AND is_active = 1", [$username]);
if (!$admin) {
    fwrite(STDERR, "No active Super Admin with username \"$username\".\n");
    exit(1);
}
if (!isset($opts['yes'])) {
    fwrite(STDERR, "This erases data. Re-run with --yes to confirm.\n");
    exit(1);
}

$backup = Installer::reset($mode, $admin);
echo "Reset ($mode) complete. Safety backup: storage/backups/$backup\n";
