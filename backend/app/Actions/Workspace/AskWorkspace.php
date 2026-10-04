<?php

namespace App\Actions\Workspace;

use App\Actions\Action;
use App\Exceptions\ActionFailedException;
use App\Jobs\GenerateAssistantAnswer;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Search\HybridSearchService;

/**
 * Accepts a question about a workspace and queues the work of answering it.
 *
 * Retrieval runs here, not in the job, so a question with nothing to answer is
 * refused immediately instead of after a multi-minute model call.
 */
class AskWorkspace implements Action
{
    /**
     * Cheap next to generation, and more passages ground the answer better.
     */
    private const PASSAGES = 8;

    public function __construct(
        private readonly Workspace $workspace,
        private readonly User $user,
        private readonly string $question,
        private readonly HybridSearchService $search,
    ) {}

    /**
     * @throws ActionFailedException
     */
    public function handle(): array
    {
        $question = trim($this->question);

        if ($question === '') {
            throw new ActionFailedException('Ask a question first.', 422);
        }

        $passages = $this->search->search($this->workspace, $question, self::PASSAGES);

        if ($passages->isEmpty()) {
            throw new ActionFailedException(
                'No matching entries in this workspace yet. Add some entries, or reword the question.',
                404
            );
        }

        $sources = $passages->map(fn ($passage) => [
            'entry_id' => $passage->chunk->knowledge_entry_id,
            'title' => $passage->chunk->entry?->title,
            'body' => $passage->chunk->body,
            'found_by' => $passage->foundBy,
        ])->values()->all();

        $message = $this->workspace->assistantMessages()->create([
            'user_id' => $this->user->id,
            'question' => $question,
            'sources' => $sources,
            'status' => 'pending',
        ]);

        // Dispatched after the row exists, because the job looks it up by id.
        GenerateAssistantAnswer::dispatch($message->id);

        return $message->fresh()->toArray();
    }
}
