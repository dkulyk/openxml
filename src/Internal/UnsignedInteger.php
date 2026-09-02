<?php

declare(strict_types=1);

namespace DK\OpenXml\Internal;

use DK\OpenXml\Exception\InvalidEncryptedPackageException;

/** @internal */
final class UnsignedInteger
{
    private function __construct() {}

    public static function decode64BitLittleEndian(string $bytes): int
    {
        if (strlen($bytes) !== 8) {
            throw new InvalidEncryptedPackageException('Unable to decode a 64-bit unsigned integer.');
        }

        $words = unpack('Vlow/Vhigh', $bytes);
        if ($words === false || !is_int($words['low']) || !is_int($words['high'])) {
            throw new InvalidEncryptedPackageException('Unable to decode a 64-bit unsigned integer.');
        }

        return self::from32BitWords($words['low'], $words['high']);
    }

    public static function from32BitWords(
        int $low,
        int $high,
        int $integerSize = PHP_INT_SIZE,
        int $integerMaximum = PHP_INT_MAX,
    ): int {
        if ($integerSize < 8) {
            if ($high !== 0 || $low > $integerMaximum) {
                throw new InvalidEncryptedPackageException('The encrypted package size is too large for this platform.');
            }

            return $low;
        }

        if ($high > 0x7FFFFFFF) {
            throw new InvalidEncryptedPackageException('The encrypted package size is too large for this platform.');
        }

        return $low + $high * 0x100000000;
    }
}
