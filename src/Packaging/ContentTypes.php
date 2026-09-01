<?php

declare(strict_types=1);

namespace DK\OpenXml\Packaging;

use DK\OpenXml\Exception\OpenXmlException;

final class ContentTypes
{
    private const XML_NAMESPACE = 'http://schemas.openxmlformats.org/package/2006/content-types';

    /** @var array<string, string> */
    private array $defaults = [];

    /** @var array<string, string> */
    private array $overrides = [];

    public static function fromXml(string $xml): self
    {
        $document = new \DOMDocument();
        if (!@$document->loadXML($xml, LIBXML_NONET)) {
            throw new OpenXmlException('Invalid [Content_Types].xml.');
        }

        $types = new self();
        foreach ($document->getElementsByTagNameNS(self::XML_NAMESPACE, 'Default') as $node) {
            $types->setDefault(
                $node->getAttribute('Extension'),
                $node->getAttribute('ContentType'),
            );
        }
        foreach ($document->getElementsByTagNameNS(self::XML_NAMESPACE, 'Override') as $node) {
            $types->setOverride(
                $node->getAttribute('PartName'),
                $node->getAttribute('ContentType'),
            );
        }

        return $types;
    }

    public function getForPart(string $name): ?string
    {
        $name = PartName::normalize($name);
        if (isset($this->overrides[$name])) {
            return $this->overrides[$name];
        }

        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));

        return $this->defaults[$extension] ?? null;
    }

    public function setDefault(string $extension, string $contentType): void
    {
        $extension = strtolower(ltrim($extension, '.'));
        if ($extension === '' || $contentType === '') {
            throw new OpenXmlException('Content type extension and value must not be empty.');
        }

        $this->defaults[$extension] = $contentType;
    }

    public function setOverride(string $name, string $contentType): void
    {
        if ($contentType === '') {
            throw new OpenXmlException('Content type must not be empty.');
        }

        $this->overrides[PartName::normalize($name)] = $contentType;
    }

    public function removeOverride(string $name): void
    {
        unset($this->overrides[PartName::normalize($name)]);
    }

    public function toXml(): string
    {
        $document = new \DOMDocument('1.0', 'UTF-8');
        $document->formatOutput = true;
        $root = $document->createElementNS(self::XML_NAMESPACE, 'Types');
        $document->appendChild($root);

        ksort($this->defaults);
        ksort($this->overrides);

        foreach ($this->defaults as $extension => $type) {
            $node = $document->createElementNS(self::XML_NAMESPACE, 'Default');
            $node->setAttribute('Extension', $extension);
            $node->setAttribute('ContentType', $type);
            $root->appendChild($node);
        }
        foreach ($this->overrides as $name => $type) {
            $node = $document->createElementNS(self::XML_NAMESPACE, 'Override');
            $node->setAttribute('PartName', $name);
            $node->setAttribute('ContentType', $type);
            $root->appendChild($node);
        }

        return (string) $document->saveXML();
    }
}
