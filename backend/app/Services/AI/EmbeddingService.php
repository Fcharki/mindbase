<?php

namespace App\Services\AI;

use App\Exceptions\ActionFailedException;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Client for the embedding endpoint of the AI service.
 *
 * The model takes about a minute to load, so text goes over in batches: one
 * call per entry rather than one per chunk.
 */
class EmbeddingService
{
    private Client $client;

    public function __construct(?int $timeout = null)
    {
        $this->client = new Client([
            'base_uri' => rtrim(config('ai.service_url'), '/').'/',
            'timeout' => $timeout ?? config('ai.background_timeout'),
        ]);
    }

    /**
     * Embed a list of strings, returning vectors in the same order.
     *
     * Ordering is the point: text with no prose comes back as an empty vector
     * rather than being dropped, so callers can zip chunks to vectors without
     * losing track of which is which.
     *
     * @param  list<string>  $texts
     * @return list<list<float>>
     *
     * @throws GuzzleException
     */
    public function embedMany(array $texts): array
    {
        if ($texts === []) {
            return [];
        }

        $texts = array_values($texts);

        $response = $this->client->post('embed/batch', [
            'json' => ['texts' => $texts],
        ]);

        $body = json_decode($response->getBody()->getContents(), true);

        $embeddings = $body['embeddings'] ?? null;

        if (! is_array($embeddings) || count($embeddings) !== count($texts)) {
            throw new ActionFailedException(sprintf(
                'Expected %d embeddings from the AI service but received %d.',
                count($texts),
                is_array($embeddings) ? count($embeddings) : 0,
            ));
        }

        foreach ($embeddings as $embedding) {
            // An empty vector is a documented answer, not a fault: the service
            // sends one for text it has nothing to say about, and the caller is
            // expected to leave that chunk out rather than fail the batch. Any
            // vector that does exist still has to match the column width.
            if ($embedding !== []) {
                $this->assertUsable($embedding);
            }
        }

        return $embeddings;
    }

    /**
     * Embed a single string.
     *
     * @return list<float>
     *
     * @throws GuzzleException
     */
    public function embed(string $text): array
    {
        $response = $this->client->post('embed', ['json' => ['text' => $text]]);

        $body = json_decode($response->getBody()->getContents(), true);

        $this->assertUsable($body['embedding'] ?? null);

        return $body['embedding'];
    }

    /**
     * A vector has to match the column width, otherwise the insert fails at the
     * database with an error that says nothing about which model caused it.
     *
     * @param  mixed  $embedding
     */
    private function assertUsable($embedding): void
    {
        $expected = config('ai.embedding_dimension');

        if (! is_array($embedding) || count($embedding) === 0) {
            throw new ActionFailedException(
                'The AI service returned an empty embedding.'
            );
        }

        if (count($embedding) !== $expected) {
            throw new ActionFailedException(sprintf(
                'The AI service returned a %d dimension embedding but the vector column is %d wide. '
                    .'Change AI_EMBEDDING_DIMENSION or the model, then rebuild the column.',
                count($embedding),
                $expected,
            ));
        }
    }
}
