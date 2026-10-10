<?php

namespace App\Services\AI;

use App\Helpers\RequestContext;
use Illuminate\Support\Facades\Log;

class AiLoggingService
{
    public function logRequest(string $operation, string $model, int $latencyMs, int $tokens, bool $success, array $extra = []): void
    {
        $context = array_merge([
            'request_id' => RequestContext::getRequestId(),
            'user_id' => RequestContext::getUserId(),
            'workspace_id' => RequestContext::getWorkspaceId(),
            'operation' => $operation,
            'model' => $model,
            'latency_ms' => $latencyMs,
            'tokens' => $tokens,
            'success' => $success,
        ], $extra);

        Log::info('AI request', $context);
    }
}
