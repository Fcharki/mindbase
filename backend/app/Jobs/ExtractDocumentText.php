<?php

namespace App\Jobs;

use App\Models\Document;
use App\Services\EntrySummaryService;
use App\Services\Extraction\TextExtractionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Reads text out of an uploaded document, stores it, and reindexes its entry so
 * the text becomes part of what search and the assistant can see.
 *
 * Queued rather than run inline: parsing a PDF is not instant, and an upload
 * should not hold a request open while it happens.
 */
class ExtractDocumentText implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 300;

    public int $tries = 3;

    /**
     * @var list<int>
     */
    public array $backoff = [30, 120, 300];

    public function __construct(public int $documentId) {}

    public function handle(
        TextExtractionService $extractor,
        EntrySummaryService $summaries,
    ): void {
        $document = Document::find($this->documentId);

        if ($document === null) {
            return;
        }

        $result = $extractor->extract($document);

        if ($result->hasWarnings()) {
            Log::warning('Document extraction finished with warnings.', [
                'document_id' => $document->id,
                'warnings' => $result->warnings,
            ]);
        }

        $document->forceFill([
            'extracted_text' => $result->hasText() ? $result->text : null,
            'extraction_status' => $result->status,
            'extraction_error' => $result->error,
            'extracted_at' => now(),
        ])->save();

        $this->reindexEntry($document, $summaries);
    }

    /**
     * Re-run indexing so the entry's chunks pick up the new attachment text.
     *
     * The entry's own content is unchanged, so its hash still matches and the
     * reindex job will not discard itself.
     */
    private function reindexEntry(Document $document, EntrySummaryService $summaries): void
    {
        $entry = $document->knowledgeEntry()->first();

        if ($entry === null) {
            return;
        }

        IndexEntryChunks::dispatch($entry->id, $summaries->hashFor($entry->content));
    }
}
