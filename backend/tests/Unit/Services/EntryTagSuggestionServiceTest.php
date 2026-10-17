<?php

namespace Tests\Unit\Services;

use App\Models\KnowledgeEntry;
use App\Models\User;
use App\Models\Workspace;
use App\Services\EntryTagSuggestionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EntryTagSuggestionServiceTest extends TestCase
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

    private function entry(Workspace $workspace, string $title, string $content): KnowledgeEntry
    {
        return KnowledgeEntry::create([
            'workspace_id' => $workspace->id,
            'created_by' => $workspace->owner_id,
            'title' => $title,
            'content' => $content,
        ]);
    }

    public function test_a_vocabulary_tag_mentioned_in_the_entry_is_suggested(): void
    {
        $workspace = $this->workspace();
        $workspace->tags()->create(['name' => 'deployment']);

        $entry = $this->entry(
            $workspace,
            'Pipeline',
            '<p>The deployment runs migrations before serving traffic.</p>',
        );

        $suggestions = (new EntryTagSuggestionService)->suggest($entry);

        $this->assertContains('deployment', $suggestions);
    }

    public function test_a_tag_already_on_the_entry_is_not_suggested_again(): void
    {
        $workspace = $this->workspace();
        $tag = $workspace->tags()->create(['name' => 'deployment']);

        $entry = $this->entry($workspace, 'Pipeline', '<p>About deployment.</p>');
        $entry->tags()->attach($tag->id);
        $entry->load('tags');

        $suggestions = (new EntryTagSuggestionService)->suggest($entry);

        $this->assertNotContains('deployment', $suggestions);
    }

    public function test_a_phrase_only_matches_on_word_boundaries(): void
    {
        $workspace = $this->workspace();
        $workspace->tags()->create(['name' => 'api']);

        // "capital" contains "api" but is not the tag, so it must not score.
        $entry = $this->entry(
            $workspace,
            'Finances',
            '<p>We raised capital. The capital round closed.</p>',
        );

        $suggestions = (new EntryTagSuggestionService)->suggest($entry);

        $this->assertNotContains('api', $suggestions);
    }

    public function test_repeated_salient_words_are_suggested_when_the_vocabulary_is_thin(): void
    {
        $workspace = $this->workspace();

        $entry = $this->entry(
            $workspace,
            'Ops',
            '<p>Kubernetes runs the workloads. Kubernetes also schedules them. '
                .'Kubernetes is the platform.</p>',
        );

        $suggestions = (new EntryTagSuggestionService)->suggest($entry);

        $this->assertContains('kubernetes', $suggestions);
    }

    public function test_a_single_mention_is_not_enough_to_suggest_a_word(): void
    {
        $workspace = $this->workspace();

        $entry = $this->entry(
            $workspace,
            'Ops',
            '<p>Kubernetes once mentioned only here.</p>',
        );

        $suggestions = (new EntryTagSuggestionService)->suggest($entry);

        $this->assertNotContains('kubernetes', $suggestions);
    }
}
