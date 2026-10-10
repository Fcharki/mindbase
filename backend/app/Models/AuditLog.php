<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditLog extends Model
{
    public const ENTITY_WORKSPACE = 'workspace';

    public const ENTITY_KNOWLEDGE_ENTRY = 'knowledge_entry';

    public const ENTITY_MEMBER = 'member';

    public const ENTITY_JOIN_REQUEST = 'join_request';

    protected $fillable = [
        'workspace_id',
        'user_id',
        'action',
        'entity_type',
        'entity_id',
        'metadata',
        'ip_address',
        'user_agent',
        'occurred_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        'occurred_at' => 'datetime',
    ];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // Audit logging is a side effect: never let a failure here break the
    // operation, so any exception is swallowed.
    public static function record(
        int $workspaceId,
        string $action,
        string $entityType,
        ?int $entityId = null,
        array $metadata = []
    ): ?self {
        try {
            $request = request();

            return static::create([
                'workspace_id' => $workspaceId,
                'user_id' => auth()->id(),
                'action' => $action,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'metadata' => $metadata ?: null,
                'ip_address' => $request?->ip(),
                'user_agent' => $request?->userAgent(),
                'occurred_at' => now(),
            ]);
        } catch (\Throwable $e) {
            return null;
        }
    }
}
