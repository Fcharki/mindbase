<?php

namespace App\Jobs;

use App\Models\AssistantMessage;
use App\Services\AI\PassageFormatter;
use App\Services\AI\RagService;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Answers a queued question from the passages stored with it.
 *
 * Re-running retrieval here would cite whatever is indexed now, not what the
 * model actually answered from.
 */
class GenerateAssistantAnswer implements ShouldQueue
{
    use Queueable;

    /**
     * Must clear ai.answer_timeout (2400), the call this job waits on, and stay
     * under DB_QUEUE_RETRY_AFTER (3000) so the job is never claimed twice.
     */
    public int $timeout = 2700;

    public int $tries = 2;

    public function __construct(
        public readonly int $assistantMessageId,
    ) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [60];
    }

    public function handle(RagService $rag, PassageFormatter $passages): void
    {
        $message = AssistantMessage::find($this->assistantMessageId);

        // Deleted while queued, or already answered by a retried attempt.
        if ($message === null || ! $message->isPending()) {
            return;
        }

        try {
            $answer = $rag->ask(
                $message->question,
                $passages->format($message->sources ?? []),
                timeout: (int) config('ai.answer_timeout'),
            );
        } catch (GuzzleException $e) {
            $this->fail($message, 'The AI service is unavailable. Please try again.', $e);

            return;
        }

        if (trim($answer) === '') {
            $this->fail($message, 'The AI service did not return an answer. Please try again.');

            return;
        }

        $message->update([
            'answer' => $answer,
            'status' => AssistantMessage::STATUS_READY,
            'error' => null,
        ]);
    }

    /**
     * Left visible on purpose: an unanswered turn is more honest than a missing
     * one, and it shows the user that retrying is worthwhile.
     */
    private function fail(AssistantMessage $message, string $reason, ?GuzzleException $e = null): void
    {
        if ($e !== null) {
            report($e);
        }

        $message->update([
            'status' => AssistantMessage::STATUS_FAILED,
            'error' => $reason,
        ]);
    }
}
