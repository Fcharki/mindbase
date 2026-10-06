<?php

namespace App\Http\Controllers;

use App\Http\Requests\Comment\CommentRequest;
use App\Http\Resources\CommentResource;
use App\Models\Comment;
use App\Models\KnowledgeEntry;
use App\Models\User;
use App\Models\Workspace;
use App\Notifications\CommentedOnEntry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;

class CommentController extends Controller
{
    /**
     * The discussion on an entry, oldest first, each comment carrying its
     * replies.
     *
     * The author and the replies are eager loaded because every row needs them;
     * letting them lazy load would be two queries per comment. The entries the
     * replies point at are not loaded, since nothing here renders one.
     */
    public function index(
        Workspace $workspace,
        KnowledgeEntry $entry
    ): AnonymousResourceCollection {
        $this->abortUnlessEntryBelongsToWorkspace($entry, $workspace);

        Gate::authorize('viewAny', [Comment::class, $entry]);

        $comments = $entry
            ->comments()
            ->with([
                'user:id,name',
                'replies' => fn ($query) => $query
                    ->with('user:id,name')
                    ->orderBy('created_at')
                    ->orderBy('id'),
            ])
            ->orderBy('created_at')
            // Timestamps have second precision, so two comments written in the
            // same second would otherwise come back in an arbitrary order.
            ->orderBy('id')
            ->get();

        return CommentResource::collection($comments);
    }

    public function store(
        CommentRequest $request,
        Workspace $workspace,
        KnowledgeEntry $entry
    ): JsonResponse {
        $this->abortUnlessEntryBelongsToWorkspace($entry, $workspace);

        Gate::authorize('create', [Comment::class, $entry]);

        $comment = $entry->comments()->create([
            'user_id' => $request->user()->id,
            'body' => $request->validated('body'),
        ]);

        $comment->load('user:id,name');

        $this->notifyEntryAuthor($comment, $request->user());

        return CommentResource::make($comment)
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Tell the entry's author that someone commented on it.
     *
     * The author is the entry's creator rather than every member of the
     * workspace: notifying a whole workspace on every comment turns a
     * discussion into a flood, and the people who care about a comment on an
     * entry are the ones already reading it.
     *
     * Skipped when the commenter wrote the entry, because being notified about
     * your own comment is noise.
     */
    private function notifyEntryAuthor(
        Comment $comment,
        User $actor,
    ): void {
        $author = $comment->knowledgeEntry?->creator;

        if ($author === null || $author->id === $actor->id) {
            return;
        }

        $author->notify(new CommentedOnEntry($comment, $actor));
    }

    public function update(
        CommentRequest $request,
        Workspace $workspace,
        KnowledgeEntry $entry,
        Comment $comment
    ): JsonResource {
        $this->abortUnlessEntryBelongsToWorkspace($entry, $workspace);

        $this->abortUnlessCommentBelongsToEntry($comment, $entry);

        Gate::authorize('update', [$comment, $entry]);

        $comment->update([
            'body' => $request->validated('body'),
        ]);

        $comment->load('user:id,name');

        return CommentResource::make($comment);
    }

    /**
     * Deleting a comment takes its replies with it.
     *
     * The database cascades replies, so nothing here is needed to make that
     * happen; it is stated because a caller may reasonably expect the replies
     * to survive as replies to nothing, and they should not.
     */
    public function destroy(
        Workspace $workspace,
        KnowledgeEntry $entry,
        Comment $comment
    ): JsonResponse {
        $this->abortUnlessEntryBelongsToWorkspace($entry, $workspace);

        $this->abortUnlessCommentBelongsToEntry($comment, $entry);

        Gate::authorize('delete', [$comment, $entry]);

        $comment->delete();

        return response()->json([
            'message' => 'Comment deleted successfully.',
        ]);
    }

    private function abortUnlessEntryBelongsToWorkspace(
        KnowledgeEntry $entry,
        Workspace $workspace
    ): void {
        abort_unless(
            $entry->workspace_id === $workspace->id,
            404
        );
    }

    private function abortUnlessCommentBelongsToEntry(
        Comment $comment,
        KnowledgeEntry $entry
    ): void {
        abort_unless(
            $comment->knowledge_entry_id === $entry->id,
            404
        );
    }
}
