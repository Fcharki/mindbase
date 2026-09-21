<?php

namespace App\Http\Controllers;

use App\Models\Workspace;
use App\Services\Search\SearchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class SearchController extends Controller
{
    public function __construct(private readonly SearchService $search) {}

    public function __invoke(Request $request, Workspace $workspace): JsonResponse
    {
        Gate::authorize('view', $workspace);

        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'tag' => ['nullable', 'string', 'max:60'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        return response()->json([
            'data' => $this->search->search(
                $workspace,
                $validated['q'] ?? '',
                $validated['tag'] ?? null,
                $validated['limit'] ?? 20,
            ),
        ]);
    }
}
