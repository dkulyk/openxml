<?php

declare(strict_types=1);

namespace DK\OpenXml\Packaging;

interface PackageInterface
{
    public function hasPart(string $name): bool;

    public function getPart(string $name): PartInterface;

    /** @return \Traversable<PartInterface> */
    public function getParts(): \Traversable;

    public function addPart(string $name, string $contentType, string $contents): PartInterface;

    /** @param resource $stream */
    public function addPartFromStream(string $name, string $contentType, $stream): PartInterface;

    public function removePart(string $name): void;

    public function getRelationships(?string $sourcePartName = null): Relationships;

    public function addRelationship(string $type, string $target, bool $external = false, ?string $id = null, ?string $sourcePartName = null): RelationshipInterface;

    public function removeRelationship(string $id, ?string $sourcePartName = null): void;

    /** @return list<string> */
    public function validate(): array;

    public function hasChanges(): bool;

    public function discardChanges(): void;

    public function save(): void;

    public function saveAs(string $filename): void;
}
