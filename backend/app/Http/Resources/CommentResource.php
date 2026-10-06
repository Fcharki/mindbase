<?php

namespace App\Http\Resources;

use App\Models\Comment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Comment
 */
class CommentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'knowledge_entry_id' => $this->knowledge_entry_id,
            'user_id' => $this->user_id,
            'body' => $this->body,
            // Absent once the account is deleted. The client falls back to a
            // placeholder rather than the resource inventing a name here, so
            // the two agree on what a missing author looks like.
            'user' => UserSummaryResource::make($this->whenLoaded('user')),
            'replies' => ReplyResource::collection($this->whenLoaded('replies')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
