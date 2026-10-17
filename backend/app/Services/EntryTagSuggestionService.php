<?php

namespace App\Services;

use App\Models\KnowledgeEntry;
use App\Models\Tag;
use Illuminate\Support\Str;

/**
 * Suggests tags for an entry from the workspace's own vocabulary.
 *
 * Preferred over a model for two reasons: it is instant on a workspace whose AI
 * models take minutes to load, and it only ever proposes tags that already exist
 * here, which keeps the vocabulary from drifting into near-duplicates like
 * "api" and "apis" that a generative model would happily invent.
 *
 * When the vocabulary is too thin to help, it falls back to the entry's own
 * salient words, which still saves typing and still round-trips through
 * SyncEntryTags like any other tag.
 */
class EntryTagSuggestionService
{
    private const LIMIT = 6;

    /**
     * Words too common to distinguish an entry, kept deliberately short. This
     * is a ranking accessory, not a stop-word list for correctness.
     *
     * @var list<string>
     */
    private const STOP_WORDS = [
        'the', 'and', 'for', 'are', 'but', 'not', 'you', 'all', 'any', 'can',
        'her', 'was', 'one', 'our', 'out', 'day', 'get', 'has', 'him', 'his',
        'how', 'its', 'new', 'now', 'old', 'see', 'two', 'way', 'who', 'boy',
        'did', 'let', 'put', 'say', 'she', 'too', 'use', 'that', 'this', 'with',
        'from', 'have', 'will', 'your', 'what', 'when', 'them', 'then', 'than',
        'into', 'only', 'also', 'some', 'such', 'been', 'were', 'they', 'their',
        'there', 'here', 'about', 'would', 'could', 'should', 'these', 'those',
        'each', 'which', 'while', 'after', 'before', 'because', 'other', 'more',
        'most', 'very', 'just', 'like', 'using', 'used', 'make', 'made',
    ];

    /**
     * @return list<string>
     */
    public function suggest(KnowledgeEntry $entry): array
    {
        $text = Str::lower(
            $entry->title.' '.strip_tags((string) $entry->content)
        );

        $onEntry = ($entry->tags ?? [])->pluck('name')->map(fn ($name) => Str::lower($name))->all();

        $scores = [];

        // Existing tags first: a tag used elsewhere in this workspace is the
        // strongest signal that it belongs on this entry too.
        $existing = Tag::where('workspace_id', $entry->workspace_id)->get();

        foreach ($existing as $tag) {
            if (in_array(Str::lower($tag->name), $onEntry, true)) {
                continue;
            }

            $score = $this->phraseOccurrences($text, Str::lower($tag->name));

            if ($score > 0) {
                // Weighted above derived words so the workspace vocabulary wins
                // ties against an invented tag.
                $scores[$tag->name] = $score * 2;
            }
        }

        foreach ($this->salientWords($text) as $word => $count) {
            if (isset($scores[$word])) {
                $scores[$word] += $count;

                continue;
            }

            if (in_array($word, $onEntry, true)) {
                continue;
            }

            $scores[$word] = $count;
        }

        arsort($scores);

        return array_slice(array_keys($scores), 0, self::LIMIT);
    }

    /**
     * Count phrase hits, respecting word boundaries so "api" does not match
     * "capital".
     */
    private function phraseOccurrences(string $text, string $phrase): int
    {
        $pattern = '/(?<![\pL\pN])'.preg_quote($phrase, '/').'(?![\pL\pN])/u';

        return (int) preg_match_all($pattern, $text);
    }

    /**
     * @return array<string, int>
     */
    private function salientWords(string $text): array
    {
        preg_match_all('/[a-z][a-z0-9\-]{2,}/u', $text, $matches);

        $counts = [];

        foreach ($matches[0] as $word) {
            if (strlen($word) < 4 || in_array($word, self::STOP_WORDS, true)) {
                continue;
            }

            $counts[$word] = ($counts[$word] ?? 0) + 1;
        }

        // A one-off word is noise; two mentions is the least a real topic needs.
        return array_filter($counts, fn (int $count) => $count >= 2);
    }
}
