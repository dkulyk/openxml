<?php

declare(strict_types=1);

namespace DK\OpenXml\Tests;

use DK\CompoundFile\CompoundFile;
use DK\CompoundFile\CompoundFileWriter;
use DK\OpenXml\Encryption\AgileEncryptionOptions;
use DK\OpenXml\Encryption\EncryptedOfficeFile;
use DK\OpenXml\Encryption\EncryptionLimits;
use DK\OpenXml\Exception\InvalidEncryptedPackageException;
use DK\OpenXml\Exception\InvalidPasswordException;
use DK\OpenXml\Exception\UnsupportedEncryptionException;
use DK\OpenXml\OfficeFileDetector;
use DK\OpenXml\OfficeFileFormat;
use DK\OpenXml\OpenXmlPackage;
use PHPUnit\Framework\TestCase;

final class EncryptedOfficeFileTest extends TestCase
{
    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    public function testAgileEncryptionRoundTripPreservesTheOpcPackage(): void
    {
        $source = $this->createPackage(str_repeat('payload-', 1_000));
        $encrypted = $this->temporaryFile();
        $decrypted = $this->temporaryFile();

        EncryptedOfficeFile::encrypt(
            $source,
            $encrypted,
            'correct horse battery staple',
            new AgileEncryptionOptions(10),
        );

        self::assertSame(OfficeFileFormat::EncryptedOpcPackage, OfficeFileDetector::detect($encrypted));
        $compoundFile = CompoundFile::open($encrypted);
        self::assertTrue($compoundFile->hasStream('EncryptionInfo'));
        self::assertTrue($compoundFile->hasStream('EncryptedPackage'));
        self::assertTrue($compoundFile->hasStream("\x06DataSpaces/DataSpaceMap"));
        self::assertTrue($compoundFile->hasStream("\x06DataSpaces/TransformInfo/StrongEncryptionTransform/\x06Primary"));

        EncryptedOfficeFile::decrypt($encrypted, $decrypted, 'correct horse battery staple');

        self::assertSame(hash_file('sha256', $source), hash_file('sha256', $decrypted));
        self::assertSame(
            str_repeat('payload-', 1_000),
            OpenXmlPackage::open($decrypted)->getPart('/document.xml')->getContents(),
        );
    }

    public function testUnicodePasswordRoundTrip(): void
    {
        $source = $this->createPackage('unicode password');
        $encrypted = $this->temporaryFile();
        $decrypted = $this->temporaryFile();

        EncryptedOfficeFile::encrypt($source, $encrypted, 'пароль🔐', new AgileEncryptionOptions(10));
        EncryptedOfficeFile::decrypt($encrypted, $decrypted, 'пароль🔐');

        self::assertSame(hash_file('sha256', $source), hash_file('sha256', $decrypted));
    }

    public function testWrongPasswordDoesNotReplaceTheDestination(): void
    {
        $source = $this->createPackage('secret');
        $encrypted = $this->temporaryFile();
        $destination = $this->temporaryFile('keep me');
        EncryptedOfficeFile::encrypt($source, $encrypted, 'right', new AgileEncryptionOptions(10));

        try {
            EncryptedOfficeFile::decrypt($encrypted, $destination, 'wrong');
            self::fail('An invalid password was expected to fail.');
        } catch (InvalidPasswordException) {
            self::assertSame('keep me', file_get_contents($destination));
        }
    }

    public function testTamperedEncryptedPayloadFailsIntegrityCheck(): void
    {
        $source = $this->createPackage('integrity');
        $encrypted = $this->temporaryFile();
        $destination = $this->temporaryFile('untouched');
        EncryptedOfficeFile::encrypt($source, $encrypted, 'password', new AgileEncryptionOptions(10));

        $compoundFile = CompoundFile::open($encrypted);
        $payload = $compoundFile->getStreamContents('EncryptedPackage');
        $payload[12] = chr(ord($payload[12]) ^ 1);
        CompoundFileWriter::open($encrypted)
            ->setStreamContents('EncryptedPackage', $payload)
            ->save($encrypted);

        try {
            EncryptedOfficeFile::decrypt($encrypted, $destination, 'password');
            self::fail('A modified encrypted payload was expected to fail.');
        } catch (InvalidEncryptedPackageException $exception) {
            self::assertStringContainsString('integrity check', $exception->getMessage());
            self::assertSame('untouched', file_get_contents($destination));
        }
    }

    public function testConfiguredDecryptedSizeLimitIsEnforced(): void
    {
        $source = $this->createPackage(str_repeat('large', 1_000));
        $encrypted = $this->temporaryFile();
        EncryptedOfficeFile::encrypt($source, $encrypted, 'password', new AgileEncryptionOptions(10));

        $this->expectException(InvalidEncryptedPackageException::class);
        $this->expectExceptionMessage('configured maximum');
        EncryptedOfficeFile::decrypt(
            $encrypted,
            $this->temporaryFile(),
            'password',
            new EncryptionLimits(maximumDecryptedBytes: 10),
        );
    }

    public function testDefaultOptionsUseOfficeCompatibleSpinCount(): void
    {
        self::assertSame(100_000, (new AgileEncryptionOptions())->spinCount);
    }

    /** @dataProvider standardAesKeySizes */
    public function testStandardEncryptionCanBeDecrypted(int $keyBits): void
    {
        $source = $this->createPackage(sprintf('Standard AES-%d', $keyBits));
        $encrypted = $this->createStandardEncryptedFile($source, 'old office password', $keyBits);
        $decrypted = $this->temporaryFile();

        EncryptedOfficeFile::decrypt($encrypted, $decrypted, 'old office password');

        self::assertSame(hash_file('sha256', $source), hash_file('sha256', $decrypted));
    }

    /** @return iterable<string, array{int}> */
    public static function standardAesKeySizes(): iterable
    {
        yield 'AES-128' => [128];
        yield 'AES-192' => [192];
        yield 'AES-256' => [256];
    }

    public function testWrongStandardEncryptionPasswordDoesNotReplaceDestination(): void
    {
        $source = $this->createPackage('standard secret');
        $encrypted = $this->createStandardEncryptedFile($source, 'right', 128);
        $destination = $this->temporaryFile('keep me');

        try {
            EncryptedOfficeFile::decrypt($encrypted, $destination, 'wrong');
            self::fail('An invalid password was expected to fail.');
        } catch (InvalidPasswordException) {
            self::assertSame('keep me', file_get_contents($destination));
        }
    }

    public function testStandardEncryptionHonorsPasswordWorkLimit(): void
    {
        $source = $this->createPackage('standard work limit');
        $encrypted = $this->createStandardEncryptedFile($source, 'password', 128);

        $this->expectException(UnsupportedEncryptionException::class);
        $this->expectExceptionMessage('50,000 password-hash iterations');
        EncryptedOfficeFile::decrypt(
            $encrypted,
            $this->temporaryFile(),
            'password',
            new EncryptionLimits(maximumSpinCount: 49_999),
        );
    }

    private function createPackage(string $contents): string
    {
        $filename = $this->temporaryFile();
        $package = OpenXmlPackage::create();
        $package->addPart('/document.xml', 'application/xml', $contents);
        $package->saveAs($filename);

        return $filename;
    }

    private function temporaryFile(string $contents = ''): string
    {
        $filename = tempnam(sys_get_temp_dir(), 'openxml-encryption-');
        self::assertNotFalse($filename);
        if ($contents !== '') {
            file_put_contents($filename, $contents);
        }
        $this->files[] = $filename;

        return $filename;
    }

    private function createStandardEncryptedFile(string $source, string $password, int $keyBits): string
    {
        $plainText = file_get_contents($source);
        self::assertNotFalse($plainText);
        $salt = hex2bin('00112233445566778899aabbccddeeff');
        $verifier = hex2bin('ffeeddccbbaa99887766554433221100');
        self::assertNotFalse($salt);
        self::assertNotFalse($verifier);
        $key = self::standardKey($password, $salt, intdiv($keyBits, 8));
        $cipher = sprintf('aes-%d-ecb', $keyBits);
        $encryptedVerifier = openssl_encrypt(
            $verifier,
            $cipher,
            $key,
            OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING,
        );
        $verifierHash = str_pad(hash('sha1', $verifier, true), 32, "\0");
        $encryptedVerifierHash = openssl_encrypt(
            $verifierHash,
            $cipher,
            $key,
            OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING,
        );
        $encryptedPayload = openssl_encrypt(
            str_pad($plainText, self::roundUp(strlen($plainText), 16), "\0"),
            $cipher,
            $key,
            OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING,
        );
        self::assertNotFalse($encryptedVerifier);
        self::assertNotFalse($encryptedVerifierHash);
        self::assertNotFalse($encryptedPayload);

        $algorithm = [128 => 0x660E, 192 => 0x660F, 256 => 0x6610][$keyBits];
        $providerName = mb_convert_encoding(
            'Microsoft Enhanced RSA and AES Cryptographic Provider' . "\0",
            'UTF-16LE',
        );
        $header = pack('V8', 0x24, 0, $algorithm, 0x8004, $keyBits, 0x18, 0, 0) . $providerName;
        $encryptionInfo = pack('vvVV', 4, 2, 0x24, strlen($header))
            . $header
            . pack('V', 16)
            . $salt
            . $encryptedVerifier
            . pack('V', 20)
            . $encryptedVerifierHash;
        $encryptedPackage = pack('V2', strlen($plainText), 0) . $encryptedPayload;

        $filename = $this->temporaryFile();
        CompoundFileWriter::create()
            ->setStreamContents('EncryptionInfo', $encryptionInfo)
            ->setStreamContents('EncryptedPackage', $encryptedPackage)
            ->save($filename);

        return $filename;
    }

    private static function standardKey(string $password, string $salt, int $keyBytes): string
    {
        $hash = hash('sha1', $salt . mb_convert_encoding($password, 'UTF-16LE', 'UTF-8'), true);
        for ($iteration = 0; $iteration < 50_000; ++$iteration) {
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

    private static function roundUp(int $value, int $multiple): int
    {
        return intdiv($value + $multiple - 1, $multiple) * $multiple;
    }
}
