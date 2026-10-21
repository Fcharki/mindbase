<?php

namespace App\Services\AI;

use GuzzleHttp\Exception\GuzzleException;

/**
 * Client for the AI service's OCR endpoint.
 * The image bytes are sent because the services share no filesystem.
 */
class OcrService
{
    public function __construct(private readonly AiHttpClient $http) {}

    /**
     * Read the text an image contains. Client retries are left to the job.
     *
     * @throws GuzzleException
     */
    public function extract(string $contents, string $filename, ?string $mimeType = null): string
    {
        $body = $this->http->postMultipart('ocr', [
            [
                'name' => 'file',
                'contents' => $contents,
                'filename' => $filename,
                'headers' => [
                    'Content-Type' => $mimeType ?: 'application/octet-stream',
                ],
            ],
        ], timeout: (int) config('ai.ocr_timeout'), retries: 0);

        if (! array_key_exists('text', $body) || ! is_string($body['text'])) {
            throw new \RuntimeException(
                'The AI service returned an OCR response without text.'
            );
        }

        return trim($body['text']);
    }
}
