<?php
use App\Modules\Admin\AdminController;

return [
    'system/audit'      => ['GET', [AdminController::class, 'audit'], 'audit.view'],
    'settings/hospital' => ['GET|POST', [AdminController::class, 'hospital'], 'settings.manage'],
];
