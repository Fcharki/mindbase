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
     * Tags are stripped only to check there is something worth sending; the
     * markup itself goes over untouched, because the AI service knows how to
     * flatten it and stripping here would weld adjacent paragraphs together
     * ("<p>one</p><p>two</p>" becomes "onetwo").
     */
    public function handle(): string
    {
        $content = (string) $this->entry->content;

        if (trim(strip_tags($content)) === '') {
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
