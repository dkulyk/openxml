<?php

declare(strict_types=1);

namespace DK\OpenXml\Internal;

use DK\OpenXml\Exception\OpenXmlException;

/** @internal */
final class XmlDocument
{
    public const DEFAULT_MAXIMUM_BYTES = 32 * 1024 * 1024;

    private function __construct() {}

    public static function load(
        string $xml,
        string $expectedRootName,
        string $expectedNamespace,
        int $maximumBytes,
    ): \DOMDocument {
        if (strlen($xml) > $maximumBytes) {
            throw new OpenXmlException(sprintf(
                'XML document exceeds the configured limit of %d bytes.',
                $maximumBytes,
            ));
        }

        if (stripos($xml, '<!DOCTYPE') !== false) {
            throw new OpenXmlException('DTD declarations are not allowed in package XML.');
        }

        $document = new \DOMDocument();
        $document->preserveWhiteSpace = false;

        if (!@$document->loadXML($xml, LIBXML_NONET | LIBXML_COMPACT)) {
            throw new OpenXmlException('Invalid package XML.');
        }

        $root = $document->documentElement;
        if (
            $root === null
            || $root->localName !== $expectedRootName
            || $root->namespaceURI !== $expectedNamespace
        ) {
            throw new OpenXmlException(sprintf(
                'Expected {%s}%s as the document root.',
                $expectedNamespace,
                $expectedRootName,
            ));
        }

        return $document;
    }
}
