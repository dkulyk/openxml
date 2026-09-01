<?php

declare(strict_types=1);

namespace DK\OpenXml\Security;

use DK\OpenXml\Exception\OpenXmlException;

final class PackageLimits
{
    public function __construct(
        public readonly int $maximumEntries = 10_000,
        public readonly int $maximumPartBytes = 256 * 1024 * 1024,
        public readonly int $maximumPackageBytes = 1024 * 1024 * 1024,
        public readonly float $maximumCompressionRatio = 1_000.0,
        public readonly int $maximumXmlBytes = 32 * 1024 * 1024,
    ) {
        if (
            $maximumEntries < 1
            || $maximumPartBytes < 1
            || $maximumPackageBytes < 1
            || $maximumCompressionRatio < 1.0
            || $maximumXmlBytes < 1
        ) {
            throw new OpenXmlException('All package security limits must be positive.');
        }
    }
}
