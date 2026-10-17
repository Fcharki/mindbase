<?php

namespace App\Services\AI;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Log;

/**
 * Shared HTTP client for the AI service, with bounded retries.
 *
 * The service restarts its models under load and sits behind a container
 * network, so a single refused or dropped connection is common and usually
 * temporary. Retrying it here keeps that from surfacing as a failed answer or a
 * failed search, while keeping the budget predictable: the retry count and the
 * per-attempt timeout are both bounded, so a dead service still fails in
 * bounded time rather than hanging a worker.
 *
 * Only transport-level faults are retried. A 4xx means the request itself was
 * wrong and repeating it would only waste the same budget again.
 */
class AiHttpClient
{
    private Client $client;

    private int $retries;

    public function __construct(?int $timeout = null, ?int $retries = null)
    {
        $this->client = new Client([
            'base_uri' => rtrim((string) config('ai.service_url'), '/').'/',
            'timeout' => $timeout ?? (int) config('ai.timeout'),
        ]);

        $this->retries = $retries ?? max(0, (int) config('ai.retries', 2));
    }

    /**
     * POST JSON and decode the JSON response.
     *
     * @param  array<string, mixed>  $json
     * @return array<string, mixed>
     *
     * @throws GuzzleException
     */
    public function post(string $path, array $json, ?int $timeout = null): array
    {
        $response = $this->sendWithRetries($path, [
            'json' => $json,
            'timeout' => $timeout ?? $this->client->getConfig('timeout'),
        ]);

        $body = json_decode($response, true);

        if (! is_array($body)) {
            throw new \RuntimeException(
                'The AI service returned a response that was not JSON.',
            );
        }

        return $body;
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function sendWithRetries(string $path, array $options): string
    {
        $attempt = 0;
        $maxAttempts = $this->retries + 1;

        while (true) {
            $attempt++;

            try {
                $response = $this->client->post($path, $options);

                return $response->getBody()->getContents();
            } catch (GuzzleException $e) {
                if (! $this->shouldRetry($e) || $attempt >= $maxAttempts) {
                    throw $e;
                }

                // Linear backoff: the common case is the service restarting or
                // warming a model, and a one second pause already covers most
                // of that without stacking up behind a longer outage.
                usleep(500_000 * $attempt);

                Log::warning('Retrying AI service call after a transient failure.', [
                    'path' => $path,
                    'attempt' => $attempt,
                    'message' => $e->getMessage(),
                ]);
            }
        }
    }

    private function shouldRetry(GuzzleException $e): bool
    {
        if ($e instanceof ConnectException) {
            return true;
        }

        if (method_exists($e, 'getResponse')) {
            $status = $e->getResponse()?->getStatusCode();

            // 429 too: the model service shedding load is transient by
            // definition, and the pause is what lets the retry land after it.
            return $status !== null && ($status === 429 || $status >= 500);
        }

        return false;
    }
}
