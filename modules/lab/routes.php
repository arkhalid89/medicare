<?php
use App\Modules\Lab\LabController;

return [
    'lab/reception'    => ['GET|POST', [LabController::class, 'reception'], 'lab.reception|lab.report.handover'],
    'lab/collection'   => ['GET|POST', [LabController::class, 'collection'], 'lab.sample.collect'],
    'lab/labels'       => ['GET', [LabController::class, 'labels'], 'lab.sample.collect|lab.reception'],
    'lab/bench'        => ['GET', [LabController::class, 'bench'], 'lab.result.enter'],
    'lab/result'       => ['GET|POST', [LabController::class, 'result'], 'lab.result.enter|lab.result.verify'],
    'lab/pathologist'  => ['GET|POST', [LabController::class, 'pathologist'], 'lab.sample.review|lab.result.verify'],
    'lab/qc'           => ['GET|POST', [LabController::class, 'qc'], 'lab.qc.record|lab.qc.manage'],
    'lab/orders'       => ['GET', [LabController::class, 'orders'], 'lab.view|lab.order.create'],
    'lab/order'        => ['GET|POST', [LabController::class, 'order'], 'lab.view|lab.order.create'],
    'lab/report'       => ['GET', [LabController::class, 'report'], 'lab.view|lab.order.create'],
    'lab/critical'     => ['GET|POST', [LabController::class, 'critical'], 'visits.create|lab.result.verify'],
    'lab/catalog'      => ['GET|POST', [LabController::class, 'catalog'], 'lab.catalog.manage'],
    'lab/outsource'    => ['POST', [LabController::class, 'outsource'], 'lab.outsource'],
];
