<?php

declare(strict_types=1);

namespace DK\OpenXml\Packaging;

final class PartRemovalResult
{
    /** @param list<RelationshipReference> $removedRelationships */
    public function __construct(
        public readonly string $partName,
        private array $removedRelationships,
    ) {}

    /** @return list<RelationshipReference> */
    public function getRemovedRelationships(): array
    {
        return $this->removedRelationships;
    }
}
