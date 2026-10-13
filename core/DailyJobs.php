<?php
declare(strict_types=1);

namespace App\Core;

/**
 * "Nightly" jobs without a cron (the clinic server is a Windows PC): they run
 * once per day on the first request, the same way as the scheduled backup.
 * Every job is skipped when its module is not installed, and a failing job is
 * logged without breaking the page.
 */
final class DailyJobs
{
    public const JOBS = [
        'App\\Modules\\Inventory\\StockService::dailyExpiryWatch',
        'App\\Modules\\Inventory\\StockService::reconcile',
        'App\\Modules\\Appointments\\AppointmentJobs::markNoShows',
    ];

    public static function runIfDue(): void
    {
        if ((string) Settings::get('last_daily_jobs', '') === today()) {
            return;
        }
        // Claim the day first so parallel requests do not run the jobs twice.
        Settings::set(['last_daily_jobs' => today()]);
        foreach (self::JOBS as $job) {
            [$class, $method] = explode('::', $job);
            if (!class_exists($class) || !method_exists($class, $method)) {
                continue;
            }
            try {
                $class::$method();
            } catch (\Throwable $e) {
                Logger::error('Daily job ' . $job . ' failed: ' . $e->getMessage());
            }
        }
    }
}
