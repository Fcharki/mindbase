<?php

namespace App\Http\Controllers;

use App\Models\KnowledgeEntry;
use App\Models\Workspace;
use App\Services\AI\SummarizationService;
use App\Services\TagService;
use GuzzleHttp\Exception\GuzzleException;
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
            ->with('creator:id,name', 'tags:id,name,workspace_id')
            ->withCount('tags')
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
        Gate::authorize('create', [KnowledgeEntry::class, $workspace]);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'content' => ['nullable', 'string'],
        ]);

        $knowledgeEntry = $workspace->knowledgeEntries()->create([
            'title' => $validated['title'],
            'content' => $validated['content'] ?? null,
            'created_by' => $request->user()->id,
        ]);

        $knowledgeEntry->load('creator:id,name', 'tags:id,name,workspace_id');
        $knowledgeEntry->loadCount('tags');

        return response()->json([
            'data' => $knowledgeEntry,
        ], 201);
    }

    public function show(
        Workspace $workspace,
        KnowledgeEntry $entry
    ): JsonResponse {
        Gate::authorize('view', $entry);

        abort_unless(
            $entry->workspace_id === $workspace->id,
            404
        );

        $entry->load('creator:id,name', 'tags:id,name,workspace_id');
        $entry->loadCount('tags');

        return response()->json([
            'data' => $entry,
        ]);
    }

    public function update(
        Request $request,
        Workspace $workspace,
        KnowledgeEntry $entry
    ): JsonResponse {
        Gate::authorize('update', $entry);

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

        $entry->load('creator:id,name', 'tags:id,name,workspace_id');
        $entry->loadCount('tags');

        return response()->json([
            'data' => $entry,
        ]);
    }

    public function destroy(
        Workspace $workspace,
        KnowledgeEntry $entry
    ): JsonResponse {
        Gate::authorize('delete', $entry);

        abort_unless(
            $entry->workspace_id === $workspace->id,
            404
        );

        $entry->delete();

        return response()->json([
            'message' => 'Knowledge entry deleted successfully.',
        ]);
    }

    public function syncTags(
        Request $request,
        Workspace $workspace,
        KnowledgeEntry $entry
    ): JsonResponse {
        Gate::authorize('update', $entry);

        abort_unless(
            $entry->workspace_id === $workspace->id,
            404
        );

        $validated = $request->validate([
            'tags' => ['required', 'array', 'max:50'],
            'tags.*' => ['required', 'string', 'max:60'],
        ]);

        $tags = app(TagService::class)->syncForEntry(
            $entry,
            $validated['tags']
        );

        return response()->json([
            'data' => $tags,
        ]);
    }

    public function summarize(
        Workspace $workspace,
        KnowledgeEntry $entry,
        SummarizationService $service
    ): JsonResponse {
        Gate::authorize('view', $entry);

        abort_unless(
            $entry->workspace_id === $workspace->id,
            404
        );

        $content = trim(strip_tags((string) $entry->content));

        if ($content === '') {
            return response()->json([
                'message' => 'This entry has no content to summarize.',
            ], 422);
        }

        try {
            $summary = $service->summarize($content);
        } catch (GuzzleException) {
            return response()->json([
                'message' => 'The AI service is unavailable. Please try again.',
            ], 503);
        }

        if ($summary === '') {
            return response()->json([
                'message' => 'The AI service returned an empty summary.',
            ], 502);
        }

        return response()->json([
            'data' => [
                'summary' => $summary,
            ],
        ]);
    }
}
