<?php

namespace App\Jobs;

use App\Casts\AsVector;
use App\Models\EntryChunk;
use App\Models\KnowledgeEntry;
use App\Services\AI\EmbeddingService;
use App\Services\ChunkingService;
use App\Services\EntrySummaryService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Embeds an entry as chunks so retrieval can match a specific idea instead of a
 * whole document.
 */
class IndexEntryChunks implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Loading the embedding model takes about a minute on CPU, well past the
     * default job budget.
     */
    public int $timeout = 900;

    public int $tries = 3;

    /**
     * @var list<int>
     */
    public array $backoff = [30, 120, 300];

    public function __construct(
        public int $knowledgeEntryId,
        public string $contentHash,
    ) {}

    public function handle(
        ChunkingService $chunker,
        EmbeddingService $embeddings,
        EntrySummaryService $hashes,
    ): void {
        $entry = KnowledgeEntry::find($this->knowledgeEntryId);

        if ($entry === null) {
            return;
        }

        // The entry was edited again while this job waited. The newer revision
        // has its own job, so indexing this one would leave two revisions mixed
        // together under one entry.
        if (! hash_equals($hashes->hashFor($entry->content), $this->contentHash)) {
            return;
        }

        $chunks = $chunker->chunksFor($entry);

        if ($chunks === []) {
            EntryChunk::where('knowledge_entry_id', $entry->id)->delete();

            return;
        }

        $vectors = $embeddings->embedMany(array_column($chunks, 'body'));

        $indexed = 0;
        $skipped = 0;

        DB::transaction(function () use ($entry, $chunks, $vectors, $hashes, &$indexed, &$skipped): void {
            // Replaced wholesale rather than merged: a shortened entry leaves
            // fewer chunks, and leftover rows from the longer revision would keep
            // answering searches with text the entry no longer contains.
            EntryChunk::where('knowledge_entry_id', $entry->id)->delete();

            $now = now();

            $rows = [];

            foreach ($chunks as $index => $chunk) {
                // A chunk the model had nothing to say about is left out
                // instead of being stored with a vector that would rank it
                // against every other entry. Positions are assigned before
                // embedding, so skipping one leaves a harmless gap rather than
                // colliding with its neighbour.
                if ($vectors[$index] === []) {
                    $skipped++;

                    continue;
                }

                $rows[] = [
                    'knowledge_entry_id' => $entry->id,
                    'source' => $chunk['source'],
                    'position' => $chunk['position'],
                    'content_hash' => $hashes->hashFor($entry->content),
                    'body' => $chunk['body'],
                    // A bulk insert skips model casts, so the vector column is
                    // formatted here.
                    'embedding' => AsVector::encode($vectors[$index]),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            $indexed = count($rows);

            if ($rows !== []) {
                EntryChunk::insert($rows);
            }
        });

        Log::info('Indexed entry chunks.', [
            'knowledge_entry_id' => $entry->id,
            'chunks' => $indexed,
            'skipped' => $skipped,
        ]);
    }
}
