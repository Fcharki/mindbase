<?php

use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\Auth\GoogleController;
use App\Http\Controllers\CommentController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\EmailAuthController;
use App\Http\Controllers\KnowledgeEntryController;
use App\Http\Controllers\MembershipRequestController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ReplyController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\TagController;
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
| Public Profile Photo
|--------------------------------------------------------------------------
| A viewer sees the workspace owner's photo on the read-only profile, so the
| image itself must not require a session. The file is streamed from private
| storage by the controller.
*/

Route::get('users/{user}/photo', [
    ProfileController::class,
    'photo',
])->name('users.photo');

/*
|--------------------------------------------------------------------------
| Profile API
|--------------------------------------------------------------------------
| Sits in the web middleware group like the other session-auth routes, since
| deleting an account has to be able to invalidate the session.
*/

Route::middleware(['web', 'auth:sanctum'])->group(function () {
    Route::get('profile', [ProfileController::class, 'show']);

    Route::patch('profile', [ProfileController::class, 'update']);

    Route::post('profile/photo', [ProfileController::class, 'updatePhoto']);

    Route::delete('profile/photo', [ProfileController::class, 'destroyPhoto']);

    Route::patch('profile/password', [ProfileController::class, 'updatePassword']);

    Route::delete('profile', [ProfileController::class, 'destroy']);
});

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

    // Tag names that look like they belong on an entry, from this workspace's
    // own vocabulary where possible.
    Route::post('workspaces/{workspace}/entries/{entry}/suggest-tags', [
        KnowledgeEntryController::class,
        'suggestTags',
    ]);

    // Toggle or set whether an entry leads the workspace list.
    Route::post('workspaces/{workspace}/entries/{entry}/pin', [
        KnowledgeEntryController::class,
        'pin',
    ]);

    // One change applied to a selection of entries (delete, tag, untag, pin).
    Route::post('workspaces/{workspace}/entries/bulk', [
        KnowledgeEntryController::class,
        'bulk',
    ]);

    // Download one entry as Markdown, HTML or JSON.
    Route::get('workspaces/{workspace}/entries/{entry}/export', [
        KnowledgeEntryController::class,
        'export',
    ]);

    /*
    |----------------------------------------------------------------------
    | Workspace tag vocabulary and hierarchy
    |----------------------------------------------------------------------
    */

    Route::get('workspaces/{workspace}/tags', [TagController::class, 'index']);

    Route::patch('workspaces/{workspace}/tags/{tag}', [TagController::class, 'update']);

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

    // Same bytes as the download, served inline so a PDF can be previewed.
    Route::get('workspaces/{workspace}/entries/{entry}/documents/{document}/preview', [
        DocumentController::class,
        'preview',
    ]);

    Route::delete('workspaces/{workspace}/entries/{entry}/documents/{document}', [
        DocumentController::class,
        'destroy',
    ]);

    /*
    |--------------------------------------------------------------------------
    | Comments and replies on an entry
    |--------------------------------------------------------------------------
    |
    | The discussion is workspace data, so these routes are gated by
    | CommentPolicy and ReplyPolicy rather than by the looser KnowledgeEntryPolicy
    | that lets any authenticated user browse entry text.
    |
    | Replies are nested under both the entry and the comment so that every
    | request carries a route-bound entry to authorize against, and so a reply
    | cannot be reached through a comment it does not belong to.
    |
    */

    Route::get('workspaces/{workspace}/entries/{entry}/comments', [
        CommentController::class,
        'index',
    ]);

    Route::post('workspaces/{workspace}/entries/{entry}/comments', [
        CommentController::class,
        'store',
    ]);

    Route::patch('workspaces/{workspace}/entries/{entry}/comments/{comment}', [
        CommentController::class,
        'update',
    ]);

    Route::delete('workspaces/{workspace}/entries/{entry}/comments/{comment}', [
        CommentController::class,
        'destroy',
    ]);

    Route::get('workspaces/{workspace}/entries/{entry}/comments/{comment}/replies', [
        ReplyController::class,
        'index',
    ]);

    Route::post('workspaces/{workspace}/entries/{entry}/comments/{comment}/replies', [
        ReplyController::class,
        'store',
    ]);

    Route::patch('workspaces/{workspace}/entries/{entry}/comments/{comment}/replies/{reply}', [
        ReplyController::class,
        'update',
    ]);

    Route::delete('workspaces/{workspace}/entries/{entry}/comments/{comment}/replies/{reply}', [
        ReplyController::class,
        'destroy',
    ]);

    /*
    |--------------------------------------------------------------------------
    | Notifications
    |--------------------------------------------------------------------------
    |
    | Not workspace scoped: a notification belongs to the reader, not to a
    | workspace, and a reader who belongs to no workspace still has an
    | inboxes worth reading. Both routes reach only the authenticated user's own
    | rows, via the notifiable relation on the User model.
    |
    */

    Route::get('notifications', [
        NotificationController::class,
        'index',
    ]);

    Route::post('notifications/read-all', [
        NotificationController::class,
        'readAll',
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

    /*
    |----------------------------------------------------------------------
    | Ask a question about the workspace, answered from its own entries
    |----------------------------------------------------------------------
    */

    Route::post('workspaces/{workspace}/ask', [
        WorkspaceController::class,
        'ask',
    ]);

    /**
     * The thread so far: recent questions asked of this workspace. Registered
     * before the {message} routes so the literal segment is never mistaken for
     * an id, even if the number constraint below ever loosens.
     */
    Route::get('workspaces/{workspace}/ask/messages', [
        WorkspaceController::class,
        'messages',
    ]);

    /**
     * Poll a question asked above, since the answer arrives via the queue
     * rather than in the response.
     */
    Route::get('workspaces/{workspace}/ask/{message}', [
        WorkspaceController::class,
        'answer',
    ])->whereNumber('message');

    /**
     * The same answer as server-sent events: deltas as the job writes them,
     * then done. Same row as the poll above, so a client may do either.
     */
    Route::get('workspaces/{workspace}/ask/{message}/stream', [
        WorkspaceController::class,
        'stream',
    ])->whereNumber('message');

    /**
     * Answer the same question again, re-retrieving passages first.
     */
    Route::post('workspaces/{workspace}/ask/{message}/regenerate', [
        WorkspaceController::class,
        'regenerate',
    ])->whereNumber('message');

    /**
     * Thumbs up or down on one answer, or clear an earlier rating.
     */
    Route::post('workspaces/{workspace}/ask/{message}/feedback', [
        WorkspaceController::class,
        'feedback',
    ])->whereNumber('message');
});

/*
|--------------------------------------------------------------------------
| Audit Logs
|--------------------------------------------------------------------------
*/

Route::middleware('auth:sanctum')->group(function () {
    Route::get('workspaces/{workspace}/audit-logs', [
        AuditLogController::class,
        'index',
    ]);

    Route::get('workspaces/{workspace}/audit-logs/{auditLog}', [
        AuditLogController::class,
        'show',
    ]);
});
