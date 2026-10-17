<?php

namespace App\Http\Controllers;

use App\Http\Resources\TagResource;
use App\Models\Tag;
use App\Models\Workspace;
use App\Services\WorkspacePermissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * The workspace tag vocabulary and its hierarchy.
 *
 * Tags are created implicitly when attached to an entry, so this controller is
 * only about what an existing tag is nested under. A parent must belong to the
 * same workspace, and a tag may not be its own ancestor: both are checked here
 * because neither is expressible as a database constraint in this schema.
 */
class TagController extends Controller
{
    public function __construct(private readonly WorkspacePermissions $permissions) {}

    public function index(Workspace $workspace): AnonymousResourceCollection
    {
        Gate::authorize('view', $workspace);

        $tags = $workspace->tags()
            ->withCount('entries')
            ->orderBy('name')
            ->get();

        return TagResource::collection($tags);
    }

    public function update(
        Request $request,
        Workspace $workspace,
        Tag $tag
    ): JsonResponse {
        Gate::authorize('view', $workspace);

        abort_unless($tag->workspace_id === $workspace->id, 404);

        abort_unless(
            $this->permissions->canContribute($request->user(), $workspace->id),
            403,
            'You do not have permission to organize tags in this workspace.'
        );

        $validated = $request->validate([
            'parent_id' => ['nullable', 'integer', 'exists:tags,id'],
            'name' => ['sometimes', 'string', 'max:50'],
        ]);

        if (array_key_exists('parent_id', $validated) && $validated['parent_id'] !== null) {
            $this->assertParentIsUsable($tag, $workspace, (int) $validated['parent_id']);
        }

        $update = [];

        // array_key_exists, not isset: null is the meaningful "make it a root
        // tag again" value and would be dropped by isset.
        if (array_key_exists('parent_id', $validated)) {
            $update['parent_id'] = $validated['parent_id'];
        }

        if (array_key_exists('name', $validated)) {
            $update['name'] = mb_strtolower(trim((string) $validated['name']));
        }

        if ($update !== []) {
            $tag->update($update);
        }

        return response()->json(['data' => TagResource::make($tag->fresh())]);
    }

    /**
     * A parent has to be a different tag in the same workspace, and not one of
     * this tag's own descendants, which would make a cycle.
     */
    private function assertParentIsUsable(Tag $tag, Workspace $workspace, int $parentId): void
    {
        abort_if($parentId === $tag->id, 422, 'A tag cannot be its own parent.');

        $parent = Tag::whereKey($parentId)->first();

        abort_if($parent === null || $parent->workspace_id !== $workspace->id, 422, 'That tag is not in this workspace.');

        // The proposed parent must not sit below this tag already: nesting a
        // tag under one of its own descendants would close a loop.
        abort_if(
            in_array($parent->id, $tag->descendantIds(), true),
            422,
            'That would nest a tag under one of its own children.'
        );
    }
}
