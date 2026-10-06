<?php

namespace App\Http\Controllers;

use App\Http\Requests\Reply\ReplyRequest;
use App\Http\Resources\ReplyResource;
use App\Models\Comment;
use App\Models\KnowledgeEntry;
use App\Models\Reply;
use App\Models\User;
use App\Models\Workspace;
use App\Notifications\RepliedToComment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;

/**
 * Replies to a comment.
 *
 * Nested under the entry as well as the comment, rather than only under the
 * comment, so every authorization here can be taken against a route-bound
 * entry and no request has to resolve a comment before it can decide whether it
 * is allowed.
 */
class ReplyController extends Controller
{
    public function index(
        Workspace $workspace,
        KnowledgeEntry $entry,
        Comment $comment
    ): AnonymousResourceCollection {
        $this->abortUnlessEntryBelongsToWorkspace($entry, $workspace);

        $this->abortUnlessCommentBelongsToEntry($comment, $entry);

        Gate::authorize('viewAny', [Reply::class, $entry]);

        $replies = $comment
            ->replies()
            ->with('user:id,name')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        return ReplyResource::collection($replies);
    }

    public function store(
        ReplyRequest $request,
        Workspace $workspace,
        KnowledgeEntry $entry,
        Comment $comment
    ): JsonResponse {
        $this->abortUnlessEntryBelongsToWorkspace($entry, $workspace);

        $this->abortUnlessCommentBelongsToEntry($comment, $entry);

        Gate::authorize('create', [Reply::class, $entry]);

        $reply = $comment->replies()->create([
            'user_id' => $request->user()->id,
            'body' => $request->validated('body'),
        ]);

        $reply->load('user:id,name');

        $this->notifyCommentAuthor($reply, $comment, $request->user());

        return ReplyResource::make($reply)
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Tell the comment's author that someone replied to it.
     *
     * The comment's author rather than the entry's author: a reply belongs to
     * the thread, and the person who wrote the comment is the one who has an
     * answer to give. Notifying the entry's author as well would mean a popular
     * entry's author is notified about every exchange between other people.
     *
     * Skipped when the reply is to the replier's own comment.
     */
    private function notifyCommentAuthor(
        Reply $reply,
        Comment $comment,
        User $actor,
    ): void {
        $author = $comment->user;

        if ($author === null || $author->id === $actor->id) {
            return;
        }

        $author->notify(new RepliedToComment($reply, $actor));
    }

    public function update(
        ReplyRequest $request,
        Workspace $workspace,
        KnowledgeEntry $entry,
        Comment $comment,
        Reply $reply
    ): JsonResource {
        $this->abortUnlessEntryBelongsToWorkspace($entry, $workspace);

        $this->abortUnlessCommentBelongsToEntry($comment, $entry);

        $this->abortUnlessReplyBelongsToComment($reply, $comment);

        Gate::authorize('update', [$reply, $entry]);

        $reply->update([
            'body' => $request->validated('body'),
        ]);

        $reply->load('user:id,name');

        return ReplyResource::make($reply);
    }

    public function destroy(
        Workspace $workspace,
        KnowledgeEntry $entry,
        Comment $comment,
        Reply $reply
    ): JsonResponse {
        $this->abortUnlessEntryBelongsToWorkspace($entry, $workspace);

        $this->abortUnlessCommentBelongsToEntry($comment, $entry);

        $this->abortUnlessReplyBelongsToComment($reply, $comment);

        Gate::authorize('delete', [$reply, $entry]);

        $reply->delete();

        return response()->json([
            'message' => 'Reply deleted successfully.',
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

    /**
     * Without this, a reply could be reached through any comment in the entry,
     * which would let one thread's reply be deleted or edited from another.
     */
    private function abortUnlessReplyBelongsToComment(
        Reply $reply,
        Comment $comment
    ): void {
        abort_unless(
            $reply->comment_id === $comment->id,
            404
        );
    }
}
