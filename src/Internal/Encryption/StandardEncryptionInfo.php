<?php

declare(strict_types=1);

namespace DK\OpenXml\Internal\Encryption;

use DK\OpenXml\Exception\InvalidEncryptedPackageException;
use DK\OpenXml\Exception\UnsupportedEncryptionException;

/** @internal Parsed ECMA-376 Standard Encryption metadata. */
final class StandardEncryptionInfo
{
    private const AES_ALGORITHMS = [
        0x660E => 128,
        0x660F => 192,
        0x6610 => 256,
    ];

    public function __construct(
        public readonly int $keyBits,
        public readonly string $salt,
        public readonly string $encryptedVerifier,
        public readonly string $encryptedVerifierHash,
    ) {}

    public static function fromStream(string $contents, int $maximumSpinCount): self
    {
        if (strlen($contents) < 12) {
            throw new InvalidEncryptedPackageException('The EncryptionInfo stream is too short.');
        }
        if ($maximumSpinCount < StandardEncryption::SPIN_COUNT) {
            throw new UnsupportedEncryptionException(sprintf(
                'Standard Encryption requires %s password-hash iterations, exceeding the configured maximum of %s.',
                number_format(StandardEncryption::SPIN_COUNT),
                number_format($maximumSpinCount),
            ));
        }

        $prefix = self::unpack('vmajor/vminor/Vflags/VheaderSize', substr($contents, 0, 12));
        if (!in_array($prefix['major'], [2, 3, 4], true) || $prefix['minor'] !== 2) {
            throw new UnsupportedEncryptionException('The EncryptionInfo stream is not ECMA-376 Standard Encryption.');
        }

        $headerSize = $prefix['headerSize'];
        if ($headerSize < 32 || strlen($contents) < 12 + $headerSize + 72) {
            throw new InvalidEncryptedPackageException('Standard EncryptionInfo has an invalid header size.');
        }

        $headerBytes = substr($contents, 12, $headerSize);
        $header = self::unpack(
            'Vflags/VsizeExtra/Valgorithm/VhashAlgorithm/VkeyBits/VproviderType/Vreserved1/Vreserved2',
            substr($headerBytes, 0, 32),
        );
        if ($header['flags'] !== $prefix['flags'] || ($header['flags'] & 0x24) !== 0x24 || ($header['flags'] & 0x10) !== 0) {
            throw new UnsupportedEncryptionException('Unsupported Standard Encryption flags.');
        }
        if ($header['sizeExtra'] !== 0 || $header['providerType'] !== 0x18 || $header['reserved2'] !== 0) {
            throw new UnsupportedEncryptionException('Unsupported Standard Encryption provider parameters.');
        }
        if (!isset(self::AES_ALGORITHMS[$header['algorithm']])) {
            throw new UnsupportedEncryptionException(sprintf(
                'Unsupported Standard Encryption algorithm 0x%04X.',
                $header['algorithm'],
            ));
        }
        if (self::AES_ALGORITHMS[$header['algorithm']] !== $header['keyBits'] || !in_array($header['hashAlgorithm'], [0, 0x8004], true)) {
            throw new UnsupportedEncryptionException('Standard Encryption AES key size or hash algorithm is inconsistent.');
        }

        $providerName = substr($headerBytes, 32);
        if ($providerName === '' || strlen($providerName) % 2 !== 0 || !str_ends_with($providerName, "\0\0")) {
            throw new InvalidEncryptedPackageException('Standard EncryptionInfo has an invalid provider name.');
        }

        $verifierOffset = 12 + $headerSize;
        if (strlen($contents) !== $verifierOffset + 72) {
            throw new InvalidEncryptedPackageException('Standard EncryptionInfo has an invalid verifier size.');
        }
        $verifier = self::unpack('VsaltSize', substr($contents, $verifierOffset, 4));
        $hash = self::unpack('VhashSize', substr($contents, $verifierOffset + 36, 4));
        if ($verifier['saltSize'] !== 16 || $hash['hashSize'] !== 20) {
            throw new UnsupportedEncryptionException('Standard Encryption requires a 16-byte salt and SHA-1 verifier.');
        }

        return new self(
            $header['keyBits'],
            substr($contents, $verifierOffset + 4, 16),
            substr($contents, $verifierOffset + 20, 16),
            substr($contents, $verifierOffset + 40, 32),
        );
    }

    /** @return array<string, int> */
    private static function unpack(string $format, string $contents): array
    {
        $values = unpack($format, $contents);
        if ($values === false) {
            throw new InvalidEncryptedPackageException('Unable to decode Standard EncryptionInfo.');
        }

        $integers = [];
        foreach ($values as $name => $value) {
            if (!is_string($name) || !is_int($value)) {
                throw new InvalidEncryptedPackageException('Standard EncryptionInfo contains an invalid integer.');
            }
            $integers[$name] = $value;
        }

        return $integers;
    }
}
