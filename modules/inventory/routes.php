<?php
use App\Modules\Inventory\InventoryController;
use App\Modules\Inventory\StoreDocsController;

return [
    'inventory'              => ['GET', [InventoryController::class, 'dashboard'], 'inventory.view'],
    'inventory/stock'        => ['GET', [InventoryController::class, 'stock'], 'inventory.view'],
    'inventory/card'         => ['GET', [InventoryController::class, 'card'], 'inventory.view'],
    'inventory/items'        => ['GET', [InventoryController::class, 'items'], 'inventory.view'],
    'inventory/item'         => ['GET|POST', [InventoryController::class, 'itemForm'], 'inventory.view'],
    'inventory/opening'      => ['GET|POST', [InventoryController::class, 'opening'], 'inventory.items.manage'],
    'inventory/item-search'  => ['GET', [InventoryController::class, 'itemSearch'], 'inventory.view|pharmacy.view|pharmacy.counter_sale|dental.consumables'],
    'inventory/batches'      => ['GET', [InventoryController::class, 'batches'], 'inventory.view|pharmacy.view|pharmacy.counter_sale'],
    'inventory/expiry'       => ['GET', [InventoryController::class, 'expiry'], 'inventory.view'],
    'inventory/recall'       => ['POST', [InventoryController::class, 'recall'], 'pharmacy.writeoff.approve|inventory.adjust.approve'],
    'inventory/assets'       => ['GET|POST', [InventoryController::class, 'assets'], 'inventory.assets.manage'],
    'inventory/indents'      => ['GET', [StoreDocsController::class, 'indents'], 'inventory.requisition.create|inventory.requisition.approve|inventory.issue'],
    'inventory/indent-new'   => ['GET|POST', [StoreDocsController::class, 'newIndent'], 'inventory.requisition.create'],
    'inventory/indent'       => ['GET', [StoreDocsController::class, 'indent'], 'inventory.requisition.create|inventory.requisition.approve|inventory.issue'],
    'inventory/indent-action'=> ['POST', [StoreDocsController::class, 'indentAction'], 'inventory.requisition.create|inventory.requisition.approve|inventory.issue'],
    'inventory/po'           => ['GET', [StoreDocsController::class, 'pos'], 'inventory.po.create|inventory.po.approve'],
    'inventory/po-new'       => ['GET|POST', [StoreDocsController::class, 'newPo'], 'inventory.po.create'],
    'inventory/po-view'      => ['GET', [StoreDocsController::class, 'po'], 'inventory.po.create|inventory.po.approve|inventory.grn.post'],
    'inventory/po-action'    => ['POST', [StoreDocsController::class, 'poAction'], 'inventory.po.create|inventory.po.approve'],
    'inventory/grn'          => ['GET', [StoreDocsController::class, 'grns'], 'inventory.grn.post|inventory.po.approve'],
    'inventory/grn-new'      => ['GET|POST', [StoreDocsController::class, 'newGrn'], 'inventory.grn.post'],
    'inventory/grn-view'     => ['GET|POST', [StoreDocsController::class, 'grn'], 'inventory.grn.post|inventory.po.approve'],
    'inventory/transfers'    => ['GET|POST', [StoreDocsController::class, 'transfers'], 'inventory.transfer'],
    'inventory/adjustments'  => ['GET|POST', [StoreDocsController::class, 'adjustments'], 'inventory.adjust.propose|inventory.adjust.approve'],
    'inventory/counts'       => ['GET|POST', [StoreDocsController::class, 'counts'], 'inventory.count'],
    'inventory/count'        => ['GET|POST', [StoreDocsController::class, 'count'], 'inventory.count'],
];
