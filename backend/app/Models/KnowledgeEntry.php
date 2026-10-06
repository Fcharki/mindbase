<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class KnowledgeEntry extends Model
{
    protected $fillable = [
        'workspace_id',
        'created_by',
        'title',
        'content',
    ];

    /**
     * Delete attached documents (and their binaries) with the entry.
     *
     * The database cascades the document rows, but not the files on disk,
     * so the rows have to be removed through Eloquent to fire the model's
     * deleting hook.
     */
    protected static function booted(): void
    {
        static::deleting(function (KnowledgeEntry $entry) {
            $entry->documents->each->delete();
        });
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(
            Tag::class,
            'knowledge_entry_tag'
        );
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }

    /**
     * The summary of this entry's current content, if one has been generated.
     *
     * Not eager loaded by the entry endpoints: it is absent for the majority of
     * entries until a job has run, and nothing in the list or detail views
     * needs it.
     */
    public function summary(): HasOne
    {
        return $this->hasOne(Summary::class);
    }

    /**
     * The embedded passages retrieval searches over.
     *
     * Not eager loaded by the entry endpoints: a viewer only sees these when
     * something retrieves them, and there is one row per couple of thousand
     * characters of content.
     */
    public function chunks(): HasMany
    {
        return $this->hasMany(EntryChunk::class);
    }

    /**
     * The discussion on this entry.
     *
     * Not eager loaded by the entry endpoints for the same reason as chunks,
     * and for an additional reason: a deleted author leaves user_id null, so the
     * count alone would not tell a client who to show. The discussion is read
     * through CommentController::index, which needs its own eager loads.
     */
    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class);
    }
}
