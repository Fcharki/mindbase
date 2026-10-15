<?php

namespace App\Actions\Workspace;

use App\Actions\Action;
use App\Exceptions\ActionFailedException;
use App\Models\User;
use App\Models\Workspace;
use App\Services\WorkspaceIndexCache;

class UpdateWorkspaceMemberRole implements Action
{
    public function __construct(
        private readonly Workspace $workspace,
        private readonly User $user,
        private readonly string $role,
    ) {}

    /**
     * Change an existing member's role.
     *
     * @return User the member, read back through the relation so the pivot is
     *              populated for WorkspaceMemberResource
     */
    public function handle(): User
    {
        if ($this->workspace->owner_id === $this->user->id) {
            throw new ActionFailedException('The owner\'s role cannot be changed.');
        }

        $updated = $this->workspace
            ->members()
            ->updateExistingPivot($this->user->id, [
                'role' => $this->role,
            ]);

        if (! $updated) {
            throw new ActionFailedException(
                'That user is not a member of this workspace.',
                404
            );
        }

        app(WorkspaceIndexCache::class)->flush();

        return $this->workspace->members()
            ->where('users.id', $this->user->id)
            ->firstOrFail();
    }
}
