<?php

use App\Http\Controllers\MercadoPagoWebhookController;
use Illuminate\Support\Facades\Route;

Route::post(
    'webhooks/finance/mercado-pago/{connectionPublicId}',
    MercadoPagoWebhookController::class
)
    ->whereUuid('connectionPublicId')
    ->name('api.webhooks.finance.mercado-pago');
