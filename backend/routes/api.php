<?php

use App\Http\Controllers\Auth\GoogleController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\EmailAuthController;
use App\Http\Controllers\KnowledgeEntryController;
use App\Http\Controllers\MembershipRequestController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\WorkspaceController;
use App\Http\Controllers\WorkspaceMemberController;
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
| Public workspace lookup (via invite code)
|--------------------------------------------------------------------------
*/

Route::get('join/{inviteCode}', [
    WorkspaceController::class,
    'byInviteCode',
]);

/*
|--------------------------------------------------------------------------
| Workspace API
|--------------------------------------------------------------------------
*/

Route::middleware('auth:sanctum')->group(function () {
    Route::apiResource('workspaces', WorkspaceController::class);

    /*
    |----------------------------------------------------------------------
    | Workspace membership (owner-managed)
    |----------------------------------------------------------------------
    */

    Route::post('workspaces/{workspace}/join', [
        WorkspaceController::class,
        'join',
    ]);

    Route::post('workspaces/{workspace}/members', [
        WorkspaceMemberController::class,
        'store',
    ]);

    Route::patch('workspaces/{workspace}/members/{user}', [
        WorkspaceMemberController::class,
        'update',
    ]);

    Route::delete('workspaces/{workspace}/members/{user}', [
        WorkspaceMemberController::class,
        'destroy',
    ]);

    /*
    |----------------------------------------------------------------------
    | Membership requests (request-to-join + owner approval)
    |----------------------------------------------------------------------
    */

    Route::post('workspaces/{workspace}/join-requests', [
        MembershipRequestController::class,
        'store',
    ]);

    Route::get('workspaces/{workspace}/join-requests', [
        MembershipRequestController::class,
        'index',
    ]);

    Route::post('workspaces/{workspace}/join-requests/{membershipRequest}/approve', [
        MembershipRequestController::class,
        'approve',
    ]);

    Route::delete('workspaces/{workspace}/join-requests/{membershipRequest}', [
        MembershipRequestController::class,
        'destroy',
    ]);
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

    Route::post('workspaces/{workspace}/entries/{entry}/tags', [
        KnowledgeEntryController::class,
        'syncTags',
    ]);

    Route::post('workspaces/{workspace}/entries/{entry}/summarize', [
        KnowledgeEntryController::class,
        'summarize',
    ]);

    // Queues a regeneration of the stored summary, optionally naming the model.
    // Distinct from /summarize above, which answers immediately and stores
    // nothing.
    Route::post('workspaces/{workspace}/entries/{entry}/summary/regenerate', [
        KnowledgeEntryController::class,
        'regenerateSummary',
    ]);

    /*
    |----------------------------------------------------------------------
    | Document uploads (metadata in Postgres, binary on the documents disk)
    |----------------------------------------------------------------------
    */

    Route::get('workspaces/{workspace}/entries/{entry}/documents', [
        DocumentController::class,
        'index',
    ]);

    Route::post('workspaces/{workspace}/entries/{entry}/documents', [
        DocumentController::class,
        'store',
    ]);

    Route::get('workspaces/{workspace}/entries/{entry}/documents/{document}/download', [
        DocumentController::class,
        'download',
    ]);

    Route::delete('workspaces/{workspace}/entries/{entry}/documents/{document}', [
        DocumentController::class,
        'destroy',
    ]);

    /*
    |----------------------------------------------------------------------
    | Full-text search (entries + tags)
    |----------------------------------------------------------------------
    */

    Route::get('workspaces/{workspace}/search', SearchController::class);

    /*
    |----------------------------------------------------------------------
    | Retrieval over embedded chunks (keyword + vector, fused by rank)
    |----------------------------------------------------------------------
    */

    Route::get('workspaces/{workspace}/search/chunks', [
        SearchController::class,
        'chunks',
    ]);
});

// later
// POST /api/v1/chat
