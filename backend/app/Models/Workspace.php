<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Workspace extends Model
{
    protected $fillable = ['name', 'owner_id', 'invite_code'];

    protected static function booted(): void
    {
        static::creating(function (Workspace $workspace) {
            $workspace->invite_code = Str::random(10);
        });

        // Inline images are not reachable through the entry cascade, so their
        // files have to be removed before the database rows disappear.
        static::deleting(function (Workspace $workspace) {
            $workspace->entryImages->each->delete();
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

    public function entryImages()
    {
        return $this->hasMany(EntryImage::class);
    }

    public function membershipRequests(): HasMany
    {
        return $this->hasMany(MembershipRequest::class);
    }

    public function assistantMessages(): HasMany
    {
        return $this->hasMany(AssistantMessage::class);
    }
}
