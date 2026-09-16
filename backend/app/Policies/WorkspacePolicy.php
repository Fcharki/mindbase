<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Workspace;

class WorkspacePolicy
{
    /**
     * Determine whether the user can view any workspaces
     */
    public function viewAny(User $user): bool
    {
        // Any authenticated user can list their workspaces
        return true;
    }

    /**
     * Determine whether the user can view the workspace
     */
    public function view(User $user, Workspace $workspace): bool
    {
        // Owner, admin, and member can view the workspace
        return $workspace->members()
            ->where('users.id', $user->id)
            ->exists();
    }

    /**
     * Determine whether the user can create a workspace
     */
    public function create(User $user): bool
    {
        // Any authenticated user can create a workspace
        // The creator becomes the owner
        return true;
    }

    /**
     * Determine whether the user can update the workspace
     *
     * Owner: allowed directly
     * Admin: allowed only through the owner-confirmation workflow
     * Member: not allowed
     */
    public function update(User $user, Workspace $workspace): bool
    {
        if ($workspace->owner_id === $user->id) {
            return true;
        }

        return $workspace->members()
            ->where('users.id', $user->id)
            ->wherePivot('role', 'admin')
            ->exists();
    }

    /**
     * Determine whether the user can delete the workspace
     */
    public function delete(User $user, Workspace $workspace): bool
    {
        // Only the owner can delete a workspace
        return $workspace->owner_id === $user->id;
    }

    /**
     * Workspace restoration is not currently supported
     */
    public function restore(User $user, Workspace $workspace): bool
    {
        return false;
    }

    /**
     * Workspace permanent deletion is not currently supported
     */
    public function forceDelete(User $user, Workspace $workspace): bool
    {
        return false;
    }
}