<?php

namespace App\Http\Controllers;

use App\Actions\Workspace\AddWorkspaceMember;
use App\Actions\Workspace\RemoveWorkspaceMember;
use App\Actions\Workspace\UpdateWorkspaceMemberRole;
use App\Http\Requests\Workspace\AddMemberRequest;
use App\Http\Requests\Workspace\UpdateMemberRoleRequest;
use App\Http\Resources\WorkspaceMemberResource;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;

class WorkspaceMemberController extends Controller
{
    public function store(
        AddMemberRequest $request,
        Workspace $workspace
    ): JsonResponse {
        Gate::authorize('manageMembers', $workspace);

        $validated = $request->validated();

        $member = app(AddWorkspaceMember::class, [
            'workspace' => $workspace,
            'email' => $validated['email'],
            'role' => $validated['role'],
        ])->handle();

        return WorkspaceMemberResource::make($member)
            ->response()
            ->setStatusCode(201);
    }

    public function update(
        UpdateMemberRoleRequest $request,
        Workspace $workspace,
        User $user
    ): JsonResource {
        Gate::authorize('manageMembers', $workspace);

        return WorkspaceMemberResource::make(app(UpdateWorkspaceMemberRole::class, [
            'workspace' => $workspace,
            'user' => $user,
            'role' => $request->validated('role'),
        ])->handle());
    }

    public function destroy(
        Workspace $workspace,
        User $user
    ): JsonResponse {
        Gate::authorize('manageMembers', $workspace);

        app(RemoveWorkspaceMember::class, [
            'workspace' => $workspace,
            'user' => $user,
        ])->handle();

        return response()->json([
            'message' => 'Member removed successfully.',
        ]);
    }
}
