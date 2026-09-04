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

    /** @var array<string, string> Lowercase part name => stored part name. */
    private array $overrideNames = [];

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
            $comparisonKey = strtolower($partName);
            if (isset($seenOverrides[$comparisonKey])) {
                throw new OpenXmlException(sprintf('Duplicate content type override for "%s".', $partName));
            }
            $seenOverrides[$comparisonKey] = true;
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
        $storedName = $this->overrideNames[strtolower($name)] ?? null;
        if ($storedName !== null) {
            return $this->overrides[$storedName];
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

        $name = PartName::normalize($name);
        $this->removeOverride($name);
        $this->overrides[$name] = $contentType;
        $this->overrideNames[strtolower($name)] = $name;
    }

    public function removeOverride(string $name): void
    {
        $name = PartName::normalize($name);
        $comparisonKey = strtolower($name);
        $storedName = $this->overrideNames[$comparisonKey] ?? null;
        if ($storedName !== null) {
            unset($this->overrides[$storedName], $this->overrideNames[$comparisonKey]);
        }
    }

    /** @return array<string, string> */
    public function getOverrides(): array
    {
        return $this->overrides;
    }

    public function toXml(): string
    {
        // Sorted copies: serializing a package must not reorder its own state.
        $defaults = $this->defaults;
        $overrides = $this->overrides;
        ksort($defaults);
        ksort($overrides);

        $body = '';
        foreach ($defaults as $extension => $type) {
            $body .= sprintf(
                "  <Default Extension=\"%s\" ContentType=\"%s\"/>\n",
                XmlDocument::attributeValue($extension, 'Extension'),
                XmlDocument::attributeValue($type, 'ContentType'),
            );
        }
        foreach ($overrides as $name => $type) {
            $body .= sprintf(
                "  <Override PartName=\"%s\" ContentType=\"%s\"/>\n",
                XmlDocument::attributeValue($name, 'PartName'),
                XmlDocument::attributeValue($type, 'ContentType'),
            );
        }

        return XmlDocument::serialize('Types', self::XML_NAMESPACE, $body);
    }
}
