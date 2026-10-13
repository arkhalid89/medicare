<?php
use App\Modules\Specialty\SpecialtyController;

return [
    'specialty'              => ['GET', [SpecialtyController::class, 'index'], 'specialty.exam.record|visits.create|clinical.notes.write'],
    'specialty/record'       => ['POST', [SpecialtyController::class, 'record'], 'specialty.exam.record|vitals.record'],
    'specialty/immunization' => ['GET|POST', [SpecialtyController::class, 'immunization'], 'immunization.record'],
    'specialty/templates'    => ['GET|POST', [SpecialtyController::class, 'templates'], 'clinical.parameters.configure'],
    'clinical/referrals'     => ['GET|POST', [SpecialtyController::class, 'referrals'], 'clinical.referral.create|visits.create'],
    'clinical/note'          => ['POST', [SpecialtyController::class, 'note'], 'clinical.notes.write|visits.create'],
];
