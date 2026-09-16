<?php

use App\Http\Controllers\Auth\GoogleController;
use App\Http\Controllers\EmailAuthController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Email Authentication
|--------------------------------------------------------------------------
*/

Route::prefix('auth')->group(function () {

    // Register / Login
    Route::post('/register', [EmailAuthController::class, 'register']);
    Route::post('/login', [EmailAuthController::class, 'login']);

    // Password reset
    Route::post('/forgot-password', [
        EmailAuthController::class,
        'forgotPassword'
    ]);

    Route::post('/reset-password', [
        EmailAuthController::class,
        'resetPassword'
    ]);

    // Email verification
    Route::get('/verify-email/{id}/{hash}', [
        EmailAuthController::class,
        'verifyEmail'
    ])->middleware('signed')->name('auth.verification.verify');

    /*
    |--------------------------------------------------------------------------
    | Google Authentication
    |--------------------------------------------------------------------------
    */

 Route::middleware('web')->group(function () {
    Route::get('/google', [GoogleController::class, 'redirect']);
    Route::get('/google/callback', [GoogleController::class, 'callback']);
});

    /*
    |--------------------------------------------------------------------------
    | Authenticated Routes
    |--------------------------------------------------------------------------
    */

    Route::middleware('auth:sanctum')->group(function () {

        Route::get('/user', [
            EmailAuthController::class,
            'me'
        ]);

        Route::post('/logout', [
            EmailAuthController::class,
            'logout'
        ]);

        Route::post('/resend-verification', [
            EmailAuthController::class,
            'resendVerificationEmail'
        ]);
    });
});
