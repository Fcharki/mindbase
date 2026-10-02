<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The stored summary of a knowledge entry's content.
 *
 * One row per entry, rewritten in place. `content_hash` records the content it
 * was generated from so a summary can be told apart from one produced for a
 * revision that has since been edited away.
 *
 * @property int $id
 * @property int $knowledge_entry_id
 * @property string $content_hash
 * @property string $body
 * @property string|null $backend
 */
class Summary extends Model
{
    protected $fillable = [
        'knowledge_entry_id',
        'content_hash',
        'body',
        'backend',
    ];

    public function knowledgeEntry(): BelongsTo
    {
        return $this->belongsTo(KnowledgeEntry::class);
    }
}
