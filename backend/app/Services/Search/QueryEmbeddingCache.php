<?php

namespace App\Services\Search;

use App\Services\AI\EmbeddingService;
use Illuminate\Support\Facades\Cache;

/**
 * Remembers the vector for a search question.
 *
 * The embedding model is the slowest part of a search -- about a second warm,
 * minutes cold -- and a debounced search box asks for the same question again on
 * almost every keystroke that survives the pause, so the same text is embedded
 * over and over. A question's vector depends only on its text and on the model,
 * not on the workspace or the caller, so one cache entry serves every search of
 * that text.
 *
 * The embedding dimension is part of the key because it is the only handle the
 * PHP side has on which model produced the vector: a stored vector is only
 * meaningful next to vectors built by the same model.
 */
class QueryEmbeddingCache
{
    public function __construct(private readonly EmbeddingService $embeddings) {}

    /**
     * The question's vector, embedding it only on a miss.
     *
     * A failure is not cached: the client throws before the closure returns, so
     * the next search of a recovering model is allowed to try again.
     *
     * @return list<float>
     */
    public function vectorFor(string $question): array
    {
        return Cache::remember(
            $this->key($question),
            config('ai.embedding_cache_ttl'),
            fn (): array => $this->embeddings->embed($question),
        );
    }

    private function key(string $question): string
    {
        return 'search:embedding:'
            .config('ai.embedding_dimension')
            .':'
            .hash('sha256', $question);
    }
}
