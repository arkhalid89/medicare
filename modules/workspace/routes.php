<?php
use App\Modules\Workspace\WorkspaceController;

return [
    'context'          => ['POST', [WorkspaceController::class, 'context'], 'auth'],
    'consult'          => ['GET', [WorkspaceController::class, 'consult'], 'visits.create'],
    'consult/start'    => ['GET', [WorkspaceController::class, 'startConsult'], 'visits.create'],
    'emergency'        => ['GET', [WorkspaceController::class, 'emergency'], 'emergency.access|emergency.review'],
    'emergency/grant'  => ['POST', [WorkspaceController::class, 'grant'], 'emergency.access'],
    'emergency/review' => ['POST', [WorkspaceController::class, 'review'], 'emergency.review'],
    'clinical/cosign'  => ['GET', [WorkspaceController::class, 'cosign'], 'clinical.cosign'],
    'clinical/cosign-do' => ['POST', [WorkspaceController::class, 'doCosign'], 'clinical.cosign'],
];
