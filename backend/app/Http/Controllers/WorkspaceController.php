<?php

namespace App\Http\Controllers;

use App\Actions\Workspace\AskWorkspace;
use App\Actions\Workspace\CreateWorkspace;
use App\Actions\Workspace\JoinWorkspace;
use App\Actions\Workspace\RegenerateAssistantAnswer;
use App\Http\Requests\Workspace\AskOptionsRequest;
use App\Http\Requests\Workspace\AskWorkspaceRequest;
use App\Http\Requests\Workspace\WorkspaceRequest;
use App\Http\Resources\JoinableWorkspaceResource;
use App\Http\Resources\WorkspaceResource;
use App\Models\AssistantMessage;
use App\Models\MembershipRequest;
use App\Models\Workspace;
use App\Services\WorkspaceIndexCache;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;

class WorkspaceController extends Controller
{
    /**
     * Accept a question about the workspace from its own entries.
     *
     * Returns 202 with a pending message, not the answer: generation takes
     * minutes here, so the client polls answer() until the status flips.
     */
    public function ask(AskWorkspaceRequest $request, Workspace $workspace): JsonResponse
    {
        Gate::authorize('view', $workspace);

        return response()->json([
            'data' => app(AskWorkspace::class, [
                'workspace' => $workspace,
                'user' => $request->user(),
                'question' => $request->question(),
                'topK' => $request->topK(),
                'rewriteQuery' => $request->rewrite(),
            ])->handle(),
        ], 202);
    }

    /**
     * One question and its answer, for polling after an ask.
     */
    public function answer(Request $request, Workspace $workspace, int $message): JsonResponse
    {
        Gate::authorize('view', $workspace);

        // Scoped to the workspace so an id from elsewhere reads as absent rather
        // than as someone else's conversation.
        $found = $workspace->assistantMessages()
            ->whereKey($message)
            ->first();

        abort_if($found === null, 404, 'Question not found.');

        return response()->json(['data' => $found]);
    }

    /**
     * The thread so far: recent questions asked of this workspace, newest first.
     *
     * Workspace scoped rather than user scoped, matching answer() above, so a
     * shared workspace reads as one conversation rather than one per person.
     */
    public function messages(Request $request, Workspace $workspace): JsonResponse
    {
        Gate::authorize('view', $workspace);

        $limit = min(50, max(1, (int) $request->query('limit', 30)));

        $messages = $workspace->assistantMessages()
            ->with('user:id,name')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        return response()->json(['data' => $messages]);
    }

    /**
     * Answer the same question again from freshly retrieved passages.
     */
    public function regenerate(
        AskOptionsRequest $request,
        Workspace $workspace,
        int $message
    ): JsonResponse {
        Gate::authorize('view', $workspace);

        $found = $workspace->assistantMessages()
            ->whereKey($message)
            ->first();

        abort_if($found === null, 404, 'Question not found.');

        abort_if(
            $found->status === AssistantMessage::STATUS_PENDING,
            409,
            'This question is still being answered.'
        );

        return response()->json([
            'data' => app(RegenerateAssistantAnswer::class, [
                'message' => $found,
                'topK' => $request->topK(),
                'rewriteQuery' => $request->rewrite(),
            ])->handle(),
        ], 202);
    }

    /**
     * Record whether the answer was useful, or clear an earlier opinion.
     */
    public function feedback(
        Request $request,
        Workspace $workspace,
        int $message
    ): JsonResponse {
        Gate::authorize('view', $workspace);

        $found = $workspace->assistantMessages()
            ->whereKey($message)
            ->first();

        abort_if($found === null, 404, 'Question not found.');

        $validated = $request->validate([
            'feedback' => ['nullable', 'string', 'in:up,down'],
        ]);

        $found->update(['feedback' => $validated['feedback'] ?? null]);

        return response()->json(['data' => $found->fresh()]);
    }

    /**
     * Server-sent events for one answer: the text as it is written, then done.
     *
     * Generation itself still runs in the job -- a model call this long cannot
     * hold a request. What streams here is the job's progress: it flushes the
     * partial answer into the row as tokens arrive, and this endpoint tails that
     * row. Polling and streaming therefore show the same text; streaming just
     * shows it sooner and without the client choosing an interval.
     */
    public function stream(Workspace $workspace, int $message)
    {
        Gate::authorize('view', $workspace);

        $found = $workspace->assistantMessages()
            ->whereKey($message)
            ->first();

        abort_if($found === null, 404, 'Question not found.');

        // The job's own answer budget, plus a margin for the job to have been
        // picked up. Past it, the row is either finished or the worker is gone;
        // staying open longer only keeps a dead connection alive.
        $deadline = microtime(true) + (int) config('ai.answer_timeout') + 120;

        return response()->stream(function () use ($found, $deadline) {
            @set_time_limit(0);
            ignore_user_abort(true);

            $id = $found->id;
            $sent = 0;

            while (true) {
                $fresh = AssistantMessage::find($id);

                if ($fresh === null) {
                    $this->emitStream(['type' => 'error', 'message' => 'This answer is no longer available.']);

                    break;
                }

                $answer = (string) ($fresh->answer ?? '');
                $length = mb_strlen($answer);

                if ($length > $sent) {
                    $this->emitStream([
                        'type' => 'delta',
                        'text' => mb_substr($answer, $sent, $length - $sent),
                    ]);
                    $sent = $length;
                }

                if ($fresh->status === AssistantMessage::STATUS_READY) {
                    $this->emitStream(['type' => 'done']);

                    break;
                }

                if ($fresh->status === AssistantMessage::STATUS_FAILED) {
                    $this->emitStream([
                        'type' => 'error',
                        'message' => $fresh->error ?? 'The AI service could not answer that.',
                    ]);

                    break;
                }

                if (microtime(true) > $deadline) {
                    $this->emitStream([
                        'type' => 'timeout',
                        'message' => 'This is taking longer than expected. The answer may still arrive; refresh to check.',
                    ]);

                    break;
                }

                if (connection_aborted()) {
                    break;
                }

                usleep(400_000);
            }

            flush();
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache, no-store, no-transform',
            'X-Accel-Buffering' => 'no',
            'Connection' => 'keep-alive',
        ]);
    }

    /**
     * One SSE frame. Echoed straight through so a client can read the JSON
     * without this endpoint inventing a second schema for it.
     *
     * @param  array<string, mixed>  $payload
     */
    private function emitStream(array $payload): void
    {
        echo 'data: '.json_encode($payload, JSON_UNESCAPED_UNICODE)."\n\n";
        @ob_flush();
        flush();
    }

    public function byInviteCode(string $inviteCode): JsonResource
    {
        $workspace = Workspace::where('invite_code', $inviteCode)
            ->with('owner:id,name')
            ->first();

        abort_if($workspace === null, 404, 'Workspace not found.');

        return JoinableWorkspaceResource::make($workspace);
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Workspace::class);

        $user = $request->user();

        $workspaces = app(WorkspaceIndexCache::class)->remember(
            $user->id,
            fn () => Workspace::query()
                ->leftJoin('workspace_members as current_user_membership', function (JoinClause $join) use ($user) {
                    $join->on('current_user_membership.workspace_id', '=', 'workspaces.id')
                        ->where('current_user_membership.user_id', '=', $user->id);
                })
                ->addSelect('workspaces.*')
                ->addSelect('current_user_membership.role as current_user_role')
                ->addSelect(['current_user_join_request_status' => MembershipRequest::query()
                    ->select('status')
                    ->whereColumn('workspace_id', 'workspaces.id')
                    ->where('user_id', $user->id)
                    ->where('status', MembershipRequest::STATUS_PENDING)
                    ->limit(1),
                ])
                ->with('owner:id,name,email')
                ->withCount(['knowledgeEntries as entries_count'])
                ->orderBy('workspaces.name')
                ->get()
        );

        return WorkspaceResource::collection($workspaces);
    }

    public function join(Request $request, Workspace $workspace): JsonResponse
    {
        // The only legitimate direct join is redeeming an invite code. Without
        // the code this endpoint would let any authenticated user add
        // themselves to any workspace by id, so it is checked here and not left
        // to the client.
        $validated = $request->validate([
            'invite_code' => ['required', 'string'],
        ]);

        abort_unless(
            $workspace->invite_code !== null
                && hash_equals($workspace->invite_code, $validated['invite_code']),
            403,
            'A valid invite code is required to join this workspace.'
        );

        $user = $request->user();

        $created = app(JoinWorkspace::class, [
            'workspace' => $workspace,
            'user' => $user,
        ])->handle();

        return response()->json([
            'data' => [
                'workspace_id' => $workspace->id,
                'user_id' => $user->id,
                'role' => 'member',
            ],
        ], $created ? 201 : 200);
    }

    public function store(WorkspaceRequest $request): JsonResponse
    {
        Gate::authorize('create', Workspace::class);

        $user = $request->user();

        $workspace = app(CreateWorkspace::class, [
            'name' => $request->validated('name'),
            'owner' => $user,
        ])->handle();

        return WorkspaceResource::make(
            $workspace->load('owner:id,name,email')
        )->response()->setStatusCode(201);
    }

    public function show(Workspace $workspace): WorkspaceResource
    {
        Gate::authorize('view', $workspace);

        $workspace->load(['owner:id,name,email', 'members:id,name,email']);

        return WorkspaceResource::make($workspace);
    }

    public function update(
        WorkspaceRequest $request,
        Workspace $workspace
    ): WorkspaceResource {
        Gate::authorize('update', $workspace);

        $workspace->update($request->validated());

        $workspace->load('owner:id,name,email');

        return WorkspaceResource::make($workspace);
    }

    public function destroy(Workspace $workspace): JsonResponse
    {
        Gate::authorize('delete', $workspace);

        $workspace->delete();

        return response()->json([
            'message' => 'Workspace deleted successfully.',
        ]);
    }
}
