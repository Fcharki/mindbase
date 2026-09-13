<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class KnowledgeEntry extends Model
{

    protected $fillable = ['workspace_id', 'created_by', 'title', 'content'];
    
    public function workspace()
    {
        return $this->belongsTo(Workspace::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function tags()
    {
        return $this->belongsToMany(Tag::class, 'knowledge_entry_tag');
    }
}
