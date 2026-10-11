<?php

namespace App\Services\Extraction;

use DOMDocument;
use DOMNode;
use DOMXPath;

/**
 * Secure XML loading for the office formats.
 *
 * Parsing is deliberately locked down: `LIBXML_NONET` stops libxml fetching
 * anything over the network, and the flags that would substitute entities
 * (`LIBXML_NOENT`) or load a DTD (`LIBXML_DTDLOAD`) are never set. Office XML
 * is machine-generated and does not need either, so refusing them removes the
 * external-entity and entity-expansion attacks without costing anything.
 */
final class Xml
{
    /**
     * Namespaces used across WordprocessingML, DrawingML, PresentationML,
     * SpreadsheetML and the package relationship files.
     */
    private const NAMESPACES = [
        'w' => 'http://schemas.openxmlformats.org/wordprocessingml/2006/main',
        'a' => 'http://schemas.openxmlformats.org/drawingml/2006/main',
        'p' => 'http://schemas.openxmlformats.org/presentationml/2006/main',
        'r' => 'http://schemas.openxmlformats.org/officeDocument/2006/relationships',
        'rel' => 'http://schemas.openxmlformats.org/package/2006/relationships',
        'c' => 'http://schemas.openxmlformats.org/drawingml/2006/chart',
        'ss' => 'http://schemas.openxmlformats.org/spreadsheetml/2006/main',
    ];

    public static function load(string $xml, string $context = 'document'): DOMDocument
    {
        $document = new DOMDocument;

        $previousErrors = libxml_use_internal_errors(true);
        $previousLoader = libxml_set_external_entity_loader(static fn () => null);

        try {
            $loaded = $document->loadXML($xml, LIBXML_NONET | LIBXML_COMPACT);
        } finally {
            // The default resolver is handed back as an internal marker, not a
            // callable, so only restore it when it really is one.
            libxml_set_external_entity_loader(is_callable($previousLoader) ? $previousLoader : null);
            libxml_clear_errors();
            libxml_use_internal_errors($previousErrors);
        }

        if ($loaded === false) {
            throw new ExtractionException("The {$context} XML could not be parsed.");
        }

        return $document;
    }

    public static function xpath(DOMDocument $document): DOMXPath
    {
        $xpath = new DOMXPath($document);

        foreach (self::NAMESPACES as $prefix => $uri) {
            $xpath->registerNamespace($prefix, $uri);
        }

        return $xpath;
    }

    public static function text(DOMNode $node): string
    {
        return $node->textContent;
    }
}
