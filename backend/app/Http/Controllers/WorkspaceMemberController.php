<?php

namespace App\Http\Controllers;

use App\Models\MembershipRequest;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class WorkspaceMemberController extends Controller
{
    public function store(
        Request $request,
        Workspace $workspace
    ): JsonResponse {
        Gate::authorize('manageMembers', $workspace);

        $validated = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'role' => ['required', Rule::in(['member', 'viewer'])],
        ]);

        $user = User::where('email', $validated['email'])->first();

        if (! $user) {
            return response()->json([
                'message' => 'No account exists for that email address.',
            ], 422);
        }

        if ($workspace->members()->where('users.id', $user->id)->exists()) {
            return response()->json([
                'message' => 'That user is already a member of this workspace.',
            ], 409);
        }

        $workspace->members()->attach($user->id, [
            'role' => $validated['role'],
        ]);

        $workspace->membershipRequests()
            ->where('user_id', $user->id)
            ->where('status', MembershipRequest::STATUS_PENDING)
            ->update(['status' => MembershipRequest::STATUS_APPROVED]);

        return response()->json([
            'data' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'pivot' => [
                    'role' => $validated['role'],
                ],
            ],
        ], 201);
    }

    public function update(
        Request $request,
        Workspace $workspace,
        User $user
    ): JsonResponse {
        Gate::authorize('manageMembers', $workspace);

        if ($workspace->owner_id === $user->id) {
            return response()->json([
                'message' => 'The owner\'s role cannot be changed.',
            ], 422);
        }

        $validated = $request->validate([
            'role' => ['required', Rule::in(['member', 'viewer'])],
        ]);

        $updated = $workspace
            ->members()
            ->updateExistingPivot($user->id, [
                'role' => $validated['role'],
            ]);

        if (! $updated) {
            return response()->json([
                'message' => 'That user is not a member of this workspace.',
            ], 404);
        }

        return response()->json([
            'data' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'pivot' => [
                    'role' => $validated['role'],
                ],
            ],
        ]);
    }

    public function destroy(
        Workspace $workspace,
        User $user
    ): JsonResponse {
        Gate::authorize('manageMembers', $workspace);

        if ($workspace->owner_id === $user->id) {
            return response()->json([
                'message' => 'The workspace owner cannot be removed.',
            ], 422);
        }

        $removed = $workspace->members()->detach($user->id);

        if ($removed === 0) {
            return response()->json([
                'message' => 'That user is not a member of this workspace.',
            ], 404);
        }

        return response()->json([
            'message' => 'Member removed successfully.',
        ]);
    }
}
