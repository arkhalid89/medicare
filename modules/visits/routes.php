<?php
use App\Modules\Visits\VisitController;

return [
    'visits'           => ['GET', [VisitController::class, 'index'], 'visits.view'],
    'visits/new'       => ['GET', [VisitController::class, 'create'], 'visits.create'],
    'visits/checkout'  => ['POST', [VisitController::class, 'checkout'], 'visits.create'],
    'visits/view'      => ['GET', [VisitController::class, 'show'], 'visits.view'],
    'visits/print'     => ['GET', [VisitController::class, 'printout'], 'visits.print'],
    'visits/printed'   => ['POST', [VisitController::class, 'printed'], 'visits.print'],
    'visits/delete'    => ['POST', [VisitController::class, 'delete'], 'records.delete'],
    'visits/medicines' => ['GET', [VisitController::class, 'medicines'], 'visits.create'],
    'visits/lookup'    => ['GET', [VisitController::class, 'lookup'], 'visits.create'],
];
