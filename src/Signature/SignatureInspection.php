<?php

declare(strict_types=1);

namespace DK\OpenXml\Signature;

/** @implements \IteratorAggregate<int, PackageSignature> */
final class SignatureInspection implements \IteratorAggregate, \Countable
{
    /**
     * @param list<PackageSignature> $signatures
     * @param list<string>           $issues
     */
    public function __construct(
        public readonly SignatureStatus $status,
        public readonly ?string $originPartName,
        private array $signatures,
        private array $issues,
    ) {}

    /** @return list<PackageSignature> */
    public function getSignatures(): array
    {
        return $this->signatures;
    }

    /** @return list<string> */
    public function getIssues(): array
    {
        return $this->issues;
    }

    public function count(): int
    {
        return count($this->signatures);
    }

    public function getIterator(): \Traversable
    {
        yield from $this->signatures;
    }
}
