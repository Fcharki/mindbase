<?php

namespace App\Logging;

use App\Helpers\RequestContext;
use Illuminate\Support\Facades\Log;

class CustomLogger
{
    public static function logAppAction(string $operation, string $status = 'success', array $context = []): void
    {
        $base = array_merge([
            'operation' => $operation,
            'status' => $status,
            'request_id' => RequestContext::getRequestId(),
            'user_id' => RequestContext::getUserId(),
            'workspace_id' => RequestContext::getWorkspaceId(),
            'duration_ms' => null,
        ], $context);

        Log::info('app.action', $base);
    }

    public static function logAiRequest(array $context): void
    {
        Log::info('AI request', $context);
    }
}
