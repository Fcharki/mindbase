<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Notifications\DatabaseNotification;

/**
 * One stored notification.
 *
 * @mixin DatabaseNotification
 */
class NotificationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // The payload is cast to an array by DatabaseNotification, but a
        // notification written by an older version may not have every key. Each
        // one is defaulted here rather than assumed, so one stale row cannot
        // blank the whole list.
        $data = is_array($this->data) ? $this->data : [];

        return [
            'id' => $this->id,
            // The class name. The client matches on the 'kind' inside data for
            // wording and on this for nothing, but it is what makes an unknown
            // notification type identifiable rather than unrenderable.
            'type' => $this->type,
            'kind' => $data['kind'] ?? 'unknown',
            'actor_username' => $data['actor_username'] ?? $data['actor_name'] ?? null,
            'entry_title' => $data['entry_title'] ?? null,
            'excerpt' => $data['excerpt'] ?? null,
            'workspace_id' => $data['workspace_id'] ?? null,
            'entry_id' => $data['entry_id'] ?? null,
            'comment_id' => $data['comment_id'] ?? null,
            'reply_id' => $data['reply_id'] ?? null,
            'read_at' => $this->read_at,
            'created_at' => $this->created_at,
        ];
    }
}
