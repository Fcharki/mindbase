<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Reply extends Model
{
    protected $fillable = [
        'comment_id',
        'user_id',
        'body',
    ];

    public function comment(): BelongsTo
    {
        return $this->belongsTo(Comment::class);
    }

    /**
     * The author. Nullable for the same reason as on Comment: the reply stays
     * readable after the account is deleted.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
