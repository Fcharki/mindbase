<?php

use App\Jobs\IndexEntryChunks;
use App\Models\KnowledgeEntry;
use App\Services\EntrySummaryService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/**
 * An entry is only retrievable once its text has been chunked and embedded, and
 * the only thing that does that is a job queued when the entry is written
 * through the API. Entries that arrived another way -- seeded, imported, or left
 * behind by a job that failed while the embedding model was unavailable -- have
 * no chunks, so hybrid search finds nothing for a question they plainly answer.
 * This queues that indexing again.
 */
Artisan::command('entries:reindex
    {--workspace= : Restrict to a single workspace id}
    {--all : Also reindex entries that already have chunks}
    {--sync : Run inline instead of queueing, for when no worker is running}', function () {
    $hashes = app(EntrySummaryService::class);

    $query = KnowledgeEntry::query()->orderBy('id');

    if ($workspace = $this->option('workspace')) {
        $query->where('workspace_id', $workspace);
    }

    // An entry with no chunks is the broken case and is what is reindexed by
    // default. Re-embedding everything is a model call per chunk, so it is opt
    // in through --all.
    if (! $this->option('all')) {
        $query->whereDoesntHave('chunks');
    }

    $queued = 0;

    $query->chunkById(100, function ($entries) use ($hashes, &$queued) {
        foreach ($entries as $entry) {
            $hash = $hashes->hashFor($entry->content);

            if ($this->option('sync')) {
                IndexEntryChunks::dispatchSync($entry->id, $hash);
            } else {
                IndexEntryChunks::dispatch($entry->id, $hash);
            }

            $queued++;
        }
    });

    if ($queued === 0) {
        $this->info('Nothing to index: every matching entry already has chunks.');

        return;
    }

    $this->info("Queued chunk indexing for {$queued} entries.");

    if (! $this->option('sync')) {
        $this->line('A running queue worker will embed them. Watch it with:');
        $this->line('  docker compose logs -f backend');
    }
})->purpose('Queue chunk indexing for entries that are not searchable yet');
