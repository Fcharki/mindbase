<?php

namespace App\Actions\KnowledgeEntry;

use App\Actions\Action;
use App\Exceptions\ActionFailedException;
use App\Models\KnowledgeEntry;
use App\Services\AI\SummarizationService;
use GuzzleHttp\Exception\GuzzleException;

class SummarizeEntry implements Action
{
    public function __construct(
        private readonly KnowledgeEntry $entry,
        private readonly SummarizationService $summarization,
    ) {}

    /**
     * Ask the AI service for a plain-text summary of the entry's content.
     *
     * The two failure modes are kept distinct because the caller can act on
     * them differently: a transport error means the service is down and is
     * worth retrying, whereas an empty summary means it answered and there was
     * nothing usable in the response.
     */
    public function handle(): string
    {
        $content = trim(strip_tags((string) $this->entry->content));

        if ($content === '') {
            throw new ActionFailedException(
                'This entry has no content to summarize.'
            );
        }

        try {
            $summary = $this->summarization->summarize($content);
        } catch (GuzzleException) {
            throw new ActionFailedException(
                'The AI service is unavailable. Please try again.',
                503
            );
        }

        if ($summary === '') {
            throw new ActionFailedException(
                'The AI service returned an empty summary.',
                502
            );
        }

        return $summary;
    }
}
