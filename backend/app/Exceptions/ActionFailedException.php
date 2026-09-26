<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown by an action when a business rule rejects the operation.
 *
 * Carrying the HTTP status on the exception keeps the rule in the action while
 * still letting the transport layer decide how to report it, instead of the
 * action having to know about status codes.
 */
class ActionFailedException extends RuntimeException
{
    public function __construct(
        string $message,
        private readonly int $status = 422,
    ) {
        parent::__construct($message);
    }

    public function status(): int
    {
        return $this->status;
    }
}
