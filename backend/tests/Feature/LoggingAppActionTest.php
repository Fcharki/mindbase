<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class LoggingAppActionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    public function test_app_action_logged_on_entry_creation(): void
    {
        Log::shouldReceive('info')
            ->with('app.action', \Mockery::on(function ($context) {
                return array_key_exists('request_id', $context)
                    && array_key_exists('user_id', $context)
                    && array_key_exists('workspace_id', $context)
                    && array_key_exists('operation', $context)
                    && array_key_exists('status', $context);
            }))->atLeast()->once();

        $owner = User::factory()->create();
        $workspace = Workspace::create([
            'name' => 'Test',
            'owner_id' => $owner->id,
        ]);
        $workspace->members()->attach($owner->id, ['role' => 'owner']);

        $this->actingAs($owner)
            ->postJson("/api/v1/workspaces/{$workspace->id}/entries", [
                'title' => 'Test Entry',
                'content' => 'Content',
            ]);
    }
}
