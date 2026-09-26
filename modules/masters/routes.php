<?php
use App\Modules\Masters\MasterController;

$routes = [
    'masters/form'    => ['GET|POST', [MasterController::class, 'form'], 'auth'],
    'masters/toggle'  => ['POST', [MasterController::class, 'toggle'], 'auth'],
    'masters/delete'  => ['POST', [MasterController::class, 'delete'], 'records.delete'],
    'masters/mapping' => ['GET|POST', [MasterController::class, 'mapping'], 'masters.view'],
];

// One listing route per master defined in config/masters.php
foreach (config('masters') as $key => $def) {
    $routes['masters/' . $key] = ['GET', [MasterController::class, 'index'], $def['perm_view'] ?? 'masters.view'];
}

return $routes;
