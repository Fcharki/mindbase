<?php

namespace App\Services;

use App\Models\KnowledgeEntry;
use Dompdf\Dompdf;

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
     * Render the entry as a PDF.
     *
     * The screen HTML optimises for a browser (max-width, system fonts); a
     * print sheet wants page-sized rules and a font Dompdf can embed, so the
     * document is built separately rather than reusing toHtml().
     */
    public function toPdf(KnowledgeEntry $entry): string
    {
        $dompdf = new Dompdf([
            'isRemoteEnabled' => false,
            'isHtml5ParserEnabled' => true,
        ]);

        $dompdf->setPaper('A4', 'portrait');
        $dompdf->loadHtml($this->pdfDocument($entry), 'UTF-8');
        $dompdf->render();

        return (string) $dompdf->output();
    }

    private function pdfDocument(KnowledgeEntry $entry): string
    {
        $tags = ($entry->tags ?? [])->pluck('name')->filter()->values();

        $tagChips = $tags
            ->map(fn (string $tag) => '<span class="tag">'.e($tag).'</span>')
            ->implode('');

        return '<!doctype html>'."\n"
            .'<html lang="en"><head><meta charset="utf-8">'
            .'<title>'.e($entry->title).'</title>'
            .'<style>'
            .'@page{margin:2.5cm 2cm}'
            .'body{font-family:"DejaVu Sans",sans-serif;line-height:1.65;color:#20202a;font-size:11pt}'
            .'h1{font-size:20pt;margin:0 0 .3rem;letter-spacing:-.02em}'
            .'.meta{color:#686873;font-size:9pt;margin:0 0 .8rem}'
            .'.tag{background:#eeedff;color:#4338ca;border-radius:999px;'
            .'padding:1pt 6pt;font-size:8pt;margin-right:4pt}'
            .'hr{border:none;border-top:1px solid #dddce3;margin:1rem 0}'
            .'blockquote{border-left:3px solid #dddce3;margin:1rem 0;padding:.1rem 0 .1rem 1rem;color:#4b4b57}'
            .'pre{background:#f4f3f8;padding:.7rem;border-radius:6px;font-size:9pt;'
            .'white-space:pre-wrap;word-wrap:break-word}'
            .'code{font-family:"DejaVu Sans Mono",monospace;font-size:9.5pt}'
            .'a{color:#4338ca;text-decoration:none}'
            .'</style></head><body>'
            .'<h1>'.e($entry->title).'</h1>'
            .'<p class="meta">Exported from Mindbase on '.e(now()->toDateString()).'</p>'
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
