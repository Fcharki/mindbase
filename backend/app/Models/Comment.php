<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Comment extends Model
{
    protected $fillable = [
        'knowledge_entry_id',
        'user_id',
        'body',
    ];

    /**
     * The entry a comment belongs to.
     */
    public function knowledgeEntry(): BelongsTo
    {
        return $this->belongsTo(KnowledgeEntry::class);
    }

    /**
     * The author.
     *
     * Nullable in the database, so a comment outlives the account that wrote it
     * rather than disappearing from the middle of a discussion. CommentResource
     * renders an absent author as "Deleted user".
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Replies to this comment, one level deep by design.
     *
     * A reply has no replies of its own, so this is always a flat list and the
     * frontend never has to recurse.
     */
    public function replies(): HasMany
    {
        return $this->hasMany(Reply::class);
    }
}
