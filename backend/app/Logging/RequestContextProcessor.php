<?php

namespace App\Logging;

use App\Helpers\RequestContext;
use Monolog\Processor\ProcessorInterface;

class RequestContextProcessor implements ProcessorInterface
{
    public function __invoke($record)
    {
        if (is_array($record)) {
            $record['extra'] = array_merge($record['extra'] ?? [], [
                'request_id' => RequestContext::getRequestId(),
                'user_id' => RequestContext::getUserId(),
                'workspace_id' => RequestContext::getWorkspaceId(),
                'ip_address' => request()?->ip(),
                'user_agent' => request()?->userAgent(),
            ]);
        } else {
            $record->extra = array_merge($record->extra ?? [], [
                'request_id' => RequestContext::getRequestId(),
                'user_id' => RequestContext::getUserId(),
                'workspace_id' => RequestContext::getWorkspaceId(),
                'ip_address' => request()?->ip(),
                'user_agent' => request()?->userAgent(),
            ]);
        }

        return $record;
    }
}
