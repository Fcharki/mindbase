<?php

namespace App\Http\Controllers;

use App\Actions\Workspace\ApproveMembershipRequest;
use App\Actions\Workspace\RequestWorkspaceMembership;
use App\Http\Requests\MembershipRequest\ApproveJoinRequest;
use App\Http\Requests\MembershipRequest\StoreJoinRequest;
use App\Http\Resources\MembershipRequestResource;
use App\Models\AuditLog;
use App\Models\MembershipRequest;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;

class MembershipRequestController extends Controller
{
    public function store(
        StoreJoinRequest $request,
        Workspace $workspace
    ): JsonResponse {
        Gate::authorize('create', [MembershipRequest::class, $workspace]);

        $membershipRequest = app(RequestWorkspaceMembership::class, [
            'workspace' => $workspace,
            'user' => $request->user(),
            'role' => $request->validated('role'),
        ])->handle();

        AuditLog::record(
            $workspace->id,
            'requested',
            AuditLog::ENTITY_JOIN_REQUEST,
            $membershipRequest->id,
            [
                'name' => $request->user()->name,
                'email' => $request->user()->email,
                'role' => $membershipRequest->requested_role,
            ],
        );

        return MembershipRequestResource::make(
            $membershipRequest->load('user:id,name,email')
        )->response()->setStatusCode(201);
    }

    public function index(Workspace $workspace): AnonymousResourceCollection
    {
        Gate::authorize('manageMembers', $workspace);

        $requests = $workspace->membershipRequests()
            ->where('status', MembershipRequest::STATUS_PENDING)
            ->with('user:id,name,email')
            ->orderBy('created_at', 'desc')
            ->get();

        return MembershipRequestResource::collection($requests);
    }

    public function approve(
        ApproveJoinRequest $request,
        Workspace $workspace,
        MembershipRequest $membershipRequest
    ): JsonResource {
        Gate::authorize('manageMembers', $workspace);

        abort_unless(
            $membershipRequest->workspace_id === $workspace->id,
            404
        );

        $resolved = app(ApproveMembershipRequest::class, [
            'workspace' => $workspace,
            'membershipRequest' => $membershipRequest,
            'role' => $request->validated('role'),
        ])->handle();

        AuditLog::record(
            $workspace->id,
            'approved',
            AuditLog::ENTITY_JOIN_REQUEST,
            $resolved->id,
            [
                'name' => $resolved->user?->name,
                'email' => $resolved->user?->email,
                'role' => $request->validated('role') ?? $resolved->requested_role,
            ],
        );

        return MembershipRequestResource::make(
            $resolved->load('user:id,name,email')
        );
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

        AuditLog::record(
            $workspace->id,
            'rejected',
            AuditLog::ENTITY_JOIN_REQUEST,
            $membershipRequest->id,
            [
                'name' => $membershipRequest->user?->name,
                'email' => $membershipRequest->user?->email,
            ],
        );

        $membershipRequest->delete();

        return response()->json([
            'message' => 'Request rejected.',
        ]);
    }
}
