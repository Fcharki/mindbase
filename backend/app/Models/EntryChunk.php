<?php

namespace App\Models;

use App\Casts\AsVector;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One embedded piece of an entry.
 *
 * @property int $id
 * @property int $knowledge_entry_id
 * @property string $source
 * @property int $position
 * @property string $content_hash
 * @property string $body
 */
class EntryChunk extends Model
{
    public const SOURCE_SUMMARY = 'summary';

    public const SOURCE_CONTENT = 'content';

    /**
     * Chunks from one attachment share a source, namespaced by its id so two
     * documents on the same entry cannot collide on position.
     */
    public static function sourceForDocument(int $documentId): string
    {
        return 'document:'.$documentId;
    }

    /**
     * Source for text OCR read out of an inline image.
     */
    public static function sourceForImage(int $entryImageId): string
    {
        return 'image:'.$entryImageId;
    }

    protected $fillable = [
        'knowledge_entry_id',
        'source',
        'position',
        'content_hash',
        'body',
        'embedding',
    ];

    /**
     * The vector is not exposed through the API and has no reason to be
     * serialised into a response.
     *
     * @var list<int>
     */
    protected $hidden = [
        'embedding',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'embedding' => AsVector::class,
        ];
    }

    /**
     * @return BelongsTo<KnowledgeEntry, $this>
     */
    public function entry(): BelongsTo
    {
        return $this->belongsTo(KnowledgeEntry::class, 'knowledge_entry_id');
    }
}
