<?php

namespace Tests\Unit\Actions;

use App\Actions\KnowledgeEntry\SummarizeEntry;
use App\Exceptions\ActionFailedException;
use App\Models\KnowledgeEntry;
use App\Services\AI\SummarizationService;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request as PsrRequest;
use Tests\TestCase;

/**
 * The action decides which failure a caller is told about; rendering that
 * decision as an HTTP response is covered in Feature/ActionFailureTest.
 */
class SummarizeEntryTest extends TestCase
{
    private function entry(?string $content): KnowledgeEntry
    {
        $entry = new KnowledgeEntry;
        $entry->content = $content;

        return $entry;
    }

    private function serviceReturning(string $summary): SummarizationService
    {
        return new class($summary) extends SummarizationService
        {
            public function __construct(private readonly string $summary) {}

            public function summarize(string $text): string
            {
                return $this->summary;
            }
        };
    }

    private function serviceThrowing(ConnectException $exception): SummarizationService
    {
        return new class($exception) extends SummarizationService
        {
            public function __construct(private readonly ConnectException $exception) {}

            public function summarize(string $text): string
            {
                throw $this->exception;
            }
        };
    }

    public function test_it_returns_the_summary(): void
    {
        $summary = (new SummarizeEntry(
            $this->entry('<p>Some knowledge.</p>'),
            $this->serviceReturning('A concise summary.'),
        ))->handle();

        $this->assertSame('A concise summary.', $summary);
    }

    public function test_it_rejects_an_entry_with_no_content(): void
    {
        $action = new SummarizeEntry(
            $this->entry('<p>   </p>'),
            $this->serviceReturning('never used'),
        );

        try {
            $action->handle();
            $this->fail('Expected an ActionFailedException.');
        } catch (ActionFailedException $exception) {
            $this->assertSame('This entry has no content to summarize.', $exception->getMessage());
            $this->assertSame(422, $exception->status());
        }
    }

    public function test_a_transport_failure_is_reported_as_the_service_being_unavailable(): void
    {
        $action = new SummarizeEntry(
            $this->entry('Some knowledge.'),
            $this->serviceThrowing(new ConnectException(
                'Connection refused',
                new PsrRequest('POST', 'summarize'),
            )),
        );

        try {
            $action->handle();
            $this->fail('Expected an ActionFailedException.');
        } catch (ActionFailedException $exception) {
            $this->assertSame(
                'The AI service is unavailable. Please try again.',
                $exception->getMessage()
            );
            $this->assertSame(503, $exception->status());
        }
    }

    public function test_an_empty_summary_is_reported_separately_from_an_outage(): void
    {
        $action = new SummarizeEntry(
            $this->entry('Some knowledge.'),
            $this->serviceReturning(''),
        );

        try {
            $action->handle();
            $this->fail('Expected an ActionFailedException.');
        } catch (ActionFailedException $exception) {
            $this->assertSame(
                'The AI service returned an empty summary.',
                $exception->getMessage()
            );
            $this->assertSame(502, $exception->status());
        }
    }
}
