<?php

declare(strict_types=1);

namespace DK\OpenXml\Signature;

final class SignatureRemovalResult
{
    /** @param list<string> $removedPartNames */
    public function __construct(
        private array $removedPartNames,
        public readonly int $removedRelationships,
    ) {}

    /** @return list<string> */
    public function getRemovedPartNames(): array
    {
        return $this->removedPartNames;
    }

    public function removedAnything(): bool
    {
        return $this->removedPartNames !== [] || $this->removedRelationships !== 0;
    }
}
