<?php

namespace App\Actions\Workspace;

use App\Actions\Action;
use App\Exceptions\ActionFailedException;
use App\Models\User;
use App\Models\Workspace;
use App\Services\WorkspaceIndexCache;

class RemoveWorkspaceMember implements Action
{
    public function __construct(
        private readonly Workspace $workspace,
        private readonly User $user,
    ) {}

    public function handle(): void
    {
        if ($this->workspace->owner_id === $this->user->id) {
            throw new ActionFailedException('The workspace owner cannot be removed.');
        }

        $removed = $this->workspace->members()->detach($this->user->id);

        if ($removed === 0) {
            throw new ActionFailedException(
                'That user is not a member of this workspace.',
                404
            );
        }

        app(WorkspaceIndexCache::class)->flush();
    }
}
