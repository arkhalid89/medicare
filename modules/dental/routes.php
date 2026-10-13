<?php
use App\Modules\Dental\DentalController;

return [
    'dental'            => ['GET', [DentalController::class, 'index'], 'dental.chart.view'],
    'dental/state'      => ['GET', [DentalController::class, 'state'], 'dental.chart.view'],
    'dental/plans'      => ['GET|POST', [DentalController::class, 'planList'], 'dental.chart.view'],
    'dental/plan-print' => ['GET', [DentalController::class, 'planPrint'], 'dental.chart.view'],
    'dental/chairside'  => ['GET|POST', [DentalController::class, 'chairside'], 'dental.consumables|dental.perio.edit'],
    'dental/lab-cases'  => ['GET|POST', [DentalController::class, 'labCases'], 'dental.labcase.view|dental.labcase.manage'],
    'dental/image'      => ['GET|POST', [DentalController::class, 'image'], 'dental.chart.view'],
];
