<?php

namespace App\Http\Resources;

use App\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A workspace as the API exposes it.
 *
 * Optional blocks are emitted only when the caller eager-loaded them, so the
 * same resource serves both the index and the detail endpoint without the two
 * silently disagreeing about the shape of a workspace.
 *
 * @mixin Workspace
 */
class WorkspaceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $role = $this->roleFor($request);

        return [
            'id' => $this->id,
            'name' => $this->name,
            'invite_code' => $this->invite_code,
            'owner_id' => $this->owner_id,
            'role' => $role ?? 'viewer',
            // Role alone cannot say whether the caller belongs here: a genuine
            // viewer and a stranger are both reported as "viewer", and the
            // frontend needs to tell those apart to explain a 403.
            'is_member' => $role !== null,
            'owner' => UserWithEmailResource::make($this->whenLoaded('owner')),
            // Counted under an alias, so it is matched by name rather than
            // through whenCounted(), which derives the attribute from the
            // relation name.
            'entries_count' => $this->when(
                isset($this->entries_count),
                $this->entries_count
            ),
            'members' => WorkspaceMemberResource::collection(
                $this->whenLoaded('members')
            ),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }

    /**
     * The caller's role, or null when they do not belong to this workspace.
     *
     * Each source corresponds to a way a workspace can reach this resource: the
     * owner check needs nothing loaded, "current_user_role" is selected by
     * queries that join the caller's membership row, and a pivot or members
     * relation covers workspaces fetched through a relation.
     */
    private function roleFor(Request $request): ?string
    {
        $userId = $request->user()?->getKey();

        if ($userId === null) {
            return null;
        }

        if ($this->owner_id === $userId) {
            return 'owner';
        }

        return $this->resource->current_user_role
            ?? $this->resource->pivot?->role
            ?? $this->roleFromLoadedMembers($userId);
    }

    private function roleFromLoadedMembers(int|string|null $userId): ?string
    {
        if ($userId === null || ! $this->resource->relationLoaded('members')) {
            return null;
        }

        return $this->resource->members->firstWhere('id', $userId)?->pivot?->role;
    }
}
