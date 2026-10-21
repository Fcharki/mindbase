<?php

namespace App\Jobs;

use App\Models\EntryImage;
use App\Services\AI\OcrService;
use App\Services\EntryImageStorageService;
use App\Services\EntrySummaryService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Reads text from an inline image and indexes it with its entry.
 * The image may be uploaded before the entry exists.
 */
class ExtractEntryImageText implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    // Above the OCR client timeout, which includes a cold model load.
    public int $timeout = 600;

    public int $tries = 3;

    /**
     * @var list<int>
     */
    public array $backoff = [30, 120, 300];

    public function __construct(public int $entryImageId) {}

    public function handle(OcrService $ocr, EntrySummaryService $summaries): void
    {
        $image = EntryImage::find($this->entryImageId);

        if ($image === null) {
            return;
        }

        $disk = Storage::disk($image->disk ?: EntryImageStorageService::DISK);

        if (! $disk->exists($image->path)) {
            $this->markFailed($image, 'The stored image is missing.');

            return;
        }

        try {
            $text = $ocr->extract(
                (string) $disk->get($image->path),
                $image->original_name,
                $image->mime_type,
            );
        } catch (Throwable $e) {
            Log::warning('Entry image OCR failed.', [
                'entry_image_id' => $image->id,
                'message' => $e->getMessage(),
            ]);

            $this->markFailed($image, $e->getMessage());

            return;
        }

        $text = $this->cap($text);

        $image->forceFill([
            'extracted_text' => $text === '' ? null : $text,
            'extraction_status' => $text === ''
                ? EntryImage::EXTRACTION_EMPTY
                : EntryImage::EXTRACTION_READY,
            'extraction_error' => null,
            'extracted_at' => now(),
        ])->save();

        // No text means nothing new to index.
        if ($text !== '') {
            $this->reindexEntry($image, $summaries);
        }
    }

    private function markFailed(EntryImage $image, string $reason): void
    {
        $image->forceFill([
            'extracted_text' => null,
            'extraction_status' => EntryImage::EXTRACTION_FAILED,
            'extraction_error' => $reason,
            'extracted_at' => now(),
        ])->save();
    }

    /**
     * Reindex once the image belongs to an entry.
     */
    private function reindexEntry(EntryImage $image, EntrySummaryService $summaries): void
    {
        $entry = $image->knowledgeEntry()->first();

        if ($entry === null) {
            return;
        }

        IndexEntryChunks::dispatch($entry->id, $summaries->hashFor($entry->content));
    }

    private function cap(string $text): string
    {
        $max = (int) config('extraction.max_characters', 200000);

        if ($max > 0 && mb_strlen($text) > $max) {
            return mb_substr($text, 0, $max);
        }

        return $text;
    }
}
