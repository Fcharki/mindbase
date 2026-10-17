<?php

namespace App\Services\AI;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use RuntimeException;

/**
 * Talks to the interactive side of the AI service: /rag for a full answer,
 * /rag/stream for the same answer written as it is generated.
 */
class RagService
{
    /**
     * How often the caller's delta callback may be invoked per second at most.
     *
     * The job flushes each delta into the row it is answering, and each flush is
     * a write. Tokens arrive faster than a client can usefully read them, so
     * this bounds the write rate without changing what the reader finally sees:
     * the deltas are concatenated either way.
     */
    private const DELTA_MIN_INTERVAL = 0.2;

    private AiHttpClient $http;

    private string $baseUri;

    public function __construct(?int $timeout = null, ?int $retries = null)
    {
        $this->http = new AiHttpClient($timeout, $retries);
        $this->baseUri = rtrim((string) config('ai.service_url'), '/').'/';
    }

    /**
     * Answer a prompt, given the passages retrieved for it.
     *
     * @param  string  $context  Retrieved passages, already formatted.
     * @param  int|null  $timeout  Overrides the configured budget.
     * @param  int|null  $maxNewTokens  Caps answer length for small calls.
     *
     * @throws GuzzleException
     */
    public function ask(
        string $prompt,
        string $context,
        ?string $system = null,
        ?int $timeout = null,
        ?int $maxNewTokens = null,
    ): string {
        $start = microtime(true);
        $payload = ['prompt' => $this->withContext($prompt, $context)];

        if ($system !== null) {
            $payload['system'] = $system;
        }

        if ($maxNewTokens !== null) {
            $payload['max_new_tokens'] = $maxNewTokens;
        }

        try {
            $body = $this->http->post('rag', $payload, timeout: $timeout);
            $latency = (int) round((microtime(true) - $start) * 1000);

            app(AiOperationLogger::class)->log(
                'rag',
                'rag-model',
                $latency,
                null,
                true
            );

            return (string) ($body['response'] ?? '');
        } catch (\Throwable $e) {
            $latency = (int) round((microtime(true) - $start) * 1000);
            app(AiOperationLogger::class)->log(
                'rag',
                'rag-model',
                $latency,
                null,
                false
            );
            throw $e;
        }
    }

    /**
     * The same answer, handed to $onDelta as the model writes it.
     *
     * Returns the complete text, so a caller may use the deltas purely for
     * progress and still trust the return value as the answer. A stream that
     * fails after producing part of the text throws rather than returning the
     * fragment: half an answer is not an answer.
     *
     * @param  callable(string): void|null  $onDelta
     *
     * @throws GuzzleException
     */
    public function askStreaming(
        string $prompt,
        string $context,
        ?string $system = null,
        ?int $timeout = null,
        ?callable $onDelta = null,
    ): string {
        $start = microtime(true);
        $payload = ['prompt' => $this->withContext($prompt, $context)];

        if ($system !== null) {
            $payload['system'] = $system;
        }

        $answer = '';
        $lastDelta = 0.0;

        // Its own client rather than the shared one: the body has to be read
        // chunk by chunk as the model writes it, which a client that hands back
        // a finished string cannot do. Retries are left to the caller, because
        // restarting a stream mid-answer would throw away minutes of generation.
        $client = new Client([
            'base_uri' => $this->baseUri,
            'timeout' => $timeout ?? (int) config('ai.timeout'),
            'read_timeout' => $timeout ?? (int) config('ai.timeout'),
        ]);

        try {
            $response = $client->post('rag/stream', [
                'json' => $payload,
                'stream' => true,
            ]);

            $body = $response->getBody();
            $pending = '';

            while (! $body->eof()) {
                $chunk = $body->read(2048);

                if ($chunk === '' || $chunk === false) {
                    break;
                }

                $pending .= $chunk;

                // Frames arrive as complete lines: JSON escapes any newline
                // inside the text, so a physical newline always ends one.
                while (($position = strpos($pending, "\n")) !== false) {
                    $line = substr($pending, 0, $position);
                    $pending = substr($pending, $position + 1);

                    if (! str_starts_with($line, 'data:')) {
                        continue;
                    }

                    $event = json_decode(trim(substr($line, 5)), true);

                    if (! is_array($event)) {
                        continue;
                    }

                    $type = $event['type'] ?? null;

                    if ($type === 'delta') {
                        $delta = (string) ($event['text'] ?? '');

                        if ($delta === '') {
                            continue;
                        }

                        $answer .= $delta;

                        $now = microtime(true);

                        if ($onDelta !== null && $now - $lastDelta >= self::DELTA_MIN_INTERVAL) {
                            $lastDelta = $now;
                            $onDelta($delta);
                        }
                    } elseif ($type === 'done') {
                        // The service reports the authoritative full text with
                        // the final frame, which covers a delta lost to the
                        // rate limit above.
                        if (isset($event['response']) && is_string($event['response'])) {
                            $answer = $event['response'];
                        }
                    } elseif ($type === 'error') {
                        throw new RuntimeException(
                            (string) ($event['message'] ?? 'The AI service failed to generate an answer.'),
                        );
                    }
                }
            }

            $latency = (int) round((microtime(true) - $start) * 1000);

            app(AiOperationLogger::class)->log(
                'rag',
                'rag-model',
                $latency,
                null,
                true
            );

            return $answer;
        } catch (\Throwable $e) {
            $latency = (int) round((microtime(true) - $start) * 1000);
            app(AiOperationLogger::class)->log(
                'rag',
                'rag-model',
                $latency,
                null,
                false
            );
            throw $e;
        }
    }

    /**
     * Without this the model answers from its weights, which for a question
     * about somebody's own notes means inventing plausible ones.
     */
    private function withContext(string $prompt, string $context): string
    {
        $context = trim($context);

        if ($context === '') {
            return $prompt;
        }

        return "Reference passages from the user's knowledge base:\n\n"
            .$context
            ."\n\nAnswer the question using only these passages. If they do not "
            ."contain the answer, say so plainly rather than guessing.\n\n"
            .'Question: '.$prompt;
    }
}
