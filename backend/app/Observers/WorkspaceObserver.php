<?php

namespace App\Observers;

use App\Models\AuditLog;
use App\Models\Workspace;
use App\Traits\LogsAppActions;

class WorkspaceObserver
{
    use LogsAppActions;

    public function created(Workspace $workspace): void
    {
        $this->logAppAction('workspace.created', 'success', [
            'workspace_id' => $workspace->id,
            'entity_id' => $workspace->id,
        ]);
        $this->log($workspace, 'created');
    }

    public function updated(Workspace $workspace): void
    {
        $changes = $workspace->getChanges();
        unset($changes['updated_at']);

        $this->logAppAction('workspace.updated', 'success', [
            'workspace_id' => $workspace->id,
            'entity_id' => $workspace->id,
        ]);
        $this->log($workspace, 'updated', $changes);
    }

    public function deleted(Workspace $workspace): void
    {
        $this->logAppAction('workspace.deleted', 'success', [
            'workspace_id' => $workspace->id,
            'entity_id' => $workspace->id,
        ]);
        $this->log($workspace, 'deleted');
    }

    private function log(Workspace $workspace, string $action, array $metadata = []): void
    {
        AuditLog::record(
            $workspace->id,
            $action,
            AuditLog::ENTITY_WORKSPACE,
            $workspace->id,
            array_merge(['workspace_name' => $workspace->name], $metadata),
        );
    }
}
