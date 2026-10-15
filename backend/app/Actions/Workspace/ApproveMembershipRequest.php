<?php

namespace App\Actions\Workspace;

use App\Actions\Action;
use App\Exceptions\ActionFailedException;
use App\Models\MembershipRequest;
use App\Models\Workspace;
use App\Services\WorkspaceIndexCache;
use Illuminate\Support\Facades\DB;

class ApproveMembershipRequest implements Action
{
    public function __construct(
        private readonly Workspace $workspace,
        private readonly MembershipRequest $membershipRequest,
        private readonly ?string $role = null,
    ) {}

    /**
     * Turn a pending join request into a membership.
     *
     * The role falls back to whatever the requester asked for, so an owner who
     * just wants to accept does not have to restate it.
     */
    public function handle(): MembershipRequest
    {
        if ($this->membershipRequest->status !== MembershipRequest::STATUS_PENDING) {
            throw new ActionFailedException(
                'This request has already been resolved.',
                409
            );
        }

        DB::transaction(function (): void {
            $this->workspace->members()->syncWithoutDetaching([
                $this->membershipRequest->user_id => [
                    'role' => $this->role ?? $this->membershipRequest->requested_role,
                ],
            ]);

            $this->membershipRequest->update([
                'status' => MembershipRequest::STATUS_APPROVED,
            ]);
        });

        app(WorkspaceIndexCache::class)->flush();

        return $this->membershipRequest;
    }
}
