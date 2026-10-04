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
     *
     * The owning entry is passed in rather than resolved from the document.
     * Every caller has it route-bound and has already asserted the document
     * belongs to it, so reading $document->knowledgeEntry here would spend a
     * query to re-derive something the caller just proved — and would do it
     * once per document on any path that authorizes a collection.
     */
    public function view(
        User $user,
        Document $document,
        KnowledgeEntry $entry
    ): bool {
        return $this->isMember($user, (int) $entry->workspace_id);
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
    public function delete(
        User $user,
        Document $document,
        KnowledgeEntry $entry
    ): bool {
        return $this->hasWriteAccess($user, (int) $entry->workspace_id);
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
