<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Workspace;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class LoggingAiRequestTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    public function test_ai_request_logged_on_summarization(): void
    {
        Log::spy();

        $mock = new MockHandler([
            new Response(200, [], json_encode([
                'response' => 'Summary text',
                'backend' => 't5',
            ])),
        ]);
        $handlerStack = HandlerStack::create($mock);
        $client = new Client(['handler' => $handlerStack]);
        app()->bind(Client::class, fn () => $client);

        $owner = User::factory()->create();
        $workspace = Workspace::create([
            'name' => 'Test',
            'owner_id' => $owner->id,
        ]);
        $workspace->members()->attach($owner->id, ['role' => 'owner']);

        $entry = $workspace->knowledgeEntries()->create([
            'title' => 'Test',
            'content' => '<p>Some content here that is long enough</p>',
            'created_by' => $owner->id,
        ]);

        $this->actingAs($owner)
            ->postJson("/api/v1/workspaces/{$workspace->id}/entries/{$entry->id}/summarize");

        Log::shouldHaveReceived('info')
            ->withArgs(function ($message, $context) {
                if ($message === 'AI request') {
                    return isset($context['model'])
                        && array_key_exists('operation', $context)
                        && array_key_exists('latency', $context)
                        && array_key_exists('tokens', $context)
                        && array_key_exists('success', $context);
                }

                return true;
            });
    }
}
