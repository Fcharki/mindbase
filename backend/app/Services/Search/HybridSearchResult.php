<?php

namespace App\Services\Search;

use App\Models\EntryChunk;

/**
 * One chunk returned by hybrid search, with the reasoning kept alongside it.
 *
 * The provenance matters beyond debugging. A result only the vector side found
 * is a paraphrase match and is worth trusting less than one that also matched
 * the literal words of the question, and that difference is invisible once the
 * scores have been fused into a single number.
 */
class HybridSearchResult
{
    /**
     * @param  list<string>  $foundBy  Which search methods retrieved this chunk.
     * @param  int|null  $lexicalRank  Position in the keyword list, if found.
     * @param  int|null  $semanticRank  Position in the vector list, if found.
     * @param  float|null  $distance  Raw cosine distance, for display.
     */
    public function __construct(
        public readonly EntryChunk $chunk,
        public readonly float $score,
        public readonly array $foundBy = [],
        public readonly ?int $lexicalRank = null,
        public readonly ?int $semanticRank = null,
        public readonly ?float $distance = null,
    ) {}

    public function foundByBoth(): bool
    {
        return in_array('lexical', $this->foundBy, true)
            && in_array('semantic', $this->foundBy, true);
    }
}
