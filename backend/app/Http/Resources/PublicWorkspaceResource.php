<?php

namespace App\Http\Resources;

use App\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A workspace on a user's public profile.
 *
 * @mixin Workspace
 */
class PublicWorkspaceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $role = $this->current_user_role ?? null;

        if ($this->owner_id === $request->user()?->getKey()) {
            $role = 'owner';
        }

        return [
            'id' => $this->id,
            'name' => $this->name,
            'entries_count' => $this->entries_count ?? 0,
            'role' => $role ?? 'viewer',
            'is_member' => $role !== null,
            'join_request_status' => $this->current_user_join_request_status ?? null,
            'created_at' => $this->created_at,
        ];
    }
}
