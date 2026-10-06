<?php

namespace App\Policies;

use App\Models\Comment;
use App\Models\KnowledgeEntry;
use App\Models\User;
use App\Services\WorkspacePermissions;

class CommentPolicy
{
    public function __construct(private readonly WorkspacePermissions $permissions) {}

    /** A discussion is workspace data, so only members can read it. */
    public function viewAny(User $user, KnowledgeEntry $entry): bool
    {
        return $this->permissions->isMember($user, $entry->workspace_id);
    }

    public function view(User $user, Comment $comment, KnowledgeEntry $entry): bool
    {
        return $this->permissions->isMember($user, $entry->workspace_id);
    }

    /** Every member can, viewer included: read-only applies to documents, not speech. */
    public function create(User $user, KnowledgeEntry $entry): bool
    {
        return $this->permissions->canDiscuss($user, $entry->workspace_id);
    }

    /** Author only, and only while the author still exists. */
    public function update(User $user, Comment $comment, KnowledgeEntry $entry): bool
    {
        return $comment->user_id !== null && $comment->user_id === $user->id;
    }

    /** Author or workspace owner, so a disagreement cannot be deleted away. */
    public function delete(User $user, Comment $comment, KnowledgeEntry $entry): bool
    {
        if ($comment->user_id !== null && $comment->user_id === $user->id) {
            return true;
        }

        return $this->permissions->isOwner($user, $entry->workspace_id);
    }
}
