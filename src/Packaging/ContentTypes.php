<?php

declare(strict_types=1);

namespace DK\OpenXml\Packaging;

use DK\OpenXml\Exception\OpenXmlException;
use DK\OpenXml\Internal\XmlDocument;

final class ContentTypes
{
    private const XML_NAMESPACE = 'http://schemas.openxmlformats.org/package/2006/content-types';

    /** @var array<string, string> */
    private array $defaults = [];

    /** @var array<string, string> */
    private array $overrides = [];

    public static function fromXml(
        string $xml,
        int $maximumXmlBytes = XmlDocument::DEFAULT_MAXIMUM_BYTES,
    ): self {
        $document = XmlDocument::load(
            $xml,
            'Types',
            self::XML_NAMESPACE,
            $maximumXmlBytes,
        );

        $types = new self();
        $seenDefaults = [];
        foreach ($document->getElementsByTagNameNS(self::XML_NAMESPACE, 'Default') as $node) {
            $extension = strtolower($node->getAttribute('Extension'));
            if (isset($seenDefaults[$extension])) {
                throw new OpenXmlException(sprintf('Duplicate content type default for "%s".', $extension));
            }
            $seenDefaults[$extension] = true;
            $types->setDefault(
                $extension,
                $node->getAttribute('ContentType'),
            );
        }

        $seenOverrides = [];
        foreach ($document->getElementsByTagNameNS(self::XML_NAMESPACE, 'Override') as $node) {
            $partName = PartName::normalize($node->getAttribute('PartName'));
            if (isset($seenOverrides[$partName])) {
                throw new OpenXmlException(sprintf('Duplicate content type override for "%s".', $partName));
            }
            $seenOverrides[$partName] = true;
            $types->setOverride(
                $partName,
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

    /** @return array<string, string> */
    public function getOverrides(): array
    {
        return $this->overrides;
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
