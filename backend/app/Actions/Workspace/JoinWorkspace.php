<?php

namespace App\Actions\Workspace;

use App\Actions\Action;
use App\Exceptions\ActionFailedException;
use App\Models\MembershipRequest;
use App\Models\User;
use App\Models\Workspace;
use App\Services\WorkspaceIndexCache;
use Illuminate\Support\Facades\DB;

class JoinWorkspace implements Action
{
    public function __construct(
        private readonly Workspace $workspace,
        private readonly User $user,
    ) {}

    /**
     * Join with an invite code, always as a member.
     *
     * A viewer who redeems an invite is promoted, since presenting a valid
     * invite code is the intent to take part. Any pending join request is
     * resolved at the same time so the owner is not left with a stale one.
     *
     * @return bool whether the caller was newly added, which the caller maps to
     *              a 201 rather than a 200
     */
    public function handle(): bool
    {
        if ($this->workspace->owner_id === $this->user->id) {
            throw new ActionFailedException('You already own this workspace.', 409);
        }

        return DB::transaction(function (): bool {
            $membership = $this->workspace->members()
                ->where('users.id', $this->user->id)
                ->first();

            if ($membership) {
                $this->workspace->members()->updateExistingPivot($this->user->id, [
                    'role' => 'member',
                ]);
            } else {
                $this->workspace->members()->attach($this->user->id, [
                    'role' => 'member',
                ]);
            }

            $this->resolvePendingRequests();

            app(WorkspaceIndexCache::class)->flush();

            return $membership === null;
        });
    }

    private function resolvePendingRequests(): void
    {
        $this->workspace->membershipRequests()
            ->where('user_id', $this->user->id)
            ->where('status', MembershipRequest::STATUS_PENDING)
            ->update(['status' => MembershipRequest::STATUS_APPROVED]);
    }
}
