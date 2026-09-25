<?php

namespace App\Http\Controllers;

use App\Models\MembershipRequest;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class WorkspaceController extends Controller
{
    public function byInviteCode(string $inviteCode): JsonResponse
    {
        $workspace = Workspace::where('invite_code', $inviteCode)
            ->with('owner:id,name')
            ->first();

        if (! $workspace) {
            return response()->json([
                'message' => 'Workspace not found.',
            ], 404);
        }

        return response()->json([
            'data' => [
                'id' => $workspace->id,
                'name' => $workspace->name,
                'invite_code' => $workspace->invite_code,
                'owner' => $workspace->owner,
            ],
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Workspace::class);

        $user = $request->user();

        $workspaces = Workspace::query()
            ->with([
                'owner:id,name,email',
                'members' => fn ($query) => $query->where('users.id', $user->id),
            ])
            ->withCount(['knowledgeEntries as entries_count'])
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => $workspaces,
        ]);
    }

    public function join(Request $request, Workspace $workspace): JsonResponse
    {
        $user = $request->user();

        if ($workspace->owner_id === $user->id) {
            return response()->json([
                'message' => 'You already own this workspace.',
            ], 409);
        }

        $status = DB::transaction(function () use ($user, $workspace) {
            $membership = $workspace->members()
                ->where('users.id', $user->id)
                ->first();

            if ($membership) {
                $workspace->members()->updateExistingPivot($user->id, [
                    'role' => 'member',
                ]);
            } else {
                $workspace->members()->attach($user->id, [
                    'role' => 'member',
                ]);
            }

            $workspace->membershipRequests()
                ->where('user_id', $user->id)
                ->where('status', MembershipRequest::STATUS_PENDING)
                ->update(['status' => MembershipRequest::STATUS_APPROVED]);

            return $membership ? 200 : 201;
        });

        return response()->json([
            'data' => [
                'workspace_id' => $workspace->id,
                'user_id' => $user->id,
                'role' => 'member',
            ],
        ], $status);
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('create', Workspace::class);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        $user = $request->user();

        $workspace = DB::transaction(function () use ($user, $validated) {
            $workspace = Workspace::create([
                'name' => $validated['name'],
                'owner_id' => $user->id,
            ]);

            $workspace->members()->attach($user->id, [
                'role' => 'owner',
            ]);

            return $workspace;
        });

        $workspace->load('owner:id,name,email');

        return response()->json([
            'data' => $workspace,
        ], 201);
    }

    public function show(Workspace $workspace): JsonResponse
    {
        Gate::authorize('view', $workspace);

        $workspace->load(['owner:id,name,email', 'members:id,name,email']);

        return response()->json([
            'data' => $workspace,
        ]);
    }

    public function update(
        Request $request,
        Workspace $workspace
    ): JsonResponse {
        Gate::authorize('update', $workspace);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        $workspace->update($validated);

        $workspace->load('owner:id,name,email');

        return response()->json([
            'data' => $workspace,
        ]);
    }

    public function destroy(Workspace $workspace): JsonResponse
    {
        Gate::authorize('delete', $workspace);

        $workspace->delete();

        return response()->json([
            'message' => 'Workspace deleted successfully.',
        ]);
    }
}
