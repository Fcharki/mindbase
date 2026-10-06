<?php

namespace App\Policies;

use App\Models\Document;
use App\Models\KnowledgeEntry;
use App\Models\User;
use App\Services\WorkspacePermissions;

class DocumentPolicy
{
    public function __construct(private readonly WorkspacePermissions $permissions) {}

    /** Listing is membership: knowing a document exists leaks nothing useful. */
    public function viewAny(User $user, KnowledgeEntry $entry): bool
    {
        return $this->permissions->isMember($user, $entry->workspace_id);
    }

    /** Taking the bytes is a capability, and a viewer is denied it. */
    public function view(
        User $user,
        Document $document,
        KnowledgeEntry $entry
    ): bool {
        return $this->permissions->canDownload($user, $entry->workspace_id);
    }

    public function create(User $user, KnowledgeEntry $entry): bool
    {
        return $this->permissions->canContribute($user, $entry->workspace_id);
    }

    public function delete(
        User $user,
        Document $document,
        KnowledgeEntry $entry
    ): bool {
        return $this->permissions->canContribute($user, $entry->workspace_id);
    }
}
