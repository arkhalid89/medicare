<?php
use App\Modules\Search\SearchController;

return [
    'search'       => ['GET', [SearchController::class, 'index'], 'search.use'],
    'search/quick' => ['GET', [SearchController::class, 'quick'], 'search.use'],
];
