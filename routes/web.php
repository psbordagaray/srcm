<?php

use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\BrandController;
use App\Http\Controllers\CatalogProductController;
use App\Http\Controllers\InventoryAvailabilityController;
use App\Http\Controllers\InventoryLocationController;
use App\Http\Controllers\InventoryMovementController;
use App\Http\Controllers\InventoryNegativeIncidentController;
use App\Http\Controllers\SupplierController;
use App\Http\Controllers\SupplierOfferController;
use App\Http\Controllers\CompatibilityController;
use App\Http\Controllers\EntityController;
use App\Http\Controllers\IdentifierController;
use App\Http\Controllers\KnowledgeController;
use App\Http\Controllers\ManufacturerController;
use App\Http\Controllers\OrganizationController;
use App\Http\Controllers\ProductCategoryController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\TechnicalModelController;
use App\Http\Middleware\RequireOrganization;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
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
            Route::get('/dashboard', function () {
                return view('dashboard');
            })->name('dashboard');

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
                            'supplier-offers' =>
                                'supplierOffer',
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
