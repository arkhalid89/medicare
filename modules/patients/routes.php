<?php
use App\Modules\Patients\PatientController;

return [
    'patients'                  => ['GET', [PatientController::class, 'index'], 'patients.view'],
    'patients/view'             => ['GET', [PatientController::class, 'show'], 'patients.view'],
    'patients/create'           => ['GET|POST', [PatientController::class, 'create'], 'patients.create'],
    'patients/edit'             => ['GET|POST', [PatientController::class, 'edit'], 'patients.edit|patients.edit_any'],
    'patients/delete'           => ['POST', [PatientController::class, 'delete'], 'records.delete'],
    'patients/search'           => ['GET', [PatientController::class, 'search'], 'patients.view'],
    'patients/duplicates'       => ['GET', [PatientController::class, 'duplicates'], 'patients.view'],
    'patients/contact-add'      => ['POST', [PatientController::class, 'addContact'], 'patients.view'],
    'patients/contact-remove'   => ['POST', [PatientController::class, 'removeContact'], 'patients.edit'],
    'patients/identifier-add'   => ['POST', [PatientController::class, 'addIdentifier'], 'patients.edit|patients.edit_any'],
    'patients/document-upload'  => ['POST', [PatientController::class, 'uploadDocument'], 'patients.documents'],
    'patients/document'         => ['GET', [PatientController::class, 'document'], 'patients.documents'],
    'patients/document-delete'  => ['POST', [PatientController::class, 'deleteDocument'], 'records.delete'],
    'patients/merge'            => ['GET|POST', [PatientController::class, 'merge'], 'patients.merge'],
    'patients/emergency'        => ['POST', [PatientController::class, 'emergency'], 'patients.emergency'],
];
