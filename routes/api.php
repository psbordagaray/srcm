<?php

use App\Http\Controllers\MercadoPagoWebhookController;
use App\Http\Controllers\ProductionReadinessController;
use Illuminate\Support\Facades\Route;

Route::get(
    'health/ready',
    ProductionReadinessController::class
)->name('api.health.ready');

Route::post(
    'webhooks/finance/mercado-pago/{connectionPublicId}',
    MercadoPagoWebhookController::class
)
    ->whereUuid('connectionPublicId')
    ->name('api.webhooks.finance.mercado-pago');
