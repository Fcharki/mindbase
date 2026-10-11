<?php

namespace App\Services\Extraction;

use DOMElement;

/**
 * The `.rels` relationship parts that wire an OOXML package together.
 *
 * A slide, worksheet or chart is referenced by a relationship id, and the
 * relationship names the part and its type. Reading these is what lets the
 * presentation order and chart links be followed instead of guessed at from
 * filenames.
 */
final class Relationships
{
    /**
     * @return array<string, array{target: string, type: string}>
     */
    public static function load(Archive $archive, string $path): array
    {
        if (! $archive->has($path)) {
            return [];
        }

        $xpath = Xml::xpath(Xml::load($archive->get($path), 'relationships'));

        $map = [];

        foreach ($xpath->query('//rel:Relationship') as $relationship) {
            if (! $relationship instanceof DOMElement) {
                continue;
            }

            $id = $relationship->getAttribute('Id');

            if ($id === '') {
                continue;
            }

            $map[$id] = [
                'target' => $relationship->getAttribute('Target'),
                'type' => $relationship->getAttribute('Type'),
            ];
        }

        return $map;
    }

    /**
     * Resolve a relationship target against the directory holding the part that
     * referenced it, collapsing `.` and `..` segments.
     */
    public static function resolve(string $baseDir, string $target): string
    {
        if (str_starts_with($target, '/')) {
            return ltrim($target, '/');
        }

        $segments = [];

        foreach (explode('/', trim($baseDir, '/').'/'.$target) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                array_pop($segments);

                continue;
            }

            $segments[] = $segment;
        }

        return implode('/', $segments);
    }
}
