<?php

declare(strict_types=1);

namespace DK\OpenXml\Internal\Encryption;

use DK\CompoundFile\Stream;
use DK\OpenXml\Exception\InvalidEncryptedPackageException;
use DK\OpenXml\Exception\MissingDependencyException;

/** @internal Byte helpers shared by the Agile and Standard encryption codecs. */
final class EncryptedPackageIo
{
    private function __construct() {}

    public static function assertOpenSsl(): void
    {
        if (!extension_loaded('openssl')) {
            throw new MissingDependencyException('Office encryption requires the OpenSSL PHP extension.');
        }
    }

    public static function roundUp(int $value, int $multiple): int
    {
        return intdiv($value + $multiple - 1, $multiple) * $multiple;
    }

    public static function readExactly(Stream $stream, int $bytes): string
    {
        $contents = $stream->read($bytes);
        if (strlen($contents) !== $bytes) {
            throw new InvalidEncryptedPackageException('EncryptedPackage ended unexpectedly.');
        }

        return $contents;
    }

    /** @param resource $stream */
    public static function write($stream, string $contents): void
    {
        $offset = 0;
        while ($offset < strlen($contents)) {
            $written = fwrite($stream, substr($contents, $offset));
            if ($written === false || $written === 0) {
                throw new InvalidEncryptedPackageException('Unable to write Office data.');
            }
            $offset += $written;
        }
    }
}
