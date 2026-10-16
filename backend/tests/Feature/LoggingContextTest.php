<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoggingContextTest extends TestCase
{
    use RefreshDatabase;

    public function test_request_id_middleware_sets_header(): void
    {
        $owner = User::factory()->create();
        $workspace = Workspace::create([
            'name' => 'Test',
            'owner_id' => $owner->id,
        ]);
        $workspace->members()->attach($owner->id, ['role' => 'owner']);

        $response = $this->actingAs($owner)
            ->getJson("/api/v1/workspaces/{$workspace->id}");

        $response->assertHeader('X-Request-ID');
    }
}
