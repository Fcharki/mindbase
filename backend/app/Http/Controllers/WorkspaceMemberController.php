<?php

namespace App\Http\Controllers;

use App\Actions\Workspace\AddWorkspaceMember;
use App\Actions\Workspace\RemoveWorkspaceMember;
use App\Actions\Workspace\UpdateWorkspaceMemberRole;
use App\Http\Requests\Workspace\AddMemberRequest;
use App\Http\Requests\Workspace\UpdateMemberRoleRequest;
use App\Http\Resources\WorkspaceMemberResource;
use App\Models\AuditLog;
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

        AuditLog::record(
            $workspace->id,
            'added',
            AuditLog::ENTITY_MEMBER,
            $member->id,
            [
                'username' => $member->username,
                'email' => $member->email,
                'role' => $validated['role'],
            ],
        );

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

        $previousRole = $workspace->members()
            ->where('users.id', $user->id)
            ->first()?->pivot->role;

        $member = app(UpdateWorkspaceMemberRole::class, [
            'workspace' => $workspace,
            'user' => $user,
            'role' => $request->validated('role'),
        ])->handle();

        AuditLog::record(
            $workspace->id,
            'role_changed',
            AuditLog::ENTITY_MEMBER,
            $member->id,
            [
                'username' => $member->username,
                'email' => $member->email,
                'from' => $previousRole,
                'to' => $member->pivot->role,
            ],
        );

        return WorkspaceMemberResource::make($member);
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

        AuditLog::record(
            $workspace->id,
            'removed',
            AuditLog::ENTITY_MEMBER,
            $user->id,
            [
                'username' => $user->username,
                'email' => $user->email,
            ],
        );

        return response()->json([
            'message' => 'Member removed successfully.',
        ]);
    }
}
