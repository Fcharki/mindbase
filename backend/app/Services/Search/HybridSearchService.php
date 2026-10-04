<?php

namespace App\Services\Search;

use App\Casts\AsVector;
use App\Models\EntryChunk;
use App\Models\Workspace;
use App\Services\AI\EmbeddingService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Finds the entry chunks that could answer a question, using keyword search and
 * vector search together.
 *
 * Each is good at what the other cannot do. Full-text search is the only thing
 * here that finds an exact identifier like "E4021" or a UUID, but it is blind to
 * meaning: "how do tokens expire" shares no words with a passage about
 * credential lifetimes. Vector search answers that paraphrase easily, but has no
 * notion of an exact string and will happily rank a fluent passage that is about
 * something else.
 *
 * Scores are combined by rank rather than by value. A ts_rank of 0.08 and a
 * cosine distance of 0.31 are not comparable and neither is calibrated across
 * queries, so weighting them together would mostly measure the two scoring
 * functions. RRF scores by position in each list instead, which is scale free.
 */
class HybridSearchService
{
    private const TS_CONFIGURATION = 'english';

    /**
     * Smoothing constant for Reciprocal Rank Fusion.
     *
     * Controls how sharply the top ranks matter. Sixty is the value from the
     * original RRF paper and the de facto default: large enough that rank 1
     * outweighs rank 10, small enough that being found at all still counts for
     * something. There is no reason to treat it as a tunable.
     */
    private const RRF_K = 60;

    /**
     * How many candidates to pull from each side before fusing.
     *
     * Each engine sees only part of the picture, so a chunk one of them ranked
     * 30th may be the best answer overall. Fusing a shortlist would throw that
     * away, so each side fetches several times the final limit.
     */
    private const CANDIDATE_MULTIPLIER = 5;

    /**
     * Cosine distance above which a vector hit is not a match at all.
     *
     * bge-m3 does not spread its vectors over the full range. Measured here, a
     * question paraphrasing the right passage landed at 0.25 to 0.39 and
     * unrelated ones at 0.72 to 0.75, so 0.7 sits inside that gap. Past it the
     * vectors share too little direction to mean anything.
     *
     * A floor for "not a match", not a relevance ranking -- fusion decides that.
     */
    private const MAX_SEMANTIC_DISTANCE = 0.7;

    public function __construct(private readonly EmbeddingService $embeddings) {}

    /**
     * The chunks most likely to answer a question, best first.
     *
     * @return Collection<int, HybridSearchResult>
     */
    public function search(
        Workspace $workspace,
        string $question,
        int $limit = 8
    ): Collection {
        $question = trim($question);

        if ($question === '' || $limit < 1) {
            return collect();
        }

        $candidates = $limit * self::CANDIDATE_MULTIPLIER;

        $lexical = $this->lexicalCandidates($workspace, $question, $candidates);
        $semantic = $this->semanticCandidates($workspace, $question, $candidates);

        return $this->fuse($lexical, $semantic)
            ->take($limit)
            ->values();
    }

    /**
     * Chunks matching the question's literal words, ranked by ts_rank.
     *
     * @return Collection<int, EntryChunk>
     */
    private function lexicalCandidates(Workspace $workspace, string $question, int $limit): Collection
    {
        if (! $this->isPostgres()) {
            // No full-text support on sqlite. A LIKE match keeps the feature
            // exercised by the test suite rather than returning nothing.
            return EntryChunk::query()
                ->with('entry:id,title,workspace_id')
                ->where('body', 'like', "%{$question}%")
                ->whereHas(
                    'entry',
                    fn ($entry) => $entry->where('workspace_id', $workspace->id),
                )
                ->limit($limit)
                ->get();
        }

        return EntryChunk::query()
            ->select('entry_chunks.*')
            ->with('entry:id,title,workspace_id')
            ->selectRaw(
                "ts_rank(to_tsvector('".self::TS_CONFIGURATION."', entry_chunks.body), "
                    ."plainto_tsquery('".self::TS_CONFIGURATION."', ?)) AS lexical_rank",
                [$question]
            )
            ->whereRaw(
                "to_tsvector('".self::TS_CONFIGURATION."', entry_chunks.body) "
                    ."@@ plainto_tsquery('".self::TS_CONFIGURATION."', ?)",
                [$question]
            )
            ->whereHas(
                'entry',
                fn ($entry) => $entry->where('workspace_id', $workspace->id),
            )
            ->orderByDesc('lexical_rank')
            ->limit($limit)
            ->get();
    }

    /**
     * Chunks closest to the question's vector, nearest first.
     *
     * @return Collection<int, EntryChunk>
     */
    private function semanticCandidates(Workspace $workspace, string $question, int $limit): Collection
    {
        // Nothing to compare against: the vector column only exists on Postgres,
        // so asking the model to encode the question first would be a minute of
        // model load spent on a result that cannot be used.
        if (! $this->isPostgres()) {
            return collect();
        }

        try {
            $vector = $this->embeddings->embed($question);
        } catch (\Throwable $e) {
            // A model that is down should not take keyword search with it. The
            // user still gets results, just not the semantic half.
            Log::warning('Hybrid search fell back to keyword only: embedding failed.', [
                'message' => $e->getMessage(),
            ]);

            return collect();
        }

        if ($vector === []) {
            return collect();
        }

        $encoded = AsVector::encode($vector);

        return EntryChunk::query()
            ->select('entry_chunks.*')
            ->with('entry:id,title,workspace_id')
            ->selectRaw('entry_chunks.embedding <=> ?::vector AS distance', [$encoded])
            ->whereHas(
                'entry',
                fn ($entry) => $entry->where('workspace_id', $workspace->id),
            )
            ->orderBy('distance')
            ->limit($limit)
            ->get();
    }

    /**
     * Combine the two ranked lists by position.
     *
     * Only chunks that look genuinely similar are worth counting. On the vector
     * side a distance past the floor means the vectors share too little
     * direction to be a match, not a weak match.
     *
     * @param  Collection<int, EntryChunk>  $lexical
     * @param  Collection<int, EntryChunk>  $semantic
     * @return Collection<int, HybridSearchResult>
     */
    private function fuse(Collection $lexical, Collection $semantic): Collection
    {
        $scores = [];

        $record = function (Collection $list, string $source) use (&$scores): void {
            // One engine gets one vote per chunk. Without this, a chunk repeated
            // in a single list would score once per position and float to the
            // top, which is something a paginating or buggy engine can do.
            $seen = [];

            foreach ($list as $position => $chunk) {
                if ($source === 'semantic'
                    && (float) ($chunk->distance ?? 1.0) >= self::MAX_SEMANTIC_DISTANCE) {
                    continue;
                }

                $id = $chunk->id;

                if (isset($seen[$id])) {
                    continue;
                }

                $seen[$id] = true;

                $rank = $position + 1;

                $scores[$id]['score'] = ($scores[$id]['score'] ?? 0.0)
                    + 1 / (self::RRF_K + $rank);
                $scores[$id]['sources'][] = $source;
                $scores[$id]['ranks'][$source] = $rank;
                $scores[$id]['chunk'] = $chunk;
                $scores[$id]['distance'] = $chunk->distance ?? null;
            }
        };

        $record($lexical, 'lexical');
        $record($semantic, 'semantic');

        // Chunk id, keeping the fused score as the sort key.
        arsort($scores);

        $results = collect();

        foreach ($scores as $id => $data) {
            $results->push(new HybridSearchResult(
                chunk: $data['chunk'],
                score: $data['score'],
                foundBy: $data['sources'],
                lexicalRank: $data['ranks']['lexical'] ?? null,
                semanticRank: $data['ranks']['semantic'] ?? null,
                distance: $data['distance'],
            ));
        }

        return $results;
    }

    private function isPostgres(): bool
    {
        return DB::connection()->getDriverName() === 'pgsql';
    }
}
