<?php

namespace App\Http\Controllers;

use App\Http\Resources\PublicUserResource;
use App\Http\Resources\UserSearchResource;
use App\Models\MembershipRequest;
use App\Models\User;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class UserController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:50'],
        ]);

        $term = ltrim(strtolower(trim((string) ($validated['q'] ?? ''))), '@');

        if ($term === '') {
            return UserSearchResource::collection(collect());
        }

        $users = User::query()
            ->where('id', '!=', $request->user()->getKey())
            ->where('username', 'like', '%'.$term.'%')
            ->withCount('ownedWorkspaces')
            ->orderBy('username')
            ->limit(20)
            ->get();

        return UserSearchResource::collection($users);
    }

    public function show(Request $request, string $username): PublicUserResource
    {
        $user = User::where('username', strtolower($username))->firstOrFail();
        $viewer = $request->user();

        $workspaces = $user->ownedWorkspaces()
            ->leftJoin('workspace_members as current_user_membership', function (JoinClause $join) use ($viewer) {
                $join->on('current_user_membership.workspace_id', '=', 'workspaces.id')
                    ->where('current_user_membership.user_id', '=', $viewer->getKey());
            })
            ->addSelect('workspaces.*')
            ->addSelect('current_user_membership.role as current_user_role')
            ->addSelect(['current_user_join_request_status' => MembershipRequest::query()
                ->select('status')
                ->whereColumn('workspace_id', 'workspaces.id')
                ->where('user_id', $viewer->getKey())
                ->where('status', MembershipRequest::STATUS_PENDING)
                ->limit(1),
            ])
            ->withCount(['knowledgeEntries as entries_count'])
            ->orderBy('workspaces.name')
            ->get();

        return PublicUserResource::make(
            $user->setRelation('ownedWorkspaces', $workspaces)
        );
    }
}
