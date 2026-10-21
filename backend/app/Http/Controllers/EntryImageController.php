<?php

namespace App\Http\Controllers;

use App\Http\Requests\EntryImage\StoreEntryImageRequest;
use App\Http\Resources\EntryImageResource;
use App\Jobs\ExtractEntryImageText;
use App\Models\EntryImage;
use App\Models\KnowledgeEntry;
use App\Models\Workspace;
use App\Services\EntryImageStorageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EntryImageController extends Controller
{
    public function store(
        StoreEntryImageRequest $request,
        Workspace $workspace,
        EntryImageStorageService $storage
    ): JsonResponse {
        Gate::authorize('create', [KnowledgeEntry::class, $workspace]);

        $image = $storage->store(
            $request->file('file'),
            $workspace,
            $request->user()->id
        );

        // Queued: OCR loads a model, and the editor only needs the URL now.
        ExtractEntryImageText::dispatch($image->id);

        return EntryImageResource::make($image)
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Serve one inline image.
     *
     * Like profile photos, entry images are public because content is rendered
     * in the browser and a private disk cannot be reached from an <img> tag.
     * The UUID route key keeps them unguessable, and only raster formats are
     * ever stored, so the response cannot execute script.
     */
    public function show(EntryImage $entryImage): StreamedResponse
    {
        $disk = Storage::disk($entryImage->disk ?: EntryImageStorageService::DISK);

        abort_unless($disk->exists($entryImage->path), 404);

        return $disk->response(
            $entryImage->path,
            $entryImage->original_name,
            [
                'Content-Type' => $entryImage->mime_type,
                'Cache-Control' => 'public, max-age=31536000, immutable',
            ]
        );
    }
}
