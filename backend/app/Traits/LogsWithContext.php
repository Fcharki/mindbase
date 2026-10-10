<?php

namespace App\Traits;

use App\Helpers\RequestContext;
use Illuminate\Support\Facades\Log;

trait LogsWithContext
{
    protected function logInfo(string $message, array $context = []): void
    {
        Log::info($message, $this->withContext($context));
    }

    protected function logWarning(string $message, array $context = []): void
    {
        Log::warning($message, $this->withContext($context));
    }

    protected function logError(string $message, array $context = []): void
    {
        Log::error($message, $this->withContext($context));
    }

    protected function logDebug(string $message, array $context = []): void
    {
        Log::debug($message, $this->withContext($context));
    }

    protected function logCritical(string $message, array $context = []): void
    {
        Log::critical($message, $this->withContext($context));
    }

    private function withContext(array $context): array
    {
        return array_merge([
            'request_id' => RequestContext::getRequestId(),
            'user_id' => RequestContext::getUserId(),
            'workspace_id' => RequestContext::getWorkspaceId(),
        ], $context);
    }
}
