<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Tag extends Model
{
    protected $fillable = ['workspace_id', 'name'];

    public function workspace()
    {
        return $this->belongsTo(Workspace::class);
    }

    public function entries()
    {
        return $this->belongsToMany(KnowledgeEntry::class, 'knowledge_entry_tag');
    }
}
