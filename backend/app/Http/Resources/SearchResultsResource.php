<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The composite result of a workspace search, which is not a model but a
 * bundle of two collections over different tables.
 *
 * The underlying array comes from SearchService::search().
 */
class SearchResultsResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'query' => $this->resource['query'],
            'entries' => KnowledgeEntryResource::collection(
                $this->resource['entries']
            ),
            'tags' => TagResource::collection($this->resource['tags']),
        ];
    }
}
