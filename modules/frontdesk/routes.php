<?php
use App\Modules\Frontdesk\FrontdeskController;

return [
    'frontdesk'              => ['GET', [FrontdeskController::class, 'index'], 'frontdesk.register'],
    'frontdesk/fee'          => ['GET', [FrontdeskController::class, 'fee'], 'frontdesk.register'],
    'frontdesk/issue'        => ['POST', [FrontdeskController::class, 'issue'], 'frontdesk.token.issue'],
    'frontdesk/token-print'  => ['GET', [FrontdeskController::class, 'printToken'], 'frontdesk.token.issue|frontdesk.token.manage|billing.view'],
    'frontdesk/tokens'       => ['GET', [FrontdeskController::class, 'console'], 'frontdesk.token.manage'],
    'frontdesk/token-action' => ['POST', [FrontdeskController::class, 'action'], 'frontdesk.token.manage'],
    'frontdesk/queue-data'   => ['GET', [FrontdeskController::class, 'queueData'], 'frontdesk.token.manage|frontdesk.register'],
    'frontdesk/vitals'       => ['GET', [FrontdeskController::class, 'vitals'], 'vitals.record'],
    'frontdesk/vitals-save'  => ['POST', [FrontdeskController::class, 'saveVitals'], 'vitals.record'],
    'frontdesk/boards'       => ['GET', [FrontdeskController::class, 'boards'], 'frontdesk.queue.configure'],
    'frontdesk/board-save'   => ['POST', [FrontdeskController::class, 'saveBoard'], 'frontdesk.queue.configure'],
    // Waiting-room screen: public, protected by the board's secret key.
    'board'                  => ['GET', [FrontdeskController::class, 'board'], null],
    'board/data'             => ['GET', [FrontdeskController::class, 'boardData'], null],
];
