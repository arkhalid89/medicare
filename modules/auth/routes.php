<?php
use App\Modules\Auth\AuthController;

return [
    'login'            => ['GET|POST', [AuthController::class, 'login'], null],
    'login/2fa'        => ['GET|POST', [AuthController::class, 'twoFactor'], null],
    'logout'           => ['POST', [AuthController::class, 'logout'], 'auth'],
    'profile'          => ['GET|POST', [AuthController::class, 'profile'], 'auth'],
    'profile/password' => ['GET|POST', [AuthController::class, 'password'], 'auth'],
    'profile/2fa'      => ['GET|POST', [AuthController::class, 'setupTwoFactor'], 'auth'],
];
