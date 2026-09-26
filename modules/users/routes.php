<?php
use App\Modules\Users\UserController;

return [
    'users'          => ['GET', [UserController::class, 'index'], 'users.view'],
    'users/view'     => ['GET', [UserController::class, 'show'], 'users.view'],
    'users/form'     => ['GET|POST', [UserController::class, 'form'], 'users.manage'],
    'users/toggle'   => ['POST', [UserController::class, 'toggle'], 'users.manage'],
    'users/password' => ['POST', [UserController::class, 'resetPassword'], 'users.manage'],
    'users/delete'   => ['POST', [UserController::class, 'delete'], 'records.delete'],
];
