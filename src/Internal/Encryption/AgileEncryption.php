<?php

declare(strict_types=1);

namespace DK\OpenXml\Internal\Encryption;

use DK\CompoundFile\Stream;
use DK\OpenXml\Exception\InvalidEncryptedPackageException;
use DK\OpenXml\Exception\InvalidPasswordException;
use DK\OpenXml\Internal\UnsignedInteger;

/** @internal */
final class AgileEncryption
{
    private const BLOCK_SIZE = 16;
    private const HASH_SIZE = 64;
    private const KEY_BYTES = 32;
    private const SALT_SIZE = 16;
    private const SEGMENT_SIZE = 4096;

    // MS-OFFCRYPTO assigns these fixed block keys to separate password-derived
    // keys for the verifier, package key, and integrity values.
    private const VERIFIER_BLOCK = "\xFE\xA7\xD2\x76\x3B\x4B\x9E\x79";
    private const VERIFIER_HASH_BLOCK = "\xD7\xAA\x0F\x6D\x30\x61\x34\x4E";
    private const KEY_BLOCK = "\x14\x6E\x0B\xE7\xAB\xAC\xD0\xD6";
    private const HMAC_KEY_BLOCK = "\x5F\xB2\xAD\x01\x0C\xB9\xE1\xF6";
    private const HMAC_VALUE_BLOCK = "\xA0\x67\x7F\x02\xB2\x2C\x84\x33";

    /**
     * @param resource $source
     * @param resource $destination
     */
    public static function encrypt($source, $destination, string $password, int $spinCount): AgileEncryptionInfo
    {
        self::assertOpenSsl();
        $metadata = fstat($source);
        if ($metadata === false) {
            throw new InvalidEncryptedPackageException('Unable to determine the source package size.');
        }

        $passwordSalt = random_bytes(self::SALT_SIZE);
        $keyDataSalt = random_bytes(self::SALT_SIZE);
        $secretKey = random_bytes(self::KEY_BYTES);
        $verifier = random_bytes(self::SALT_SIZE);
        $passwordHash = self::passwordHash($password, $passwordSalt, $spinCount);

        $encryptedVerifier = self::encryptBlock(
            $verifier,
            self::deriveKey($passwordHash, self::VERIFIER_BLOCK),
            $passwordSalt,
        );
        $encryptedVerifierHash = self::encryptBlock(
            hash('sha512', $verifier, true),
            self::deriveKey($passwordHash, self::VERIFIER_HASH_BLOCK),
            $passwordSalt,
        );
        $encryptedKey = self::encryptBlock(
            $secretKey,
            self::deriveKey($passwordHash, self::KEY_BLOCK),
            $passwordSalt,
        );

        $sizeHeader = self::packUInt64($metadata['size']);
        self::write($destination, $sizeHeader);
        // The integrity message is the complete EncryptedPackage stream,
        // including its eight-byte plaintext-size header.
        $hmac = hash_init('sha512', HASH_HMAC, $hmacKey = random_bytes(self::HASH_SIZE));
        hash_update($hmac, $sizeHeader);

        $segment = 0;
        $bytesRead = 0;
        while (!feof($source)) {
            $plainText = fread($source, self::SEGMENT_SIZE);
            if ($plainText === false) {
                throw new InvalidEncryptedPackageException('Unable to read the source OPC package.');
            }
            if ($plainText === '') {
                break;
            }

            // Each 4096-byte segment gets an independent IV derived from its
            // zero-based little-endian segment number.
            $cipherText = self::encryptBlock(
                self::zeroPad($plainText),
                $secretKey,
                self::initializationVector($keyDataSalt, pack('V', $segment)),
            );
            self::write($destination, $cipherText);
            hash_update($hmac, $cipherText);
            $bytesRead += strlen($plainText);
            ++$segment;
        }

        if ($bytesRead !== $metadata['size']) {
            throw new InvalidEncryptedPackageException('The source OPC package changed while it was being encrypted.');
        }

        $hmacValue = hash_final($hmac, true);

        return new AgileEncryptionInfo(
            $keyDataSalt,
            $passwordSalt,
            $spinCount,
            $encryptedVerifier,
            $encryptedVerifierHash,
            $encryptedKey,
            self::encryptBlock(
                $hmacKey,
                $secretKey,
                self::initializationVector($keyDataSalt, self::HMAC_KEY_BLOCK),
            ),
            self::encryptBlock(
                $hmacValue,
                $secretKey,
                self::initializationVector($keyDataSalt, self::HMAC_VALUE_BLOCK),
            ),
        );
    }

    /** @param resource $destination */
    public static function decrypt(
        Stream $encryptedPackage,
        AgileEncryptionInfo $info,
        string $password,
        $destination,
        int $maximumBytes,
    ): void {
        self::assertOpenSsl();
        $passwordHash = self::passwordHash(
            $password,
            $info->passwordSalt,
            $info->spinCount,
            $info->hashAlgorithm,
        );
        $verifier = self::decryptBlock(
            $info->encryptedVerifier,
            self::deriveKey($passwordHash, self::VERIFIER_BLOCK, $info->hashAlgorithm, $info->keyBits),
            $info->passwordSalt,
            $info->keyBits,
        );
        $expectedVerifierHash = self::decryptBlock(
            $info->encryptedVerifierHash,
            self::deriveKey($passwordHash, self::VERIFIER_HASH_BLOCK, $info->hashAlgorithm, $info->keyBits),
            $info->passwordSalt,
            $info->keyBits,
        );

        if (!hash_equals(
            substr($expectedVerifierHash, 0, $info->hashSize),
            hash($info->hashAlgorithm, $verifier, true),
        )) {
            throw new InvalidPasswordException('The password for the encrypted Office file is incorrect.');
        }

        $secretKey = substr(
            self::decryptBlock(
                $info->encryptedKey,
                self::deriveKey($passwordHash, self::KEY_BLOCK, $info->hashAlgorithm, $info->keyBits),
                $info->passwordSalt,
                $info->keyBits,
            ),
            0,
            intdiv($info->keyBits, 8),
        );
        self::verifyIntegrity($encryptedPackage, $info, $secretKey);

        if (!$encryptedPackage->seek(0)) {
            throw new InvalidEncryptedPackageException('Unable to rewind the EncryptedPackage stream.');
        }
        $plainTextSize = self::unpackUInt64(self::readExactly($encryptedPackage, 8));
        if ($plainTextSize > $maximumBytes) {
            throw new InvalidEncryptedPackageException(sprintf(
                'Decrypted package size %d exceeds the configured maximum of %d bytes.',
                $plainTextSize,
                $maximumBytes,
            ));
        }

        $expectedCipherTextBytes = intdiv($plainTextSize, self::SEGMENT_SIZE) * self::SEGMENT_SIZE;
        $remainder = $plainTextSize % self::SEGMENT_SIZE;
        if ($remainder > 0) {
            $expectedCipherTextBytes += self::roundUp($remainder, self::BLOCK_SIZE);
        }
        if ($encryptedPackage->getSize() !== 8 + $expectedCipherTextBytes) {
            throw new InvalidEncryptedPackageException('EncryptedPackage size does not match its declared plaintext size.');
        }

        $remaining = $plainTextSize;
        $segment = 0;
        while ($remaining > 0) {
            $cipherTextBytes = min(self::SEGMENT_SIZE, $expectedCipherTextBytes);
            $plainText = self::decryptBlock(
                self::readExactly($encryptedPackage, $cipherTextBytes),
                $secretKey,
                self::initializationVector($info->keyDataSalt, pack('V', $segment), $info->hashAlgorithm),
                $info->keyBits,
            );
            self::write($destination, substr($plainText, 0, min($remaining, strlen($plainText))));
            $remaining -= min($remaining, strlen($plainText));
            $expectedCipherTextBytes -= $cipherTextBytes;
            ++$segment;
        }
    }

    private static function verifyIntegrity(Stream $stream, AgileEncryptionInfo $info, string $secretKey): void
    {
        $hmacKey = self::decryptBlock(
            $info->encryptedHmacKey,
            $secretKey,
            self::initializationVector($info->keyDataSalt, self::HMAC_KEY_BLOCK, $info->hashAlgorithm),
            $info->keyBits,
        );
        $expectedHmac = self::decryptBlock(
            $info->encryptedHmacValue,
            $secretKey,
            self::initializationVector($info->keyDataSalt, self::HMAC_VALUE_BLOCK, $info->hashAlgorithm),
            $info->keyBits,
        );
        if (!$stream->seek(0)) {
            throw new InvalidEncryptedPackageException('Unable to rewind the EncryptedPackage stream.');
        }

        $hmac = hash_init($info->hashAlgorithm, HASH_HMAC, substr($hmacKey, 0, $info->hashSize));
        while (!$stream->eof()) {
            $chunk = $stream->read(65_536);
            if ($chunk === '') {
                break;
            }
            hash_update($hmac, $chunk);
        }

        if (!hash_equals(substr($expectedHmac, 0, $info->hashSize), hash_final($hmac, true))) {
            throw new InvalidEncryptedPackageException('EncryptedPackage failed its integrity check.');
        }
    }

    private static function passwordHash(
        string $password,
        string $salt,
        int $spinCount,
        string $hashAlgorithm = 'sha512',
    ): string {
        $utf16Password = mb_convert_encoding($password, 'UTF-16LE', 'UTF-8');
        $hash = hash($hashAlgorithm, $salt . $utf16Password, true);
        for ($iteration = 0; $iteration < $spinCount; ++$iteration) {
            $hash = hash($hashAlgorithm, pack('V', $iteration) . $hash, true);
        }

        return $hash;
    }

    private static function deriveKey(
        string $passwordHash,
        string $blockKey,
        string $hashAlgorithm = 'sha512',
        int $keyBits = 256,
    ): string {
        $keyBytes = intdiv($keyBits, 8);
        $derived = hash($hashAlgorithm, $passwordHash . $blockKey, true);

        return substr(str_pad($derived, $keyBytes, "\x36"), 0, $keyBytes);
    }

    private static function initializationVector(
        string $salt,
        string $blockKey,
        string $hashAlgorithm = 'sha512',
    ): string {
        return substr(str_pad(hash($hashAlgorithm, $salt . $blockKey, true), self::BLOCK_SIZE, "\x36"), 0, self::BLOCK_SIZE);
    }

    private static function encryptBlock(string $contents, string $key, string $iv): string
    {
        $result = openssl_encrypt(
            $contents,
            'aes-256-cbc',
            $key,
            OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING,
            $iv,
        );
        if ($result === false) {
            throw new InvalidEncryptedPackageException('OpenSSL could not encrypt Office data.');
        }

        return $result;
    }

    private static function decryptBlock(
        string $contents,
        string $key,
        string $iv,
        int $keyBits = 256,
    ): string {
        $result = openssl_decrypt(
            $contents,
            sprintf('aes-%d-cbc', $keyBits),
            $key,
            OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING,
            $iv,
        );
        if ($result === false) {
            throw new InvalidEncryptedPackageException('OpenSSL could not decrypt Office data.');
        }

        return $result;
    }

    private static function zeroPad(string $contents): string
    {
        return str_pad($contents, self::roundUp(strlen($contents), self::BLOCK_SIZE), "\0");
    }

    private static function roundUp(int $value, int $multiple): int
    {
        return intdiv($value + $multiple - 1, $multiple) * $multiple;
    }

    private static function packUInt64(int $value): string
    {
        return pack('V2', $value & 0xFFFFFFFF, intdiv($value, 0x100000000));
    }

    private static function unpackUInt64(string $value): int
    {
        return UnsignedInteger::decode64BitLittleEndian($value);
    }

    private static function readExactly(Stream $stream, int $bytes): string
    {
        $contents = $stream->read($bytes);
        if (strlen($contents) !== $bytes) {
            throw new InvalidEncryptedPackageException('EncryptedPackage ended unexpectedly.');
        }

        return $contents;
    }

    /** @param resource $stream */
    private static function write($stream, string $contents): void
    {
        $offset = 0;
        while ($offset < strlen($contents)) {
            $written = fwrite($stream, substr($contents, $offset));
            if ($written === false || $written === 0) {
                throw new InvalidEncryptedPackageException('Unable to write encrypted Office data.');
            }
            $offset += $written;
        }
    }

    private static function assertOpenSsl(): void
    {
        if (!extension_loaded('openssl')) {
            throw new \DK\OpenXml\Exception\MissingDependencyException(
                'Office encryption requires the OpenSSL PHP extension.',
            );
        }
    }
}
