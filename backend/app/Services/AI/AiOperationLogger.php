<?php

namespace App\Services\AI;

use App\Helpers\RequestContext;
use Illuminate\Support\Facades\Log;

class AiOperationLogger
{
    public function log(string $operation, string $model, int $latencyMs, ?int $tokens = null, bool $success = true, array $extra = []): void
    {
        $context = array_merge([
            'request_id' => RequestContext::getRequestId(),
            'user_id' => RequestContext::getUserId(),
            'workspace_id' => RequestContext::getWorkspaceId(),
            'operation' => $operation,
            'model' => $model,
            'latency' => $latencyMs,
            'tokens' => $tokens,
            'success' => $success,
        ], $extra);

        Log::info('AI request', $context);
    }
}
