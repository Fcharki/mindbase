<?php

namespace App\Actions\KnowledgeEntry;

use App\Actions\Action;
use App\Jobs\GenerateEntrySummary;
use App\Jobs\IndexEntryChunks;
use App\Models\KnowledgeEntry;
use App\Services\EntrySummaryService;

/**
 * Queues a summary of the entry's content as it stands right now.
 *
 * Dispatching rather than generating inline is what lets an entry stay
 * responsive while the model works; the fingerprint handed to the job is what
 * lets the job tell whether the content it was asked about still exists.
 *
 * Naming a backend regenerates with that model instead of the configured
 * default, which is how a single entry is upgraded without changing what every
 * other entry uses.
 */
class ScheduleEntrySummary implements Action
{
    public function __construct(
        private readonly KnowledgeEntry $entry,
        private readonly EntrySummaryService $summaries,
        private readonly ?string $backend = null,
    ) {}

    public function handle(): void
    {
        $content = (string) $this->entry->content;

        // Nothing worth summarizing, so nothing to queue.
        if (trim(strip_tags($content)) === '') {
            return;
        }

        $hash = $this->summaries->hashFor($content);

        GenerateEntrySummary::dispatch(
            $this->entry->id,
            $hash,
            $this->backend,
        );

        // Queued alongside the summary rather than after it. Retrieval does not
        // depend on the summary existing, and the content chunks are the ones
        // that carry the detail a summary drops, so indexing should not sit
        // behind a model that takes minutes.
        IndexEntryChunks::dispatch($this->entry->id, $hash);
    }
}
