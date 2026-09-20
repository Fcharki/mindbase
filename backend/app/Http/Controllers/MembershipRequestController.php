<?php

namespace App\Http\Controllers;

use App\Models\MembershipRequest;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class MembershipRequestController extends Controller
{
    public function store(
        Request $request,
        Workspace $workspace
    ): JsonResponse {
        $user = $request->user();

        if ($workspace->owner_id === $user->id) {
            return response()->json([
                'message' => 'You already own this workspace.',
            ], 409);
        }

        if ($workspace->members()->where('users.id', $user->id)->exists()) {
            return response()->json([
                'message' => 'You are already a member of this workspace.',
            ], 409);
        }

        $pending = $workspace->membershipRequests()
            ->where('user_id', $user->id)
            ->where('status', MembershipRequest::STATUS_PENDING)
            ->exists();

        if ($pending) {
            return response()->json([
                'message' => 'You already have a pending request for this workspace.',
            ], 409);
        }

        $validated = $request->validate([
            'role' => ['nullable', Rule::in(['member', 'viewer'])],
        ]);

        $membershipRequest = $workspace->membershipRequests()->create([
            'user_id' => $user->id,
            'status' => MembershipRequest::STATUS_PENDING,
            'requested_role' => $validated['role'] ?? 'member',
        ]);

        $membershipRequest->load('user:id,name,email');

        return response()->json([
            'data' => $membershipRequest,
        ], 201);
    }

    public function index(Workspace $workspace): JsonResponse
    {
        Gate::authorize('manageMembers', $workspace);

        $requests = $workspace->membershipRequests()
            ->where('status', MembershipRequest::STATUS_PENDING)
            ->with('user:id,name,email')
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'data' => $requests,
        ]);
    }

    public function approve(
        Request $request,
        Workspace $workspace,
        MembershipRequest $membershipRequest
    ): JsonResponse {
        Gate::authorize('manageMembers', $workspace);

        abort_unless(
            $membershipRequest->workspace_id === $workspace->id,
            404
        );

        if ($membershipRequest->status !== MembershipRequest::STATUS_PENDING) {
            return response()->json([
                'message' => 'This request has already been resolved.',
            ], 409);
        }

        $validated = $request->validate([
            'role' => ['nullable', Rule::in(['member', 'viewer'])],
        ]);

        $role = $validated['role'] ?? $membershipRequest->requested_role;

        $workspace->members()->syncWithoutDetaching([
            $membershipRequest->user_id => ['role' => $role],
        ]);

        $membershipRequest->update([
            'status' => MembershipRequest::STATUS_APPROVED,
        ]);

        return response()->json([
            'data' => $membershipRequest->load('user:id,name,email'),
        ]);
    }

    public function destroy(
        Workspace $workspace,
        MembershipRequest $membershipRequest
    ): JsonResponse {
        Gate::authorize('manageMembers', $workspace);

        abort_unless(
            $membershipRequest->workspace_id === $workspace->id,
            404
        );

        $membershipRequest->delete();

        return response()->json([
            'message' => 'Request rejected.',
        ]);
    }
}
