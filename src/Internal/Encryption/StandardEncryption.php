<?php

declare(strict_types=1);

namespace DK\OpenXml\Internal\Encryption;

use DK\CompoundFile\Stream;
use DK\OpenXml\Exception\InvalidEncryptedPackageException;
use DK\OpenXml\Exception\InvalidPasswordException;
use DK\OpenXml\Internal\UnsignedInteger;

/** @internal ECMA-376 Standard Encryption reader (AES/SHA-1). */
final class StandardEncryption
{
    public const SPIN_COUNT = 50_000;

    private const BLOCK_SIZE = 16;

    /** @param resource $destination */
    public static function decrypt(
        Stream $encryptedPackage,
        StandardEncryptionInfo $info,
        string $password,
        $destination,
        int $maximumBytes,
    ): void {
        EncryptedPackageIo::assertOpenSsl();
        $key = self::deriveKey($password, $info->salt, intdiv($info->keyBits, 8));
        $verifier = self::decryptBlock($info->encryptedVerifier, $key, $info->keyBits);
        $verifierHash = self::decryptBlock($info->encryptedVerifierHash, $key, $info->keyBits);
        if (!hash_equals(hash('sha1', $verifier, true), substr($verifierHash, 0, 20))) {
            throw new InvalidPasswordException('The password for the encrypted Office file is incorrect.');
        }

        if (!$encryptedPackage->seek(0)) {
            throw new InvalidEncryptedPackageException('Unable to rewind the EncryptedPackage stream.');
        }
        $plainTextSize = UnsignedInteger::decode64BitLittleEndian(EncryptedPackageIo::readExactly($encryptedPackage, 8));
        if ($plainTextSize > $maximumBytes) {
            throw new InvalidEncryptedPackageException(sprintf(
                'Decrypted package size %d exceeds the configured maximum of %d bytes.',
                $plainTextSize,
                $maximumBytes,
            ));
        }

        $cipherTextBytes = EncryptedPackageIo::roundUp($plainTextSize, self::BLOCK_SIZE);
        if ($encryptedPackage->getSize() !== 8 + $cipherTextBytes) {
            throw new InvalidEncryptedPackageException('EncryptedPackage size does not match its declared plaintext size.');
        }

        $remaining = $plainTextSize;
        while ($cipherTextBytes > 0) {
            $chunkSize = min(65_536, $cipherTextBytes);
            $plainText = self::decryptBlock(
                EncryptedPackageIo::readExactly($encryptedPackage, $chunkSize),
                $key,
                $info->keyBits,
            );
            EncryptedPackageIo::write($destination, substr($plainText, 0, min($remaining, strlen($plainText))));
            $remaining -= min($remaining, strlen($plainText));
            $cipherTextBytes -= $chunkSize;
        }
    }

    private static function deriveKey(string $password, string $salt, int $keyBytes): string
    {
        $passwordBytes = mb_convert_encoding($password, 'UTF-16LE', 'UTF-8');
        $hash = hash('sha1', $salt . $passwordBytes, true);
        for ($iteration = 0; $iteration < self::SPIN_COUNT; ++$iteration) {
            $hash = hash('sha1', pack('V', $iteration) . $hash, true);
        }
        $hash = hash('sha1', $hash . pack('V', 0), true);

        $inner = str_repeat("\x36", 64);
        $outer = str_repeat("\x5C", 64);
        for ($index = 0; $index < strlen($hash); ++$index) {
            $inner[$index] = chr(ord($inner[$index]) ^ ord($hash[$index]));
            $outer[$index] = chr(ord($outer[$index]) ^ ord($hash[$index]));
        }

        return substr(hash('sha1', $inner, true) . hash('sha1', $outer, true), 0, $keyBytes);
    }

    private static function decryptBlock(string $contents, string $key, int $keyBits): string
    {
        $result = openssl_decrypt(
            $contents,
            sprintf('aes-%d-ecb', $keyBits),
            $key,
            OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING,
        );
        if ($result === false) {
            throw new InvalidEncryptedPackageException('OpenSSL could not decrypt Standard Encryption data.');
        }

        return $result;
    }
}
