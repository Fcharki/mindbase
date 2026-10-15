<?php

namespace App\Actions\Workspace;

use App\Actions\Action;
use App\Exceptions\ActionFailedException;
use App\Models\MembershipRequest;
use App\Models\User;
use App\Models\Workspace;
use App\Services\WorkspaceIndexCache;

class AddWorkspaceMember implements Action
{
    public function __construct(
        private readonly Workspace $workspace,
        private readonly string $email,
        private readonly string $role,
    ) {}

    /**
     * Add an existing account to the workspace at the given role.
     *
     * Resolving a pending join request here means an owner can accept somebody
     * either by replying to their request or by adding them by email, and end
     * up in the same state either way.
     *
     * @return User the member, read back through the relation so the pivot is
     *              populated for WorkspaceMemberResource
     */
    public function handle(): User
    {
        $user = User::where('email', $this->email)->first();

        if (! $user) {
            throw new ActionFailedException(
                'No account exists for that email address.'
            );
        }

        if ($this->workspace->members()->where('users.id', $user->id)->exists()) {
            throw new ActionFailedException(
                'That user is already a member of this workspace.',
                409
            );
        }

        $this->workspace->members()->attach($user->id, [
            'role' => $this->role,
        ]);

        $this->workspace->membershipRequests()
            ->where('user_id', $user->id)
            ->where('status', MembershipRequest::STATUS_PENDING)
            ->update(['status' => MembershipRequest::STATUS_APPROVED]);

        app(WorkspaceIndexCache::class)->flush();

        return $this->workspace->members()
            ->where('users.id', $user->id)
            ->firstOrFail();
    }
}
