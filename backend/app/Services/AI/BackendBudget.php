<?php

namespace App\Services\AI;

use App\Exceptions\ActionFailedException;

/**
 * Decides whether a named summarization backend can actually be waited for.
 *
 * Lives here rather than in a controller because the AI service reports nothing
 * about its own speed and config/ai.php is the only record of it. The job asks
 * the same question as a last line of defence, since one dispatched before a
 * config change can still land after it.
 */
class BackendBudget
{
    /**
     * Refuse a backend that cannot finish inside the budget it was granted.
     *
     * The point is to fail before any work starts, with the two numbers
     * involved, rather than ten minutes later as an opaque transport timeout.
     */
    public function assertAffordable(?string $backend): void
    {
        if ($backend === null || config('ai.allow_slow_backends')) {
            return;
        }

        $seconds = (int) config("ai.backend_seconds.{$backend}", 0);

        // An unlisted backend is the service's problem to answer, not ours to
        // pre-empt: it falls back to its configured default there.
        if ($seconds <= 0) {
            return;
        }

        $budget = (int) config('ai.background_timeout');

        if ($seconds <= $budget) {
            return;
        }

        throw new ActionFailedException(sprintf(
            "The '%s' backend needs about %d seconds for this entry, but the "
                .'background budget is %d seconds, so the request would be '
                .'abandoned before it finished. Use a faster backend, raise '
                .'AI_SERVICE_BACKGROUND_TIMEOUT to at least %d, or set '
                .'AI_ALLOW_SLOW_BACKENDS=true to queue it anyway.',
            $backend,
            $seconds,
            $budget,
            $seconds,
        ), 422);
    }
}
