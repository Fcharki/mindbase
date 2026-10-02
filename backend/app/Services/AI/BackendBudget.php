<?php

namespace App\Services\AI;

use App\Exceptions\ActionFailedException;

/**
 * Decides whether a named summarization backend can actually be waited for.
 *
 * The AI service reports nothing about its own speed, and the numbers in
 * config/ai.php are the only record of it, so the check lives here rather than
 * being inlined in a controller. The job asks the same question as a last line of
 * defence, because a job dispatched before a config change can still land after
 * it.
 */
class BackendBudget
{
    /**
     * Refuse a backend that cannot finish inside the budget it was granted.
     *
     * A caller asking for the slow model gets told so immediately, with the two
     * numbers involved, instead of discovering it ten minutes later as a
     * transport timeout. That is the whole point: the failure is predictable
     * before any work starts, and saying so plainly is more useful than
     * pretending the request was accepted.
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
