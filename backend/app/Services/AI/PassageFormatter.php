<?php

namespace App\Services\AI;

/**
 * Renders retrieved passages into the reference block sent to the model. The
 * numbered titles are what let a reader trace an answer back to its entry.
 */
class PassageFormatter
{
    /**
     * @param  array<int, mixed>|null  $sources
     */
    public function format(?array $sources): string
    {
        if ($sources === null || $sources === []) {
            return '';
        }

        $blocks = [];

        foreach ($sources as $index => $source) {
            $title = $source['title'] ?? null;

            $blocks[] = sprintf(
                '[%d] %s%s',
                $index + 1,
                is_string($title) && trim($title) !== '' ? $title : 'Untitled entry',
                isset($source['body']) ? "\n".$source['body'] : '',
            );
        }

        return implode("\n\n", $blocks);
    }
}
