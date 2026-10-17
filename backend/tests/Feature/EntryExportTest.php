<?php

namespace Tests\Feature;

use App\Models\KnowledgeEntry;
use App\Models\Tag;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EntryExportTest extends TestCase
{
    use RefreshDatabase;

    private function workspace(): Workspace
    {
        $owner = User::factory()->create();
        $workspace = Workspace::create([
            'name' => 'Acme',
            'owner_id' => $owner->id,
        ]);
        $workspace->members()->attach($owner->id, ['role' => 'owner']);

        return $workspace;
    }

    private function entry(Workspace $workspace, string $content = '<h1>Body</h1><p>Hello there.</p>'): KnowledgeEntry
    {
        return KnowledgeEntry::create([
            'workspace_id' => $workspace->id,
            'created_by' => $workspace->owner_id,
            'title' => 'Quarterly Plan',
            'content' => $content,
        ]);
    }

    public function test_markdown_is_the_default_export(): void
    {
        $workspace = $this->workspace();
        $entry = $this->entry($workspace);
        $tag = $workspace->tags()->create(['name' => 'planning']);
        $entry->tags()->attach($tag->id);

        $response = $this->actingAs($workspace->owner)
            ->get("/api/v1/workspaces/{$workspace->id}/entries/{$entry->id}/export");

        $response->assertOk()
            ->assertHeader('content-type', 'text/markdown; charset=UTF-8');

        $this->assertStringContainsString('# Quarterly Plan', $response->getContent());
        $this->assertStringContainsString('#planning', $response->getContent());
        $this->assertStringContainsString('Hello there.', $response->getContent());
    }

    public function test_html_export_keeps_the_document_self_contained(): void
    {
        $workspace = $this->workspace();
        $entry = $this->entry($workspace);

        $response = $this->actingAs($workspace->owner)
            ->get("/api/v1/workspaces/{$workspace->id}/entries/{$entry->id}/export?format=html");

        $response->assertOk()
            ->assertHeader('content-type', 'text/html; charset=UTF-8');

        $this->assertStringContainsString('<!doctype html>', $response->getContent());
        $this->assertStringContainsString('Quarterly Plan', $response->getContent());
    }

    public function test_json_export_is_lossless(): void
    {
        $workspace = $this->workspace();
        $entry = $this->entry($workspace);

        $this->actingAs($workspace->owner)
            ->getJson("/api/v1/workspaces/{$workspace->id}/entries/{$entry->id}/export?format=json")
            ->assertOk()
            ->assertJsonPath('data.title', 'Quarterly Plan')
            ->assertJsonPath('data.content_html', '<h1>Body</h1><p>Hello there.</p>')
            ->assertJsonPath('data.content_markdown', "# Body\n\nHello there.");
    }

    public function test_an_unknown_format_is_refused(): void
    {
        $workspace = $this->workspace();
        $entry = $this->entry($workspace);

        $this->actingAs($workspace->owner)
            ->getJson("/api/v1/workspaces/{$workspace->id}/entries/{$entry->id}/export?format=xml")
            ->assertStatus(422);
    }

    public function test_pdf_export_returns_a_pdf_document(): void
    {
        $workspace = $this->workspace();
        $entry = $this->entry($workspace);
        $tag = $workspace->tags()->create(['name' => 'planning']);
        $entry->tags()->attach($tag->id);

        $response = $this->actingAs($workspace->owner)
            ->get("/api/v1/workspaces/{$workspace->id}/entries/{$entry->id}/export?format=pdf");

        $response->assertOk()
            ->assertHeader('content-type', 'application/pdf');

        $body = $response->getContent();

        $this->assertStringStartsWith('%PDF-', $body);
        $this->assertStringContainsString('attachment; filename=', $response->headers->get('content-disposition'));
        $this->assertStringEndsWith('.pdf"', $response->headers->get('content-disposition'));
    }

    public function test_an_entry_from_another_workspace_is_not_found(): void
    {
        $mine = $this->workspace();
        $theirs = $this->workspace();
        $foreign = $this->entry($theirs);

        $this->actingAs($mine->owner)
            ->getJson("/api/v1/workspaces/{$mine->id}/entries/{$foreign->id}/export")
            ->assertNotFound();
    }

    public function test_a_viewer_may_export(): void
    {
        $workspace = $this->workspace();
        $viewer = User::factory()->create();
        $workspace->members()->attach($viewer->id, ['role' => 'viewer']);
        $entry = $this->entry($workspace);

        $this->actingAs($viewer)
            ->getJson("/api/v1/workspaces/{$workspace->id}/entries/{$entry->id}/export?format=json")
            ->assertOk();
    }

    public function test_a_guest_cannot_export(): void
    {
        $workspace = $this->workspace();
        $entry = $this->entry($workspace);

        $this->getJson("/api/v1/workspaces/{$workspace->id}/entries/{$entry->id}/export")
            ->assertUnauthorized();
    }

    public function test_a_pinned_flag_is_stored_and_returned(): void
    {
        $workspace = $this->workspace();
        $entry = $this->entry($workspace);

        $this->actingAs($workspace->owner)
            ->postJson("/api/v1/workspaces/{$workspace->id}/entries/{$entry->id}/pin", [
                'is_pinned' => true,
            ])
            ->assertOk()
            ->assertJsonPath('data.is_pinned', true);

        $this->assertDatabaseHas('knowledge_entries', ['id' => $entry->id, 'is_pinned' => true]);

        $this->actingAs($workspace->owner)
            ->postJson("/api/v1/workspaces/{$workspace->id}/entries/{$entry->id}/pin")
            ->assertOk()
            ->assertJsonPath('data.is_pinned', false);
    }

    public function test_a_viewer_cannot_pin(): void
    {
        $workspace = $this->workspace();
        $viewer = User::factory()->create();
        $workspace->members()->attach($viewer->id, ['role' => 'viewer']);
        $entry = $this->entry($workspace);

        $this->actingAs($viewer)
            ->postJson("/api/v1/workspaces/{$workspace->id}/entries/{$entry->id}/pin", [
                'is_pinned' => true,
            ])
            ->assertForbidden();
    }

    public function test_tag_suggestions_prefer_the_workspace_vocabulary(): void
    {
        $workspace = $this->workspace();
        $entry = $this->entry(
            $workspace,
            '<p>The deployment pipeline runs migrations before serving traffic. '
                .'A failed deployment pipeline rolls back automatically.</p>',
        );

        // A tag already used elsewhere, mentioned in this entry, is the
        // strongest signal and should be proposed.
        $existing = $workspace->tags()->create(['name' => 'deployment']);
        $other = $this->entry($workspace);
        $other->tags()->attach($existing->id);

        $response = $this->actingAs($workspace->owner)
            ->postJson("/api/v1/workspaces/{$workspace->id}/entries/{$entry->id}/suggest-tags");

        $response->assertOk();
        $this->assertContains('deployment', $response->json('data.suggestions'));
    }

    public function test_a_guest_cannot_request_tag_suggestions(): void
    {
        $workspace = $this->workspace();
        $entry = $this->entry($workspace);

        $this->postJson("/api/v1/workspaces/{$workspace->id}/entries/{$entry->id}/suggest-tags")
            ->assertUnauthorized();
    }
}
