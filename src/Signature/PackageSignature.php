<?php

declare(strict_types=1);

namespace DK\OpenXml\Signature;

final class PackageSignature
{
    /** @param list<SignatureReference> $references */
    public function __construct(
        public readonly string $partName,
        public readonly ?string $id,
        public readonly string $canonicalizationMethod,
        public readonly string $signatureMethod,
        private array $references,
    ) {}

    /** @return list<SignatureReference> */
    public function getReferences(): array
    {
        return $this->references;
    }
}
