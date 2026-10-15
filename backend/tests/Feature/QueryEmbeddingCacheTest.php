<?php

namespace Tests\Feature;

use App\Services\AI\EmbeddingService;
use App\Services\Search\QueryEmbeddingCache;
use Tests\TestCase;

/**
 * The cache in front of the embedding model for a search question.
 *
 * The model is the slowest part of a search, and a debounced search box asks the
 * same question repeatedly, so what is proved here is that identical text is
 * embedded once, that different text is not conflated, and that a failure is not
 * remembered as an answer.
 */
class QueryEmbeddingCacheTest extends TestCase
{
    /**
     * Counts embed calls without constructing the HTTP client. The override
     * skips the parent constructor for the same reason the search endpoint's
     * fake does: there is no model to talk to here.
     */
    private function fakeEmbeddings(): EmbeddingService
    {
        return new class extends EmbeddingService
        {
            public int $calls = 0;

            public function __construct() {}

            public function embed(string $text): array
            {
                $this->calls++;

                return array_fill(0, config('ai.embedding_dimension'), 0.1);
            }
        };
    }

    public function test_the_same_question_is_embedded_once(): void
    {
        $embeddings = $this->fakeEmbeddings();
        $cache = new QueryEmbeddingCache($embeddings);

        $first = $cache->vectorFor('how do tokens expire');
        $second = $cache->vectorFor('how do tokens expire');

        $this->assertSame($first, $second);
        $this->assertSame(1, $embeddings->calls);
    }

    public function test_a_different_question_is_embedded_separately(): void
    {
        $embeddings = $this->fakeEmbeddings();
        $cache = new QueryEmbeddingCache($embeddings);

        $cache->vectorFor('how do tokens expire');
        $cache->vectorFor('how do credentials expire');

        $this->assertSame(2, $embeddings->calls);
    }

    public function test_a_failed_embedding_is_not_cached(): void
    {
        $embeddings = new class extends EmbeddingService
        {
            public int $calls = 0;

            public function __construct() {}

            public function embed(string $text): array
            {
                $this->calls++;

                if ($this->calls === 1) {
                    throw new \RuntimeException('model unavailable');
                }

                return array_fill(0, config('ai.embedding_dimension'), 0.2);
            }
        };

        $cache = new QueryEmbeddingCache($embeddings);

        try {
            $cache->vectorFor('how do tokens expire');
            $this->fail('The first call was expected to fail.');
        } catch (\RuntimeException) {
            // The failure is the point: it must not be remembered as an answer.
        }

        $cache->vectorFor('how do tokens expire');

        $this->assertSame(2, $embeddings->calls);
    }

    public function test_the_model_width_is_part_of_the_key(): void
    {
        // A vector is only meaningful next to vectors from the same model, and
        // the width is the one thing the PHP side knows about it. Changing it
        // must not hand back a vector built at the old width.
        $embeddings = $this->fakeEmbeddings();
        $cache = new QueryEmbeddingCache($embeddings);

        $cache->vectorFor('how do tokens expire');

        config(['ai.embedding_dimension' => 768]);

        $cache->vectorFor('how do tokens expire');

        $this->assertSame(2, $embeddings->calls);
    }
}
