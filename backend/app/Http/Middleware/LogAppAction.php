<?php

namespace App\Http\Middleware;

use App\Traits\LogsAppActions;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class LogAppAction
{
    use LogsAppActions;

    public function handle(Request $request, Closure $next, ?string $operation = null): Response
    {
        $startTime = microtime(true);
        $response = $next($request);

        $op = $operation ?? $request->method().' '.$request->path();
        $status = $response->getStatusCode() < 400 ? 'success' : 'failure';
        $this->logAppAction($op, $status, ['status_code' => $response->getStatusCode()], $startTime);

        return $response;
    }
}
