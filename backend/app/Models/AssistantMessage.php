<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One question asked of a workspace's assistant, and the answer it produced.
 *
 * Rows sit pending until the job fills in the answer, since generation takes
 * far longer than a request may hold a worker.
 */
class AssistantMessage extends Model
{
    /** Waiting for the model. */
    public const STATUS_PENDING = 'pending';

    /** Answered, and `answer` is readable. */
    public const STATUS_READY = 'ready';

    /** Gave up; `error` says why. */
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'workspace_id',
        'user_id',
        'question',
        'answer',
        'sources',
        'status',
        'error',
        'feedback',
    ];

    /**
     * Stored as JSON, handed back as an array.
     *
     * @return array<int, mixed>|null
     */
    protected function casts(): array
    {
        return [
            'sources' => 'array',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @param  Builder<AssistantMessage>  $query
     */
    public function scopeForWorkspace(Builder $query, int $workspaceId): Builder
    {
        return $query->where('workspace_id', $workspaceId);
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }
}
