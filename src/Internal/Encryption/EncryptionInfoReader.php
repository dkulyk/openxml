<?php

declare(strict_types=1);

namespace DK\OpenXml\Internal\Encryption;

use DK\OpenXml\Exception\InvalidEncryptedPackageException;
use DK\OpenXml\Exception\UnsupportedEncryptionException;

/** @internal */
final class EncryptionInfoReader
{
    public static function read(string $contents, int $maximumSpinCount): AgileEncryptionInfo|StandardEncryptionInfo
    {
        if (strlen($contents) < 4) {
            throw new InvalidEncryptedPackageException('The EncryptionInfo stream is too short.');
        }

        $version = unpack('vmajor/vminor', substr($contents, 0, 4));
        if ($version === false || !is_int($version['major']) || !is_int($version['minor'])) {
            throw new InvalidEncryptedPackageException('Unable to decode the EncryptionInfo version.');
        }
        $major = $version['major'];
        $minor = $version['minor'];
        if ($major === 4 && $minor === 4) {
            return AgileEncryptionInfo::fromStream($contents, $maximumSpinCount);
        }
        if (in_array($major, [2, 3, 4], true) && $minor === 2) {
            return StandardEncryptionInfo::fromStream($contents, $maximumSpinCount);
        }
        if (in_array($major, [3, 4], true) && $minor === 3) {
            throw new UnsupportedEncryptionException('ECMA-376 Extensible Encryption is not supported.');
        }

        throw new UnsupportedEncryptionException(sprintf(
            'Unsupported Office encryption version %d.%d.',
            $major,
            $minor,
        ));
    }
}
