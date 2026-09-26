<?php
declare(strict_types=1);

namespace App\Core;

/** Technical log file: storage/logs/app-YYYY-MM-DD.log (one file per day). */
final class Logger
{
    public static function error(string $message): void
    {
        self::write('ERROR', $message);
    }

    public static function warning(string $message): void
    {
        self::write('WARNING', $message);
    }

    public static function info(string $message): void
    {
        self::write('INFO', $message);
    }

    private static function write(string $level, string $message): void
    {
        $dir = STORAGE_PATH . '/logs';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $user = '';
        if (session_status() === PHP_SESSION_ACTIVE && isset($_SESSION['user_id'])) {
            $user = ' user#' . $_SESSION['user_id'];
        }
        $uri = PHP_SAPI === 'cli' ? 'cli' : ($_SERVER['REQUEST_METHOD'] ?? '') . ' ' . ($_SERVER['REQUEST_URI'] ?? '');
        $line = sprintf("[%s] %s%s (%s) %s\n", date('Y-m-d H:i:s'), $level, $user, $uri, $message);
        @file_put_contents($dir . '/app-' . date('Y-m-d') . '.log', $line, FILE_APPEND | LOCK_EX);
    }
}
