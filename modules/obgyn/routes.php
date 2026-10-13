<?php
use App\Modules\Obgyn\ObgynController;

return [
    'obgyn'             => ['GET', [ObgynController::class, 'index'], 'obgyn.history.view'],
    'obgyn/summary'     => ['GET', [ObgynController::class, 'summary'], 'obgyn.history.view'],
    'obgyn/anc'         => ['GET', [ObgynController::class, 'anc'], 'obgyn.anc.record|obgyn.delivery.record'],
    'obgyn/pregnancies' => ['GET', [ObgynController::class, 'pregnancies'], 'obgyn.history.view'],
    'obgyn/action'      => ['POST', [ObgynController::class, 'action'], 'obgyn.history.view|obgyn.anc.record|obgyn.delivery.record|obgyn.us.biometry'],
];
