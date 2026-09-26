<?php
/**
 * MediCare Practice — front controller. Every page request enters here.
 */
declare(strict_types=1);

require dirname(__DIR__) . '/core/bootstrap.php';

use App\Core\Installer;
use App\Core\Router;
use App\Core\Session;

if (!Installer::isInstalled()) {
    header('Location: ' . base_url() . '/install.php');
    exit;
}

header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');

Session::start();
Router::dispatch();
