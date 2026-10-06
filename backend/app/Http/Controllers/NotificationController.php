<?php

namespace App\Http\Controllers;

use App\Http\Resources\NotificationResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class NotificationController extends Controller
{
    /**
     * How many notifications one request may ask for.
     *
     * The list is a glance, not an archive: the client renders it in a panel
     * and there is no pagination control behind it. Capped so an account with
     * thousands of unread notifications cannot ask the API to serialise all of
     * them in one response.
     */
    private const MAX_LIMIT = 30;

    /**
     * The reader's own notifications, newest first.
     *
     * Scoped through the notifiable relation rather than filtered on
     * notifiable_type in a where clause, so a row belonging to another account
     * cannot be reached even if the morph columns are guessed.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $limit = min(
            max((int) $request->integer('limit', self::MAX_LIMIT), 1),
            self::MAX_LIMIT,
        );

        $notifications = $request->user()
            ->notifications()
            ->latest()
            ->limit($limit)
            ->get();

        return NotificationResource::collection($notifications);
    }

    /**
     * Mark every unread notification as read.
     *
     * Scoped to the reader, so this cannot mark anyone else's notifications
     * read. Rows already read are left alone rather than having read_at
     * rewritten, which keeps the original read time meaningful.
     */
    public function readAll(Request $request): JsonResponse
    {
        $marked = $request->user()
            ->unreadNotifications()
            ->update(['read_at' => now()]);

        return response()->json([
            'message' => 'Notifications marked as read.',
            'data' => [
                'marked' => $marked,
            ],
        ]);
    }
}
