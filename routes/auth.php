<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\ConfirmablePasswordController;
use App\Http\Controllers\Auth\EmailVerificationNotificationController;
use App\Http\Controllers\Auth\EmailVerificationPromptController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\Auth\VerifyEmailController;
use Illuminate\Support\Facades\Route;
use Laravel\Passkeys\Http\Controllers\PasskeyConfirmationController;
use Laravel\Passkeys\Http\Controllers\PasskeyRegistrationController;

Route::middleware('guest')->group(function () {
    Route::get('login', [
        AuthenticatedSessionController::class,
        'create',
    ])->name('login');

    Route::post('login', [
        AuthenticatedSessionController::class,
        'store',
    ]);

    Route::get('forgot-password', [
        PasswordResetLinkController::class,
        'create',
    ])->name('password.request');

    Route::post('forgot-password', [
        PasswordResetLinkController::class,
        'store',
    ])->name('password.email');

    Route::get('reset-password/{token}', [
        NewPasswordController::class,
        'create',
    ])->name('password.reset');

    Route::post('reset-password', [
        NewPasswordController::class,
        'store',
    ])->name('password.store');
});

Route::middleware('auth')->group(function () {
    Route::get(
        'verify-email',
        EmailVerificationPromptController::class
    )->name('verification.notice');

    Route::get(
        'verify-email/{id}/{hash}',
        VerifyEmailController::class
    )
        ->middleware(['signed', 'throttle:6,1'])
        ->name('verification.verify');

    Route::post('email/verification-notification', [
        EmailVerificationNotificationController::class,
        'store',
    ])
        ->middleware('throttle:6,1')
        ->name('verification.send');

    Route::get('confirm-password', [
        ConfirmablePasswordController::class,
        'show',
    ])->name('password.confirm');

    Route::post('confirm-password', [
        ConfirmablePasswordController::class,
        'store',
    ]);

    Route::put('password', [
        PasswordController::class,
        'update',
    ])->name('password.update');

    Route::post('logout', [
        AuthenticatedSessionController::class,
        'destroy',
    ])->name('logout');
});
Route::middleware([
    'auth:'.config('passkeys.guard'),
    'verified',
])->group(function () {
    Route::get('passkeys/confirm/options', [
        PasskeyConfirmationController::class,
        'index',
    ])
        ->middleware('throttle:6,1')
        ->name('passkey.confirm-options');

    Route::post('passkeys/confirm', [
        PasskeyConfirmationController::class,
        'store',
    ])
        ->middleware('throttle:6,1')
        ->name('passkey.confirm');

    Route::middleware([
        'password.confirm',
        'throttle:6,1',
    ])->group(function () {
        Route::get('user/passkeys/options', [
            PasskeyRegistrationController::class,
            'index',
        ])->name('passkey.registration-options');

        Route::post('user/passkeys', [
            PasskeyRegistrationController::class,
            'store',
        ])->name('passkey.store');

        Route::delete('user/passkeys/{passkey}', [
            PasskeyRegistrationController::class,
            'destroy',
        ])->name('passkey.destroy');
    });
});
