<?php
use App\Modules\Patients\PatientController;

return [
    'patients'            => ['GET', [PatientController::class, 'index'], 'patients.view'],
    'patients/view'       => ['GET', [PatientController::class, 'show'], 'patients.view'],
    'patients/create'     => ['GET|POST', [PatientController::class, 'create'], 'patients.create'],
    'patients/edit'       => ['GET|POST', [PatientController::class, 'edit'], 'patients.edit'],
    'patients/delete'     => ['POST', [PatientController::class, 'delete'], 'records.delete'],
    'patients/search'     => ['GET', [PatientController::class, 'search'], 'patients.view'],
    'patients/duplicates' => ['GET', [PatientController::class, 'duplicates'], 'patients.view'],
];
