<?php
use App\Modules\System\SystemController;

return [
    'system/backup'          => ['GET', [SystemController::class, 'backups'], 'backup.create'],
    'system/backup/create'   => ['POST', [SystemController::class, 'createBackup'], 'backup.create'],
    'system/backup/download' => ['GET', [SystemController::class, 'downloadBackup'], 'backup.create'],
    'system/backup/restore'  => ['POST', [SystemController::class, 'restoreBackup'], 'backup.restore'],
    'system/backup/delete'   => ['POST', [SystemController::class, 'deleteBackup'], 'backup.restore'],
    'system/reset'           => ['GET|POST', [SystemController::class, 'reset'], 'db.reset'],
    'system/recycle'         => ['GET', [SystemController::class, 'recycle'], 'records.delete'],
    'system/recycle/restore' => ['POST', [SystemController::class, 'restore'], 'records.delete'],
    'system/logs'            => ['GET', [SystemController::class, 'logs'], 'activity.view'],
];
