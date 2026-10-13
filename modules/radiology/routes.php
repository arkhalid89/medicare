<?php
use App\Modules\Radiology\RadiologyController;

return [
    'radiology/reception'   => ['GET|POST', [RadiologyController::class, 'reception'], 'radiology.schedule'],
    'radiology/technician'  => ['GET|POST', [RadiologyController::class, 'technician'], 'radiology.acquire'],
    'radiology/radiologist' => ['GET|POST', [RadiologyController::class, 'radiologist'], 'radiology.review|radiology.report'],
    'radiology/report'      => ['GET|POST', [RadiologyController::class, 'report'], 'radiology.report'],
    'radiology/order'       => ['GET|POST', [RadiologyController::class, 'order'], 'radiology.view|radiology.order.create'],
    'radiology/print'       => ['GET', [RadiologyController::class, 'print'], 'radiology.view|radiology.order.create'],
    'radiology/image'       => ['GET', [RadiologyController::class, 'image'], 'radiology.view'],
    'radiology/orders'      => ['GET', [RadiologyController::class, 'orders'], 'radiology.view|radiology.order.create'],
    'radiology/setup'       => ['GET|POST', [RadiologyController::class, 'setup'], 'radiology.manage'],
];
