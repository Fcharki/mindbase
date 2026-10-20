<?php

namespace App\Services;

use App\Models\EntryImage;
use App\Models\Workspace;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class EntryImageStorageService
{
    /**
     * Images share the documents disk: both live outside the public directory
     * and are streamed through a controller instead of being served directly.
     */
    public const DISK = 'documents';

    /**
     * Persist an uploaded image on the documents disk and record its metadata.
     */
    public function store(
        UploadedFile $file,
        Workspace $workspace,
        ?int $uploadedBy
    ): EntryImage {
        $disk = Storage::disk(self::DISK);

        $uuid = Str::uuid()->toString();
        $extension = strtolower($file->getClientOriginalExtension());
        $filename = $extension === '' ? $uuid : $uuid.'.'.$extension;

        $path = $disk->putFileAs(
            $this->directoryFor($workspace),
            $file,
            $filename
        );

        if ($path === false) {
            throw new \RuntimeException('The image could not be written to storage.');
        }

        return EntryImage::create([
            'uuid' => $uuid,
            'workspace_id' => $workspace->getKey(),
            'uploaded_by' => $uploadedBy,
            'disk' => self::DISK,
            'path' => $path,
            'original_name' => $this->sanitiseName($file),
            'mime_type' => $file->getMimeType() ?: $file->getClientMimeType(),
            'size' => $disk->size($path),
            'checksum' => hash_file('sha256', $file->getRealPath()),
        ]);
    }

    /**
     * Remove an image's binary. Missing files are not an error.
     */
    public function delete(EntryImage $image): void
    {
        if ($image->path === null || $image->path === '') {
            return;
        }

        $disk = Storage::disk($image->disk ?: self::DISK);

        if ($disk->exists($image->path)) {
            $disk->delete($image->path);
        }
    }

    /**
     * Images are namespaced per workspace, matching how entry documents are
     * kept apart on the same disk.
     */
    private function directoryFor(Workspace $workspace): string
    {
        return sprintf('entry-images/workspaces/%d', $workspace->getKey());
    }

    /**
     * Keep a readable name for the response without trusting the client
     * supplied value verbatim.
     */
    private function sanitiseName(UploadedFile $file): string
    {
        $name = basename($file->getClientOriginalName());

        return Str::limit($name, 255, '');
    }
}
