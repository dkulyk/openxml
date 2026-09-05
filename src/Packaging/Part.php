<?php

declare(strict_types=1);

namespace DK\OpenXml\Packaging;

use DK\OpenXml\OpenXmlPackage;

final class Part implements PartInterface
{
    public function __construct(
        private OpenXmlPackage $package,
        private string $name,
        private string $contentType,
    ) {
        $this->name = PartName::normalize($name);
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getContentType(): string
    {
        // Resolved through the package rather than frozen at construction: a default
        // or override registered afterwards changes what this part's type is. The
        // constructed value stands in once the package no longer covers the name.
        return $this->package->getPartContentType($this->name) ?? $this->contentType;
    }

    public function getContents(): string
    {
        return $this->package->readPart($this->name);
    }

    public function setContents(string $contents, ?bool $compress = null): void
    {
        $this->package->writePart($this->name, $contents, $compress);
    }

    public function openStream()
    {
        return $this->package->openPartStream($this->name);
    }

    public function getReadablePath(): string
    {
        return $this->package->getPartReadablePath($this->name);
    }

    public function getLocalPath(): string
    {
        return $this->package->getPartLocalPath($this->name);
    }

    public function setContentsFromStream($stream, ?bool $compress = null): void
    {
        $this->package->writePartFromStream($this->name, $stream, $compress);
    }

    public function setContentsFromPath(string $path, ?bool $compress = null): void
    {
        $this->package->writePartFromPath($this->name, $path, $compress);
    }

    public function getRelationships(): Relationships
    {
        return $this->package->getRelationships($this->name);
    }

    public function addRelationship(
        string $type,
        string $target,
        bool $external = false,
        ?string $id = null,
    ): RelationshipInterface {
        return $this->package->addRelationship(
            $type,
            $target,
            $external,
            $id,
            $this->name,
        );
    }

    public function removeRelationship(string $id): void
    {
        $this->package->removeRelationship($id, $this->name);
    }
}
