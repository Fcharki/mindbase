<?php

namespace App\Http\Controllers;

use App\Actions\Workspace\CreateWorkspace;
use App\Actions\Workspace\JoinWorkspace;
use App\Http\Requests\Workspace\WorkspaceRequest;
use App\Http\Resources\JoinableWorkspaceResource;
use App\Http\Resources\WorkspaceResource;
use App\Models\Workspace;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;

class WorkspaceController extends Controller
{
    public function byInviteCode(string $inviteCode): JsonResource
    {
        $workspace = Workspace::where('invite_code', $inviteCode)
            ->with('owner:id,name')
            ->first();

        abort_if($workspace === null, 404, 'Workspace not found.');

        return JoinableWorkspaceResource::make($workspace);
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Workspace::class);

        $user = $request->user();

        $workspaces = Workspace::query()
            ->leftJoin('workspace_members as current_user_membership', function (JoinClause $join) use ($user) {
                $join->on('current_user_membership.workspace_id', '=', 'workspaces.id')
                    ->where('current_user_membership.user_id', '=', $user->id);
            })
            ->addSelect('workspaces.*')
            ->addSelect('current_user_membership.role as current_user_role')
            ->with('owner:id,name,email')
            ->withCount(['knowledgeEntries as entries_count'])
            ->orderBy('workspaces.name')
            ->get();

        return WorkspaceResource::collection($workspaces);
    }

    public function join(Request $request, Workspace $workspace): JsonResponse
    {
        $user = $request->user();

        $created = app(JoinWorkspace::class, [
            'workspace' => $workspace,
            'user' => $user,
        ])->handle();

        return response()->json([
            'data' => [
                'workspace_id' => $workspace->id,
                'user_id' => $user->id,
                'role' => 'member',
            ],
        ], $created ? 201 : 200);
    }

    public function store(WorkspaceRequest $request): JsonResponse
    {
        Gate::authorize('create', Workspace::class);

        $user = $request->user();

        $workspace = app(CreateWorkspace::class, [
            'name' => $request->validated('name'),
            'owner' => $user,
        ])->handle();

        return WorkspaceResource::make(
            $workspace->load('owner:id,name,email')
        )->response()->setStatusCode(201);
    }

    public function show(Workspace $workspace): WorkspaceResource
    {
        Gate::authorize('view', $workspace);

        $workspace->load(['owner:id,name,email', 'members:id,name,email']);

        return WorkspaceResource::make($workspace);
    }

    public function update(
        WorkspaceRequest $request,
        Workspace $workspace
    ): WorkspaceResource {
        Gate::authorize('update', $workspace);

        $workspace->update($request->validated());

        $workspace->load('owner:id,name,email');

        return WorkspaceResource::make($workspace);
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
