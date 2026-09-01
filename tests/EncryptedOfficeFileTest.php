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
}
