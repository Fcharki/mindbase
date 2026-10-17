<?php

namespace App\Services\AI;

use App\Services\Search\HybridSearchResult;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Reorders fused retrieval results with a cross-encoder before they are cut to
 * the final count.
 *
 * RRF fuses two rankings by position, which is scale free but blind to the
 * query: it cannot tell a passage that merely ranked well in one list from one
 * that actually answers the question. A cross-encoder reads the question and
 * the passage together and scores how well they match, which is the stronger
 * signal, at the cost of a model call per batch.
 *
 * Every failure falls back to the fused order: a missing model should cost
 * precision, never results.
 */
class RerankService
{
    /**
     * Rerank more than we intend to keep. Below about a dozen candidates the
     * list is too short for reranking to matter; far above it only wastes a
     * model call.
     */
    private const CANDIDATE_LIMIT = 20;

    public function __construct(private readonly AiHttpClient $http) {}

    /**
     * @param  Collection<int, HybridSearchResult>  $results
     * @return Collection<int, HybridSearchResult>
     */
    public function rerank(string $question, Collection $results): Collection
    {
        if (! (bool) config('ai.rerank_enabled', true) || $results->isEmpty()) {
            return $results;
        }

        $candidates = $results->take(self::CANDIDATE_LIMIT)->values();

        try {
            $order = $this->http->post('rerank', [
                'query' => $question,
                'passages' => $candidates
                    ->map(fn (HybridSearchResult $result) => $result->chunk->body)
                    ->all(),
            ], timeout: 60);
        } catch (GuzzleException $e) {
            Log::warning('Cross-encoder rerank failed, keeping fused order.', [
                'message' => $e->getMessage(),
            ]);

            return $results;
        }

        $indexes = $order['order'] ?? [];

        if (! is_array($indexes) || $indexes === []) {
            return $results;
        }

        $reranked = collect();

        foreach ($indexes as $index) {
            if (! is_int($index)) {
                continue;
            }

            if (isset($candidates[$index])) {
                $reranked->push($candidates[$index]);
            }
        }

        // Anything the model left out (a truncated reply, an index it dropped)
        // keeps its fused position at the tail rather than vanishing.
        foreach ($candidates as $index => $result) {
            if (! $reranked->contains(fn (HybridSearchResult $kept) => $kept->chunk->id === $result->chunk->id)) {
                $reranked->push($result);
            }
        }

        $tail = $results->slice(self::CANDIDATE_LIMIT)->values();

        return $reranked->concat($tail)->values();
    }
}
