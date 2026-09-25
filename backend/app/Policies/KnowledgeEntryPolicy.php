<?php

namespace App\Policies;

use App\Models\KnowledgeEntry;
use App\Models\User;
use App\Models\Workspace;

class KnowledgeEntryPolicy
{
    /**
     * Determine whether the user can view any entries in the workspace
     *
     * Authenticated users can browse entries as viewers by default.
     */
    public function view(User $user, KnowledgeEntry $entry): bool
    {
        return true;
    }

    /**
     * Determine whether the user can create an entry in the workspace
     *
     * Owner and member can create; viewer cannot.
     */
    public function create(User $user, Workspace $workspace): bool
    {
        return $this->isMemberWithWriteAccess($user, $workspace);
    }

    /**
     * Determine whether the user can update an entry
     */
    public function update(User $user, KnowledgeEntry $entry): bool
    {
        return $this->isMemberWithWriteAccess($user, $entry);
    }

    /**
     * Determine whether the user can delete an entry
     */
    public function delete(User $user, KnowledgeEntry $entry): bool
    {
        return $this->isMemberWithWriteAccess($user, $entry);
    }

    /**
     * Entry restoration is not currently supported
     */
    public function restore(User $user, KnowledgeEntry $entry): bool
    {
        return false;
    }

    /**
     * Entry permanent deletion is not currently supported
     */
    public function forceDelete(User $user, KnowledgeEntry $entry): bool
    {
        return false;
    }

    private function isMemberWithWriteAccess(
        User $user,
        KnowledgeEntry|Workspace $model
    ): bool {
        $workspaceId = $model instanceof Workspace
            ? $model->id
            : $model->workspace_id;

        return $this->isOwner($user, $workspaceId)
            || $user->workspaces()
                ->where('workspaces.id', $workspaceId)
                ->wherePivotIn('role', ['owner', 'member'])
                ->exists();
    }

    private function isOwner(User $user, int $workspaceId): bool
    {
        return Workspace::whereKey($workspaceId)->value('owner_id') === $user->id;
    }
}
