<?php
use App\Modules\Templates\TemplateController;

return [
    'templates'           => ['GET', [TemplateController::class, 'index'], 'templates.manage'],
    'templates/create'    => ['GET', [TemplateController::class, 'editor'], 'templates.manage'],
    'templates/edit'      => ['GET', [TemplateController::class, 'editor'], 'templates.manage'],
    'templates/save'      => ['POST', [TemplateController::class, 'save'], 'templates.manage'],
    'templates/load'      => ['GET', [TemplateController::class, 'load'], 'templates.manage'],
    'templates/favourite' => ['POST', [TemplateController::class, 'favourite'], 'templates.manage'],
    'templates/delete'    => ['POST', [TemplateController::class, 'delete'], 'templates.manage'],
];
