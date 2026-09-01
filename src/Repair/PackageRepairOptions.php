<?php

declare(strict_types=1);

namespace DK\OpenXml\Repair;

final class PackageRepairOptions
{
    public function __construct(
        public readonly bool $removeDanglingRelationships = false,
        public readonly bool $removeInvalidRelationships = false,
        public readonly bool $removeOrphanRelationshipParts = false,
        public readonly bool $removeStaleContentTypeOverrides = false,
        public readonly bool $correctRelationshipContentTypes = false,
    ) {}
}
