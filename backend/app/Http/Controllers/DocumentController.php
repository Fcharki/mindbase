<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Models\KnowledgeEntry;
use App\Models\Workspace;
use App\Services\DocumentStorageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DocumentController extends Controller
{
    /**
     * Maximum accepted upload size, in kilobytes. Kept in step with
     * upload_max_filesize in backend/docker/uploads.ini, which is itself below
     * the client_max_body_size ceiling in docker/nginx/nginx.conf.
     */
    private const MAX_UPLOAD_KB = 20480;

    /**
     * Allowed upload types, validated against the reported extension.
     *
     * Content sniffing ("mimes:") is deliberately not used: the MIME guesser
     * reports text/plain for .md and .csv, and cannot identify every .docx,
     * so it rejects legitimate uploads. The extensions rule also blocks PHP
     * uploads outright. Downloads are always streamed as an attachment with
     * a stored filename, so the browser never renders the binary inline.
     */
    private const ALLOWED_EXTENSIONS = [
        'pdf',
        'doc',
        'docx',
        'xls',
        'xlsx',
        'ppt',
        'pptx',
        'txt',
        'md',
        'csv',
    ];

    public function index(
        Workspace $workspace,
        KnowledgeEntry $entry
    ): JsonResponse {
        $this->abortUnlessEntryBelongsToWorkspace($entry, $workspace);

        Gate::authorize('viewAny', [Document::class, $entry]);

        $documents = $entry
            ->documents()
            ->with('uploader:id,name')
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'data' => $documents,
        ]);
    }

    public function store(
        Request $request,
        Workspace $workspace,
        KnowledgeEntry $entry,
        DocumentStorageService $storage
    ): JsonResponse {
        $this->abortUnlessEntryBelongsToWorkspace($entry, $workspace);

        Gate::authorize('create', [Document::class, $entry]);

        $validated = $request->validate([
            'file' => [
                'required',
                'file',
                'max:'.self::MAX_UPLOAD_KB,
                'extensions:'.implode(',', self::ALLOWED_EXTENSIONS),
            ],
        ]);

        $document = $storage->store(
            $validated['file'],
            $entry,
            $request->user()->id
        );

        $document->load('uploader:id,name');

        return response()->json([
            'data' => $document,
        ], 201);
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
