<?php

declare(strict_types=1);

namespace DK\OpenXml\Encryption;

final class EncryptionLimits
{
    public function __construct(
        public readonly int $maximumSpinCount = 1_000_000,
        public readonly int $maximumDecryptedBytes = 1_073_741_824,
    ) {
        if ($maximumSpinCount < 1 || $maximumDecryptedBytes < 1) {
            throw new \InvalidArgumentException('Encryption limits must be positive.');
        }
    }
}
