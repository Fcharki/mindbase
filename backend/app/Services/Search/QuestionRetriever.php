<?php

namespace App\Services\Search;

use App\Models\Workspace;
use App\Services\AI\QueryRewriteService;
use App\Services\AI\RerankService;
use Illuminate\Support\Collection;

/**
 * The full retrieval path for one assistant question: hybrid search, an
 * optional rewrite when literal words find nothing, then cross-encoder
 * reranking, cut to the final count.
 *
 * Shared by asking and regenerating so both cite passages retrieved the same
 * way; a regenerate that silently skipped reranking would answer from a
 * differently ordered list than the question that started the thread.
 */
class QuestionRetriever
{
    /**
     * Retrieval overfetches by this factor when reranking is on, since the
     * cross-encoder reorders the list before it is cut.
     */
    private const OVERFETCH = 3;

    public function __construct(
        private readonly HybridSearchService $search,
        private readonly QueryRewriteService $rewriter,
        private readonly RerankService $reranker,
    ) {}

    /**
     * @return Collection<int, HybridSearchResult>
     */
    public function retrieve(
        Workspace $workspace,
        string $question,
        int $limit,
        bool $rewrite = false,
    ): Collection {
        $limit = max(1, min(20, $limit));

        $fetch = $this->rerankEnabled()
            ? max($limit * self::OVERFETCH, $limit)
            : $limit;

        $passages = $this->search->search($workspace, $question, $fetch);

        // Rewriting only earns its model call when the question as asked found
        // nothing. When it did, the literal words were already the better query.
        if ($passages->isEmpty() && $rewrite) {
            $rewritten = $this->rewriter->rewrite($question);

            if ($rewritten !== null) {
                $passages = $this->search->search($workspace, $rewritten, $fetch);
            }
        }

        $passages = $this->reranker->rerank($question, $passages);

        return $passages->take($limit)->values();
    }

    private function rerankEnabled(): bool
    {
        return (bool) config('ai.rerank_enabled', true);
    }
}
