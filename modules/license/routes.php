<?php
use App\Modules\License\LicenseController;

return [
    // Public on purpose: an expired subscription must be renewable without logging in.
    'license'          => ['GET', [LicenseController::class, 'index'], null],
    'license/activate' => ['POST', [LicenseController::class, 'activate'], null],
    // Super Admin: generate keys (vendor installation only) and the logs.
    'license/generate' => ['POST', [LicenseController::class, 'generate'], 'settings.manage'],
    'license/export'   => ['GET', [LicenseController::class, 'exportLog'], 'settings.manage'],
];
