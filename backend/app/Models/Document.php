<?php

namespace App\Models;

use App\Services\DocumentStorageService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class Document extends Model
{
    protected $fillable = [
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
     * The on-disk location and digest are storage internals; the browser only
     * ever addresses a document by id and downloads it through the API.
     *
     * DocumentResource also leaves them out. This stays as a second line of
     * defence in case the model is ever serialised without that resource.
     *
     * @var list<string>
     */
    protected $hidden = [
        'path',
        'checksum',
    ];

    /**
     * Remove the binary from the disk whenever its metadata row goes away.
     *
     * The database cascades the row when an entry or workspace is deleted,
     * but a database cascade does not fire Eloquent events, so entry and
     * workspace deletion go through this model's events first.
     */
    protected static function booted(): void
    {
        static::deleting(function (Document $document) {
            app(DocumentStorageService::class)->delete($document);
        });
    }

    public function knowledgeEntry(): BelongsTo
    {
        return $this->belongsTo(KnowledgeEntry::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
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
     * Whether the binary is still present on its disk.
     */
    public function fileExists(): bool
    {
        return Storage::disk($this->disk)->exists($this->path);
    }
}
