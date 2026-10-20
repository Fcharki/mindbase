<?php

namespace App\Models;

use App\Services\EntryImageStorageService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class EntryImage extends Model
{
    protected $fillable = [
        'uuid',
        'workspace_id',
        'knowledge_entry_id',
        'uploaded_by',
        'disk',
        'path',
        'original_name',
        'mime_type',
        'size',
        'checksum',
    ];

    protected $casts = [
        'size' => 'integer',
    ];

    /**
     * The on-disk location and digest are storage internals. The browser only
     * addresses an image by its UUID, and never sees where it lives.
     *
     * @var list<string>
     */
    protected $hidden = [
        'path',
        'checksum',
    ];

    /**
     * The UUID is the public route key, so content HTML can point at an image
     * without exposing the sequential database id.
     */
    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /**
     * Remove the binary from the disk whenever the metadata row goes away.
     */
    protected static function booted(): void
    {
        static::deleting(function (EntryImage $image) {
            app(EntryImageStorageService::class)->delete($image);
        });
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function knowledgeEntry(): BelongsTo
    {
        return $this->belongsTo(KnowledgeEntry::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /**
     * Absolute URL referenced by the editor content.
     */
    public function url(): string
    {
        return route('entry-images.show', ['entryImage' => $this->uuid]);
    }

    public function getHumanSizeAttribute(): string
    {
        $bytes = max((int) $this->size, 0);
        $units = ['B', 'KB', 'MB', 'GB'];
        $power = $bytes > 0 ? (int) floor(log($bytes, 1024)) : 0;
        $power = min($power, count($units) - 1);

        return round($bytes / (1024 ** $power), $power === 0 ? 0 : 1).' '.$units[$power];
    }

    /**
     * Lower-case extension of the name the uploader gave the file.
     */
    public function extension(): string
    {
        return strtolower(pathinfo($this->original_name, PATHINFO_EXTENSION));
    }

    public function fileExists(): bool
    {
        return Storage::disk($this->disk)->exists($this->path);
    }
}
