<?php

namespace App\Http\Controllers;

use App\Models\KnowledgeEntry;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class KnowledgeEntryController extends Controller
{
    public function index(Workspace $workspace): JsonResponse
    {
        Gate::authorize('view', $workspace);

        $entries = $workspace
            ->knowledgeEntries()
            ->with('creator:id,name')
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'data' => $entries,
        ]);
    }

    public function store(
        Request $request,
        Workspace $workspace
    ): JsonResponse {
        Gate::authorize('update', $workspace);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'content' => ['nullable', 'string'],
        ]);

        $knowledgeEntry = $workspace->knowledgeEntries()->create([
            'title' => $validated['title'],
            'content' => $validated['content'] ?? null,
            'created_by' => $request->user()->id,
        ]);

        $knowledgeEntry->load('creator:id,name');

        return response()->json([
            'data' => $knowledgeEntry,
        ], 201);
    }

    public function show(
        Workspace $workspace,
        KnowledgeEntry $entry
    ): JsonResponse {
        Gate::authorize('view', $workspace);

        abort_unless(
            $entry->workspace_id === $workspace->id,
            404
        );

        $entry->load('creator:id,name');

        return response()->json([
            'data' => $entry,
        ]);
    }

    public function update(
        Request $request,
        Workspace $workspace,
        KnowledgeEntry $entry
    ): JsonResponse {
        Gate::authorize('update', $workspace);

        abort_unless(
            $entry->workspace_id === $workspace->id,
            404
        );

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'content' => ['nullable', 'string'],
        ]);

        $entry->update([
            'title' => $validated['title'],
            'content' => $validated['content'] ?? null,
        ]);

        $entry->load('creator:id,name');

        return response()->json([
            'data' => $entry,
        ]);
    }

    public function destroy(
        Workspace $workspace,
        KnowledgeEntry $entry
    ): JsonResponse {
        Gate::authorize('update', $workspace);

        abort_unless(
            $entry->workspace_id === $workspace->id,
            404
        );

        $entry->delete();

        return response()->json([
            'message' => 'Knowledge entry deleted successfully.',
        ]);
    }
}
