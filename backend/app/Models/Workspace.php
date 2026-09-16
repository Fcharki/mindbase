<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Workspace extends Model
{
    protected $fillable = ['name', 'owner_id'];
    
    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function members()
    {
        return $this->belongsToMany(User::class, 'workspace_members')
            ->using(WorkspaceMember::class)
            ->withPivot('role')
            ->withTimestamps();
    }

    public function entries()
    {
        return $this->hasMany(KnowledgeEntry::class);
    }

    public function tags()
    {
        return $this->hasMany(Tag::class);
    }
    
}
