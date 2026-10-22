<?php

namespace App\Http\Controllers;

use App\Actions\KnowledgeEntry\BulkManageEntries;
use App\Actions\KnowledgeEntry\ScheduleEntrySummary;
use App\Actions\KnowledgeEntry\SummarizeEntry;
use App\Actions\KnowledgeEntry\SyncEntryTags;
use App\Exceptions\ActionFailedException;
use App\Http\Requests\KnowledgeEntry\BulkEntriesRequest;
use App\Http\Requests\KnowledgeEntry\KnowledgeEntryRequest;
use App\Http\Requests\KnowledgeEntry\RegenerateSummaryRequest;
use App\Http\Requests\KnowledgeEntry\SyncTagsRequest;
use App\Http\Resources\KnowledgeEntryResource;
use App\Http\Resources\TagResource;
use App\Models\KnowledgeEntry;
use App\Models\Workspace;
use App\Services\AI\BackendBudget;
use App\Services\EntryExportService;
use App\Services\EntryImageService;
use App\Services\EntryTagSuggestionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class KnowledgeEntryController extends Controller
{
    public function index(Workspace $workspace): AnonymousResourceCollection
    {
        Gate::authorize('view', $workspace);

        $entries = $workspace
            ->knowledgeEntries()
            // Pinned entries lead the list; within each group the newest first.
            ->with('creator:id,username', 'tags:id,name,workspace_id,parent_id')
            ->withCount('tags')
            ->orderByDesc('is_pinned')
            ->orderByDesc('created_at')
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

        // Images can be uploaded before the entry exists, so attach the ones
        // the saved content references now.
        app(EntryImageService::class)->sync($knowledgeEntry);

        app(ScheduleEntrySummary::class, ['entry' => $knowledgeEntry])->handle();

        $knowledgeEntry->load('creator:id,username', 'tags:id,name,workspace_id,parent_id');
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

        $entry->load('creator:id,username', 'tags:id,name,workspace_id,parent_id');
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

        // Captured before the write so a rename, which cannot change a summary,
        // does not queue a regeneration that would only reproduce it.
        $previousContent = $entry->getOriginal('content');

        $entry->update([
            'title' => $validated['title'],
            'content' => $validated['content'] ?? null,
        ]);

        // Keep the image rows (and their files) in step with what the editor
        // actually saved.
        app(EntryImageService::class)->sync($entry);

        if ($previousContent !== ($validated['content'] ?? null)) {
            app(ScheduleEntrySummary::class, ['entry' => $entry])->handle();
        }

        $entry->load('creator:id,username', 'tags:id,name,workspace_id,parent_id');
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

    public function regenerateSummary(
        RegenerateSummaryRequest $request,
        Workspace $workspace,
        KnowledgeEntry $entry
    ): JsonResponse {
        Gate::authorize('update', $entry);

        $this->abortUnlessEntryBelongsToWorkspace($entry, $workspace);

        if (trim(strip_tags((string) $entry->content)) === '') {
            throw new ActionFailedException(
                'This entry has no content to summarize.'
            );
        }

        $backend = $request->validated('backend');

        // Refused before the job is queued, and with the reason, rather than
        // accepted and then killed at the budget with a transport error the
        // caller cannot interpret.
        app(BackendBudget::class)->assertAffordable($backend);

        app(ScheduleEntrySummary::class, [
            'entry' => $entry,
            'backend' => $backend,
        ])->handle();

        // Which model ends up being used is reported by the stored summary
        // itself, so nothing is claimed here that could turn out to be wrong
        // once the job falls back to the service default.
        return response()->json([
            'message' => 'Summary regeneration queued.',
            'data' => [
                'status' => 'queued',
            ],
        ], 202);
    }

    /**
     * Pin or unpin an entry so it holds the top of the workspace list.
     *
     * A body of {"is_pinned": true|false} sets the value explicitly; without a
     * body the current state is toggled, which is what a single button wants.
     */
    public function pin(
        Request $request,
        Workspace $workspace,
        KnowledgeEntry $entry
    ): JsonResource {
        Gate::authorize('update', $entry);

        $this->abortUnlessEntryBelongsToWorkspace($entry, $workspace);

        $validated = $request->validate([
            'is_pinned' => ['sometimes', 'boolean'],
        ]);

        $entry->update([
            'is_pinned' => $validated['is_pinned'] ?? ! $entry->is_pinned,
        ]);

        $this->loadRelations($entry);

        return KnowledgeEntryResource::make($entry);
    }

    /**
     * Apply one action to a selection of entries.
     *
     * Per-entry authorization happens inside the action, so a partial selection
     * cannot be used to reach entries the caller could not touch one at a time.
     */
    public function bulk(
        BulkEntriesRequest $request,
        Workspace $workspace
    ): JsonResponse {
        Gate::authorize('view', $workspace);

        return response()->json([
            'data' => app(BulkManageEntries::class, [
                'workspace' => $workspace,
                'entryIds' => $request->entryIds(),
                'action' => $request->action(),
                'tags' => $request->tags(),
            ])->handle(),
        ]);
    }

    /**
     * Download one entry as Markdown, HTML, JSON or PDF.
     */
    public function export(
        Request $request,
        Workspace $workspace,
        KnowledgeEntry $entry
    ) {
        Gate::authorize('view', $entry);

        $this->abortUnlessEntryBelongsToWorkspace($entry, $workspace);

        $entry->load('tags:id,name,workspace_id,parent_id');

        $service = app(EntryExportService::class);
        $format = strtolower((string) $request->query('format', 'md'));
        $slug = Str::slug($entry->title) ?: 'entry';

        return match ($format) {
            'md', 'markdown' => response($service->toMarkdown($entry), 200, [
                'Content-Type' => 'text/markdown; charset=UTF-8',
                'Content-Disposition' => 'attachment; filename="'.$slug.'.md"',
            ]),
            'html' => response($service->toHtml($entry), 200, [
                'Content-Type' => 'text/html; charset=UTF-8',
                'Content-Disposition' => 'attachment; filename="'.$slug.'.html"',
            ]),
            'json' => response()->json(['data' => $service->toArray($entry)]),
            'pdf' => response($service->toPdf($entry), 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'attachment; filename="'.$slug.'.pdf"',
            ]),
            default => abort(422, 'Unsupported export format. Use md, html, json or pdf.'),
        };
    }

    /**
     * Tag names that look like they belong on this entry, drawn from the
     * workspace's existing vocabulary where possible.
     */
    public function suggestTags(
        Workspace $workspace,
        KnowledgeEntry $entry
    ): JsonResponse {
        Gate::authorize('view', $entry);

        $this->abortUnlessEntryBelongsToWorkspace($entry, $workspace);

        $entry->load('tags:id,name,workspace_id,parent_id');

        return response()->json([
            'data' => [
                'suggestions' => app(EntryTagSuggestionService::class)->suggest($entry),
            ],
        ]);
    }

    private function loadRelations(KnowledgeEntry $entry): void
    {
        $entry->load('creator:id,username', 'tags:id,name,workspace_id,parent_id');
        $entry->loadCount('tags');
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
