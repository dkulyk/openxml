<?php

declare(strict_types=1);

namespace DK\OpenXml\Packaging;

use DK\OpenXml\Repair\PackageRepairOptions;
use DK\OpenXml\Repair\RepairReport;
use DK\OpenXml\Signature\SignatureInspection;
use DK\OpenXml\Signature\SignatureRemovalResult;

interface PackageInterface
{
    public function hasPart(string $name): bool;

    public function getPart(string $name): PartInterface;

    /** @return \Traversable<PartInterface> */
    public function getParts(): \Traversable;

    public function addPart(string $name, string $contentType, string $contents): PartInterface;

    /** @param resource $stream */
    public function addPartFromStream(string $name, string $contentType, $stream): PartInterface;

    public function addPartFromPath(string $name, string $contentType, string $path): PartInterface;

    public function removePart(string $name): void;

    /** @return list<RelationshipReference> */
    public function getInboundRelationships(string $partName): array;

    public function removePartAndRelationships(string $name): PartRemovalResult;

    public function movePart(string $source, string $destination): PartInterface;

    public function getRelationships(?string $sourcePartName = null): Relationships;

    public function addRelationship(string $type, string $target, bool $external = false, ?string $id = null, ?string $sourcePartName = null): RelationshipInterface;

    public function removeRelationship(string $id, ?string $sourcePartName = null): void;

    public function inspectSignatures(): SignatureInspection;

    public function removeSignatures(): SignatureRemovalResult;

    /** @return list<string> */
    public function validate(): array;

    public function analyzeRepairs(PackageRepairOptions $options): RepairReport;

    public function applyRepairs(PackageRepairOptions $options): RepairReport;

    public function hasChanges(): bool;

    public function discardChanges(): void;

    public function save(): void;

    public function saveAs(string $filename): void;
}
