<?php

namespace App\Http\Controllers;

use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class WorkspaceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Workspace::class);

        $workspaces = $request->user()
            ->workspaces()
            ->with('owner:id,name,email')
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => $workspaces,
        ]);
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