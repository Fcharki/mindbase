<?php

namespace App\Actions;

use App\Exceptions\ActionFailedException;

/**
 * Marker for a single-purpose, invokable unit of work.
 *
 * An action owns one business operation end to end: the rules, the writes, and
 * the transactions. Controllers stay responsible for the things that are
 * genuinely about HTTP -- resolving the request, authorizing the caller,
 * choosing a status code, and wrapping the result in a resource.
 *
 * Actions signal failure by throwing, not by returning null or a falsy value,
 * so a caller cannot silently ignore a rejected operation. The status on the
 * exception says how the rejection should be reported without the action
 * having to build a response.
 *
 * The return type is left off handle() so an action can declare void, bool or
 * a model without the interface forcing a meaningless common type on it.
 */
interface Action
{
    /**
     * @throws ActionFailedException
     */
    public function handle();
}
