<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class Workspace extends Model
{
    protected $fillable = ['name', 'owner_id', 'invite_code'];

    protected $appends = ['role'];

    protected static function booted(): void
    {
        static::creating(function (Workspace $workspace) {
            $workspace->invite_code = Str::random(10);
        });
    }

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

    public function membershipRequests(): HasMany
    {
        return $this->hasMany(MembershipRequest::class);
    }

    public function getRoleAttribute(): ?string
    {
        if (! Auth::check()) {
            return null;
        }

        if ($this->owner_id === Auth::id()) {
            return 'owner';
        }

        if ($this->pivot) {
            return $this->pivot->role;
        }

        if ($this->relationLoaded('members')) {
            $membership = $this->members->firstWhere('id', Auth::id());

            if ($membership?->pivot) {
                return $membership->pivot->role;
            }
        }

        return 'viewer';
    }
}
