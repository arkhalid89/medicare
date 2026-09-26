<?php
use App\Modules\Reports\ReportController;

return [
    'reports/visits' => ['GET', [ReportController::class, 'visits'], 'reports.view'],
    'reports/fees'   => ['GET', [ReportController::class, 'fees'], 'reports.view'],
];
