<?php

use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\BrandController;
use App\Http\Controllers\CatalogProductController;
use App\Http\Controllers\CashRegisterController;
use App\Http\Controllers\CommerceSaleController;
use App\Http\Controllers\FinancialAccountController;
use App\Http\Controllers\FinancialReconciliationController;
use App\Http\Controllers\BusinessPartyController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\GlobalSearchController;
use App\Http\Controllers\PurchaseObligationController;
use App\Http\Controllers\PurchasePaymentRequestController;
use App\Http\Controllers\PurchaseOrderController;
use App\Http\Controllers\PurchaseReceiptController;
use App\Http\Controllers\CompatibilityController;
use App\Http\Controllers\EntityController;
use App\Http\Controllers\IdentifierController;
use App\Http\Controllers\InventoryAvailabilityController;
use App\Http\Controllers\InventoryLocationController;
use App\Http\Controllers\InventoryMovementController;
use App\Http\Controllers\InventoryNegativeAuthorizationController;
use App\Http\Controllers\InventoryNegativeIncidentController;
use App\Http\Controllers\KnowledgeController;
use App\Http\Controllers\ManufacturerController;
use App\Http\Controllers\OrganizationController;
use App\Http\Controllers\OrganizationMemberController;
use App\Http\Controllers\OrganizationProductPriceController;
use App\Http\Controllers\OperationalAttentionController;
use App\Http\Controllers\ProductCategoryController;
use App\Http\Controllers\ProductImportController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ServiceAssessmentController;
use App\Http\Controllers\ServiceCancellationController;
use App\Http\Controllers\ServiceCompletionController;
use App\Http\Controllers\ServiceEvidenceController;
use App\Http\Controllers\ServiceOrderController;
use App\Http\Controllers\ServicePartController;
use App\Http\Controllers\ServiceWarrantyClaimController;
use App\Http\Controllers\ServiceWorkController;
use App\Http\Controllers\SupplierController;
use App\Http\Controllers\SupplierOfferController;
use App\Http\Controllers\TechnicalModelController;
use App\Http\Middleware\RequireOrganization;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect()->to(
        route('login', absolute: false)
    );
});

Route::middleware(['auth', 'verified'])->group(function () {
    /*
    |--------------------------------------------------------------------------
    | Operational read access
    |--------------------------------------------------------------------------
    */

    Route::get(
        'product-categories',
        [ProductCategoryController::class, 'index']
    )->name('product-categories.index');

    Route::get(
        'brands',
        [BrandController::class, 'index']
    )->name('brands.index');

    Route::get(
        'manufacturers',
        [ManufacturerController::class, 'index']
    )->name('manufacturers.index');

    Route::get(
        'products',
        [CatalogProductController::class, 'index']
    )->name('products.index');

    Route::get(
        'products/{product}',
        [CatalogProductController::class, 'show']
    )
        ->whereNumber('product')
        ->name('products.show');

    Route::get(
        'technical-models',
        [TechnicalModelController::class, 'index']
    )->name('technical-models.index');

    Route::get(
        'technical-models/{technical_model}',
        [TechnicalModelController::class, 'show']
    )
        ->whereNumber('technical_model')
        ->name('technical-models.show');

    Route::get(
        '/explorer',
        [KnowledgeController::class, 'explorer']
    )->name('knowledge.explorer');

    Route::get(
        '/knowledge/{query}',
        [KnowledgeController::class, 'show']
    )->name('knowledge.show');

    Route::get(
        'entities/{entity:uuid}',
        [EntityController::class, 'show']
    )
        ->whereUuid('entity')
        ->name('entities.show');

    /*
    |--------------------------------------------------------------------------
    | Catalog management
    |--------------------------------------------------------------------------
    */

    Route::middleware('can:manage-catalog')->group(function () {
        Route::get(
            'entities/create',
            [EntityController::class, 'create']
        )->name('entities.create');

        Route::post(
            'entities',
            [EntityController::class, 'store']
        )->name('entities.store');

        Route::post(
            'entities/{entity:uuid}/identifiers',
            [IdentifierController::class, 'store']
        )
            ->whereUuid('entity')
            ->name('entities.identifiers.store');

        Route::patch(
            'entities/{entity:uuid}/identifiers/{identifier}/make-primary',
            [IdentifierController::class, 'makePrimary']
        )
            ->whereUuid('entity')
            ->whereNumber('identifier')
            ->name('entities.identifiers.make-primary');

        Route::patch(
            'entities/{entity:uuid}/identifiers/{identifier}/toggle-active',
            [IdentifierController::class, 'toggleActive']
        )
            ->whereUuid('entity')
            ->whereNumber('identifier')
            ->name('entities.identifiers.toggle-active');

        Route::post(
            'entities/{entity:uuid}/compatibilities',
            [CompatibilityController::class, 'store']
        )
            ->whereUuid('entity')
            ->name('entities.compatibilities.store');

        Route::patch(
            'entities/{entity:uuid}/compatibilities/{compatibility}/toggle-active',
            [CompatibilityController::class, 'toggleActive']
        )
            ->whereUuid('entity')
            ->whereNumber('compatibility')
            ->name('entities.compatibilities.toggle-active');

        Route::patch(
            'product-categories/{product_category}/toggle-active',
            [ProductCategoryController::class, 'toggleActive']
        )->name('product-categories.toggle-active');

        Route::resource(
            'product-categories',
            ProductCategoryController::class
        )->except(['index', 'destroy']);

        Route::patch(
            'brands/{brand}/toggle-active',
            [BrandController::class, 'toggleActive']
        )->name('brands.toggle-active');

        Route::resource(
            'brands',
            BrandController::class
        )->except(['index', 'destroy']);

        Route::patch(
            'manufacturers/{manufacturer}/toggle-active',
            [ManufacturerController::class, 'toggleActive']
        )->name('manufacturers.toggle-active');

        Route::resource(
            'manufacturers',
            ManufacturerController::class
        )->except(['index', 'destroy']);

        Route::patch(
            'products/{product}/toggle-active',
            [CatalogProductController::class, 'toggleActive']
        )->name('products.toggle-active');

        Route::resource(
            'products',
            CatalogProductController::class
        )->except(['index', 'show', 'destroy']);

        Route::get(
            'imports/products',
            [ProductImportController::class, 'create']
        )->name('product-imports.create');

        Route::get(
            'imports/products/template',
            [ProductImportController::class, 'template']
        )->name('product-imports.template');

        Route::post(
            'imports/products/preview',
            [ProductImportController::class, 'preview']
        )->name('product-imports.preview');

        Route::post(
            'imports/products',
            [ProductImportController::class, 'store']
        )->name('product-imports.store');

        Route::patch(
            'technical-models/{technical_model}/toggle-active',
            [TechnicalModelController::class, 'toggleActive']
        )->name('technical-models.toggle-active');

        Route::resource(
            'technical-models',
            TechnicalModelController::class
        )->except(['index', 'show', 'destroy']);
    });

    /*
    |--------------------------------------------------------------------------
    | Organization-owned private operations
    |--------------------------------------------------------------------------
    */

    Route::middleware(RequireOrganization::class)
        ->group(function () {
            Route::get(
                '/dashboard',
                [DashboardController::class, 'index']
            )->name('dashboard');

            Route::post(
                '/operational-attention/acknowledge',
                [OperationalAttentionController::class, 'acknowledge']
            )->name('operational-attention.acknowledge');

            Route::get(
                '/search',
                [GlobalSearchController::class, 'index']
            )->name('global-search.index');

            Route::get(
                '/organization',
                [OrganizationController::class, 'show']
            )->name('organization.show');

            Route::post(
                '/organizations/{organization}/activate',
                [OrganizationController::class, 'activate']
            )
                ->whereNumber('organization')
                ->name('organizations.activate');

            Route::middleware('can:manage-organization')
                ->group(function () {
                    Route::get(
                        '/organization/edit',
                        [OrganizationController::class, 'edit']
                    )->name('organization.edit');

                    Route::put(
                        '/organization',
                        [OrganizationController::class, 'update']
                    )->name('organization.update');
                });
            Route::middleware('can:view-organization-members')
                ->group(function () {
                    Route::get(
                        '/organization/members',
                        [OrganizationMemberController::class, 'index']
                    )->name('organization-members.index');
                });

            Route::middleware('can:manage-organization-members')
                ->group(function () {
                    Route::post(
                        '/organization/members',
                        [OrganizationMemberController::class, 'store']
                    )->name('organization-members.store');

                    Route::patch(
                        '/organization/members/{membership}/role',
                        [OrganizationMemberController::class, 'updateRole']
                    )
                        ->whereNumber('membership')
                        ->name('organization-members.update-role');

                    Route::patch(
                        '/organization/members/{membership}/toggle-active',
                        [OrganizationMemberController::class, 'toggleActive']
                    )
                        ->whereNumber('membership')
                        ->name('organization-members.toggle-active');
                });

            Route::middleware('can:view-business-parties')
                ->group(function () {
                    Route::get(
                        'business-parties',
                        [BusinessPartyController::class, 'index']
                    )->name('business-parties.index');

                    Route::get(
                        'business-parties/{businessParty}',
                        [BusinessPartyController::class, 'show']
                    )
                        ->whereNumber('businessParty')
                        ->name('business-parties.show');
                });

            Route::middleware('can:manage-business-parties')
                ->group(function () {
                    Route::get(
                        'business-parties/create',
                        [BusinessPartyController::class, 'create']
                    )->name('business-parties.create');

                    Route::post(
                        'business-parties',
                        [BusinessPartyController::class, 'store']
                    )->name('business-parties.store');

                    Route::get(
                        'business-parties/{businessParty}/edit',
                        [BusinessPartyController::class, 'edit']
                    )
                        ->whereNumber('businessParty')
                        ->name('business-parties.edit');

                    Route::put(
                        'business-parties/{businessParty}',
                        [BusinessPartyController::class, 'update']
                    )
                        ->whereNumber('businessParty')
                        ->name('business-parties.update');
                });
            Route::middleware('can:view-customers')
                ->group(function () {
                    Route::get(
                        'customers',
                        [CustomerController::class, 'index']
                    )->name('customers.index');

                    Route::get(
                        'customers/{customer}',
                        [CustomerController::class, 'show']
                    )
                        ->whereNumber('customer')
                        ->name('customers.show');
                });

            Route::middleware('can:manage-customers')
                ->group(function () {
                    Route::get(
                        'customers/create',
                        [CustomerController::class, 'create']
                    )->name('customers.create');

                    Route::post(
                        'customers',
                        [CustomerController::class, 'store']
                    )->name('customers.store');

                    Route::get(
                        'customers/{customer}/edit',
                        [CustomerController::class, 'edit']
                    )
                        ->whereNumber('customer')
                        ->name('customers.edit');

                    Route::put(
                        'customers/{customer}',
                        [CustomerController::class, 'update']
                    )
                        ->whereNumber('customer')
                        ->name('customers.update');

                    Route::patch(
                        'customers/{customer}/toggle-active',
                        [CustomerController::class, 'toggleActive']
                    )
                        ->whereNumber('customer')
                        ->name('customers.toggle-active');
                });
            Route::middleware('can:operate-cash-register')
                ->group(function () {
                    Route::get(
                        'financial/cash-registers',
                        [CashRegisterController::class, 'index']
                    )->name('cash-registers.index');

                    Route::post(
                        'financial/cash-registers/{cashRegister:public_id}/open',
                        [CashRegisterController::class, 'open']
                    )
                        ->whereUuid('cashRegister')
                        ->name('cash-registers.open');

                    Route::post(
                        'financial/cash-registers/security-drop-requests',
                        [CashRegisterController::class, 'requestSecurityDrop']
                    )->name('cash-registers.security-drop-requests.store');

                    Route::post(
                        'financial/cash-registers/security-drop-requests/{cashSecurityDropRequest:public_id}/execute',
                        [CashRegisterController::class, 'executeSecurityDrop']
                    )
                        ->whereUuid('cashSecurityDropRequest')
                        ->name('cash-registers.security-drop-requests.execute');

                    Route::post(
                        'financial/cash-registers/security-drop-requests/{cashSecurityDropRequest:public_id}/cancel',
                        [CashRegisterController::class, 'cancelSecurityDrop']
                    )
                        ->whereUuid('cashSecurityDropRequest')
                        ->name('cash-registers.security-drop-requests.cancel');

                    Route::post(
                        'financial/cash-registers/close',
                        [CashRegisterController::class, 'close']
                    )->name('cash-registers.close');
                });

            Route::middleware('can:approve-cash-security-drop')
                ->group(function () {
                    Route::post(
                        'financial/cash-registers/security-drop-requests/{cashSecurityDropRequest:public_id}/approve',
                        [CashRegisterController::class, 'approveSecurityDrop']
                    )
                        ->whereUuid('cashSecurityDropRequest')
                        ->name('cash-registers.security-drop-requests.approve');

                    Route::post(
                        'financial/cash-registers/security-drop-requests/{cashSecurityDropRequest:public_id}/reject',
                        [CashRegisterController::class, 'rejectSecurityDrop']
                    )
                        ->whereUuid('cashSecurityDropRequest')
                        ->name('cash-registers.security-drop-requests.reject');
                });

            Route::middleware('can:manage-cash-registers')
                ->group(function () {
                    Route::get(
                        'financial/cash-registers/create',
                        [CashRegisterController::class, 'create']
                    )->name('cash-registers.create');

                    Route::post(
                        'financial/cash-registers',
                        [CashRegisterController::class, 'store']
                    )->name('cash-registers.store');

                    Route::get(
                        'financial/cash-registers/{cashRegister:public_id}/edit',
                        [CashRegisterController::class, 'edit']
                    )
                        ->whereUuid('cashRegister')
                        ->name('cash-registers.edit');

                    Route::put(
                        'financial/cash-registers/{cashRegister:public_id}',
                        [CashRegisterController::class, 'update']
                    )
                        ->whereUuid('cashRegister')
                        ->name('cash-registers.update');

                    Route::patch(
                        'financial/cash-registers/{cashRegister:public_id}/toggle-active',
                        [CashRegisterController::class, 'toggleActive']
                    )
                        ->whereUuid('cashRegister')
                        ->name('cash-registers.toggle-active');
                });

            Route::middleware('can:use-financial-accounts')
                ->group(function () {
                    Route::get(
                        'financial/accounts',
                        [FinancialAccountController::class, 'index']
                    )->name('financial-accounts.index');
                });

            Route::middleware('can:review-financial-reconciliation')
                ->group(function () {
                    Route::get(
                        'financial/reconciliation',
                        [FinancialReconciliationController::class, 'index']
                    )->name('financial-reconciliation.index');

                    Route::post(
                        'financial/reconciliation/payments/{commercePayment}/movements/{financialExternalMovement}',
                        [FinancialReconciliationController::class, 'reconcileCandidate']
                    )
                        ->whereNumber('commercePayment')
                        ->whereUuid('financialExternalMovement')
                        ->name('financial-reconciliation.candidates.reconcile');
                });

            Route::middleware('can:manage-financial-accounts')
                ->group(function () {
                    Route::get(
                        'financial/accounts/create',
                        [FinancialAccountController::class, 'create']
                    )->name('financial-accounts.create');

                    Route::post(
                        'financial/accounts',
                        [FinancialAccountController::class, 'store']
                    )->name('financial-accounts.store');

                    Route::post(
                        'financial/provider-connections/{financialProviderConnection:public_id}/health/read',
                        [FinancialAccountController::class, 'probeProviderReadHealth']
                    )
                        ->whereUuid('financialProviderConnection')
                        ->name('financial-provider-connections.health.read');

                    Route::get(
                        'financial/accounts/{financialAccount:public_id}/edit',
                        [FinancialAccountController::class, 'edit']
                    )
                        ->whereUuid('financialAccount')
                        ->name('financial-accounts.edit');

                    Route::put(
                        'financial/accounts/{financialAccount:public_id}',
                        [FinancialAccountController::class, 'update']
                    )
                        ->whereUuid('financialAccount')
                        ->name('financial-accounts.update');

                    Route::patch(
                        'financial/accounts/{financialAccount:public_id}/toggle-active',
                        [FinancialAccountController::class, 'toggleActive']
                    )
                        ->whereUuid('financialAccount')
                        ->name('financial-accounts.toggle-active');
                });

            Route::middleware('can:view-commerce-sales')
                ->group(function () {
                    Route::get(
                        'commerce/sales',
                        [CommerceSaleController::class, 'index']
                    )->name('commerce-sales.index');

                    Route::get(
                        'commerce/sales/{commerceSale:public_id}',
                        [CommerceSaleController::class, 'show']
                    )
                        ->whereUuid('commerceSale')
                        ->name('commerce-sales.show');
                });

            Route::middleware('can:record-commerce-sales')
                ->group(function () {
                    Route::get(
                        'commerce/sales/create',
                        [CommerceSaleController::class, 'create']
                    )->name('commerce-sales.create');

                    Route::post(
                        'commerce/sales',
                        [CommerceSaleController::class, 'store']
                    )->name('commerce-sales.store');
                });

            Route::put(
                'products/{product}/commercial-price',
                [OrganizationProductPriceController::class, 'update']
            )
                ->middleware('can:manage-commerce-prices')
                ->whereNumber('product')
                ->name('organization-product-prices.update');

            Route::middleware('can:view-purchases')
                ->group(function () {
                    Route::get(
                        'purchases',
                        [PurchaseOrderController::class, 'index']
                    )->name('purchase-orders.index');

                    Route::get(
                        'purchases/{purchaseOrder}',
                        [PurchaseOrderController::class, 'show']
                    )
                        ->whereUuid('purchaseOrder')
                        ->name('purchase-orders.show');
                });

            Route::middleware('can:draft-purchase-orders')
                ->group(function () {
                    Route::get(
                        'purchases/create',
                        [PurchaseOrderController::class, 'create']
                    )->name('purchase-orders.create');

                    Route::post(
                        'purchases',
                        [PurchaseOrderController::class, 'store']
                    )->name('purchase-orders.store');

                    Route::get(
                        'purchases/{purchaseOrder}/edit',
                        [PurchaseOrderController::class, 'edit']
                    )
                        ->whereUuid('purchaseOrder')
                        ->name('purchase-orders.edit');

                    Route::put(
                        'purchases/{purchaseOrder}',
                        [PurchaseOrderController::class, 'update']
                    )
                        ->whereUuid('purchaseOrder')
                        ->name('purchase-orders.update');
                });

            Route::middleware('can:issue-purchase-orders')
                ->group(function () {
                    Route::post(
                        'purchases/{purchaseOrder}/issue',
                        [PurchaseOrderController::class, 'issue']
                    )
                        ->whereUuid('purchaseOrder')
                        ->name('purchase-orders.issue');
                });

            Route::middleware('can:receive-purchases')
                ->group(function () {
                    Route::get(
                        'purchases/{purchaseOrder}/receipts/create',
                        [PurchaseReceiptController::class, 'create']
                    )
                        ->whereUuid('purchaseOrder')
                        ->name('purchase-orders.receipts.create');

                    Route::post(
                        'purchases/{purchaseOrder}/receipts',
                        [PurchaseReceiptController::class, 'store']
                    )
                        ->whereUuid('purchaseOrder')
                        ->name('purchase-orders.receipts.store');
                });

            Route::middleware('can:create-purchase-obligations')
                ->group(function () {
                    Route::post(
                        'purchases/{purchaseOrder}/receipts/{purchaseReceipt}/obligations',
                        [PurchaseObligationController::class, 'store']
                    )
                        ->whereUuid('purchaseOrder')
                        ->whereUuid('purchaseReceipt')
                        ->name('purchase-orders.obligations.store');
                });

            Route::middleware('can:request-purchase-payments')
                ->group(function () {
                    Route::post(
                        'purchases/{purchaseOrder}/obligations/{purchaseObligation}/payment-requests',
                        [PurchasePaymentRequestController::class, 'store']
                    )
                        ->whereUuid('purchaseOrder')
                        ->whereUuid('purchaseObligation')
                        ->name('purchase-payment-requests.store');

                    Route::post(
                        'purchases/payment-requests/{purchasePaymentRequest:public_id}/cancel',
                        [PurchasePaymentRequestController::class, 'cancel']
                    )
                        ->whereUuid('purchasePaymentRequest')
                        ->name('purchase-payment-requests.cancel');
                });

            Route::middleware('can:execute-purchase-payments')
                ->group(function () {
                    Route::post(
                        'purchases/payment-requests/{purchasePaymentRequest:public_id}/execute',
                        [PurchasePaymentRequestController::class, 'execute']
                    )
                        ->whereUuid('purchasePaymentRequest')
                        ->name('purchase-payment-requests.execute');
                });
            Route::middleware('can:approve-purchase-payments')
                ->group(function () {
                    Route::post(
                        'purchases/payment-requests/{purchasePaymentRequest:public_id}/approve',
                        [PurchasePaymentRequestController::class, 'approve']
                    )
                        ->whereUuid('purchasePaymentRequest')
                        ->name('purchase-payment-requests.approve');

                    Route::post(
                        'purchases/payment-requests/{purchasePaymentRequest:public_id}/reject',
                        [PurchasePaymentRequestController::class, 'reject']
                    )
                        ->whereUuid('purchasePaymentRequest')
                        ->name('purchase-payment-requests.reject');

                    Route::post(
                        'purchases/payment-requests/{purchasePaymentRequest:public_id}/expire',
                        [PurchasePaymentRequestController::class, 'expire']
                    )
                        ->whereUuid('purchasePaymentRequest')
                        ->name('purchase-payment-requests.expire');
                });
            Route::middleware('can:cancel-purchase-orders')
                ->group(function () {
                    Route::post(
                        'purchases/{purchaseOrder}/cancel',
                        [PurchaseOrderController::class, 'cancel']
                    )
                        ->whereUuid('purchaseOrder')
                        ->name('purchase-orders.cancel');
                });
            Route::middleware('can:view-service-orders')
                ->group(function () {
                    Route::get(
                        'service/orders',
                        [ServiceOrderController::class, 'index']
                    )->name('service-orders.index');

                    Route::get(
                        'service/orders/{serviceOrder:public_id}',
                        [ServiceOrderController::class, 'show']
                    )
                        ->whereUuid('serviceOrder')
                        ->name('service-orders.show');
                });

            Route::middleware('can:create-service-orders')
                ->group(function () {
                    Route::get(
                        'service/orders/create',
                        [ServiceOrderController::class, 'create']
                    )->name('service-orders.create');

                    Route::post(
                        'service/orders',
                        [ServiceOrderController::class, 'store']
                    )->name('service-orders.store');
                });

            Route::middleware('can:record-service-diagnostics')
                ->group(function () {
                    Route::get(
                        'service/orders/{serviceOrder:public_id}/diagnostics/create',
                        [
                            ServiceAssessmentController::class,
                            'createDiagnostic',
                        ]
                    )
                        ->whereUuid('serviceOrder')
                        ->name('service-orders.diagnostics.create');

                    Route::post(
                        'service/orders/{serviceOrder:public_id}/diagnostics',
                        [
                            ServiceAssessmentController::class,
                            'storeDiagnostic',
                        ]
                    )
                        ->whereUuid('serviceOrder')
                        ->name('service-orders.diagnostics.store');
                });

            Route::middleware('can:issue-service-quotes')
                ->group(function () {
                    Route::get(
                        'service/orders/{serviceOrder:public_id}/quotes/create',
                        [
                            ServiceAssessmentController::class,
                            'createQuote',
                        ]
                    )
                        ->whereUuid('serviceOrder')
                        ->name('service-orders.quotes.create');

                    Route::post(
                        'service/orders/{serviceOrder:public_id}/quotes',
                        [
                            ServiceAssessmentController::class,
                            'storeQuote',
                        ]
                    )
                        ->whereUuid('serviceOrder')
                        ->name('service-orders.quotes.store');
                });

            Route::middleware(
                'can:record-service-quote-decisions'
            )->group(function () {
                Route::get(
                    'service/orders/{serviceOrder:public_id}/quotes/{serviceQuote}/decision',
                    [
                        ServiceAssessmentController::class,
                        'createDecision',
                    ]
                )
                    ->whereUuid('serviceOrder')
                    ->whereNumber('serviceQuote')
                    ->name('service-orders.quotes.decisions.create');

                Route::post(
                    'service/orders/{serviceOrder:public_id}/quotes/{serviceQuote}/decision',
                    [
                        ServiceAssessmentController::class,
                        'storeDecision',
                    ]
                )
                    ->whereUuid('serviceOrder')
                    ->whereNumber('serviceQuote')
                    ->name('service-orders.quotes.decisions.store');
            });

            Route::middleware('can:plan-service-work')
                ->group(function () {
                    Route::get(
                        'service/orders/{serviceOrder:public_id}/work-items/create',
                        [ServiceWorkController::class, 'create']
                    )
                        ->whereUuid('serviceOrder')
                        ->name('service-orders.work-items.create');

                    Route::post(
                        'service/orders/{serviceOrder:public_id}/work-items',
                        [ServiceWorkController::class, 'store']
                    )
                        ->whereUuid('serviceOrder')
                        ->name('service-orders.work-items.store');
                });

            Route::middleware('can:execute-service-work')
                ->group(function () {
                    Route::post(
                        'service/orders/{serviceOrder:public_id}/work-items/{serviceWorkItem}/start',
                        [ServiceWorkController::class, 'start']
                    )
                        ->whereUuid('serviceOrder')
                        ->whereNumber('serviceWorkItem')
                        ->name('service-orders.work-items.start');

                    Route::get(
                        'service/orders/{serviceOrder:public_id}/work-items/{serviceWorkItem}/report',
                        [ServiceWorkController::class, 'createReport']
                    )
                        ->whereUuid('serviceOrder')
                        ->whereNumber('serviceWorkItem')
                        ->name('service-orders.work-items.report.create');

                    Route::post(
                        'service/orders/{serviceOrder:public_id}/work-items/{serviceWorkItem}/report',
                        [ServiceWorkController::class, 'storeReport']
                    )
                        ->whereUuid('serviceOrder')
                        ->whereNumber('serviceWorkItem')
                        ->name('service-orders.work-items.report.store');
                });

            Route::middleware('can:transfer-service-custody')
                ->group(function () {
                    Route::get(
                        'service/orders/{serviceOrder:public_id}/work-items/{serviceWorkItem}/dispatch',
                        [ServiceWorkController::class, 'createDispatch']
                    )
                        ->whereUuid('serviceOrder')
                        ->whereNumber('serviceWorkItem')
                        ->name('service-orders.work-items.dispatch.create');

                    Route::post(
                        'service/orders/{serviceOrder:public_id}/work-items/{serviceWorkItem}/dispatch',
                        [ServiceWorkController::class, 'storeDispatch']
                    )
                        ->whereUuid('serviceOrder')
                        ->whereNumber('serviceWorkItem')
                        ->name('service-orders.work-items.dispatch.store');

                    Route::get(
                        'service/orders/{serviceOrder:public_id}/work-items/{serviceWorkItem}/return',
                        [ServiceWorkController::class, 'createReturn']
                    )
                        ->whereUuid('serviceOrder')
                        ->whereNumber('serviceWorkItem')
                        ->name('service-orders.work-items.return.create');

                    Route::post(
                        'service/orders/{serviceOrder:public_id}/work-items/{serviceWorkItem}/return',
                        [ServiceWorkController::class, 'storeReturn']
                    )
                        ->whereUuid('serviceOrder')
                        ->whereNumber('serviceWorkItem')
                        ->name('service-orders.work-items.return.store');
                });
            Route::middleware('can:plan-service-parts')
                ->group(function () {
                    Route::get(
                        'service/orders/{serviceOrder:public_id}/work-items/{serviceWorkItem}/part-requirements/create',
                        [ServicePartController::class, 'createRequirement']
                    )
                        ->whereUuid('serviceOrder')
                        ->whereNumber('serviceWorkItem')
                        ->name('service-orders.part-requirements.create');

                    Route::post(
                        'service/orders/{serviceOrder:public_id}/work-items/{serviceWorkItem}/part-requirements',
                        [ServicePartController::class, 'storeRequirement']
                    )
                        ->whereUuid('serviceOrder')
                        ->whereNumber('serviceWorkItem')
                        ->name('service-orders.part-requirements.store');
                });

            Route::middleware('can:record-service-part-purchases')
                ->group(function () {
                    Route::get(
                        'service/orders/{serviceOrder:public_id}/part-purchases/create',
                        [ServicePartController::class, 'createPurchase']
                    )
                        ->whereUuid('serviceOrder')
                        ->name('service-orders.part-purchases.create');

                    Route::post(
                        'service/orders/{serviceOrder:public_id}/part-purchases',
                        [ServicePartController::class, 'storePurchase']
                    )
                        ->whereUuid('serviceOrder')
                        ->name('service-orders.part-purchases.store');
                });

            Route::middleware('can:consume-service-parts')
                ->group(function () {
                    Route::get(
                        'service/orders/{serviceOrder:public_id}/part-requirements/{servicePartRequirement}/consume',
                        [ServicePartController::class, 'createConsumption']
                    )
                        ->whereUuid('serviceOrder')
                        ->whereNumber('servicePartRequirement')
                        ->name('service-orders.part-requirements.consume.create');

                    Route::post(
                        'service/orders/{serviceOrder:public_id}/part-requirements/{servicePartRequirement}/consume',
                        [ServicePartController::class, 'storeConsumption']
                    )
                        ->whereUuid('serviceOrder')
                        ->whereNumber('servicePartRequirement')
                        ->name('service-orders.part-requirements.consume.store');
                });
            Route::middleware('can:inspect-service-quality')
                ->group(function () {
                    Route::get(
                        'service/orders/{serviceOrder:public_id}/quality-inspections/create',
                        [ServiceCompletionController::class, 'createQuality']
                    )
                        ->whereUuid('serviceOrder')
                        ->name('service-orders.quality-inspections.create');

                    Route::post(
                        'service/orders/{serviceOrder:public_id}/quality-inspections',
                        [ServiceCompletionController::class, 'storeQuality']
                    )
                        ->whereUuid('serviceOrder')
                        ->name('service-orders.quality-inspections.store');
                });

            Route::middleware('can:deliver-service-orders')
                ->group(function () {
                    Route::get(
                        'service/orders/{serviceOrder:public_id}/delivery/create',
                        [ServiceCompletionController::class, 'createDelivery']
                    )
                        ->whereUuid('serviceOrder')
                        ->name('service-orders.delivery.create');

                    Route::post(
                        'service/orders/{serviceOrder:public_id}/delivery',
                        [ServiceCompletionController::class, 'storeDelivery']
                    )
                        ->whereUuid('serviceOrder')
                        ->name('service-orders.delivery.store');
                });
            Route::middleware('can:request-service-cancellation')
                ->group(function () {
                    Route::get(
                        'service/orders/{serviceOrder:public_id}/cancellation/request',
                        [
                            ServiceCancellationController::class,
                            'createRequest',
                        ]
                    )
                        ->whereUuid('serviceOrder')
                        ->name('service-orders.cancellation.request.create');

                    Route::post(
                        'service/orders/{serviceOrder:public_id}/cancellation/request',
                        [
                            ServiceCancellationController::class,
                            'storeRequest',
                        ]
                    )
                        ->whereUuid('serviceOrder')
                        ->name('service-orders.cancellation.request.store');
                });

            Route::middleware('can:transfer-service-custody')
                ->group(function () {
                    Route::get(
                        'service/orders/{serviceOrder:public_id}/cancellation/work-items/{serviceWorkItem}/recall',
                        [
                            ServiceCancellationController::class,
                            'createRecall',
                        ]
                    )
                        ->whereUuid('serviceOrder')
                        ->whereNumber('serviceWorkItem')
                        ->name('service-orders.cancellation.recall.create');

                    Route::post(
                        'service/orders/{serviceOrder:public_id}/cancellation/work-items/{serviceWorkItem}/recall',
                        [
                            ServiceCancellationController::class,
                            'storeRecall',
                        ]
                    )
                        ->whereUuid('serviceOrder')
                        ->whereNumber('serviceWorkItem')
                        ->name('service-orders.cancellation.recall.store');
                });

            Route::middleware('can:resolve-service-cancellation')
                ->group(function () {
                    Route::get(
                        'service/orders/{serviceOrder:public_id}/cancellation/requests/{serviceCancellationRequest}/resolution',
                        [
                            ServiceCancellationController::class,
                            'createResolution',
                        ]
                    )
                        ->whereUuid('serviceOrder')
                        ->whereNumber('serviceCancellationRequest')
                        ->name('service-orders.cancellation.resolution.create');

                    Route::post(
                        'service/orders/{serviceOrder:public_id}/cancellation/requests/{serviceCancellationRequest}/resolution',
                        [
                            ServiceCancellationController::class,
                            'storeResolution',
                        ]
                    )
                        ->whereUuid('serviceOrder')
                        ->whereNumber('serviceCancellationRequest')
                        ->name('service-orders.cancellation.resolution.store');
                });

            Route::middleware('can:return-cancelled-service-order')
                ->group(function () {
                    Route::get(
                        'service/orders/{serviceOrder:public_id}/cancellation/resolutions/{serviceCancellationResolution}/return',
                        [
                            ServiceCancellationController::class,
                            'createReturn',
                        ]
                    )
                        ->whereUuid('serviceOrder')
                        ->whereNumber('serviceCancellationResolution')
                        ->name('service-orders.cancellation.return.create');

                    Route::post(
                        'service/orders/{serviceOrder:public_id}/cancellation/resolutions/{serviceCancellationResolution}/return',
                        [
                            ServiceCancellationController::class,
                            'storeReturn',
                        ]
                    )
                        ->whereUuid('serviceOrder')
                        ->whereNumber('serviceCancellationResolution')
                        ->name('service-orders.cancellation.return.store');
                });

            Route::middleware('can:register-service-warranty-claims')
                ->group(function () {
                    Route::get(
                        'service/orders/{serviceOrder:public_id}/warranties/{serviceWarrantyGrant}/claims/create',
                        [
                            ServiceWarrantyClaimController::class,
                            'createRegistration',
                        ]
                    )
                        ->whereUuid('serviceOrder')
                        ->whereNumber('serviceWarrantyGrant')
                        ->name('service-orders.warranty-claims.create');

                    Route::post(
                        'service/orders/{serviceOrder:public_id}/warranties/{serviceWarrantyGrant}/claims',
                        [
                            ServiceWarrantyClaimController::class,
                            'storeRegistration',
                        ]
                    )
                        ->whereUuid('serviceOrder')
                        ->whereNumber('serviceWarrantyGrant')
                        ->name('service-orders.warranty-claims.store');
                });

            Route::middleware('can:resolve-service-warranty-claims')
                ->group(function () {
                    Route::get(
                        'service/orders/{serviceOrder:public_id}/warranty-claims/{serviceWarrantyClaim:public_id}/resolution',
                        [
                            ServiceWarrantyClaimController::class,
                            'createResolution',
                        ]
                    )
                        ->whereUuid('serviceOrder')
                        ->whereUuid('serviceWarrantyClaim')
                        ->name('service-orders.warranty-claims.resolution.create');

                    Route::post(
                        'service/orders/{serviceOrder:public_id}/warranty-claims/{serviceWarrantyClaim:public_id}/resolution',
                        [
                            ServiceWarrantyClaimController::class,
                            'storeResolution',
                        ]
                    )
                        ->whereUuid('serviceOrder')
                        ->whereUuid('serviceWarrantyClaim')
                        ->name('service-orders.warranty-claims.resolution.store');
                });

            Route::middleware('can:return-service-warranty-claims')
                ->group(function () {
                    Route::get(
                        'service/orders/{serviceOrder:public_id}/warranty-claims/{serviceWarrantyClaim:public_id}/return',
                        [
                            ServiceWarrantyClaimController::class,
                            'createReturn',
                        ]
                    )
                        ->whereUuid('serviceOrder')
                        ->whereUuid('serviceWarrantyClaim')
                        ->name('service-orders.warranty-claims.return.create');

                    Route::post(
                        'service/orders/{serviceOrder:public_id}/warranty-claims/{serviceWarrantyClaim:public_id}/return',
                        [
                            ServiceWarrantyClaimController::class,
                            'storeReturn',
                        ]
                    )
                        ->whereUuid('serviceOrder')
                        ->whereUuid('serviceWarrantyClaim')
                        ->name('service-orders.warranty-claims.return.store');
                });

            Route::middleware('can:view-service-evidence')
                ->group(function () {
                    Route::get(
                        'service/orders/{serviceOrder:public_id}/evidences/{evidencePublicId}/download',
                        [
                            ServiceEvidenceController::class,
                            'download',
                        ]
                    )
                        ->whereUuid('serviceOrder')
                        ->whereUuid('evidencePublicId')
                        ->name('service-orders.evidences.download');
                });

            Route::middleware('can:upload-service-evidence')
                ->group(function () {
                    Route::get(
                        'service/orders/{serviceOrder:public_id}/evidences/create',
                        [
                            ServiceEvidenceController::class,
                            'create',
                        ]
                    )
                        ->whereUuid('serviceOrder')
                        ->name('service-orders.evidences.create');

                    Route::post(
                        'service/orders/{serviceOrder:public_id}/evidences',
                        [
                            ServiceEvidenceController::class,
                            'store',
                        ]
                    )
                        ->whereUuid('serviceOrder')
                        ->name('service-orders.evidences.store');
                });

            Route::middleware('can:verify-service-evidence')
                ->group(function () {
                    Route::post(
                        'service/orders/{serviceOrder:public_id}/evidences/{evidencePublicId}/verify',
                        [
                            ServiceEvidenceController::class,
                            'verify',
                        ]
                    )
                        ->whereUuid('serviceOrder')
                        ->whereUuid('evidencePublicId')
                        ->name('service-orders.evidences.verify');
                });

            Route::middleware('can:view-inventory')
                ->group(function () {
                    Route::get(
                        'inventory/movements',
                        [
                            InventoryMovementController::class,
                            'index',
                        ]
                    )->name('inventory-movements.index');

                    Route::patch(
                        'inventory/movements/{inventoryMovement:public_id}/confirm',
                        [
                            InventoryMovementController::class,
                            'confirm',
                        ]
                    )
                        ->whereUuid('inventoryMovement')
                        ->name('inventory-movements.confirm');

                    Route::get(
                        'inventory/locations',
                        [
                            InventoryLocationController::class,
                            'index',
                        ]
                    )->name('inventory-locations.index');
                });

            Route::middleware(
                'can:draft-inventory-movements'
            )->group(function () {
                Route::get(
                    'inventory/movements/create',
                    [
                        InventoryMovementController::class,
                        'create',
                    ]
                )->name('inventory-movements.create');

                Route::post(
                    'inventory/movements',
                    [
                        InventoryMovementController::class,
                        'store',
                    ]
                )->name('inventory-movements.store');
            });

            Route::middleware(
                'can:correct-inventory'
            )->group(function () {
                Route::get(
                    'inventory/movements/{inventoryMovement:public_id}/correction',
                    [
                        InventoryMovementController::class,
                        'correction',
                    ]
                )
                    ->whereUuid('inventoryMovement')
                    ->name('inventory-movements.corrections.create');

                Route::post(
                    'inventory/movements/{inventoryMovement:public_id}/correction',
                    [
                        InventoryMovementController::class,
                        'correct',
                    ]
                )
                    ->whereUuid('inventoryMovement')
                    ->name('inventory-movements.corrections.store');
            });

            Route::middleware(
                'can:view-inventory-availability'
            )->group(function () {
                Route::get(
                    'inventory/availability',
                    [
                        InventoryAvailabilityController::class,
                        'index',
                    ]
                )->name('inventory-availability.index');
            });

            Route::middleware(
                'can:view-inventory-negative-authorizations'
            )->group(function () {
                Route::get(
                    'inventory/negative-authorizations',
                    [
                        InventoryNegativeAuthorizationController::class,
                        'index',
                    ]
                )->name('inventory-negative-authorizations.index');
            });

            Route::middleware(
                'can:request-inventory-negative'
            )->group(function () {
                Route::post(
                    'inventory/movements/{inventoryMovement:public_id}/negative-request',
                    [
                        InventoryNegativeAuthorizationController::class,
                        'store',
                    ]
                )
                    ->whereUuid('inventoryMovement')
                    ->name('inventory-negative-authorizations.store');

                Route::patch(
                    'inventory/movements/{inventoryMovement:public_id}/negative-overrides/{inventoryNegativeOverride:public_id}/confirm',
                    [
                        InventoryNegativeAuthorizationController::class,
                        'confirm',
                    ]
                )
                    ->whereUuid('inventoryMovement')
                    ->whereUuid('inventoryNegativeOverride')
                    ->name('inventory-negative-authorizations.confirm');
            });

            Route::middleware(
                'can:override-inventory-negative'
            )->group(function () {
                Route::patch(
                    'inventory/negative-authorizations/{inventoryNegativeRequest:public_id}/approve',
                    [
                        InventoryNegativeAuthorizationController::class,
                        'approve',
                    ]
                )
                    ->whereUuid('inventoryNegativeRequest')
                    ->name('inventory-negative-authorizations.approve');

                Route::patch(
                    'inventory/negative-authorizations/{inventoryNegativeRequest:public_id}/reject',
                    [
                        InventoryNegativeAuthorizationController::class,
                        'reject',
                    ]
                )
                    ->whereUuid('inventoryNegativeRequest')
                    ->name('inventory-negative-authorizations.reject');

                Route::patch(
                    'inventory/negative-overrides/{inventoryNegativeOverride:public_id}/revoke',
                    [
                        InventoryNegativeAuthorizationController::class,
                        'revoke',
                    ]
                )
                    ->whereUuid('inventoryNegativeOverride')
                    ->name('inventory-negative-authorizations.revoke');
            });

            Route::middleware(
                'can:view-inventory-negative-incidents'
            )->group(function () {
                Route::get(
                    'inventory/negative-incidents',
                    [
                        InventoryNegativeIncidentController::class,
                        'index',
                    ]
                )->name('inventory-negative-incidents.index');
            });

            Route::middleware(
                'can:review-inventory-negative-incidents'
            )->group(function () {
                Route::patch(
                    'inventory/negative-incidents/{inventoryNegativeIncident:public_id}/review',
                    [
                        InventoryNegativeIncidentController::class,
                        'review',
                    ]
                )
                    ->whereUuid('inventoryNegativeIncident')
                    ->name('inventory-negative-incidents.review');

                Route::patch(
                    'inventory/negative-incidents/{inventoryNegativeIncident:public_id}/resolve',
                    [
                        InventoryNegativeIncidentController::class,
                        'resolve',
                    ]
                )
                    ->whereUuid('inventoryNegativeIncident')
                    ->name('inventory-negative-incidents.resolve');
            });

            Route::middleware(
                'can:manage-inventory-locations'
            )->group(function () {
                Route::get(
                    'inventory/locations/create',
                    [
                        InventoryLocationController::class,
                        'create',
                    ]
                )->name('inventory-locations.create');

                Route::post(
                    'inventory/locations',
                    [
                        InventoryLocationController::class,
                        'store',
                    ]
                )->name('inventory-locations.store');

                Route::get(
                    'inventory/locations/{inventoryLocation}/edit',
                    [
                        InventoryLocationController::class,
                        'edit',
                    ]
                )
                    ->whereNumber('inventoryLocation')
                    ->name('inventory-locations.edit');

                Route::put(
                    'inventory/locations/{inventoryLocation}',
                    [
                        InventoryLocationController::class,
                        'update',
                    ]
                )
                    ->whereNumber('inventoryLocation')
                    ->name('inventory-locations.update');

                Route::patch(
                    'inventory/locations/{inventoryLocation}/toggle-active',
                    [
                        InventoryLocationController::class,
                        'toggleActive',
                    ]
                )
                    ->whereNumber('inventoryLocation')
                    ->name('inventory-locations.toggle-active');
            });

            Route::get(
                'suppliers',
                [SupplierController::class, 'index']
            )->name('suppliers.index');

            Route::get(
                'suppliers/{supplier}',
                [SupplierController::class, 'show']
            )
                ->whereNumber('supplier')
                ->name('suppliers.show');

            Route::get(
                'supplier-offers',
                [SupplierOfferController::class, 'index']
            )->name('supplier-offers.index');

            Route::get(
                'supplier-offers/{supplierOffer}',
                [SupplierOfferController::class, 'show']
            )
                ->whereNumber('supplierOffer')
                ->name('supplier-offers.show');

            Route::middleware('can:manage-commerce')
                ->group(function () {
                    Route::patch(
                        'supplier-offers/{supplierOffer}/toggle-active',
                        [
                            SupplierOfferController::class,
                            'toggleActive',
                        ]
                    )->name('supplier-offers.toggle-active');

                    Route::resource(
                        'supplier-offers',
                        SupplierOfferController::class
                    )
                        ->parameters([
                            'supplier-offers' => 'supplierOffer',
                        ])
                        ->except([
                            'index',
                            'show',
                            'destroy',
                        ]);

                    Route::patch(
                        'suppliers/{supplier}/toggle-active',
                        [
                            SupplierController::class,
                            'toggleActive',
                        ]
                    )->name('suppliers.toggle-active');

                    Route::resource(
                        'suppliers',
                        SupplierController::class
                    )->except([
                        'index',
                        'show',
                        'destroy',
                    ]);
                });

            Route::middleware('can:view-audit')
                ->group(function () {
                    Route::get(
                        '/audit-logs',
                        [AuditLogController::class, 'index']
                    )->name('audit-logs.index');

                    Route::get(
                        '/audit-logs/{auditLog}',
                        [AuditLogController::class, 'show']
                    )
                        ->whereNumber('auditLog')
                        ->name('audit-logs.show');
                });
        });

});

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])
        ->name('profile.edit');

    Route::patch('/profile', [ProfileController::class, 'update'])
        ->name('profile.update');

    Route::delete('/profile', [ProfileController::class, 'destroy'])
        ->name('profile.destroy');
});

require __DIR__.'/auth.php';
