<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Tag extends Model
{
    protected $fillable = ['workspace_id', 'name', 'parent_id'];

    public function workspace()
    {
        return $this->belongsTo(Workspace::class);
    }

    public function entries()
    {
        return $this->belongsToMany(KnowledgeEntry::class, 'knowledge_entry_tag');
    }

    /**
     * The tag this one is nested under, if any. Same workspace by convention;
     * enforced by TagController rather than a composite foreign key.
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Tag::class, 'parent_id');
    }

    /**
     * Tags nested under this one.
     *
     * @return HasMany<Tag, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(Tag::class, 'parent_id')->orderBy('name');
    }

    /**
     * Every tag below this one in the hierarchy, not only direct children.
     *
     * Walking two levels (parent -> children -> grandchildren) keeps this to a
     * fixed number of queries for the nesting depth this feature supports,
     * which is far cheaper than a recursive CTE for a handful of rows.
     *
     * @return array<int, int>
     */
    public function descendantIds(): array
    {
        $ids = [];

        foreach ($this->children as $child) {
            $ids[] = $child->id;
            $ids = array_merge($ids, $child->descendantIds());
        }

        return $ids;
    }
}
