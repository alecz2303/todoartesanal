<?php

use Illuminate\Support\Facades\Route;
use Webkul\MercadoPago\Http\Controllers\MercadoPagoController;

Route::group(['middleware' => ['web']], function () {

    // 1) Bagisto llama aquí (redirige a MP)
    Route::get('/mercadopago/redirect', [MercadoPagoController::class, 'redirect'])
        ->name('mercadopago.redirect');

    // 2) MP regresa aquí (aquí creamos la orden si está approved)
    Route::get('/mercadopago/return', [MercadoPagoController::class, 'return'])
        ->name('mercadopago.return');

    // 3) Webhook (confirmación real)
    Route::post('/mercadopago/webhook', [MercadoPagoController::class, 'webhook'])
        ->name('mercadopago.webhook');

});
