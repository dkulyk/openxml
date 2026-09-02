<?php

declare(strict_types=1);

namespace DK\OpenXml\Encryption;

final class EncryptionLimits
{
    /** Limits attacker-controlled work and output when decrypting untrusted files. */
    public function __construct(
        public readonly int $maximumSpinCount = 1_000_000,
        public readonly int $maximumDecryptedBytes = 1_073_741_824,
        public readonly int $maximumEncryptionInfoBytes = 1_048_576,
    ) {
        if ($maximumSpinCount < 1 || $maximumDecryptedBytes < 1 || $maximumEncryptionInfoBytes < 1) {
            throw new \InvalidArgumentException('Encryption limits must be positive.');
        }
    }
}
