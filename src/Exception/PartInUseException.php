<?php

declare(strict_types=1);

namespace DK\OpenXml\Exception;

use DK\OpenXml\Packaging\RelationshipReference;

final class PartInUseException extends OpenXmlException
{
    /** @param non-empty-list<RelationshipReference> $references */
    public function __construct(
        public readonly string $partName,
        private array $references,
    ) {
        parent::__construct(sprintf(
            'Package part "%s" is targeted by %d relationship(s); use removePartAndRelationships() for explicit cascading removal.',
            $partName,
            count($references),
        ));
    }

    /** @return non-empty-list<RelationshipReference> */
    public function getReferences(): array
    {
        return $this->references;
    }
}
