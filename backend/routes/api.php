<?php

use App\Http\Controllers\Auth\GoogleController;
use App\Http\Controllers\EmailAuthController;
use App\Http\Controllers\KnowledgeEntryController;
use App\Http\Controllers\WorkspaceController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Session / Cookie Authentication
|--------------------------------------------------------------------------
*/

Route::middleware('web')->group(function () {

    Route::prefix('auth')->group(function () {

        /*
        |----------------------------------------------------------------------
        | Email Authentication
        |----------------------------------------------------------------------
        */

        Route::post('/register', [
            EmailAuthController::class,
            'register',
        ]);

        Route::post('/login', [
            EmailAuthController::class,
            'login',
        ]);

        /*
        |----------------------------------------------------------------------
        | Password Reset
        |----------------------------------------------------------------------
        */

        Route::post('/forgot-password', [
            EmailAuthController::class,
            'forgotPassword',
        ]);

        Route::post('/reset-password', [
            EmailAuthController::class,
            'resetPassword',
        ]);

        /*
        |----------------------------------------------------------------------
        | Email Verification
        |----------------------------------------------------------------------
        */

        Route::get('/verify-email/{id}/{hash}', [
            EmailAuthController::class,
            'verifyEmail',
        ])
            ->middleware('signed')
            ->name('auth.verification.verify');

        /*
        |----------------------------------------------------------------------
        | Google Authentication
        |----------------------------------------------------------------------
        */

        Route::get('/google', [
            GoogleController::class,
            'redirect',
        ]);

        Route::get('/google/callback', [
            GoogleController::class,
            'callback',
        ]);

        /*
        |----------------------------------------------------------------------
        | Authenticated Routes
        |----------------------------------------------------------------------
        */

        Route::middleware('auth:sanctum')->group(function () {

            Route::get('/user', [
                EmailAuthController::class,
                'me',
            ]);

            Route::post('/logout', [
                EmailAuthController::class,
                'logout',
            ]);

            Route::post('/resend-verification', [
                EmailAuthController::class,
                'resendVerificationEmail',
            ]);
        });
    });
});

/*
|--------------------------------------------------------------------------
| Workspace API
|--------------------------------------------------------------------------
*/

Route::middleware('auth:sanctum')->group(function () {
    Route::apiResource('workspaces', WorkspaceController::class);
});

/*
|--------------------------------------------------------------------------
| Knowledge entries API
|--------------------------------------------------------------------------
*/

Route::middleware('auth:sanctum')->group(function () {
    Route::apiResource(
        'workspaces.entries',
        KnowledgeEntryController::class
    );
});