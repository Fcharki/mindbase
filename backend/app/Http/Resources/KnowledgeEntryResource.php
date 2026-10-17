<?php

namespace App\Http\Resources;

use App\Models\KnowledgeEntry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin KnowledgeEntry
 */
class KnowledgeEntryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'content' => $this->content,
            'created_by' => $this->created_by,
            'workspace_id' => $this->workspace_id,
            'is_pinned' => (bool) $this->is_pinned,
            'creator' => UserSummaryResource::make($this->whenLoaded('creator')),
            'tags' => TagResource::collection($this->whenLoaded('tags')),
            'tags_count' => $this->whenCounted('tags'),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
