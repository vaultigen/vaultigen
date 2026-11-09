<?php

namespace App\Services\Imports;

use DOMDocument;
use DOMElement;
use RuntimeException;

/**
 * Hardened XML loading for imported files.
 *
 * Imported documents are untrusted. Network access, DTD/entity processing, and
 * deeply nested or oversized documents are refused before any data is read.
 */
final class SafeXml
{
    public const MAX_BYTES = 64 * 1024 * 1024;

    public const MAX_DEPTH = 64;

    public static function load(string $xml, int $maxBytes = self::MAX_BYTES, int $maxDepth = self::MAX_DEPTH): DOMDocument
    {
        if (strlen($xml) > $maxBytes) {
            throw new RuntimeException('The document is too large to process.');
        }
        if (stripos($xml, '<!DOCTYPE') !== false || stripos($xml, '<!ENTITY') !== false) {
            throw new RuntimeException('The document uses unsupported XML features.');
        }

        $document = new DOMDocument;
        $document->resolveExternals = false;
        $document->substituteEntities = false;

        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadXML($xml, LIBXML_NONET | LIBXML_COMPACT);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $loaded || ! $document->documentElement instanceof DOMElement) {
            throw new RuntimeException('The document is not valid XML.');
        }
        if (self::depth($document->documentElement) > $maxDepth) {
            throw new RuntimeException('The document is nested too deeply.');
        }

        return $document;
    }

    private static function depth(DOMElement $element, int $current = 1): int
    {
        $max = $current;
        foreach ($element->childNodes as $child) {
            if ($child instanceof DOMElement) {
                $max = max($max, self::depth($child, $current + 1));
            }
        }

        return $max;
    }
}
