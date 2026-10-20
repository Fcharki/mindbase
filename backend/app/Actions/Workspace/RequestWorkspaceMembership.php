<?php

namespace App\Actions\Workspace;

use App\Actions\Action;
use App\Models\MembershipRequest;
use App\Models\User;
use App\Models\Workspace;
use App\Services\WorkspaceIndexCache;

class RequestWorkspaceMembership implements Action
{
    public function __construct(
        private readonly Workspace $workspace,
        private readonly User $user,
        private readonly ?string $role = null,
    ) {}

    /**
     * Ask to join a workspace the caller is not a member of.
     *
     * Whether the caller is allowed to ask at all is decided by
     * MembershipRequestPolicy::create(), which the controller runs as a gate
     * before reaching this action. What is left here is only the write itself,
     * so that the policy owns the rules and this owns the record.
     */
    public function handle(): MembershipRequest
    {
        $membershipRequest = $this->workspace->membershipRequests()->create([
            'user_id' => $this->user->id,
            'status' => MembershipRequest::STATUS_PENDING,
            'requested_role' => $this->role ?? 'member',
        ]);

        // The index exposes the caller's pending request, so the version that
        // caches their workspace list has to be replaced before they reload.
        app(WorkspaceIndexCache::class)->flush();

        return $membershipRequest;
    }
}
