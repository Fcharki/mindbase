<?php

namespace App\Policies;

use App\Models\KnowledgeEntry;
use App\Models\User;
use App\Models\Workspace;
use App\Services\WorkspacePermissions;

class KnowledgeEntryPolicy
{
    public function __construct(private readonly WorkspacePermissions $permissions) {}

    /** Entries are readable by any authenticated user, member or not. */
    public function view(User $user, KnowledgeEntry $entry): bool
    {
        return true;
    }

    public function create(User $user, Workspace $workspace): bool
    {
        return $this->permissions->canContribute($user, $workspace->id);
    }

    public function update(User $user, KnowledgeEntry $entry): bool
    {
        return $this->permissions->canContribute($user, $entry->workspace_id);
    }

    public function delete(User $user, KnowledgeEntry $entry): bool
    {
        return $this->permissions->canContribute($user, $entry->workspace_id);
    }

    /** Entry restoration is not currently supported. */
    public function restore(User $user, KnowledgeEntry $entry): bool
    {
        return false;
    }

    /** Entry permanent deletion is not currently supported. */
    public function forceDelete(User $user, KnowledgeEntry $entry): bool
    {
        return false;
    }
}
