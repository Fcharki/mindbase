<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Auth;

class Workspace extends Model
{
    protected $fillable = ['name', 'owner_id'];

    protected $appends = ['role'];

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

    public function knowledgeEntries(): HasMany
    {
        return $this->hasMany(
            KnowledgeEntry::class,
            'workspace_id'
        );
    }

    public function entries(): HasMany
    {
        return $this->knowledgeEntries();
    }

    public function tags()
    {
        return $this->hasMany(Tag::class);
    }

    public function getRoleAttribute(): ?string
    {
        if ($this->pivot) {
            return $this->pivot->role;
        }

        if ($this->relationLoaded('members') && Auth::check()) {
            $membership = $this->members->firstWhere('id', Auth::id());

            if ($membership?->pivot) {
                return $membership->pivot->role;
            }
        }

        return null;
    }
}
