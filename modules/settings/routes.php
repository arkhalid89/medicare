<?php
use App\Modules\Settings\SettingsController;

return [
    'settings/general' => ['GET|POST', [SettingsController::class, 'general'], 'settings.manage'],
    'settings/codes'   => ['GET|POST', [SettingsController::class, 'codes'], 'settings.manage'],
    'settings/rx'      => ['GET|POST', [SettingsController::class, 'rx'], 'settings.manage'],
    'settings/theme'   => ['GET|POST', [SettingsController::class, 'theme'], 'settings.manage'],
    'settings/print'   => ['GET|POST', [SettingsController::class, 'printDesign'], 'print_designs.manage'],
];
