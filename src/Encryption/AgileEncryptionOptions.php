<?php

declare(strict_types=1);

namespace DK\OpenXml\Encryption;

final class AgileEncryptionOptions
{
    public function __construct(public readonly int $spinCount = 100_000)
    {
        if ($spinCount < 1 || $spinCount > 10_000_000) {
            throw new \InvalidArgumentException('Spin count must be between 1 and 10,000,000.');
        }
    }
}
