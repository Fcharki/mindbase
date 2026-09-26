<?php

namespace App\Http\Controllers;

use App\Actions\KnowledgeEntry\SummarizeEntry;
use App\Actions\KnowledgeEntry\SyncEntryTags;
use App\Http\Requests\KnowledgeEntry\KnowledgeEntryRequest;
use App\Http\Requests\KnowledgeEntry\SyncTagsRequest;
use App\Http\Resources\KnowledgeEntryResource;
use App\Http\Resources\TagResource;
use App\Models\KnowledgeEntry;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;

class KnowledgeEntryController extends Controller
{
    public function index(Workspace $workspace): AnonymousResourceCollection
    {
        Gate::authorize('view', $workspace);

        $entries = $workspace
            ->knowledgeEntries()
            ->with('creator:id,name', 'tags:id,name,workspace_id')
            ->withCount('tags')
            ->orderBy('created_at', 'desc')
            ->get();

        return KnowledgeEntryResource::collection($entries);
    }

    public function store(
        KnowledgeEntryRequest $request,
        Workspace $workspace
    ): JsonResponse {
        Gate::authorize('create', [KnowledgeEntry::class, $workspace]);

        $validated = $request->validated();

        $knowledgeEntry = $workspace->knowledgeEntries()->create([
            'title' => $validated['title'],
            'content' => $validated['content'] ?? null,
            'created_by' => $request->user()->id,
        ]);

        $knowledgeEntry->load('creator:id,name', 'tags:id,name,workspace_id');
        $knowledgeEntry->loadCount('tags');

        return KnowledgeEntryResource::make($knowledgeEntry)
            ->response()
            ->setStatusCode(201);
    }

    public function show(
        Workspace $workspace,
        KnowledgeEntry $entry
    ): JsonResource {
        Gate::authorize('view', $entry);

        $this->abortUnlessEntryBelongsToWorkspace($entry, $workspace);

        $entry->load('creator:id,name', 'tags:id,name,workspace_id');
        $entry->loadCount('tags');

        return KnowledgeEntryResource::make($entry);
    }

    public function update(
        KnowledgeEntryRequest $request,
        Workspace $workspace,
        KnowledgeEntry $entry
    ): JsonResource {
        Gate::authorize('update', $entry);

        $this->abortUnlessEntryBelongsToWorkspace($entry, $workspace);

        $validated = $request->validated();

        $entry->update([
            'title' => $validated['title'],
            'content' => $validated['content'] ?? null,
        ]);

        $entry->load('creator:id,name', 'tags:id,name,workspace_id');
        $entry->loadCount('tags');

        return KnowledgeEntryResource::make($entry);
    }

    public function destroy(
        Workspace $workspace,
        KnowledgeEntry $entry
    ): JsonResponse {
        Gate::authorize('delete', $entry);

        $this->abortUnlessEntryBelongsToWorkspace($entry, $workspace);

        $entry->delete();

        return response()->json([
            'message' => 'Knowledge entry deleted successfully.',
        ]);
    }

    public function syncTags(
        SyncTagsRequest $request,
        Workspace $workspace,
        KnowledgeEntry $entry
    ): AnonymousResourceCollection {
        Gate::authorize('update', $entry);

        $this->abortUnlessEntryBelongsToWorkspace($entry, $workspace);

        $tags = app(SyncEntryTags::class, [
            'entry' => $entry,
            'tags' => $request->validated('tags', []),
        ])->handle();

        return TagResource::collection($tags);
    }

    public function summarize(
        Workspace $workspace,
        KnowledgeEntry $entry
    ): JsonResponse {
        Gate::authorize('view', $entry);

        $this->abortUnlessEntryBelongsToWorkspace($entry, $workspace);

        $summary = app(SummarizeEntry::class, [
            'entry' => $entry,
        ])->handle();

        return response()->json([
            'data' => [
                'summary' => $summary,
            ],
        ]);
    }

    private function abortUnlessEntryBelongsToWorkspace(
        KnowledgeEntry $entry,
        Workspace $workspace
    ): void {
        abort_unless(
            $entry->workspace_id === $workspace->id,
            404
        );
    }
}
