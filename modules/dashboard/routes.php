<?php
use App\Modules\Dashboard\DashboardController;

return [
    'dashboard' => ['GET', [DashboardController::class, 'index'], 'dashboard.view'],
];
