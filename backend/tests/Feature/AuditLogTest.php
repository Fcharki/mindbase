<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuditLogTest extends TestCase
{
    use RefreshDatabase;

    private function workspaceFor(User $owner): Workspace
    {
        // Creation is silenced so the observer does not seed the table with an
        // extra "workspace created" entry, keeping each test's fixture exact.
        return Workspace::withoutEvents(function () use ($owner) {
            $workspace = Workspace::create([
                'name' => 'Acme',
                'owner_id' => $owner->id,
            ]);
            $workspace->members()->attach($owner->id, ['role' => 'owner']);

            return $workspace;
        });
    }

    public function test_only_workspace_owner_can_view_audit_logs(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $workspace = $this->workspaceFor($owner);
        $workspace->members()->attach($member->id, ['role' => 'member']);

        AuditLog::create([
            'workspace_id' => $workspace->id,
            'action' => 'created',
            'entity_type' => 'workspace',
            'occurred_at' => now(),
        ]);

        $this->actingAs($member)
            ->getJson("/api/v1/workspaces/{$workspace->id}/audit-logs")
            ->assertForbidden();

        $this->actingAs($owner)
            ->getJson("/api/v1/workspaces/{$workspace->id}/audit-logs")
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    '*' => ['id', 'action', 'entity_type', 'occurred_at'],
                ],
            ]);
    }

    public function test_audit_logs_are_paginated(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspaceFor($owner);

        foreach (range(1, 20) as $index) {
            AuditLog::create([
                'workspace_id' => $workspace->id,
                'action' => 'updated',
                'entity_type' => 'knowledge_entry',
                'occurred_at' => now(),
            ]);
        }

        $response = $this->actingAs($owner)
            ->getJson("/api/v1/workspaces/{$workspace->id}/audit-logs?per_page=5")
            ->assertOk()
            ->assertJsonCount(5, 'data')
            ->assertJsonPath('meta.total', 20)
            ->assertJsonPath('meta.per_page', 5);

        $this->assertNotEmpty($response->json('links.next'));
    }

    public function test_audit_logs_can_be_filtered_by_action_entity_and_user(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $workspace = $this->workspaceFor($owner);

        AuditLog::create([
            'workspace_id' => $workspace->id,
            'user_id' => $owner->id,
            'action' => 'created',
            'entity_type' => 'workspace',
            'occurred_at' => now(),
        ]);

        AuditLog::create([
            'workspace_id' => $workspace->id,
            'user_id' => $other->id,
            'action' => 'deleted',
            'entity_type' => 'knowledge_entry',
            'occurred_at' => now(),
        ]);

        $this->actingAs($owner)
            ->getJson("/api/v1/workspaces/{$workspace->id}/audit-logs?action=created")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.action', 'created');

        $this->actingAs($owner)
            ->getJson("/api/v1/workspaces/{$workspace->id}/audit-logs?entity=knowledge_entry")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.entity_type', 'knowledge_entry');

        $this->actingAs($owner)
            ->getJson("/api/v1/workspaces/{$workspace->id}/audit-logs?user_id={$other->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.action', 'deleted');
    }

    public function test_audit_logs_can_be_filtered_by_date_range(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspaceFor($owner);

        AuditLog::create([
            'workspace_id' => $workspace->id,
            'action' => 'created',
            'entity_type' => 'workspace',
            'occurred_at' => now()->subDays(10),
        ]);

        AuditLog::create([
            'workspace_id' => $workspace->id,
            'action' => 'updated',
            'entity_type' => 'workspace',
            'occurred_at' => now(),
        ]);

        $this->actingAs($owner)
            ->getJson("/api/v1/workspaces/{$workspace->id}/audit-logs?from=".now()->subDay()->toDateString())
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.action', 'updated');
    }

    public function test_audit_log_response_exposes_filter_options(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspaceFor($owner);

        AuditLog::create([
            'workspace_id' => $workspace->id,
            'user_id' => $owner->id,
            'action' => 'created',
            'entity_type' => 'workspace',
            'occurred_at' => now(),
        ]);

        $this->actingAs($owner)
            ->getJson("/api/v1/workspaces/{$workspace->id}/audit-logs")
            ->assertOk()
            ->assertJsonPath('filters.actions', ['created'])
            ->assertJsonPath('filters.entities', ['workspace'])
            ->assertJsonPath('filters.users.0.id', $owner->id);
    }
}
