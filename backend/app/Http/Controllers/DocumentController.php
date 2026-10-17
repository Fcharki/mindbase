<?php

namespace App\Http\Controllers;

use App\Http\Requests\Document\StoreDocumentRequest;
use App\Http\Resources\DocumentResource;
use App\Jobs\ExtractDocumentText;
use App\Jobs\IndexEntryChunks;
use App\Models\Document;
use App\Models\KnowledgeEntry;
use App\Models\Workspace;
use App\Services\DocumentStorageService;
use App\Services\EntrySummaryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DocumentController extends Controller
{
    public function index(
        Workspace $workspace,
        KnowledgeEntry $entry
    ): AnonymousResourceCollection {
        $this->abortUnlessEntryBelongsToWorkspace($entry, $workspace);

        Gate::authorize('viewAny', [Document::class, $entry]);

        $documents = $entry
            ->documents()
            ->with('uploader:id,name')
            ->orderBy('created_at', 'desc')
            ->get();

        return DocumentResource::collection($documents);
    }

    public function store(
        StoreDocumentRequest $request,
        Workspace $workspace,
        KnowledgeEntry $entry,
        DocumentStorageService $storage
    ): JsonResponse {
        $this->abortUnlessEntryBelongsToWorkspace($entry, $workspace);

        Gate::authorize('create', [Document::class, $entry]);

        $document = $storage->store(
            $request->file('file'),
            $entry,
            $request->user()->id
        );

        $document->load('uploader:id,name');

        // Extraction is queued; the response goes out with the metadata the
        // browser needs and the text arrives once the file has been read.
        ExtractDocumentText::dispatch($document->id);

        return DocumentResource::make($document)
            ->response()
            ->setStatusCode(201);
    }

    public function download(
        Workspace $workspace,
        KnowledgeEntry $entry,
        Document $document
    ): StreamedResponse {
        $this->abortUnlessEntryBelongsToWorkspace($entry, $workspace);

        abort_unless(
            $document->knowledge_entry_id === $entry->id,
            404
        );

        Gate::authorize('view', [$document, $entry]);

        abort_unless(
            Storage::disk($document->disk)->exists($document->path),
            404,
            'The stored file is missing.'
        );

        return Storage::disk($document->disk)->download(
            $document->path,
            $document->original_name
        );
    }

    /**
     * Serve one attachment inline, for a preview rather than a save.
     *
     * Same bytes and the same permission as a download -- the download
     * capability, not read access -- so previewing is never a way around who
     * may see a document. Inline disposition is what lets the browser render a
     * PDF in an iframe instead of downloading it.
     */
    public function preview(
        Workspace $workspace,
        KnowledgeEntry $entry,
        Document $document
    ): StreamedResponse {
        $this->abortUnlessEntryBelongsToWorkspace($entry, $workspace);

        abort_unless(
            $document->knowledge_entry_id === $entry->id,
            404
        );

        Gate::authorize('view', [$document, $entry]);

        abort_unless(
            Storage::disk($document->disk)->exists($document->path),
            404,
            'The stored file is missing.'
        );

        return Storage::disk($document->disk)->response(
            $document->path,
            $document->original_name,
            ['Content-Disposition' => 'inline; filename="'.addslashes($document->original_name).'"']
        );
    }

    public function destroy(
        Workspace $workspace,
        KnowledgeEntry $entry,
        Document $document,
        EntrySummaryService $summaries
    ): JsonResponse {
        $this->abortUnlessEntryBelongsToWorkspace($entry, $workspace);

        abort_unless(
            $document->knowledge_entry_id === $entry->id,
            404
        );

        Gate::authorize('delete', [$document, $entry]);

        $document->delete();

        // Drop the removed document's chunks. The entry content is unchanged,
        // so the reindex replaces every chunk rather than being discarded.
        IndexEntryChunks::dispatch($entry->id, $summaries->hashFor($entry->content));

        return response()->json([
            'message' => 'Document deleted successfully.',
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
