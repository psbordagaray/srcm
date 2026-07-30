<?php

use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\BrandController;
use App\Http\Controllers\CompatibilityController;
use App\Http\Controllers\EntityController;
use App\Http\Controllers\IdentifierController;
use App\Http\Controllers\KnowledgeController;
use App\Http\Controllers\ProductCategoryController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\TechnicalModelController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/dashboard', function () {
        return view('dashboard');
    })->name('dashboard');

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
        'technical-models',
        [TechnicalModelController::class, 'index']
    )->name('technical-models.index');

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
            'technical-models/{technical_model}/toggle-active',
            [TechnicalModelController::class, 'toggleActive']
        )->name('technical-models.toggle-active');

        Route::resource(
            'technical-models',
            TechnicalModelController::class
        )->except(['index', 'destroy']);
    });

    /*
    |--------------------------------------------------------------------------
    | Immutable audit viewer
    |--------------------------------------------------------------------------
    */

    Route::middleware('can:view-audit')->group(function () {
        Route::get(
            '/audit-logs',
            [AuditLogController::class, 'index']
        )->name('audit-logs.index');

        Route::get(
            '/audit-logs/{auditLog}',
            [AuditLogController::class, 'show']
        )->name('audit-logs.show');
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
