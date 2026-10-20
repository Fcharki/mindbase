<?php

namespace Tests\Feature;

use App\Models\EntryImage;
use App\Models\KnowledgeEntry;
use App\Models\User;
use App\Models\Workspace;
use App\Services\EntryImageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class EntryImageTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A real one-pixel PNG, because the mimetypes rule sniffs the bytes rather
     * than trusting the extension.
     */
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+ip1sAAAAASUVORK5CYII=';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('documents');
        Queue::fake();
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

    private function image(Workspace $workspace): EntryImage
    {
        return EntryImage::create([
            'uuid' => '9f1d8e6a-6c1c-4f86-9d4e-1234567890ab',
            'workspace_id' => $workspace->id,
            'uploaded_by' => $workspace->owner_id,
            'disk' => 'documents',
            'path' => 'entry-images/workspaces/'.$workspace->id.'/image.png',
            'original_name' => 'image.png',
            'mime_type' => 'image/png',
            'size' => 68,
            'checksum' => str_repeat('a', 64),
        ]);
    }

    private function uploadUrl(Workspace $workspace): string
    {
        return "/api/v1/workspaces/{$workspace->id}/images";
    }

    public function test_owner_can_upload_an_image(): void
    {
        $workspace = $this->workspace();

        $response = $this->actingAs($workspace->owner)
            ->postJson($this->uploadUrl($workspace), [
                'file' => UploadedFile::fake()->createWithContent(
                    'photo.png',
                    base64_decode(self::PNG)
                ),
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.original_name', 'photo.png')
            ->assertJsonPath('data.mime_type', 'image/png')
            ->assertJsonMissingPath('data.path')
            ->assertJsonMissingPath('data.checksum');

        $image = EntryImage::sole();

        $this->assertStringStartsWith(
            "entry-images/workspaces/{$workspace->id}/",
            $image->path
        );
        $this->assertStringEndsWith('.png', $image->path);
        $this->assertStringContainsString(
            "/api/v1/entry-images/{$image->uuid}",
            (string) $response->json('data.url')
        );
        Storage::disk('documents')->assertExists($image->path);
    }

    public function test_only_contributors_can_upload_an_image(): void
    {
        $workspace = $this->workspace();
        $viewer = User::factory()->create();
        $workspace->members()->attach($viewer->id, ['role' => 'viewer']);

        $this->actingAs($viewer)
            ->postJson($this->uploadUrl($workspace), [
                'file' => UploadedFile::fake()->createWithContent(
                    'photo.png',
                    base64_decode(self::PNG)
                ),
            ])
            ->assertForbidden();
    }

    public function test_guests_cannot_upload_an_image(): void
    {
        $workspace = $this->workspace();

        $this->postJson($this->uploadUrl($workspace), [
            'file' => UploadedFile::fake()->createWithContent(
                'photo.png',
                base64_decode(self::PNG)
            ),
        ])->assertUnauthorized();
    }

    public function test_upload_rejects_non_image_files(): void
    {
        $workspace = $this->workspace();

        $this->actingAs($workspace->owner)
            ->postJson($this->uploadUrl($workspace), [
                'file' => UploadedFile::fake()->createWithContent(
                    'vector.svg',
                    '<svg xmlns="http://www.w3.org/2000/svg"></svg>'
                ),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('file');

        $this->assertSame(0, EntryImage::count());
    }

    public function test_images_are_served_inline_without_a_session(): void
    {
        $workspace = $this->workspace();
        $image = $this->image($workspace);

        Storage::disk('documents')->put($image->path, base64_decode(self::PNG));

        $this->get("/api/v1/entry-images/{$image->uuid}")
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png')
            ->assertHeader('Cache-Control');
    }

    public function test_saving_an_entry_links_and_removing_the_image_deletes_it(): void
    {
        $workspace = $this->workspace();
        $image = $this->image($workspace);
        Storage::disk('documents')->put($image->path, base64_decode(self::PNG));

        $entry = KnowledgeEntry::create([
            'workspace_id' => $workspace->id,
            'created_by' => $workspace->owner->id,
            'title' => 'With a picture',
            'content' => '<p><img src="'.$image->url().'" alt="photo"></p>',
        ]);

        $this->actingAs($workspace->owner)
            ->putJson("/api/v1/workspaces/{$workspace->id}/entries/{$entry->id}", [
                'title' => 'With a picture',
                'content' => '<p><img src="'.$image->url().'" alt="photo"></p>',
            ])
            ->assertOk();

        $this->assertSame($entry->id, $image->fresh()->knowledge_entry_id);

        $this->actingAs($workspace->owner)
            ->putJson("/api/v1/workspaces/{$workspace->id}/entries/{$entry->id}", [
                'title' => 'With a picture',
                'content' => '<p>No image any more.</p>',
            ])
            ->assertOk();

        $this->assertSame(0, EntryImage::count());
        Storage::disk('documents')->assertMissing($image->path);
    }

    public function test_deleting_an_entry_removes_its_images(): void
    {
        $workspace = $this->workspace();
        $image = $this->image($workspace);
        $image->forceFill(['knowledge_entry_id' => null])->save();
        Storage::disk('documents')->put($image->path, base64_decode(self::PNG));

        $entry = KnowledgeEntry::create([
            'workspace_id' => $workspace->id,
            'created_by' => $workspace->owner->id,
            'title' => 'With a picture',
            'content' => '<p><img src="'.$image->url().'"></p>',
        ]);

        // Associate through the service, the same path a save takes.
        app(EntryImageService::class)->sync($entry);

        $this->actingAs($workspace->owner)
            ->deleteJson("/api/v1/workspaces/{$workspace->id}/entries/{$entry->id}")
            ->assertOk();

        $this->assertSame(0, EntryImage::count());
        Storage::disk('documents')->assertMissing($image->path);
    }
}
