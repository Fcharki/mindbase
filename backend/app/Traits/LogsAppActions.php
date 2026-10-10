<?php

namespace App\Traits;

use App\Helpers\RequestContext;
use Illuminate\Support\Facades\Log;

trait LogsAppActions
{
    protected function logAppAction(string $operation, string $status = 'success', array $context = [], ?float $startTime = null): void
    {
        $duration = null;
        if ($startTime !== null) {
            $duration = (int) round((microtime(true) - $startTime) * 1000);
        }

        $logContext = array_merge([
            'request_id' => RequestContext::getRequestId(),
            'user_id' => RequestContext::getUserId(),
            'workspace_id' => RequestContext::getWorkspaceId(),
            'operation' => $operation,
            'status' => $status,
            'duration_ms' => $duration,
        ], $context);

        Log::info('app.action', $logContext);
    }
}
