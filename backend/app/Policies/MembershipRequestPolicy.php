<?php

namespace App\Policies;

use App\Models\MembershipRequest;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Auth\Access\AuthorizationException;

class MembershipRequestPolicy
{
    /**
     * Determine whether the user may ask to join the workspace.
     *
     * Unlike the other gates in this app, a rejection here is a conflict
     * rather than a permission failure: the caller is free to act, they just
     * have nothing left to ask for. Each case therefore raises an
     * AuthorizationException carrying its own 409 and its own wording, so the
     * client can tell "you already own this" apart from "you already asked".
     *
     * @throws AuthorizationException
     */
    public function create(User $user, Workspace $workspace): bool
    {
        if ($workspace->owner_id === $user->id) {
            throw $this->conflict('You already own this workspace.');
        }

        if ($workspace->members()->where('users.id', $user->id)->exists()) {
            throw $this->conflict('You are already a member of this workspace.');
        }

        $alreadyPending = $workspace->membershipRequests()
            ->where('user_id', $user->id)
            ->where('status', MembershipRequest::STATUS_PENDING)
            ->exists();

        if ($alreadyPending) {
            throw $this->conflict('You already have a pending request for this workspace.');
        }

        return true;
    }

    private function conflict(string $message): AuthorizationException
    {
        return (new AuthorizationException($message))->withStatus(409);
    }
}
