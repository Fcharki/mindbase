<?php

namespace App\Actions\Workspace;

use App\Actions\Action;
use App\Exceptions\ActionFailedException;
use App\Jobs\GenerateAssistantAnswer;
use App\Models\AssistantMessage;
use App\Services\AI\AssistantSources;
use App\Services\Search\QuestionRetriever;

/**
 * Re-answers an existing question in place.
 *
 * Retrieval runs again rather than reusing the stored passages: the point of a
 * regenerate is usually that the first answer was weak, and re-reading the same
 * list would reproduce it. The row is reused rather than replaced so the
 * question keeps one place in the thread and its history stays linear.
 */
class RegenerateAssistantAnswer implements Action
{
    private const PASSAGES = 8;

    public function __construct(
        private readonly AssistantMessage $message,
        private readonly QuestionRetriever $retriever,
        private readonly int $topK = self::PASSAGES,
        private readonly bool $rewriteQuery = false,
    ) {}

    /**
     * @throws ActionFailedException
     */
    public function handle(): array
    {
        $question = trim($this->message->question);

        $passages = $this->retriever->retrieve(
            $this->message->workspace,
            $question,
            max(1, min(20, $this->topK)),
            $this->rewriteQuery,
        );

        if ($passages->isEmpty()) {
            throw new ActionFailedException(
                'Nothing in this workspace matches that question right now, so there is nothing to answer from. Add or edit entries, then try again.',
                404
            );
        }

        $this->message->update([
            'sources' => AssistantSources::fromPassages($passages),
            'answer' => null,
            'error' => null,
            'feedback' => null,
            'status' => AssistantMessage::STATUS_PENDING,
        ]);

        GenerateAssistantAnswer::dispatch($this->message->id);

        return $this->message->fresh()->toArray();
    }
}
