<?php

namespace App\Actions;

use App\Exceptions\ActionFailedException;

/**
 * Marker for a single-purpose, invokable unit of work.
 *
 * An action owns one business operation end to end: rules, writes, transactions.
 * Controllers keep what is genuinely about HTTP -- resolving the request,
 * authorizing the caller, picking a status code, wrapping the result.
 *
 * Failure is signalled by throwing rather than returning a falsy value, so a
 * caller cannot silently drop a rejected operation. The exception carries the
 * status, which keeps response building out of the action.
 *
 * handle() has no declared return type so an action can return void, bool or a
 * model without the interface forcing a meaningless common type on it.
 */
interface Action
{
    /**
     * @throws ActionFailedException
     */
    public function handle();
}
