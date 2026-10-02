<?php

namespace App\Services;

use App\Models\KnowledgeEntry;
use App\Models\Summary;
use Illuminate\Support\Facades\Log;

/**
 * Splits an entry into the pieces that get embedded.
 *
 * Two kinds of piece, deliberately kept apart:
 *
 *  - 'summary' is the compressed overview. It is cheap to embed and reads like
 *    the document, so it matches broad questions well.
 *  - 'content' is the entry text split into paragraphs. A summary cannot answer
 *    a question about a specific term because it dropped exactly those details,
 *    so the original text is still needed, just in pieces small enough that each
 *    vector means one thing.
 *
 * Embedding the whole entry as a single vector is what this replaces: one vector
 * per entry averages every topic in it together, so a question about any one of
 * them lands in the same blur.
 */
class ChunkingService
{
    /**
     * Chunk size in characters, roughly 450 tokens of English prose.
     *
     * The model would accept far more, but a chunk that covers a single idea
     * retrieves better than one covering three. bge-m3 truncates at 8192 tokens,
     * so this is well inside the limit and nothing is being silently cut.
     */
    public const TARGET_CHARS = 1800;

    /**
     * How much of the previous chunk to repeat at the start of the next one.
     *
     * Without it a sentence that straddles a boundary is embedded as two
     * fragments, neither of which contains the whole thought.
     */
    public const OVERLAP_CHARS = 300;

    /**
     * Upper bound on chunks for a single entry.
     *
     * A pathological paste of a whole book should not silently occupy a queue
     * worker for an hour or balloon the storage bill. The cap is generous enough
     * that it only ever engages on content that is not really an entry.
     */
    public const MAX_CHUNKS = 60;

    /**
     * Build the chunk list for an entry, summary first.
     *
     * @return list<array{source: string, position: int, body: string}>
     */
    public function chunksFor(KnowledgeEntry $entry): array
    {
        $summary = $entry->summary;

        $chunks = [];

        // Positions are unique per source, not across the whole entry, so each
        // source gets its own counter.
        if ($summary instanceof Summary) {
            $body = $this->flatten($summary->body);

            if ($body !== '') {
                $position = 0;

                foreach ($this->split($body) as $piece) {
                    $chunks[] = [
                        'source' => 'summary',
                        'position' => $position++,
                        'body' => $piece,
                    ];
                }
            }
        }

        $content = $this->flatten((string) $entry->content);

        if ($content !== '') {
            $position = 0;

            foreach ($this->split($content) as $piece) {
                $chunks[] = [
                    'source' => 'content',
                    'position' => $position++,
                    'body' => $piece,
                ];
            }
        }

        return $this->cap($chunks);
    }

    /**
     * Reduce markup to the prose an embedding model should see.
     *
     * The AI service flattens markup for its own purposes, but the chunk text
     * is what gets stored and what a reader of a search result is shown, so it
     * has to be legible on its own.
     */
    public function flatten(string $html): string
    {
        $text = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', ' ', $html) ?? $html;

        // Turn block endings into spaces so two words either side of a tag are
        // not glued together.
        $text = preg_replace('#<[^>]+>#', ' ', $text) ?? $text;

        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim($text);
    }

    /**
     * Split text into overlapping pieces on sentence boundaries.
     *
     * @return list<string>
     */
    public function split(string $text): array
    {
        if ($text === '') {
            return [];
        }

        // A string with no terminator at all still has to be cut somewhere.
        $sentences = $this->sentences($text);

        $chunks = [];
        $current = '';

        foreach ($sentences as $sentence) {
            // A single sentence longer than the target is emitted on its own
            // rather than being held back waiting for room that never comes.
            if (strlen($sentence) > self::TARGET_CHARS) {
                if ($current !== '') {
                    $chunks[] = $current;
                    $current = '';
                }

                foreach (str_split($sentence, self::TARGET_CHARS) as $piece) {
                    $chunks[] = $piece;
                }

                continue;
            }

            if ($current !== '' && strlen($current) + strlen($sentence) > self::TARGET_CHARS) {
                $chunks[] = $current;
                $current = $this->overlap($current);
            }

            $current .= $current === '' ? $sentence : ' '.$sentence;
        }

        if ($current !== '') {
            $chunks[] = $current;
        }

        return array_values(array_filter($chunks, fn (string $chunk) => $chunk !== ''));
    }

    /**
     * Break text after sentence terminators, keeping the terminator attached.
     *
     * @return list<string>
     */
    private function sentences(string $text): array
    {
        $parts = preg_split('/(?<=[.!?])\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY);

        if ($parts === false) {
            return [$text];
        }

        return array_map('trim', $parts);
    }

    /**
     * The tail of the previous chunk, to be repeated at the head of the next.
     */
    private function overlap(string $chunk): string
    {
        if (strlen($chunk) <= self::OVERLAP_CHARS) {
            return $chunk;
        }

        $tail = substr($chunk, -self::OVERLAP_CHARS);

        // Start the overlap at a word boundary so the repeated text is not cut
        // mid-word and does not begin with a fragment.
        $space = strpos($tail, ' ');

        if ($space !== false) {
            $tail = substr($tail, $space + 1);
        }

        return $tail;
    }

    /**
     * @param  list<array{source: string, position: int, body: string}>  $chunks
     * @return list<array{source: string, position: int, body: string}>
     */
    private function cap(array $chunks): array
    {
        if (count($chunks) <= self::MAX_CHUNKS) {
            return $chunks;
        }

        // Not silent: content that hits the cap is content whose tail will
        // never be retrievable, and that is worth knowing about.
        Log::warning('Entry chunking hit the per-entry cap, dropping the tail.', [
            'chunks_before' => count($chunks),
            'max_chunks' => self::MAX_CHUNKS,
        ]);

        return array_slice($chunks, 0, self::MAX_CHUNKS);
    }
}
