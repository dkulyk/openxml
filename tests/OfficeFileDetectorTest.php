<?php

declare(strict_types=1);

namespace DK\OpenXml\Tests;

use DK\CompoundFile\CompoundFileWriter;
use DK\OpenXml\Exception\EncryptedPackageException;
use DK\OpenXml\Exception\InvalidCompoundFileException;
use DK\OpenXml\Exception\InvalidEncryptedPackageException;
use DK\OpenXml\Exception\UnsupportedFileFormatException;
use DK\OpenXml\OfficeFileDetector;
use DK\OpenXml\OfficeFileFormat;
use DK\OpenXml\OpenXmlPackage;
use PHPUnit\Framework\TestCase;

final class OfficeFileDetectorTest extends TestCase
{
    private string $filename;

    protected function setUp(): void
    {
        $filename = tempnam(sys_get_temp_dir(), 'openxml-format-');
        self::assertNotFalse($filename);

        $this->filename = $filename;
    }

    protected function tearDown(): void
    {
        if (is_file($this->filename)) {
            unlink($this->filename);
        }
    }

    public function testUnknownFileIsRejectedBeforeZipParsing(): void
    {
        file_put_contents($this->filename, 'not an Office file');

        self::assertSame(OfficeFileFormat::Unknown, OfficeFileDetector::detect($this->filename));

        $this->expectException(UnsupportedFileFormatException::class);
        OpenXmlPackage::open($this->filename);
    }

    public function testPlainCompoundFileIsRecognizedButNotOpenedAsOpc(): void
    {
        CompoundFileWriter::create()
            ->setStreamContents('LegacyDocument', 'legacy')
            ->save($this->filename);

        self::assertSame(OfficeFileFormat::CompoundFile, OfficeFileDetector::detect($this->filename));

        $this->expectException(UnsupportedFileFormatException::class);
        OpenXmlPackage::open($this->filename);
    }

    public function testEncryptedOfficeContainerIsRecognized(): void
    {
        $this->writeEncryptedContainer();

        self::assertSame(OfficeFileFormat::EncryptedOpcPackage, OfficeFileDetector::detect($this->filename));

        $this->expectException(EncryptedPackageException::class);
        OpenXmlPackage::open($this->filename);
    }

    public function testIncompleteEncryptedContainerExplainsTheMissingStream(): void
    {
        CompoundFileWriter::create()
            ->setStreamContents('EncryptionInfo', str_repeat("\0", 8))
            ->save($this->filename);

        $this->expectException(InvalidEncryptedPackageException::class);
        $this->expectExceptionMessage('no EncryptedPackage stream');
        OfficeFileDetector::detect($this->filename);
    }

    public function testInvalidEncryptionStreamIsRejected(): void
    {
        CompoundFileWriter::create()
            ->setStreamContents('EncryptionInfo', 'short')
            ->setStreamContents('EncryptedPackage', str_repeat("\0", 9))
            ->save($this->filename);

        $this->expectException(InvalidEncryptedPackageException::class);
        $this->expectExceptionMessage('EncryptionInfo stream is too short');
        OfficeFileDetector::detect($this->filename);
    }

    public function testCorruptCompoundFileReportsACompoundFileError(): void
    {
        file_put_contents($this->filename, "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1garbage");

        $this->expectException(InvalidCompoundFileException::class);
        OfficeFileDetector::detect($this->filename);
    }

    private function writeEncryptedContainer(): void
    {
        CompoundFileWriter::create()
            ->setStreamContents('EncryptionInfo', str_repeat("\0", 8))
            ->setStreamContents('EncryptedPackage', str_repeat("\0", 9))
            ->save($this->filename);
    }
}
