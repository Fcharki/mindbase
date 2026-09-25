<?php

namespace App\Services;

use App\Models\Document;
use App\Models\KnowledgeEntry;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class DocumentStorageService
{
    /**
     * Disk that backs the documents table. Swapping in S3/MinIO later only
     * means pointing this disk at a different driver.
     */
    public const DISK = 'documents';

    /**
     * Persist an uploaded file on the documents disk and record its metadata.
     */
    public function store(
        UploadedFile $file,
        KnowledgeEntry $entry,
        ?int $uploadedBy
    ): Document {
        $disk = Storage::disk(self::DISK);

        $path = $disk->putFileAs(
            $this->directoryFor($entry),
            $file,
            $this->filenameFor($file)
        );

        if ($path === false) {
            throw new \RuntimeException('The document could not be written to storage.');
        }

        return $entry->documents()->create([
            'uploaded_by' => $uploadedBy,
            'disk' => self::DISK,
            'path' => $path,
            'original_name' => $this->sanitiseName($file),
            'mime_type' => $file->getClientMimeType(),
            'size' => $disk->size($path),
            'checksum' => hash_file('sha256', $file->getRealPath()),
        ]);
    }

    /**
     * Remove a document's binary. Missing files are not an error.
     */
    public function delete(Document $document): void
    {
        if ($document->path === null || $document->path === '') {
            return;
        }

        $disk = Storage::disk($document->disk ?: self::DISK);

        if ($disk->exists($document->path)) {
            $disk->delete($document->path);
        }
    }

    /**
     * Documents are namespaced per entry so a single entry can be removed
     * from disk as a unit.
     */
    private function directoryFor(KnowledgeEntry $entry): string
    {
        return sprintf(
            'workspaces/%d/entries/%d',
            $entry->workspace_id,
            $entry->getKey()
        );
    }

    /**
     * Store under a random name so the original filename can never influence
     * the path written to disk.
     */
    private function filenameFor(UploadedFile $file): string
    {
        $extension = strtolower($file->getClientOriginalExtension());

        return $extension === ''
            ? Str::uuid()->toString()
            : Str::uuid()->toString().'.'.$extension;
    }

    /**
     * Keep a readable name for the response and the Content-Disposition header
     * without trusting the client-supplied value verbatim.
     */
    private function sanitiseName(UploadedFile $file): string
    {
        $name = basename($file->getClientOriginalName());

        return Str::limit($name, 255, '');
    }
}
