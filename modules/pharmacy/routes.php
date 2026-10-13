<?php
use App\Modules\Pharmacy\PharmacyController;

return [
    'pharmacy'            => ['GET', [PharmacyController::class, 'queue'], 'pharmacy.dispense|pharmacy.dispense.finalize'],
    'pharmacy/dispense'   => ['GET|POST', [PharmacyController::class, 'dispense'], 'pharmacy.dispense|pharmacy.dispense.finalize'],
    'pharmacy/sale'       => ['GET|POST', [PharmacyController::class, 'sale'], 'pharmacy.counter_sale'],
    'pharmacy/returns'    => ['GET|POST', [PharmacyController::class, 'returns'], 'pharmacy.return|pharmacy.return.approve'],
    'pharmacy/controlled' => ['GET', [PharmacyController::class, 'controlled'], 'pharmacy.controlled.register'],
    'pharmacy/chief'      => ['GET|POST', [PharmacyController::class, 'chief'], 'pharmacy.formulary.manage'],
];
