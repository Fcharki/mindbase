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
            'base_uri' => rtrim(config('ai.service_url'), '/') . '/',
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
        $response = $this->client->post('summarize', [
            'json' => [
                'prompt' => $text,
            ],
        ]);

        $body = json_decode($response->getBody()->getContents(), true);

        return $body['response'] ?? '';
    }
}