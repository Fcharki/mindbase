<?php

namespace App\Policies;

use App\Models\Document;
use App\Models\KnowledgeEntry;
use App\Models\User;
use App\Models\Workspace;

class DocumentPolicy
{
    /**
     * Determine whether the user can list an entry's documents
     *
     * Uploads are workspace data, so unlike entry text they are only
     * readable by members of the owning workspace.
     */
    public function viewAny(User $user, KnowledgeEntry $entry): bool
    {
        return $this->isMember($user, $entry->workspace_id);
    }

    /**
     * Determine whether the user can download the document
     */
    public function view(User $user, Document $document): bool
    {
        return $this->isMember($user, $this->workspaceIdFor($document));
    }

    /**
     * Determine whether the user can attach a document to the entry
     *
     * Owner and member can upload; viewer cannot.
     */
    public function create(User $user, KnowledgeEntry $entry): bool
    {
        return $this->hasWriteAccess($user, $entry->workspace_id);
    }

    /**
     * Determine whether the user can delete the document
     *
     * Owner and member can delete; viewer cannot.
     */
    public function delete(User $user, Document $document): bool
    {
        return $this->hasWriteAccess($user, $this->workspaceIdFor($document));
    }

    private function workspaceIdFor(Document $document): int
    {
        return (int) $document->knowledgeEntry->workspace_id;
    }

    private function isMember(User $user, int $workspaceId): bool
    {
        return $user->workspaces()
            ->where('workspaces.id', $workspaceId)
            ->exists();
    }

    private function hasWriteAccess(User $user, int $workspaceId): bool
    {
        if (Workspace::whereKey($workspaceId)->value('owner_id') === $user->id) {
            return true;
        }

        return $user->workspaces()
            ->where('workspaces.id', $workspaceId)
            ->wherePivotIn('role', ['owner', 'member'])
            ->exists();
    }
}
