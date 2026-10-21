<?php

namespace App\Services;

use App\Models\Document;
use App\Models\EntryChunk;
use App\Models\EntryImage;
use App\Models\KnowledgeEntry;
use App\Models\Summary;
use Illuminate\Support\Facades\Log;

/**
 * Splits an entry into the pieces that get embedded.
 *
 * 'summary' is the compressed overview: cheap to embed and reads like the
 * document, so it matches broad questions. 'content' is the entry text split
 * into paragraphs, because a summary has dropped the specific terms a question
 * may be about. 'document:<id>' is the text extracted from an attachment, so a
 * PDF's contents are as searchable as the entry's own prose. One vector per
 * entry would average every topic together, so a question about any one of them
 * lands in the same blur.
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

        $position = 0;

        // Content keeps its block structure: splitting on the paragraph and
        // heading boundaries the editor already produces lands a chunk on one
        // idea far more often than cutting a stream of characters does.
        foreach ($this->splitHtml((string) $entry->content) as $piece) {
            $chunks[] = [
                'source' => 'content',
                'position' => $position++,
                'body' => $piece,
            ];
        }

        foreach ($this->documentChunks($entry) as $chunk) {
            $chunks[] = $chunk;
        }

        foreach ($this->entryImageChunks($entry) as $chunk) {
            $chunks[] = $chunk;
        }

        return $this->cap($chunks);
    }

    /**
     * Chunks for every attachment whose text was extracted successfully.
     *
     * @return list<array{source: string, position: int, body: string}>
     */
    private function documentChunks(KnowledgeEntry $entry): array
    {
        $documents = $entry->documents()
            ->where('extraction_status', Document::EXTRACTION_READY)
            ->whereNotNull('extracted_text')
            ->orderBy('id')
            ->get();

        $chunks = [];

        foreach ($documents as $document) {
            $body = $this->flatten((string) $document->extracted_text);

            if ($body === '') {
                continue;
            }

            $position = 0;
            $source = EntryChunk::sourceForDocument($document->id);

            foreach ($this->split($body) as $piece) {
                $chunks[] = [
                    'source' => $source,
                    'position' => $position++,
                    'body' => $piece,
                ];
            }
        }

        return $chunks;
    }

    /**
     * Chunks for text OCR read out of images embedded in the entry.
     *
     * @return list<array{source: string, position: int, body: string}>
     */
    private function entryImageChunks(KnowledgeEntry $entry): array
    {
        $images = $entry->entryImages()
            ->where('extraction_status', EntryImage::EXTRACTION_READY)
            ->whereNotNull('extracted_text')
            ->orderBy('id')
            ->get();

        $chunks = [];

        foreach ($images as $image) {
            $body = $this->flatten((string) $image->extracted_text);

            if ($body === '') {
                continue;
            }

            $position = 0;
            $source = EntryChunk::sourceForImage($image->id);

            foreach ($this->split($body) as $piece) {
                $chunks[] = [
                    'source' => $source,
                    'position' => $position++,
                    'body' => $piece,
                ];
            }
        }

        return $chunks;
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
     * Split entry markup on the block boundaries the editor writes.
     *
     * A heading begins a new section, so a chunk rarely spans two topics, and
     * within a section blocks are packed to the target size. Blocks are still
     * sentence-split internally when one is too long on its own, so a wall of
     * text without paragraph breaks is handled like any other prose.
     *
     * @return list<string>
     */
    public function splitHtml(string $html): array
    {
        $blocks = $this->blocks($html);

        // No recognisable structure (plain text pasted in, an unknown wrapper):
        // fall back to sentence packing so nothing is dropped.
        if ($blocks === []) {
            return $this->split($this->flatten($html));
        }

        return $this->packBlocks($blocks);
    }

    /**
     * The block elements of the entry, in document order, as plain text.
     *
     * Nested blocks are skipped: a paragraph inside a blockquote is reported
     * once, as the blockquote, rather than twice.
     *
     * @return list<array{text: string, heading: bool}>
     */
    private function blocks(string $html): array
    {
        if (trim($html) === '') {
            return [];
        }

        $previous = libxml_use_internal_errors(true);

        $document = new \DOMDocument;
        $document->loadHTML(
            '<?xml encoding="UTF-8"><div id="chunk-root">'.$html.'</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $document->getElementById('chunk-root');

        if ($root === null) {
            return [];
        }

        $xpath = new \DOMXPath($document);
        $nodes = $xpath->query(
            '//*[self::h1 or self::h2 or self::h3 or self::h4 or self::h5 or self::h6'
            .' or self::p or self::li or self::pre or self::blockquote or self::td or self::th]',
            $root
        );

        if ($nodes === false) {
            return [];
        }

        $matched = new \SplObjectStorage;

        foreach ($nodes as $node) {
            $matched->attach($node);
        }

        $blocks = [];

        foreach ($nodes as $node) {
            // Skip a block nested in another block, so its text is not counted
            // in both a list and the list item.
            $ancestor = $node->parentNode;
            $nested = false;

            while ($ancestor !== null) {
                if ($matched->contains($ancestor)) {
                    $nested = true;

                    break;
                }

                $ancestor = $ancestor->parentNode;
            }

            if ($nested) {
                continue;
            }

            $text = $this->flatten(
                $document->saveHTML($node) ?: ''
            );

            if ($text === '') {
                continue;
            }

            $name = strtolower($node->nodeName);

            $blocks[] = [
                'text' => $text,
                'heading' => (bool) preg_match('/^h[1-6]$/', $name),
            ];
        }

        return $blocks;
    }

    /**
     * Pack blocks into chunks up to the target size, starting a fresh chunk at
     * each heading so a chunk rarely spans two sections.
     *
     * @param  list<array{text: string, heading: bool}>  $blocks
     * @return list<string>
     */
    private function packBlocks(array $blocks): array
    {
        $target = $this->targetChars();
        $chunks = [];
        $current = '';

        foreach ($blocks as $block) {
            // A heading that would not fit, or one that lands after content,
            // begins the next chunk rather than being buried mid-paragraph.
            if ($block['heading'] && $current !== '' && strlen($current) > $target / 2) {
                $chunks[] = $current;
                $current = '';
            }

            // A block on its own can exceed the target; cut it on sentences so
            // it is not one oversized chunk nor two halves of a thought.
            if (strlen($block['text']) > $target) {
                if ($current !== '') {
                    $chunks[] = $current;
                    $current = '';
                }

                foreach ($this->split($block['text']) as $piece) {
                    $chunks[] = $piece;
                }

                continue;
            }

            if ($current !== '' && strlen($current) + strlen($block['text']) + 1 > $target) {
                $chunks[] = $current;
                $current = $this->overlap($current);
            }

            $current .= $current === '' ? $block['text'] : ' '.$block['text'];
        }

        if ($current !== '') {
            $chunks[] = $current;
        }

        return array_values(array_filter($chunks, fn (string $chunk) => $chunk !== ''));
    }

    private function targetChars(): int
    {
        return (int) config('ai.chunk_target_chars', self::TARGET_CHARS);
    }

    private function overlapChars(): int
    {
        return (int) config('ai.chunk_overlap_chars', self::OVERLAP_CHARS);
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

        $target = $this->targetChars();

        // A string with no terminator at all still has to be cut somewhere.
        $sentences = $this->sentences($text);

        $chunks = [];
        $current = '';

        foreach ($sentences as $sentence) {
            // A single sentence longer than the target is emitted on its own
            // rather than being held back waiting for room that never comes.
            if (strlen($sentence) > $target) {
                if ($current !== '') {
                    $chunks[] = $current;
                    $current = '';
                }

                foreach (str_split($sentence, $target) as $piece) {
                    $chunks[] = $piece;
                }

                continue;
            }

            if ($current !== '' && strlen($current) + strlen($sentence) > $target) {
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
        $overlap = $this->overlapChars();

        if ($overlap <= 0 || strlen($chunk) <= $overlap) {
            return $chunk;
        }

        $tail = substr($chunk, -$overlap);

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
