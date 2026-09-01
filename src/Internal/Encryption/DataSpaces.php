<?php

declare(strict_types=1);

namespace DK\OpenXml\Internal\Encryption;

use DK\CompoundFile\CompoundFileWriter;

/** @internal */
final class DataSpaces
{
    /** Adds the MS-OFFCRYPTO DataSpaces metadata expected by Office consumers. */
    public static function addTo(CompoundFileWriter $writer): void
    {
        $root = "\x06DataSpaces";
        $writer
            ->createStorage($root . '/DataSpaceInfo')
            ->createStorage($root . '/TransformInfo/StrongEncryptionTransform')
            ->setStreamContents($root . '/Version', self::version())
            ->setStreamContents($root . '/DataSpaceMap', self::dataSpaceMap())
            ->setStreamContents($root . '/DataSpaceInfo/StrongEncryptionDataSpace', self::strongEncryptionDataSpace())
            ->setStreamContents($root . "/TransformInfo/StrongEncryptionTransform/\x06Primary", self::primary());
    }

    private static function version(): string
    {
        return self::unicode('Microsoft.Container.DataSpaces') . pack('V3', 1, 1, 1);
    }

    private static function dataSpaceMap(): string
    {
        $entry = pack('V2', 1, 0)
            . self::unicode('EncryptedPackage')
            . self::unicode('StrongEncryptionDataSpace');

        return pack('V2', 8, 1) . pack('V', strlen($entry) + 4) . $entry;
    }

    private static function strongEncryptionDataSpace(): string
    {
        return pack('V2', 8, 1) . self::unicode('StrongEncryptionTransform');
    }

    private static function primary(): string
    {
        return pack('V2', 0x58, 1)
            . self::unicode('{FF9A3F03-56EF-4613-BDD5-5A41C1D07246}')
            . self::unicode('Microsoft.Container.EncryptionTransform')
            . pack('V3', 1, 1, 1)
            . str_repeat("\0", 12)
            . pack('V', 4);
    }

    private static function unicode(string $value): string
    {
        // UNICODE-LP-P4 stores a byte length followed by UTF-16LE data padded
        // to a four-byte boundary, without a null terminator.
        $utf16 = mb_convert_encoding($value, 'UTF-16LE', 'UTF-8');
        $encoded = pack('V', strlen($utf16)) . $utf16;

        return str_pad($encoded, (strlen($encoded) + 3) & ~3, "\0");
    }
}
