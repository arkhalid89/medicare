<?php
use App\Modules\Roles\RolesController;

return [
    'roles'        => ['GET', [RolesController::class, 'index'], 'roles.manage'],
    'roles/edit'   => ['GET|POST', [RolesController::class, 'edit'], 'roles.manage'],
    'roles/matrix' => ['GET|POST', [RolesController::class, 'matrix'], 'roles.manage'],
    'roles/reset'  => ['POST', [RolesController::class, 'reset'], 'roles.manage'],
    'roles/delete' => ['POST', [RolesController::class, 'delete'], 'roles.manage'],
];
