<?php
declare(strict_types=1);

namespace App\Modules\Appointments;

use App\Core\DB;

final class AppointmentJobs
{
    /** Booked appointments of past days that were never checked in become no-shows; waiting tokens of past days are closed. */
    public static function markNoShows(): void
    {
        DB::run("UPDATE appointments SET status = 'no_show' WHERE status = 'booked' AND scheduled_at < ?", [today() . ' 00:00:00']);
        DB::run("UPDATE tokens SET status = 'no_show' WHERE status IN ('waiting','called') AND issue_date < ?", [today()]);
    }
}
