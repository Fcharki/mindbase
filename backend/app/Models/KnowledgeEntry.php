<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
}
