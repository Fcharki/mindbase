<?php

namespace Tests\Feature;

use App\Models\KnowledgeEntry;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AI\SummarizationService;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request as PsrRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * An action states a rule and the status it implies; the handler registered in
 * bootstrap/app.php is what turns that into a response. These tests pin that
 * translation, which is the one part of the action layer the unit tests cannot
 * reach.
 */
class ActionFailureTest extends TestCase
{
    use RefreshDatabase;

    private function entry(?string $content): KnowledgeEntry
    {
        $workspace = Workspace::create([
            'name' => 'Acme',
            'owner_id' => User::factory()->create()->id,
        ]);

        return KnowledgeEntry::create([
            'workspace_id' => $workspace->id,
            'created_by' => $workspace->owner_id,
            'title' => 'Entry',
            'content' => $content,
        ]);
    }

    private function fakeSummarization(SummarizationService $service): void
    {
        $this->app->instance(SummarizationService::class, $service);
    }

    public function test_an_entry_without_content_returns_422(): void
    {
        $entry = $this->entry('   ');

        $this->actingAs(User::find($entry->created_by))
            ->postJson("/api/v1/workspaces/{$entry->workspace_id}/entries/{$entry->id}/summarize")
            ->assertStatus(422)
            ->assertExactJson([
                'message' => 'This entry has no content to summarize.',
            ]);
    }

    public function test_an_ai_outage_returns_503(): void
    {
        $entry = $this->entry('Some knowledge.');

        $this->fakeSummarization(new class extends SummarizationService
        {
            public function __construct() {}

            public function summarize(string $text): string
            {
                throw new ConnectException(
                    'Connection refused',
                    new PsrRequest('POST', 'summarize'),
                );
            }
        });

        $this->actingAs(User::find($entry->created_by))
            ->postJson("/api/v1/workspaces/{$entry->workspace_id}/entries/{$entry->id}/summarize")
            ->assertStatus(503)
            ->assertExactJson([
                'message' => 'The AI service is unavailable. Please try again.',
            ]);
    }

    public function test_an_empty_summary_returns_502(): void
    {
        $entry = $this->entry('Some knowledge.');

        $this->fakeSummarization(new class extends SummarizationService
        {
            public function __construct() {}

            public function summarize(string $text): string
            {
                return '';
            }
        });

        $this->actingAs(User::find($entry->created_by))
            ->postJson("/api/v1/workspaces/{$entry->workspace_id}/entries/{$entry->id}/summarize")
            ->assertStatus(502)
            ->assertExactJson([
                'message' => 'The AI service returned an empty summary.',
            ]);
    }
}
