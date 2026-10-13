<?php
use App\Modules\Reports\OpsReportController;
use App\Modules\Reports\ReportController;

return [
    'reports/visits'  => ['GET', [ReportController::class, 'visits'], 'reports.view'],
    'reports/fees'    => ['GET', [ReportController::class, 'fees'], 'reports.view'],
    'reports/revenue' => ['GET', [OpsReportController::class, 'revenue'], 'reports.view'],
    'reports/tokens'  => ['GET', [OpsReportController::class, 'tokens'], 'reports.view'],
    'reports/stock'   => ['GET', [OpsReportController::class, 'stock'], 'reports.view|inventory.view'],
];
