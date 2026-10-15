<?php

namespace Tests\Feature;

use App\Jobs\GenerateEntrySummary;
use App\Jobs\IndexEntryChunks;
use App\Models\KnowledgeEntry;
use App\Models\User;
use App\Models\Workspace;
use Database\Seeders\DemoKnowledgeEntriesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * The demo entries have to be as usable as hand-written ones.
 *
 * Seeding writes through the model, which skips the controller path that queues
 * a summary and the chunk index. If the seeder does not queue them itself, the
 * demo entries answer every question with "no matching entries" -- which reads
 * like a broken model rather than a missing index.
 */
class DemoKnowledgeEntriesSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_indexes_and_summarises_the_entries_it_seeds(): void
    {
        Queue::fake();

        $owner = User::factory()->create();

        Workspace::create([
            'name' => 'Personal knowledge',
            'owner_id' => $owner->id,
        ]);

        $this->seed(DemoKnowledgeEntriesSeeder::class);

        $count = KnowledgeEntry::count();

        $this->assertGreaterThan(0, $count);
        Queue::assertPushed(GenerateEntrySummary::class, $count);
        Queue::assertPushed(IndexEntryChunks::class, $count);
    }
}
