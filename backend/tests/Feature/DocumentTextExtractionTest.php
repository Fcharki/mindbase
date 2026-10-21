<?php

namespace Tests\Feature;

use App\Jobs\ExtractDocumentText;
use App\Jobs\IndexEntryChunks;
use App\Models\Document;
use App\Models\KnowledgeEntry;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AI\OcrService;
use App\Services\EntrySummaryService;
use App\Services\Extraction\TextExtractionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DocumentTextExtractionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('documents');
        Queue::fake();
    }

    public function test_uploading_a_document_queues_extraction(): void
    {
        [$workspace, $entry] = $this->entry();

        $this->actingAs($workspace->owner)
            ->postJson("/api/v1/workspaces/{$workspace->id}/entries/{$entry->id}/documents", [
                'file' => UploadedFile::fake()->createWithContent('notes.md', 'Some text.'),
            ])
            ->assertCreated();

        Queue::assertPushed(ExtractDocumentText::class);
    }

    public function test_plain_text_is_extracted_and_stored(): void
    {
        $document = $this->document('notes.md', "# Title\n\nSome body text.");

        $this->runExtraction($document);

        $document->refresh();

        $this->assertSame(Document::EXTRACTION_READY, $document->extraction_status);
        $this->assertStringContainsString('Some body text.', (string) $document->extracted_text);
    }

    public function test_a_pdf_text_layer_is_extracted(): void
    {
        $pdf = (string) file_get_contents(base_path('tests/Fixtures/documents/sample.pdf'));

        $document = $this->document('report.pdf', $pdf);

        $this->runExtraction($document);

        $document->refresh();

        $this->assertSame(Document::EXTRACTION_READY, $document->extraction_status);
        $this->assertStringContainsString('Hello extraction world', (string) $document->extracted_text);
    }

    public function test_an_image_is_ocred_and_stored(): void
    {
        $this->mock(OcrService::class)
            ->shouldReceive('extract')
            ->once()
            ->andReturn('Invoice 42'.PHP_EOL.'Total 10.00');

        $document = $this->document('receipt.png', 'not-really-a-png');

        $this->runExtraction($document);

        $document->refresh();

        $this->assertSame(Document::EXTRACTION_READY, $document->extraction_status);
        $this->assertSame("Invoice 42\nTotal 10.00", $document->extracted_text);
    }

    public function test_an_image_without_text_is_recorded_as_empty(): void
    {
        $this->mock(OcrService::class)
            ->shouldReceive('extract')
            ->once()
            ->andReturn('');

        $document = $this->document('photo.webp', 'not-really-an-image');

        $this->runExtraction($document);

        $document->refresh();

        $this->assertSame(Document::EXTRACTION_EMPTY, $document->extraction_status);
        $this->assertNull($document->extracted_text);
    }

    public function test_an_ocr_failure_is_recorded_as_failed(): void
    {
        $this->mock(OcrService::class)
            ->shouldReceive('extract')
            ->once()
            ->andThrow(new \RuntimeException('The OCR model is unavailable.'));

        $document = $this->document('receipt.png', 'not-really-a-png');

        $this->runExtraction($document);

        $document->refresh();

        $this->assertSame(Document::EXTRACTION_FAILED, $document->extraction_status);
        $this->assertStringContainsString('OCR model', (string) $document->extraction_error);
    }

    public function test_an_unsupported_extension_is_recorded_as_unsupported(): void
    {
        $document = $this->document('legacy.doc', 'binary-ish bytes');

        $this->runExtraction($document);

        $document->refresh();

        $this->assertSame(Document::EXTRACTION_UNSUPPORTED, $document->extraction_status);
        $this->assertNull($document->extracted_text);
        $this->assertNotNull($document->extraction_error);
    }

    public function test_a_blank_file_is_recorded_as_empty(): void
    {
        $document = $this->document('blank.txt', "   \n\n  ");

        $this->runExtraction($document);

        $document->refresh();

        $this->assertSame(Document::EXTRACTION_EMPTY, $document->extraction_status);
    }

    public function test_a_missing_file_is_recorded_as_failed(): void
    {
        $document = $this->document('gone.txt', 'Anything.');

        Storage::disk('documents')->delete($document->path);

        $this->runExtraction($document);

        $document->refresh();

        $this->assertSame(Document::EXTRACTION_FAILED, $document->extraction_status);
    }

    public function test_normalisation_keeps_tabs_and_collapses_spaces(): void
    {
        $document = $this->document('data.txt', "Name\tAlice\nRole   Lead");

        $this->runExtraction($document);

        $document->refresh();

        $this->assertSame("Name\tAlice\nRole Lead", $document->extracted_text);
    }

    public function test_extraction_reindexes_the_entry(): void
    {
        $document = $this->document('notes.txt', 'Hello.');

        $this->runExtraction($document);

        Queue::assertPushed(
            IndexEntryChunks::class,
            fn (IndexEntryChunks $job) => $job->knowledgeEntryId === $document->knowledge_entry_id,
        );
    }

    private function runExtraction(Document $document): void
    {
        (new ExtractDocumentText($document->id))->handle(
            app(TextExtractionService::class),
            app(EntrySummaryService::class),
        );
    }

    private function document(string $name, string $contents): Document
    {
        [$workspace, $entry] = $this->entry();

        return Document::create([
            'knowledge_entry_id' => $entry->id,
            'uploaded_by' => $workspace->owner_id,
            'disk' => 'documents',
            'path' => $this->storeFile($workspace, $entry, $name, $contents),
            'original_name' => $name,
            'mime_type' => 'application/octet-stream',
            'size' => strlen($contents),
        ]);
    }

    private function storeFile(Workspace $workspace, KnowledgeEntry $entry, string $name, string $contents): string
    {
        return Storage::disk('documents')->putFileAs(
            "workspaces/{$workspace->id}/entries/{$entry->id}",
            UploadedFile::fake()->createWithContent($name, $contents),
            'stored_'.$name,
        );
    }

    /**
     * @return array{Workspace, KnowledgeEntry}
     */
    private function entry(): array
    {
        $owner = User::factory()->create();

        $workspace = Workspace::create([
            'name' => 'Acme',
            'owner_id' => $owner->id,
        ]);
        $workspace->members()->attach($owner->id, ['role' => 'owner']);

        $entry = KnowledgeEntry::create([
            'workspace_id' => $workspace->id,
            'created_by' => $owner->id,
            'title' => 'Entry',
            'content' => 'Body',
        ]);

        return [$workspace, $entry];
    }
}
