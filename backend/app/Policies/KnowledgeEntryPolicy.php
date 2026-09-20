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
     * Owner, member, and viewer can all view entries.
     */
    public function view(User $user, KnowledgeEntry $entry): bool
    {
        return $this->isMember($user, $entry);
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

    private function isMember(User $user, KnowledgeEntry $entry): bool
    {
        return $this->membershipExists($user, $entry->workspace_id);
    }

    private function isMemberWithWriteAccess(
        User $user,
        KnowledgeEntry|Workspace $model
    ): bool {
        $workspaceId = $model instanceof Workspace
            ? $model->id
            : $model->workspace_id;

        return $this->isOwner($user, $workspaceId)
            || (
                $this->membershipExists($user, $workspaceId)
                && ! $this->isViewer($user, $workspaceId)
            );
    }

    private function isOwner(User $user, int $workspaceId): bool
    {
        return Workspace::whereKey($workspaceId)->value('owner_id') === $user->id;
    }

    private function membershipExists(User $user, int $workspaceId): bool
    {
        return $user->workspaces()
            ->where('workspaces.id', $workspaceId)
            ->exists();
    }

    private function isViewer(User $user, int $workspaceId): bool
    {
        return $user->workspaces()
            ->where('workspaces.id', $workspaceId)
            ->wherePivot('role', 'viewer')
            ->exists();
    }
}
