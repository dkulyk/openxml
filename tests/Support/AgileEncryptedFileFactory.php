<?php

declare(strict_types=1);

namespace DK\OpenXml\Tests\Support;

use DK\CompoundFile\CompoundFileWriter;
use DK\OpenXml\Internal\Encryption\AgileEncryptionInfo;
use DK\OpenXml\Internal\Encryption\DataSpaces;

/** Builds deterministic compatibility fixtures independently of the production writer. */
final class AgileEncryptedFileFactory
{
    private const VERIFIER_BLOCK = "\xFE\xA7\xD2\x76\x3B\x4B\x9E\x79";
    private const VERIFIER_HASH_BLOCK = "\xD7\xAA\x0F\x6D\x30\x61\x34\x4E";
    private const KEY_BLOCK = "\x14\x6E\x0B\xE7\xAB\xAC\xD0\xD6";
    private const HMAC_KEY_BLOCK = "\x5F\xB2\xAD\x01\x0C\xB9\xE1\xF6";
    private const HMAC_VALUE_BLOCK = "\xA0\x67\x7F\x02\xB2\x2C\x84\x33";

    private function __construct() {}

    public static function create(
        string $source,
        string $destination,
        string $password,
        int $keyBits,
        string $hashAlgorithm,
    ): void {
        $plainText = file_get_contents($source);
        if ($plainText === false) {
            throw new \RuntimeException('Unable to read the fixture source package.');
        }

        $passwordSalt = self::hex('00112233445566778899aabbccddeeff');
        $keyDataSalt = self::hex('ffeeddccbbaa99887766554433221100');
        $spinCount = 10;
        $hashSize = strlen(hash($hashAlgorithm, '', true));
        $keyBytes = intdiv($keyBits, 8);
        $passwordHash = self::passwordHash($password, $passwordSalt, $spinCount, $hashAlgorithm);
        $verifier = str_repeat("\x5A", 16);
        $secretKey = substr(hash('sha512', 'independent test key', true), 0, $keyBytes);

        $encryptedVerifier = self::encrypt(
            $verifier,
            self::derivedKey($passwordHash, self::VERIFIER_BLOCK, $hashAlgorithm, $keyBytes),
            $passwordSalt,
            $keyBits,
        );
        $encryptedVerifierHash = self::encrypt(
            self::zeroPad(hash($hashAlgorithm, $verifier, true)),
            self::derivedKey($passwordHash, self::VERIFIER_HASH_BLOCK, $hashAlgorithm, $keyBytes),
            $passwordSalt,
            $keyBits,
        );
        $encryptedKey = self::encrypt(
            self::zeroPad($secretKey),
            self::derivedKey($passwordHash, self::KEY_BLOCK, $hashAlgorithm, $keyBytes),
            $passwordSalt,
            $keyBits,
        );

        $encryptedPackage = self::encryptPackage($plainText, $secretKey, $keyDataSalt, $keyBits, $hashAlgorithm);
        $hmacKey = str_repeat("\xA5", $hashSize);
        $hmacValue = hash_hmac($hashAlgorithm, $encryptedPackage, $hmacKey, true);
        $info = new AgileEncryptionInfo(
            $keyDataSalt,
            $passwordSalt,
            $spinCount,
            $encryptedVerifier,
            $encryptedVerifierHash,
            $encryptedKey,
            self::encrypt(
                self::zeroPad($hmacKey),
                $secretKey,
                self::iv($keyDataSalt, self::HMAC_KEY_BLOCK, $hashAlgorithm),
                $keyBits,
            ),
            self::encrypt(
                self::zeroPad($hmacValue),
                $secretKey,
                self::iv($keyDataSalt, self::HMAC_VALUE_BLOCK, $hashAlgorithm),
                $keyBits,
            ),
            $keyBits,
            $hashAlgorithm,
            $hashSize,
        );

        $writer = CompoundFileWriter::create();
        DataSpaces::addTo($writer);
        $writer
            ->setStreamContents('EncryptionInfo', $info->toStream())
            ->setStreamContents('EncryptedPackage', $encryptedPackage)
            ->save($destination);
    }

    private static function encryptPackage(
        string $plainText,
        string $secretKey,
        string $salt,
        int $keyBits,
        string $hashAlgorithm,
    ): string {
        $encrypted = pack('V2', strlen($plainText), 0);
        for ($segment = 0, $offset = 0; $offset < strlen($plainText); ++$segment, $offset += 4096) {
            $encrypted .= self::encrypt(
                self::zeroPad(substr($plainText, $offset, 4096)),
                $secretKey,
                self::iv($salt, pack('V', $segment), $hashAlgorithm),
                $keyBits,
            );
        }

        return $encrypted;
    }

    private static function passwordHash(
        string $password,
        string $salt,
        int $spinCount,
        string $hashAlgorithm,
    ): string {
        $hash = hash($hashAlgorithm, $salt . mb_convert_encoding($password, 'UTF-16LE', 'UTF-8'), true);
        for ($iteration = 0; $iteration < $spinCount; ++$iteration) {
            $hash = hash($hashAlgorithm, pack('V', $iteration) . $hash, true);
        }

        return $hash;
    }

    private static function derivedKey(
        string $passwordHash,
        string $blockKey,
        string $hashAlgorithm,
        int $keyBytes,
    ): string {
        return substr(
            str_pad(hash($hashAlgorithm, $passwordHash . $blockKey, true), $keyBytes, "\x36"),
            0,
            $keyBytes,
        );
    }

    private static function iv(string $salt, string $blockKey, string $hashAlgorithm): string
    {
        return substr(str_pad(hash($hashAlgorithm, $salt . $blockKey, true), 16, "\x36"), 0, 16);
    }

    private static function encrypt(string $contents, string $key, string $iv, int $keyBits): string
    {
        $encrypted = openssl_encrypt(
            $contents,
            sprintf('aes-%d-cbc', $keyBits),
            $key,
            OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING,
            $iv,
        );
        if ($encrypted === false) {
            throw new \RuntimeException('Unable to encrypt the compatibility fixture.');
        }

        return $encrypted;
    }

    private static function zeroPad(string $contents): string
    {
        $length = intdiv(strlen($contents) + 15, 16) * 16;

        return str_pad($contents, $length, "\0");
    }

    private static function hex(string $value): string
    {
        $bytes = hex2bin($value);
        if ($bytes === false) {
            throw new \LogicException('Invalid fixture hex value.');
        }

        return $bytes;
    }
}
