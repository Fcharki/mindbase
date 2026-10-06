<?php

namespace App\Policies;

use App\Models\KnowledgeEntry;
use App\Models\Reply;
use App\Models\User;
use App\Services\WorkspacePermissions;

class ReplyPolicy
{
    public function __construct(private readonly WorkspacePermissions $permissions) {}

    /** A reply is only as visible as the comment it hangs off. */
    public function viewAny(User $user, KnowledgeEntry $entry): bool
    {
        return $this->permissions->isMember($user, $entry->workspace_id);
    }

    /** Same rule as commenting: viewer included. */
    public function create(User $user, KnowledgeEntry $entry): bool
    {
        return $this->permissions->canDiscuss($user, $entry->workspace_id);
    }

    /** Author only, matching CommentPolicy::update. */
    public function update(User $user, Reply $reply, KnowledgeEntry $entry): bool
    {
        return $reply->user_id !== null && $reply->user_id === $user->id;
    }

    /** Author or workspace owner, matching CommentPolicy::delete. */
    public function delete(User $user, Reply $reply, KnowledgeEntry $entry): bool
    {
        if ($reply->user_id !== null && $reply->user_id === $user->id) {
            return true;
        }

        return $this->permissions->isOwner($user, $entry->workspace_id);
    }
}
