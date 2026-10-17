<?php

namespace App\Services\AI;

use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Log;

/**
 * Asks the AI service for a search-friendlier version of a question.
 *
 * Only used as a second attempt: literal words that already retrieve passages
 * are the better query, because they can match an exact identifier. This exists
 * for the paraphrase that full-text search misses entirely ("how do tokens
 * expire" against a passage about credential lifetimes).
 */
class QueryRewriteService
{
    private const MAX_NEW_TOKENS = 48;

    public function __construct(private readonly AiHttpClient $http) {}

    /**
     * A rewritten query, or null when the model had nothing useful to offer.
     *
     * A failure here must never cost the caller their original question, so
     * every error path returns null rather than throwing.
     */
    public function rewrite(string $question): ?string
    {
        $prompt = 'Rewrite this search query as a short, literal search '
            .'query (at most ten words) that would match passages in a personal '
            .'knowledge base. Keep every concrete term, name or identifier. '
            ."Reply with the query only, no quotes and no explanation.\n\n"
            .'Query: '.$question;

        try {
            $body = $this->http->post('rag', [
                'prompt' => $prompt,
                'system' => 'You rewrite search queries. Reply with the rewritten query only.',
                'max_new_tokens' => self::MAX_NEW_TOKENS,
            ], timeout: 30);
        } catch (GuzzleException $e) {
            Log::warning('Query rewrite failed, using the original question.', [
                'message' => $e->getMessage(),
            ]);

            return null;
        }

        $answer = trim((string) ($body['response'] ?? ''));
        $answer = trim($answer, "\"'\n\t ");

        if ($answer === '' || mb_strtolower($answer) === mb_strtolower($question)) {
            return null;
        }

        return mb_substr($answer, 0, 300);
    }
}
