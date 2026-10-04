<?php

namespace App\Jobs;

use App\Exceptions\ActionFailedException;
use App\Models\KnowledgeEntry;
use App\Services\AI\BackendBudget;
use App\Services\AI\SummarizationService;
use App\Services\EntrySummaryService;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Generates the stored summary of an entry's content.
 *
 * Dispatched whenever an entry's content changes. The content fingerprint is
 * carried on the job rather than read from the entry at handling time, so a
 * summary of a revision the user has already moved past is discarded instead of
 * overwriting the current one.
 *
 * A backend may be named to regenerate with a specific model without changing
 * what the service uses everywhere else; left null, the configured default
 * applies.
 */
class GenerateEntrySummary implements ShouldQueue
{
    use Queueable;

    /**
     * The T5 backend answers in seconds and Qwen in minutes, so the budget has
     * to clear the slower of the two plus the retry window configured in
     * config/queue.php.
     */
    public int $timeout = 900;

    public int $tries = 3;

    public function __construct(
        public readonly int $knowledgeEntryId,
        public readonly string $contentHash,
        public readonly ?string $backend = null,
    ) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [30, 120, 300];
    }

    public function handle(
        SummarizationService $summarization,
        EntrySummaryService $summaries,
        BackendBudget $budget,
    ): void {
        $entry = KnowledgeEntry::find($this->knowledgeEntryId);

        // The entry was deleted while the job waited.
        if ($entry === null) {
            return;
        }

        // A newer edit has already queued its own job. Storing this one would
        // replace a current summary with one of superseded content, and it may
        // well land last.
        if ($summaries->hashFor($entry->content) !== $this->contentHash) {
            return;
        }

        $content = (string) $entry->content;

        if (trim(strip_tags($content)) === '') {
            return;
        }

        // A job queued before a config change can still land after it, so the
        // endpoint's refusal is repeated here rather than trusted.
        $budget->assertAffordable($this->backend);

        try {
            $result = $summarization->summarizeWithMetadata(
                $content,
                $this->backend,
                $this->backend === null
                    ? null
                    : (int) config('ai.background_timeout'),
            );
        } catch (GuzzleException $e) {
            throw $this->explainTransportFailure($e);
        }

        // An empty summary means the model had nothing usable to say. Leaving
        // any existing summary in place is better than replacing a good one
        // with nothing.
        if ($result['summary'] === '') {
            return;
        }

        $summaries->store(
            $entry,
            $result['summary'],
            $this->contentHash,
            $result['backend'],
        );
    }

    /**
     * Turn a transport error into something the failed-job record can explain.
     *
     * A bare "cURL error 28" in the jobs table says a request ended and nothing
     * about why, and the commonest reason here is simply that the model was
     * slower than the budget. Saying that is the difference between a five
     * minute diagnosis and an afternoon.
     */
    private function explainTransportFailure(GuzzleException $e): \RuntimeException
    {
        $seconds = $this->backend === null
            ? 0
            : (int) config("ai.backend_seconds.{$this->backend}", 0);

        $message = $seconds > 0
            ? sprintf(
                "The '%s' backend did not answer within the %d second background "
                    .'budget; it is estimated to need about %d seconds on this '
                    .'hardware. Raise AI_SERVICE_BACKGROUND_TIMEOUT, or regenerate '
                    .'with a faster backend. Underlying error: %s',
                $this->backend,
                (int) config('ai.background_timeout'),
                $seconds,
                $e->getMessage(),
            )
            : 'The AI service could not be reached: '.$e->getMessage();

        return new ActionFailedException($message, 503);
    }
}
