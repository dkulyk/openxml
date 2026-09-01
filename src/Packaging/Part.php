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
        private string $contents,
    ) {
        $this->name = PartName::normalize($name);
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getContentType(): string
    {
        return $this->contentType;
    }

    public function getContents(): string
    {
        return $this->contents;
    }

    public function setContents(string $contents): void
    {
        $this->contents = $contents;
        $this->package->writePart($this->name, $contents);
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
