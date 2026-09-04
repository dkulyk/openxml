<?php

declare(strict_types=1);

namespace DK\OpenXml\Internal;

use DK\OpenXml\Exception\OpenXmlException;

/** @internal */
final class XmlDocument
{
    public const DEFAULT_MAXIMUM_BYTES = 32 * 1024 * 1024;

    private function __construct() {}

    /**
     * Escape a value for an XML attribute, matching what DOM produces: `&`, `<`, `>`
     * and `"` become entities and `'` is left alone.
     */
    public static function attributeValue(string $value, string $attributeName): string
    {
        $escaped = htmlspecialchars($value, ENT_XML1 | ENT_COMPAT, 'UTF-8');
        if ($escaped === '' && $value !== '') {
            throw new OpenXmlException(sprintf('The %s attribute is not valid UTF-8.', $attributeName));
        }

        return $escaped;
    }

    /** Wrap serialized children in a root element, matching DOM's formatted output. */
    public static function serialize(string $rootName, string $namespace, string $body): string
    {
        $header = sprintf(
            "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<%s xmlns=\"%s\"",
            $rootName,
            self::attributeValue($namespace, 'xmlns'),
        );

        return $body === ''
            ? $header . "/>\n"
            : $header . ">\n" . $body . '</' . $rootName . ">\n";
    }

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
