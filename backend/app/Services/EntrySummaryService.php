<?php

namespace App\Services;

use App\Models\KnowledgeEntry;
use App\Models\Summary;

/**
 * Owns the stored summary of an entry: what it is fingerprinted against, and
 * how a new one replaces the old.
 */
class EntrySummaryService
{
    /**
     * Fingerprint of a piece of entry content.
     *
     * The job that generates a summary is dispatched with this value and
     * re-checks it before storing, which is what stops a summary of a
     * superseded revision from overwriting a current one.
     */
    public function hashFor(?string $content): string
    {
        return hash('sha256', (string) $content);
    }

    /**
     * Replace the entry's summary with the given one.
     */
    public function store(
        KnowledgeEntry $entry,
        string $body,
        string $contentHash,
        ?string $backend = null
    ): Summary {
        return Summary::updateOrCreate(
            ['knowledge_entry_id' => $entry->id],
            [
                'content_hash' => $contentHash,
                'body' => $body,
                'backend' => $backend,
            ],
        );
    }
}
