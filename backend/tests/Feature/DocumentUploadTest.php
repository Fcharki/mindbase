<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\KnowledgeEntry;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DocumentUploadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('documents');
    }

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

    private function entry(Workspace $workspace, User $creator): KnowledgeEntry
    {
        return KnowledgeEntry::create([
            'workspace_id' => $workspace->id,
            'created_by' => $creator->id,
            'title' => 'An entry',
            'content' => 'Body',
        ]);
    }

    private function attach(Workspace $workspace, User $user, string $role): User
    {
        $user->workspaces()->attach($workspace->id, ['role' => $role]);

        return $user;
    }

    private function url(Workspace $workspace, KnowledgeEntry $entry): string
    {
        return "/api/v1/workspaces/{$workspace->id}/entries/{$entry->id}/documents";
    }

    public function test_owner_can_upload_a_document(): void
    {
        $workspace = $this->workspace();
        $entry = $this->entry($workspace, $workspace->owner);
        $contents = 'Quarterly figures for the knowledge base.';

        $response = $this->actingAs($workspace->owner)
            ->postJson($this->url($workspace, $entry), [
                'file' => UploadedFile::fake()->createWithContent('notes.pdf', $contents),
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.original_name', 'notes.pdf')
            ->assertJsonPath('data.knowledge_entry_id', $entry->id)
            ->assertJsonPath('data.uploaded_by', $workspace->owner->id)
            ->assertJsonPath('data.size', strlen($contents));

        $document = Document::sole();

        $this->assertSame($contents, Storage::disk('documents')->get($document->path));
        $this->assertSame(hash('sha256', $contents), $document->checksum);

        Storage::disk('documents')->assertExists($document->path);
    }

    public function test_uploaded_documents_are_stored_under_a_random_name(): void
    {
        $workspace = $this->workspace();
        $entry = $this->entry($workspace, $workspace->owner);

        $this->actingAs($workspace->owner)
            ->postJson($this->url($workspace, $entry), [
                'file' => UploadedFile::fake()->create('Quarterly Report.pdf', 8, 'application/pdf'),
            ])
            ->assertCreated();

        $document = Document::sole();

        $this->assertStringStartsWith(
            "workspaces/{$workspace->id}/entries/{$entry->id}/",
            $document->path
        );
        $this->assertStringEndsWith('.pdf', $document->path);
        $this->assertStringNotContainsString('Quarterly Report', $document->path);
    }

    public function test_the_storage_path_is_not_exposed_in_the_response(): void
    {
        $workspace = $this->workspace();
        $entry = $this->entry($workspace, $workspace->owner);

        $response = $this->actingAs($workspace->owner)
            ->postJson($this->url($workspace, $entry), [
                'file' => UploadedFile::fake()->create('notes.pdf', 8, 'application/pdf'),
            ]);

        $response->assertCreated()
            ->assertJsonMissingPath('data.path')
            ->assertJsonMissingPath('data.checksum');
    }

    public function test_upload_requires_a_file(): void
    {
        $workspace = $this->workspace();
        $entry = $this->entry($workspace, $workspace->owner);

        $this->actingAs($workspace->owner)
            ->postJson($this->url($workspace, $entry), [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('file');
    }

    public function test_upload_rejects_a_disallowed_extension(): void
    {
        $workspace = $this->workspace();
        $entry = $this->entry($workspace, $workspace->owner);

        $this->actingAs($workspace->owner)
            ->postJson($this->url($workspace, $entry), [
                'file' => UploadedFile::fake()->create('payload.php', 8, 'application/x-php'),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('file');

        $this->assertSame(0, Document::count());
    }

    public function test_upload_accepts_every_allowed_extension(): void
    {
        $workspace = $this->workspace();
        $entry = $this->entry($workspace, $workspace->owner);

        foreach (['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'md', 'csv'] as $extension) {
            $this->actingAs($workspace->owner)
                ->postJson($this->url($workspace, $entry), [
                    'file' => UploadedFile::fake()->create("file.{$extension}", 4),
                ])
                ->assertCreated();
        }

        $this->assertSame(10, Document::count());
    }

    public function test_a_member_can_upload_but_a_viewer_cannot(): void
    {
        $workspace = $this->workspace();
        $entry = $this->entry($workspace, $workspace->owner);
        $member = $this->attach($workspace, User::factory()->create(), 'member');

        $this->actingAs($member)
            ->postJson($this->url($workspace, $entry), [
                'file' => UploadedFile::fake()->create('notes.pdf', 4, 'application/pdf'),
            ])
            ->assertCreated();

        $viewer = $this->attach($workspace, User::factory()->create(), 'viewer');

        $this->actingAs($viewer)
            ->postJson($this->url($workspace, $entry), [
                'file' => UploadedFile::fake()->create('sneaky.pdf', 4, 'application/pdf'),
            ])
            ->assertForbidden();
    }

    public function test_guests_cannot_upload(): void
    {
        $workspace = $this->workspace();
        $entry = $this->entry($workspace, $workspace->owner);

        $this->postJson($this->url($workspace, $entry), [
            'file' => UploadedFile::fake()->create('notes.pdf', 4, 'application/pdf'),
        ])->assertUnauthorized();
    }

    public function test_documents_are_listed_for_workspace_members(): void
    {
        $workspace = $this->workspace();
        $entry = $this->entry($workspace, $workspace->owner);

        $this->actingAs($workspace->owner)
            ->postJson($this->url($workspace, $entry), [
                'file' => UploadedFile::fake()->create('notes.pdf', 4, 'application/pdf'),
            ]);

        $this->actingAs($workspace->owner)
            ->getJson($this->url($workspace, $entry))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.original_name', 'notes.pdf');
    }

    public function test_non_members_cannot_list_documents(): void
    {
        $workspace = $this->workspace();
        $entry = $this->entry($workspace, $workspace->owner);
        $stranger = User::factory()->create();

        $this->actingAs($stranger)
            ->getJson($this->url($workspace, $entry))
            ->assertForbidden();
    }

    public function test_owner_can_download_a_document(): void
    {
        $workspace = $this->workspace();
        $entry = $this->entry($workspace, $workspace->owner);

        $this->actingAs($workspace->owner)
            ->postJson($this->url($workspace, $entry), [
                'file' => UploadedFile::fake()->create('notes.pdf', 4, 'application/pdf'),
            ]);

        $document = Document::sole();

        $response = $this->actingAs($workspace->owner)
            ->get($this->url($workspace, $entry)."/{$document->id}/download");

        $response->assertOk()
            ->assertDownload('notes.pdf');
    }

    public function test_a_member_can_download_a_document_but_a_viewer_cannot(): void
    {
        $workspace = $this->workspace();
        $entry = $this->entry($workspace, $workspace->owner);
        $member = $this->attach($workspace, User::factory()->create(), 'member');
        $viewer = $this->attach($workspace, User::factory()->create(), 'viewer');

        $this->actingAs($workspace->owner)
            ->postJson($this->url($workspace, $entry), [
                'file' => UploadedFile::fake()->create('notes.pdf', 4, 'application/pdf'),
            ]);

        $document = Document::sole();

        $this->actingAs($member)
            ->get($this->url($workspace, $entry)."/{$document->id}/download")
            ->assertOk()
            ->assertDownload('notes.pdf');

        // A viewer may still see that a document exists; the bytes are withheld.
        $this->actingAs($viewer)
            ->getJson($this->url($workspace, $entry))
            ->assertOk()
            ->assertJsonPath('data.0.original_name', 'notes.pdf');

        $this->actingAs($viewer)
            ->get($this->url($workspace, $entry)."/{$document->id}/download")
            ->assertForbidden();
    }

    public function test_non_members_cannot_download_a_document(): void
    {
        $workspace = $this->workspace();
        $entry = $this->entry($workspace, $workspace->owner);

        $this->actingAs($workspace->owner)
            ->postJson($this->url($workspace, $entry), [
                'file' => UploadedFile::fake()->create('notes.pdf', 4, 'application/pdf'),
            ]);

        $document = Document::sole();

        $this->actingAs(User::factory()->create())
            ->get($this->url($workspace, $entry)."/{$document->id}/download")
            ->assertForbidden();
    }

    public function test_a_document_cannot_be_downloaded_through_another_entry(): void
    {
        $workspace = $this->workspace();
        $entry = $this->entry($workspace, $workspace->owner);
        $otherEntry = $this->entry($workspace, $workspace->owner);

        $this->actingAs($workspace->owner)
            ->postJson($this->url($workspace, $entry), [
                'file' => UploadedFile::fake()->create('notes.pdf', 4, 'application/pdf'),
            ]);

        $document = Document::sole();

        $this->actingAs($workspace->owner)
            ->get($this->url($workspace, $otherEntry)."/{$document->id}/download")
            ->assertNotFound();
    }

    public function test_a_document_from_another_workspace_is_not_found(): void
    {
        $workspace = $this->workspace();
        $entry = $this->entry($workspace, $workspace->owner);

        $this->actingAs($workspace->owner)
            ->postJson($this->url($workspace, $entry), [
                'file' => UploadedFile::fake()->create('notes.pdf', 4, 'application/pdf'),
            ]);

        $document = Document::sole();

        $otherWorkspace = $this->workspace();
        $otherEntry = $this->entry($otherWorkspace, $otherWorkspace->owner);

        $this->actingAs($otherWorkspace->owner)
            ->get($this->url($otherWorkspace, $otherEntry)."/{$document->id}/download")
            ->assertNotFound();
    }

    public function test_download_returns_not_found_when_the_binary_is_gone(): void
    {
        $workspace = $this->workspace();
        $entry = $this->entry($workspace, $workspace->owner);

        $this->actingAs($workspace->owner)
            ->postJson($this->url($workspace, $entry), [
                'file' => UploadedFile::fake()->create('notes.pdf', 4, 'application/pdf'),
            ]);

        $document = Document::sole();
        Storage::disk('documents')->delete($document->path);

        $this->actingAs($workspace->owner)
            ->get($this->url($workspace, $entry)."/{$document->id}/download")
            ->assertNotFound();
    }

    public function test_deleting_a_document_removes_its_binary(): void
    {
        $workspace = $this->workspace();
        $entry = $this->entry($workspace, $workspace->owner);

        $this->actingAs($workspace->owner)
            ->postJson($this->url($workspace, $entry), [
                'file' => UploadedFile::fake()->create('notes.pdf', 4, 'application/pdf'),
            ]);

        $document = Document::sole();
        $path = $document->path;

        $this->actingAs($workspace->owner)
            ->deleteJson($this->url($workspace, $entry)."/{$document->id}")
            ->assertOk();

        $this->assertSame(0, Document::count());
        Storage::disk('documents')->assertMissing($path);
    }

    public function test_viewers_cannot_delete_a_document(): void
    {
        $workspace = $this->workspace();
        $entry = $this->entry($workspace, $workspace->owner);

        $this->actingAs($workspace->owner)
            ->postJson($this->url($workspace, $entry), [
                'file' => UploadedFile::fake()->create('notes.pdf', 4, 'application/pdf'),
            ]);

        $document = Document::sole();
        $viewer = $this->attach($workspace, User::factory()->create(), 'viewer');

        $this->actingAs($viewer)
            ->deleteJson($this->url($workspace, $entry)."/{$document->id}")
            ->assertForbidden();

        $this->assertSame(1, Document::count());
        Storage::disk('documents')->assertExists($document->path);
    }

    public function test_authorizing_a_document_does_not_reload_its_entry(): void
    {
        $workspace = $this->workspace();
        $entry = $this->entry($workspace, $workspace->owner);

        $this->actingAs($workspace->owner)
            ->postJson($this->url($workspace, $entry), [
                'file' => UploadedFile::fake()->create('notes.pdf', 4, 'application/pdf'),
            ]);

        $document = Document::sole();

        // The policy takes the route-bound entry rather than resolving
        // $document->knowledgeEntry, which would be one extra query per
        // document on any path that authorizes a collection. Asserted on the
        // document query specifically, so an unrelated extra query elsewhere
        // does not make this pass or fail for the wrong reason.
        $entryQueries = 0;

        DB::listen(function ($query) use (&$entryQueries): void {
            if (str_contains($query->sql, 'from "knowledge_entries"')
                || str_contains($query->sql, 'from `knowledge_entries`')) {
                $entryQueries++;
            }
        });

        $this->actingAs($workspace->owner)
            ->deleteJson($this->url($workspace, $entry)."/{$document->id}")
            ->assertOk();

        // Exactly one: route model binding resolving the entry from the URL.
        // Before the policy took the entry as an argument it read
        // $document->knowledgeEntry instead, which added a second.
        $this->assertSame(1, $entryQueries);
    }

    public function test_deleting_an_entry_removes_its_documents_from_the_disk(): void
    {
        $workspace = $this->workspace();
        $entry = $this->entry($workspace, $workspace->owner);

        $this->actingAs($workspace->owner)
            ->postJson($this->url($workspace, $entry), [
                'file' => UploadedFile::fake()->create('notes.pdf', 4, 'application/pdf'),
            ]);

        $path = Document::sole()->path;

        $entry->delete();

        $this->assertSame(0, Document::count());
        Storage::disk('documents')->assertMissing($path);
    }

    public function test_deleting_a_workspace_removes_its_documents_from_the_disk(): void
    {
        $workspace = $this->workspace();
        $entry = $this->entry($workspace, $workspace->owner);

        $this->actingAs($workspace->owner)
            ->postJson($this->url($workspace, $entry), [
                'file' => UploadedFile::fake()->create('notes.pdf', 4, 'application/pdf'),
            ]);

        $path = Document::sole()->path;

        $workspace->knowledgeEntries->first()->delete();
        $workspace->delete();

        $this->assertSame(0, Document::count());
        Storage::disk('documents')->assertMissing($path);
    }
}
