<?php

namespace App\Http\Controllers;

use App\Http\Requests\Document\StoreDocumentRequest;
use App\Http\Resources\DocumentResource;
use App\Models\Document;
use App\Models\KnowledgeEntry;
use App\Models\Workspace;
use App\Services\DocumentStorageService;
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

        Gate::authorize('view', $document);

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

    public function destroy(
        Workspace $workspace,
        KnowledgeEntry $entry,
        Document $document
    ): JsonResponse {
        $this->abortUnlessEntryBelongsToWorkspace($entry, $workspace);

        abort_unless(
            $document->knowledge_entry_id === $entry->id,
            404
        );

        Gate::authorize('delete', $document);

        $document->delete();

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
