<?php

namespace App\Services\Extraction;

use ZipArchive;

/**
 * A zip archive being read under limits.
 *
 * Office files are zip archives, and a hostile one can be a zip bomb: a tiny
 * download that expands to gigabytes, or a single entry that does while
 * another is being read. Every limit is checked before the entry is inflated,
 * so the failure happens while the archive is still cheap to abandon.
 */
final class Archive
{
    private ?ZipArchive $zip = null;

    /**
     * @param  array<string, int>  $limits
     */
    public function __construct(
        private readonly string $path,
        private readonly array $limits = [],
    ) {}

    public function open(): void
    {
        if (! class_exists(ZipArchive::class)) {
            throw new ExtractionException('The zip extension is required to read office documents.');
        }

        if (! is_file($this->path) || ! is_readable($this->path)) {
            throw new ExtractionException('The uploaded file is not a readable archive.');
        }

        $size = (int) filesize($this->path);

        if ($this->limit('max_file_bytes') > 0 && $size > $this->limit('max_file_bytes')) {
            throw new ExtractionException('The file is larger than the extraction limit.');
        }

        $zip = new ZipArchive;

        if ($zip->open($this->path) !== true) {
            throw new ExtractionException('The uploaded file is not a readable archive.');
        }

        $this->zip = $zip;

        $this->guardAgainstExpansion($zip);
    }

    /**
     * Refuse an archive whose uncompressed payload is out of proportion to what
     * is allowed, before any of it has been read out.
     */
    private function guardAgainstExpansion(ZipArchive $zip): void
    {
        $count = $zip->numFiles;

        if ($this->limit('max_entries') > 0 && $count > $this->limit('max_entries')) {
            throw new ExtractionException('The archive has more entries than the extraction limit.');
        }

        $total = 0;

        for ($i = 0; $i < $count; $i++) {
            $stat = $zip->statIndex($i);

            if ($stat === false) {
                continue;
            }

            $size = (int) ($stat['size'] ?? 0);

            if ($this->limit('max_part_bytes') > 0 && $size > $this->limit('max_part_bytes')) {
                throw new ExtractionException('An entry in the archive is larger than the extraction limit.');
            }

            $total += $size;

            if ($this->limit('max_uncompressed_bytes') > 0 && $total > $this->limit('max_uncompressed_bytes')) {
                throw new ExtractionException('The archive expands beyond the extraction limit.');
            }
        }
    }

    public function has(string $name): bool
    {
        return $this->zip !== null && $this->zip->locateName($name) !== false;
    }

    /**
     * Read an entry. A missing entry returns null; an entry that is present but
     * cannot be read raises, so a corrupt part is never mistaken for an absent
     * one.
     */
    public function get(string $name): ?string
    {
        if ($this->zip === null) {
            throw new ExtractionException('The archive is not open.');
        }

        if ($this->zip->locateName($name) === false) {
            return null;
        }

        $contents = $this->zip->getFromName($name);

        if ($contents === false) {
            throw new ExtractionException("The archive entry {$name} could not be read.");
        }

        return $contents;
    }

    /**
     * @return list<string>
     */
    public function names(string $pattern = ''): array
    {
        if ($this->zip === null) {
            throw new ExtractionException('The archive is not open.');
        }

        $names = [];

        for ($i = 0; $i < $this->zip->numFiles; $i++) {
            $name = $this->zip->getNameIndex($i);

            if (! is_string($name)) {
                continue;
            }

            if ($pattern !== '' && preg_match($pattern, $name) !== 1) {
                continue;
            }

            $names[] = $name;
        }

        return $names;
    }

    public function close(): void
    {
        if ($this->zip !== null) {
            $this->zip->close();
            $this->zip = null;
        }
    }

    private function limit(string $key): int
    {
        return (int) ($this->limits[$key] ?? 0);
    }
}
