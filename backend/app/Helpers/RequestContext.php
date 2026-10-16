<?php

namespace App\Helpers;

use Illuminate\Support\Facades\Auth;

class RequestContext
{
    public static function getRequestId(): ?string
    {
        $request = request();
        if ($request && $request->hasHeader('X-Request-ID')) {
            return $request->header('X-Request-ID');
        }

        return null;
    }

    public static function getWorkspaceId(): ?int
    {
        $request = request();
        if (! $request) {
            return null;
        }
        if (method_exists($request, 'route') && $request->route()) {
            $workspace = $request->route('workspace');
            if ($workspace && is_object($workspace) && method_exists($workspace, 'getKey')) {
                return $workspace->getKey();
            }
        }

        return null;
    }

    public static function getUserId(): ?int
    {
        $request = request();
        if ($request && $request->user()) {
            return $request->user()->id;
        }

        return Auth::id();
    }
}
