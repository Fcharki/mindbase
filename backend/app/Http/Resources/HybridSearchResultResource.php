<?php

namespace App\Http\Resources;

use App\Services\Search\HybridSearchResult;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One hit from hybrid search, with the reasoning that produced it.
 *
 * The provenance is part of the response rather than a debug detail. A passage
 * only the vector side retrieved is a paraphrase match and is worth less
 * confidence than one that also matched the question's literal words, and that
 * distinction is gone the moment the two lists are fused into one score.
 */
class HybridSearchResultResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var HybridSearchResult $result */
        $result = $this->resource;

        return [
            'score' => round($result->score, 6),
            'found_by' => $result->foundBy,
            'found_by_both' => $result->foundByBoth(),
            'lexical_rank' => $result->lexicalRank,
            'semantic_rank' => $result->semanticRank,
            'distance' => $result->distance === null
                ? null
                : round($result->distance, 6),
            'chunk' => EntryChunkResource::make($result->chunk),
        ];
    }
}
