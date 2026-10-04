<?php

namespace App\Http\Controllers;

use App\Http\Requests\Search\SearchChunksRequest;
use App\Http\Requests\Search\SearchRequest;
use App\Http\Resources\HybridSearchResultResource;
use App\Http\Resources\SearchResultsResource;
use App\Models\Workspace;
use App\Services\Search\HybridSearchService;
use App\Services\Search\SearchService;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Support\Facades\Gate;

class SearchController extends Controller
{
    public function __construct(
        private readonly SearchService $search,
        private readonly HybridSearchService $hybrid,
    ) {}

    public function __invoke(SearchRequest $request, Workspace $workspace): JsonResource
    {
        Gate::authorize('view', $workspace);

        return SearchResultsResource::make($this->search->search(
            $workspace,
            $request->searchTerm(),
            $request->tag(),
            $request->limit(),
        ));
    }

    /**
     * The passages of this workspace most likely to answer a question.
     *
     * Separate from the search above on purpose. That one ranks whole entries by
     * keyword, which is the right shape for browsing a list. This one is what
     * retrieval feeds an answer with: it returns the individual passages a
     * question matches, whether it matched their words or their meaning.
     */
    public function chunks(
        SearchChunksRequest $request,
        Workspace $workspace
    ): ResourceCollection {
        Gate::authorize('view', $workspace);

        return HybridSearchResultResource::collection(
            $this->hybrid->search(
                $workspace,
                $request->searchTerm(),
                $request->limit(),
            )
        );
    }
}
