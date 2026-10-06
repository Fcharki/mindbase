<?php

namespace App\Services\AI;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

class SummarizationService
{
    private Client $client;

    public function __construct()
    {
        $this->client = new Client([
            'base_uri' => rtrim(config('ai.service_url'), '/').'/',
            'timeout' => config('ai.timeout'),
        ]);
    }

    /**
     * Summarize the given text using the local FastAPI summarization model.
     *
     * @throws GuzzleException
     */
    public function summarize(string $text): string
    {
        return $this->summarizeWithMetadata($text)['summary'];
    }

    /**
     * Summarize the given text and report which model answered.
     *
     * Nothing is cached here, so every call re-runs the model. That is why a
     * stored summary has to record which backend wrote it: after a backend swap
     * the older rows are the ones worth regenerating.
     *
     * @param  string|null  $backend  Ask for a specific model. The AI service
     *                                falls back to its configured default for an
     *                                unknown name and says so in the response.
     * @param  int|null  $timeout  Overrides the client default, because the slow
     *                             backend needs more than the synchronous path.
     * @return array{summary: string, backend: string|null}
     *
     * @throws GuzzleException
     */
    public function summarizeWithMetadata(
        string $text,
        ?string $backend = null,
        ?int $timeout = null,
    ): array {
        $payload = ['prompt' => $text];

        if ($backend !== null) {
            $payload['backend'] = $backend;
        }

        $response = $this->client->post('summarize', array_filter([
            'json' => $payload,
            'timeout' => $timeout,
        ]));

        $body = json_decode($response->getBody()->getContents(), true);

        return [
            'summary' => $body['response'] ?? '',
            'backend' => $body['backend'] ?? null,
        ];
    }
}
