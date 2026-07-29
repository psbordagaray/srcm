<?php

use App\Http\Controllers\BrandController;
use App\Http\Controllers\KnowledgeController;
use App\Http\Controllers\ProductCategoryController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\TechnicalModelController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/dashboard', function () {
    return view('dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware('auth')->group(function () {
    /*
    |--------------------------------------------------------------------------
    | Product Categories
    |--------------------------------------------------------------------------
    */

    Route::patch(
        'product-categories/{product_category}/toggle-active',
        [ProductCategoryController::class, 'toggleActive']
    )->name('product-categories.toggle-active');

    Route::resource(
        'product-categories',
        ProductCategoryController::class
    );

    /*
    |--------------------------------------------------------------------------
    | Brands
    |--------------------------------------------------------------------------
    */

    Route::patch(
        'brands/{brand}/toggle-active',
        [BrandController::class, 'toggleActive']
    )->name('brands.toggle-active');

    Route::resource(
        'brands',
        BrandController::class
    );

    /*
    |--------------------------------------------------------------------------
    | Technical Models
    |--------------------------------------------------------------------------
    */

    Route::patch(
        'technical-models/{technical_model}/toggle-active',
        [TechnicalModelController::class, 'toggleActive']
    )->name('technical-models.toggle-active');

    Route::resource(
        'technical-models',
        TechnicalModelController::class
    );

    /*
    |--------------------------------------------------------------------------
    | Knowledge Engine
    |--------------------------------------------------------------------------
    */

    Route::get(
        '/explorer',
        [KnowledgeController::class, 'explorer']
    )->name('knowledge.explorer');

    Route::get(
        '/knowledge/{query}',
        [KnowledgeController::class, 'show']
    )->name('knowledge.show');

    /*
    |--------------------------------------------------------------------------
    | Profile
    |--------------------------------------------------------------------------
    */

    Route::get('/profile', [ProfileController::class, 'edit'])
        ->name('profile.edit');

    Route::patch('/profile', [ProfileController::class, 'update'])
        ->name('profile.update');

    Route::delete('/profile', [ProfileController::class, 'destroy'])
        ->name('profile.destroy');
});

require __DIR__.'/auth.php';
