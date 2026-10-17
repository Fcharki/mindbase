<?php

namespace App\Actions\Workspace;

use App\Actions\Action;
use App\Exceptions\ActionFailedException;
use App\Jobs\GenerateAssistantAnswer;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AI\AssistantSources;
use App\Services\Search\QuestionRetriever;

/**
 * Accepts a question about a workspace and queues the work of answering it.
 *
 * Retrieval runs here, not in the job, so a question with nothing to answer is
 * refused immediately instead of after a multi-minute model call. It also runs
 * before the row is written, so the stored sources are exactly the passages
 * this question retrieved, not whatever happens to be indexed when the job
 * eventually runs.
 */
class AskWorkspace implements Action
{
    /**
     * Cheap next to generation, and more passages ground the answer better.
     */
    private const PASSAGES = 8;

    public function __construct(
        private readonly Workspace $workspace,
        private readonly User $user,
        private readonly string $question,
        private readonly QuestionRetriever $retriever,
        private readonly int $topK = self::PASSAGES,
        private readonly bool $rewriteQuery = false,
    ) {}

    /**
     * @throws ActionFailedException
     */
    public function handle(): array
    {
        $question = trim($this->question);

        if ($question === '') {
            throw new ActionFailedException('Ask a question first.', 422);
        }

        $passages = $this->retriever->retrieve(
            $this->workspace,
            $question,
            max(1, min(20, $this->topK)),
            $this->rewriteQuery,
        );

        if ($passages->isEmpty()) {
            throw new ActionFailedException(
                'I don\'t have enough information in this workspace to answer that confidently. Add more relevant entries or reword the question.',
                404
            );
        }

        $message = $this->workspace->assistantMessages()->create([
            'user_id' => $this->user->id,
            'question' => $question,
            'sources' => AssistantSources::fromPassages($passages),
            'status' => 'pending',
        ]);

        // Dispatched after the row exists, because the job looks it up by id.
        GenerateAssistantAnswer::dispatch($message->id);

        return $message->fresh()->toArray();
    }
}
