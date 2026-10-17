<?php

namespace App\Jobs;

use App\Models\AssistantMessage;
use App\Services\AI\PassageFormatter;
use App\Services\AI\RagService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Answers a queued question from the passages stored with it.
 *
 * Re-running retrieval here would cite whatever is indexed now, not what the
 * model actually answered from.
 *
 * The answer streams out of the AI service and is flushed into the row as it
 * arrives, so a client tailing that row sees the text being written rather than
 * a single update at the end. The row only flips to `ready` once the model has
 * finished, so a partial row is never mistaken for a complete answer.
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

    /**
     * How often the partial answer is written into the row while generating.
     *
     * Every flush is a row update, so this trades a little freshness for far
     * fewer writes. The value only affects how smooth the streamed text looks,
     * never the finished answer.
     */
    private const FLUSH_INTERVAL_SECONDS = 0.75;

    /**
     * The answer must be grounded and quotable.
     *
     * Citing the bracketed passage number and quoting the sentence it came from
     * is what makes an answer checkable: a reader can open the citation and see
     * the same words, rather than trusting a paraphrase.
     */
    private const SYSTEM_PROMPT = 'You answer questions strictly from the '
        .'reference passages provided. Use only those passages; if they do not '
        .'contain the answer, say so plainly instead of guessing or using outside '
        .'knowledge. Support each claim with the passage number in brackets, for '
        .'example [1], and quote the exact phrase you relied on in "double '
        .'quotes" when it is short. Be concise and never invent a citation.';

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

        $formatted = $passages->format($message->sources ?? []);
        $partial = '';
        $lastFlush = 0.0;

        try {
            $answer = $rag->askStreaming(
                $message->question,
                $formatted,
                system: self::SYSTEM_PROMPT,
                timeout: (int) config('ai.answer_timeout'),
                onDelta: function (string $delta) use ($message, &$partial, &$lastFlush): void {
                    $partial .= $delta;

                    $now = microtime(true);

                    if ($now - $lastFlush < self::FLUSH_INTERVAL_SECONDS) {
                        return;
                    }

                    $lastFlush = $now;

                    // Quiet so the observers and timestamps stay out of it: this
                    // is progress within one pending answer, not a new state.
                    $message->updateQuietly(['answer' => $partial]);
                },
            );
        } catch (\Throwable $e) {
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
    private function fail(AssistantMessage $message, string $reason, ?\Throwable $e = null): void
    {
        if ($e !== null) {
            report($e);
        }

        $message->update([
            'status' => AssistantMessage::STATUS_FAILED,
            'error' => $reason,
            // Keep whatever was streamed as context, but it is explicitly not
            // an answer: the status above is what says so.
        ]);
    }
}
