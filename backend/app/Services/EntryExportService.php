<?php

namespace App\Services;

use App\Models\KnowledgeEntry;

/**
 * Renders an entry as a portable document.
 *
 * Export exists so notes are not trapped in the app: the same entry read here
 * should still be readable in a text editor, a static site or another wiki.
 * Markdown is the default for that reason; HTML keeps the formatting verbatim
 * for readers that prefer it; JSON is the lossless form for re-import.
 */
class EntryExportService
{
    public function toMarkdown(KnowledgeEntry $entry): string
    {
        $lines = ['# '.$entry->title, ''];

        $tags = ($entry->tags ?? [])->pluck('name')->filter()->values();

        if ($tags->isNotEmpty()) {
            $lines[] = 'Tags: '.$tags->map(fn (string $tag) => '#'.$tag)->implode(' ');
            $lines[] = '';
        }

        $lines[] = 'Exported from Mindbase on '.now()->toDateString().'.';
        $lines[] = '';
        $lines[] = $this->htmlToMarkdown((string) $entry->content);

        return rtrim(implode("\n", $lines))."\n";
    }

    public function toHtml(KnowledgeEntry $entry): string
    {
        $tags = ($entry->tags ?? [])->pluck('name')->filter()->values();

        $tagChips = $tags
            ->map(fn (string $tag) => '<span class="tag">'.e($tag).'</span>')
            ->implode('');

        return '<!doctype html>'."\n"
            .'<html lang="en"><head><meta charset="utf-8">'
            .'<meta name="viewport" content="width=device-width, initial-scale=1">'
            .'<title>'.e($entry->title).'</title>'
            .'<style>'
            .'body{font-family:system-ui,-apple-system,"Segoe UI",sans-serif;'
            .'line-height:1.7;max-width:46rem;margin:3rem auto;padding:0 1.25rem;color:#20202a}'
            .'h1{letter-spacing:-.03em}.tag{background:#eeedff;color:#4338ca;'
            .'border-radius:999px;padding:.15rem .6rem;font-size:.8rem;margin-right:.35rem}'
            .'</style></head><body>'
            .'<h1>'.e($entry->title).'</h1>'
            .($tagChips !== '' ? '<p>'.$tagChips.'</p>' : '')
            .'<hr>'
            .(string) $entry->content
            .'</body></html>';
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(KnowledgeEntry $entry): array
    {
        return [
            'title' => $entry->title,
            'content_html' => $entry->content,
            'content_markdown' => $this->htmlToMarkdown((string) $entry->content),
            'tags' => ($entry->tags ?? [])->pluck('name')->values()->all(),
            'created_at' => $entry->created_at?->toIso8601String(),
            'updated_at' => $entry->updated_at?->toIso8601String(),
        ];
    }

    /**
     * A pragmatic HTML to Markdown conversion for the block types the editor
     * can produce. Anything unrecognised keeps its text, so no content is lost
     * to a missing rule.
     */
    public function htmlToMarkdown(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }

        $previous = libxml_use_internal_errors(true);

        $document = new \DOMDocument;
        $document->loadHTML(
            '<?xml encoding="UTF-8"><div id="export-root">'.$html.'</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $document->getElementById('export-root');

        if ($root === null) {
            return trim($html);
        }

        $markdown = $this->renderChildren($root);

        // Collapse the runs of blank lines the block rules leave behind.
        $markdown = preg_replace("/\n{3,}/", "\n\n", $markdown) ?? $markdown;

        return trim($markdown);
    }

    private function renderChildren(\DOMNode $node): string
    {
        $output = '';

        foreach ($node->childNodes as $child) {
            $output .= $this->renderNode($child);
        }

        return $output;
    }

    private function renderNode(\DOMNode $node): string
    {
        if ($node instanceof \DOMText) {
            return $node->wholeText;
        }

        if (! $node instanceof \DOMElement) {
            return '';
        }

        $name = strtolower($node->nodeName);

        return match ($name) {
            'h1', 'h2', 'h3', 'h4', 'h5', 'h6' => "\n\n"
                .str_repeat('#', (int) substr($name, 1)).' '
                .trim($this->renderChildren($node))."\n\n",
            'p' => "\n\n".trim($this->renderChildren($node))."\n\n",
            'br' => "  \n",
            'hr' => "\n\n---\n\n",
            'strong', 'b' => '**'.trim($this->renderChildren($node)).'**',
            'em', 'i' => '*'.trim($this->renderChildren($node)).'*',
            'code' => '`'.$this->renderChildren($node).'`',
            'pre' => "\n\n```\n".trim($node->textContent)."\n```\n\n",
            'blockquote' => "\n\n".$this->indent($this->renderChildren($node))."\n\n",
            'a' => $this->renderLink($node),
            'ul' => "\n\n".$this->renderList($node, false)."\n\n",
            'ol' => "\n\n".$this->renderList($node, true)."\n\n",
            'li' => $this->renderChildren($node),
            default => $this->renderChildren($node),
        };
    }

    private function renderLink(\DOMElement $node): string
    {
        $text = trim($this->renderChildren($node));
        $href = $node->getAttribute('href');

        if ($href === '') {
            return $text;
        }

        return '['.$text.']('.$href.')';
    }

    private function renderList(\DOMElement $list, bool $ordered): string
    {
        $lines = [];
        $index = 1;

        foreach ($list->childNodes as $child) {
            if (! $child instanceof \DOMElement || strtolower($child->nodeName) !== 'li') {
                continue;
            }

            $prefix = $ordered ? $index++.'. ' : '- ';
            $lines[] = $prefix.trim($this->renderChildren($child));
        }

        return implode("\n", $lines);
    }

    private function indent(string $text): string
    {
        $lines = array_filter(explode("\n", trim($text)), fn (string $line) => trim($line) !== '');

        return implode("\n", array_map(fn (string $line) => '> '.trim($line), $lines));
    }
}
