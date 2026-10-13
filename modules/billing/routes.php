<?php
use App\Modules\Billing\BillingController;

return [
    'billing'                 => ['GET', [BillingController::class, 'counter'], 'billing.payment.collect'],
    'billing/invoices'        => ['GET', [BillingController::class, 'invoices'], 'billing.view'],
    'billing/invoice'         => ['GET', [BillingController::class, 'show'], 'billing.view'],
    'billing/print'           => ['GET', [BillingController::class, 'printout'], 'billing.view'],
    'billing/new'             => ['GET|POST', [BillingController::class, 'create'], 'billing.invoice.create'],
    'billing/service-search'  => ['GET', [BillingController::class, 'serviceSearch'], 'billing.invoice.create|billing.view'],
    'billing/collect'         => ['POST', [BillingController::class, 'collect'], 'billing.payment.collect'],
    'billing/discount'        => ['POST', [BillingController::class, 'discount'], 'billing.discount.apply|billing.discount.approve'],
    'billing/refund'          => ['POST', [BillingController::class, 'refund'], 'billing.refund.request'],
    'billing/refund-decide'   => ['POST', [BillingController::class, 'decideRefund'], 'billing.refund.approve'],
    'billing/refund-process'  => ['POST', [BillingController::class, 'processRefund'], 'billing.refund.process'],
    'billing/discount-decide' => ['POST', [BillingController::class, 'decideDiscount'], 'billing.discount.approve'],
    'billing/void'            => ['POST', [BillingController::class, 'void'], 'billing.void.approve'],
    'billing/approvals'       => ['GET', [BillingController::class, 'approvals'], 'billing.refund.approve|billing.discount.approve|billing.void.approve'],
    'billing/sessions'        => ['GET', [BillingController::class, 'sessions'], 'billing.cash.close|billing.refund.approve'],
    'billing/session-close'   => ['POST', [BillingController::class, 'closeSession'], 'billing.cash.close|billing.refund.approve'],
    'billing/services'        => ['GET', [BillingController::class, 'services'], 'billing.services.manage'],
    'billing/service-save'    => ['POST', [BillingController::class, 'saveService'], 'billing.services.manage'],
    'billing/price-save'      => ['POST', [BillingController::class, 'savePrice'], 'billing.services.manage'],
];
