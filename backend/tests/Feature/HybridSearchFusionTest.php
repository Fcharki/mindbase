<?php

namespace Tests\Feature;

use App\Models\EntryChunk;
use App\Services\Search\HybridSearchResult;
use App\Services\Search\HybridSearchService;
use Illuminate\Support\Collection;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Exercises the fusion arithmetic on hand-built ranked lists.
 *
 * Fusing runs on every query, and a mistake in it does not throw: it just quietly
 * returns the wrong order, which is the kind of bug that survives a long time
 * being blamed on the search index instead. The engine specific SQL cannot run
 * under sqlite, so the arithmetic is tested on its own here.
 */
class HybridSearchFusionTest extends TestCase
{
    private function fuse(Collection $lexical, Collection $semantic): Collection
    {
        $method = new ReflectionMethod(HybridSearchService::class, 'fuse');
        $method->setAccessible(true);

        // The constructor needs an embedding service, but fuse never touches it.
        return $method->invoke(
            $this->app->make(HybridSearchService::class),
            $lexical,
            $semantic,
        );
    }

    /**
     * @param  list<float|null>  $distances  One per chunk, null for the lexical side.
     */
    private function chunks(array $ids, array $distances = []): Collection
    {
        return collect($ids)->values()->map(function (int $id, int $index) use ($distances) {
            $chunk = new EntryChunk(['source' => 'content', 'body' => "chunk {$id}"]);
            $chunk->id = $id;
            $chunk->setAttribute('distance', $distances[$index] ?? null);

            return $chunk;
        });
    }

    public function test_a_chunk_found_by_both_engines_outranks_one_found_by_either(): void
    {
        // 1 is first for both, 2 is first for lexical only, 3 is first for
        // semantic only. 1 should win despite tying on each individual list.
        $results = $this->fuse(
            $this->chunks([1, 2]),
            $this->chunks([3, 1], [0.2, 0.2]),
        );

        $this->assertSame(1, $results[0]->chunk->id);
        $this->assertTrue($results[0]->foundByBoth());
    }

    public function test_it_reports_which_engine_found_each_result(): void
    {
        $results = $this->fuse(
            $this->chunks([7]),
            $this->chunks([7, 8], [0.1, 0.1]),
        );

        $byId = $results->keyBy(fn (HybridSearchResult $r) => $r->chunk->id);

        $this->assertSame(['lexical', 'semantic'], $byId[7]->foundBy);
        $this->assertSame(['semantic'], $byId[8]->foundBy);
    }

    public function test_it_keeps_the_rank_each_engine_gave(): void
    {
        $results = $this->fuse(
            $this->chunks([7, 8]),
            $this->chunks([8], [0.1]),
        );

        $byId = $results->keyBy(fn (HybridSearchResult $r) => $r->chunk->id);

        // 7 leads the keyword list, 8 leads the vector list.
        $this->assertSame(1, $byId[7]->lexicalRank);
        $this->assertNull($byId[7]->semanticRank);
        $this->assertSame(2, $byId[8]->lexicalRank);
        $this->assertSame(1, $byId[8]->semanticRank);
    }

    public function test_scores_fall_as_rank_worsens(): void
    {
        // Nothing is found twice, so this is a clean read of how rank maps to
        // score: strictly decreasing.
        $results = $this->fuse($this->chunks([1, 2, 3]), collect());

        $scores = $results->map(fn (HybridSearchResult $r) => $r->score)->all();

        $this->assertGreaterThan($scores[1], $scores[0]);
        $this->assertGreaterThan($scores[2], $scores[1]);
    }

    public function test_a_chunk_only_the_vector_side_found_still_appears(): void
    {
        // The whole reason for fusing: neither list alone is the answer. The
        // keyword list never mentions 3, and the vector list never mentions 1.
        $results = $this->fuse(
            $this->chunks([1]),
            $this->chunks([2, 3], [0.2, 0.3]),
        );

        $ids = $results->map(fn (HybridSearchResult $r) => $r->chunk->id)->all();

        $this->assertCount(3, $results);
        $this->assertContains(1, $ids, 'A keyword-only hit must survive the fusion.');
        $this->assertContains(3, $ids, 'A vector-only hit must survive the fusion.');
    }

    public function test_vectors_pointing_the_other_way_are_discarded(): void
    {
        // Distance near 2.0 means the vectors are opposed, not merely distant.
        $results = $this->fuse(
            collect(),
            $this->chunks([1], [1.9]),
        );

        $this->assertCount(0, $results);
    }

    public function test_a_weak_vector_match_is_discarded(): void
    {
        // bge-m3 does not spread its vectors over the whole range, so a distant
        // hit is not a weak match but noise. Measured unrelated pairs sat at
        // 0.72 and above; a real paraphrase of the passage landed at 0.39.
        $results = $this->fuse(
            collect(),
            $this->chunks([1], [0.72]),
        );

        $this->assertCount(0, $results);
    }

    public function test_a_close_vector_match_survives_the_floor(): void
    {
        $results = $this->fuse(
            collect(),
            $this->chunks([1], [0.39]),
        );

        $this->assertCount(1, $results);
        $this->assertSame('semantic', $results[0]->foundBy[0]);
    }

    public function test_a_vector_without_a_distance_is_discarded(): void
    {
        // A missing distance cannot be vouched for, so it is not treated as a match.
        $results = $this->fuse(collect(), $this->chunks([1]));

        $this->assertCount(0, $results);
    }

    public function test_it_falls_back_to_keyword_order_when_no_vectors_qualify(): void
    {
        $results = $this->fuse(
            $this->chunks([5, 6]),
            $this->chunks([6, 5], [1.5, 1.5]),
        );

        // Still a usable answer, just without the semantic half.
        $this->assertCount(2, $results);
        $this->assertSame([5, 6], $results->map(fn ($r) => $r->chunk->id)->all());
    }

    public function test_two_empty_lists_produce_nothing(): void
    {
        $this->assertCount(0, $this->fuse(collect(), collect()));
    }

    public function test_duplicate_chunks_are_not_counted_twice(): void
    {
        // An engine returning the same chunk in its own list must not be able to
        // inflate that chunk's score by listing it repeatedly.
        $results = $this->fuse(
            $this->chunks([1, 1, 1]),
            collect(),
        );

        $this->assertCount(1, $results);
        $this->assertEqualsWithDelta(1 / 61, $results[0]->score, 0.0001);
    }

    public function test_the_raw_distance_is_carried_through(): void
    {
        $results = $this->fuse(collect(), $this->chunks([1], [0.25]));

        $this->assertSame(0.25, $results[0]->distance);
    }

    public function test_results_are_returned_best_first(): void
    {
        $results = $this->fuse(
            $this->chunks([1, 2, 3]),
            $this->chunks([3, 2, 1], [0.1, 0.1, 0.1]),
        );

        $scores = $results->map(fn (HybridSearchResult $r) => $r->score)->all();

        $sorted = $scores;
        rsort($sorted);

        $this->assertSame($sorted, $scores);
    }

    public function test_the_service_constructs_without_a_live_model(): void
    {
        // fuse() takes no dependency on the embedding client, so the arithmetic
        // can be tested without one.
        $this->assertInstanceOf(HybridSearchService::class, $this->app->make(HybridSearchService::class));
    }
}
