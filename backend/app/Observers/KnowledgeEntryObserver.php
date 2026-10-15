<?php

namespace App\Observers;

use App\Models\AuditLog;
use App\Models\KnowledgeEntry;
use App\Services\WorkspaceIndexCache;
use App\Traits\LogsAppActions;

class KnowledgeEntryObserver
{
    use LogsAppActions;

    public function created(KnowledgeEntry $entry): void
    {
        $this->logAppAction('knowledge_entry.created', 'success', [
            'workspace_id' => $entry->workspace_id,
            'entity_id' => $entry->id,
        ]);
        $this->logAudit($entry, 'created');
        // The entry count on the workspace index moved, so the cached list is
        // stale. Only create and delete change that count, not an update.
        app(WorkspaceIndexCache::class)->flush();
    }

    public function updated(KnowledgeEntry $entry): void
    {
        $changes = $entry->getChanges();
        unset($changes['updated_at']);

        $this->logAppAction('knowledge_entry.updated', 'success', [
            'workspace_id' => $entry->workspace_id,
            'entity_id' => $entry->id,
        ]);
        $this->logAudit($entry, 'updated', $changes);
    }

    public function deleted(KnowledgeEntry $entry): void
    {
        $this->logAppAction('knowledge_entry.deleted', 'success', [
            'workspace_id' => $entry->workspace_id,
            'entity_id' => $entry->id,
        ]);
        $this->logAudit($entry, 'deleted');
        app(WorkspaceIndexCache::class)->flush();
    }

    private function logAudit(KnowledgeEntry $entry, string $action, array $metadata = []): void
    {
        AuditLog::record(
            $entry->workspace_id,
            $action,
            AuditLog::ENTITY_KNOWLEDGE_ENTRY,
            $entry->id,
            array_merge(['title' => $entry->title], $metadata),
        );
    }
}
