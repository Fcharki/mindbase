<?php

namespace App\Services\AI;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Talks to the /rag endpoint, the interactive side of the AI service.
 */
class RagService
{
    private string $baseUri;

    private int $timeout;

    public function __construct(?int $timeout = null)
    {
        $this->baseUri = rtrim(config('ai.service_url'), '/').'/';
        $this->timeout = $timeout ?? (int) config('ai.timeout');
    }

    /**
     * Per call, because the budget differs: a queued job waits far longer than a
     * request holding a worker.
     */
    private function client(?int $timeout): Client
    {
        return new Client([
            'base_uri' => $this->baseUri,
            'timeout' => $timeout ?? $this->timeout,
        ]);
    }

    /**
     * Answer a prompt, given the passages retrieved for it.
     *
     * @param  string  $context  Retrieved passages, already formatted.
     * @param  int|null  $timeout  Overrides the configured budget.
     *
     * @throws GuzzleException
     */
    public function ask(
        string $prompt,
        string $context,
        ?string $system = null,
        ?int $timeout = null,
    ): string {
        $start = microtime(true);
        $payload = ['prompt' => $this->withContext($prompt, $context)];

        if ($system !== null) {
            $payload['system'] = $system;
        }

        try {
            $response = $this->client($timeout)->post('rag', ['json' => $payload]);

            $body = json_decode($response->getBody()->getContents(), true);
            $latency = (int) round((microtime(true) - $start) * 1000);

            app(AiOperationLogger::class)->log(
                'rag',
                'rag-model',
                $latency,
                null,
                true
            );

            return $body['response'] ?? '';
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
