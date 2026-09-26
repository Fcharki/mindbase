<?php

namespace App\Http\Controllers;

use App\Http\Requests\Search\SearchRequest;
use App\Http\Resources\SearchResultsResource;
use App\Models\Workspace;
use App\Services\Search\SearchService;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;

class SearchController extends Controller
{
    public function __construct(private readonly SearchService $search) {}

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
}
