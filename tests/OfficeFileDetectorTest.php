<?php

declare(strict_types=1);

namespace DK\OpenXml\Tests;

use DK\CompoundFile\CompoundFileWriter;
use DK\OpenXml\Exception\EncryptedPackageException;
use DK\OpenXml\Exception\InvalidCompoundFileException;
use DK\OpenXml\Exception\InvalidEncryptedPackageException;
use DK\OpenXml\Exception\OpenXmlException;
use DK\OpenXml\Exception\UnsupportedFileFormatException;
use DK\OpenXml\OfficeFileDetector;
use DK\OpenXml\OfficeFileFormat;
use DK\OpenXml\OpenXmlPackage;
use DK\OpenXml\Packaging\RelationshipType;
use PHPUnit\Framework\TestCase;

final class OfficeFileDetectorTest extends TestCase
{
    private const CONTENT_TYPES_ENTRY = '[Content_Types].xml';

    private const CONTENT_TYPES_XML = '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>';

    private const PRESENTATION_CONTENT_TYPE = 'application/vnd.openxmlformats-officedocument.presentationml.presentation.main+xml';

    public string $filename;

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

    /**
     * @param \Closure(self): void          $write
     * @param null|class-string<\Throwable> $openThrows
     *
     * @dataProvider inputKindProvider
     */
    public function testInputKindsAreDetectedAndOpenedConsistently(
        \Closure $write,
        OfficeFileFormat $expectedFormat,
        ?string $openThrows,
    ): void {
        $write($this);

        self::assertSame($expectedFormat, OfficeFileDetector::detect($this->filename));

        if ($openThrows === null) {
            self::assertFalse(OpenXmlPackage::open($this->filename)->hasChanges());

            return;
        }

        $this->expectException($openThrows);
        OpenXmlPackage::open($this->filename);
    }

    /** @return iterable<string, array{\Closure(self): void, OfficeFileFormat, null|class-string<\Throwable>}> */
    public static function inputKindProvider(): iterable
    {
        yield 'minimal package' => [
            static fn(self $test) => $test->writeZip([self::CONTENT_TYPES_ENTRY => self::CONTENT_TYPES_XML]),
            OfficeFileFormat::OpcPackage,
            null,
        ];

        yield 'package with a main document part' => [
            static fn(self $test) => $test->writePresentation(),
            OfficeFileFormat::OpcPackage,
            null,
        ];

        yield 'archive without content types' => [
            static fn(self $test) => $test->writeZip(['readme.txt' => 'plain archive']),
            OfficeFileFormat::Unknown,
            UnsupportedFileFormatException::class,
        ];

        yield 'OpenDocument archive' => [
            static fn(self $test) => $test->writeZip([
                'mimetype' => 'application/vnd.oasis.opendocument.presentation',
                'META-INF/manifest.xml' => '<manifest:manifest/>',
                'content.xml' => '<office:document-content/>',
            ]),
            OfficeFileFormat::Unknown,
            UnsupportedFileFormatException::class,
        ];

        // ZipArchive writes nothing for an archive with no entries, so this is the
        // bare end-of-central-directory record a valid empty ZIP consists of.
        yield 'empty archive' => [
            static fn(self $test) => $test->writeBytes("PK\x05\x06" . str_repeat("\0", 18)),
            OfficeFileFormat::Unknown,
            UnsupportedFileFormatException::class,
        ];

        yield 'empty file' => [
            static fn(self $test) => $test->writeBytes(''),
            OfficeFileFormat::Unknown,
            UnsupportedFileFormatException::class,
        ];

        yield 'arbitrary bytes' => [
            static fn(self $test) => $test->writeBytes('not an Office file at all'),
            OfficeFileFormat::Unknown,
            UnsupportedFileFormatException::class,
        ];

        yield 'truncated archive' => [
            static fn(self $test) => $test->writeBytes("PK\x03\x04 truncated beyond repair"),
            OfficeFileFormat::Unknown,
            OpenXmlException::class,
        ];

        yield 'compound file' => [
            static fn(self $test) => CompoundFileWriter::create()
                ->setStreamContents('LegacyDocument', 'legacy')
                ->save($test->filename),
            OfficeFileFormat::CompoundFile,
            UnsupportedFileFormatException::class,
        ];

        yield 'encrypted package' => [
            static fn(self $test) => $test->writeEncryptedContainer(),
            OfficeFileFormat::EncryptedOpcPackage,
            EncryptedPackageException::class,
        ];
    }

    public function testExpectedTypeIsCheckedAcrossPackageKinds(): void
    {
        $this->writePresentation();

        self::assertSame(
            '/ppt/presentation.xml',
            OpenXmlPackage::open($this->filename, expecting: self::PRESENTATION_CONTENT_TYPE)
                ->getMainDocumentPart()?->getName(),
        );

        $this->writeZip([self::CONTENT_TYPES_ENTRY => self::CONTENT_TYPES_XML]);

        $this->expectException(UnsupportedFileFormatException::class);
        $this->expectExceptionMessage('declares no main document part');
        OpenXmlPackage::open($this->filename, expecting: self::PRESENTATION_CONTENT_TYPE);
    }

    public function testExpectedTypeRejectsAMainDocumentPartThatIsMissing(): void
    {
        $this->writeZip([
            self::CONTENT_TYPES_ENTRY => '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
                . '<Override PartName="/ppt/presentation.xml" ContentType="' . self::PRESENTATION_CONTENT_TYPE . '"/>'
                . '</Types>',
            '_rels/.rels' => '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                . '<Relationship Id="rId1" Type="' . RelationshipType::OFFICE_DOCUMENT . '" Target="ppt/presentation.xml"/>'
                . '</Relationships>',
        ]);

        $this->expectException(UnsupportedFileFormatException::class);
        $this->expectExceptionMessage('"/ppt/presentation.xml" is missing from the package');
        OpenXmlPackage::open($this->filename, expecting: self::PRESENTATION_CONTENT_TYPE);
    }

    public function testExpectedTypeAcceptsAMainDocumentPartStoredWithADifferentCase(): void
    {
        $this->writeZip([
            self::CONTENT_TYPES_ENTRY => '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
                . '<Override PartName="/PPT/Presentation.xml" ContentType="' . self::PRESENTATION_CONTENT_TYPE . '"/>'
                . '</Types>',
            '_rels/.rels' => '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                . '<Relationship Id="rId1" Type="' . RelationshipType::OFFICE_DOCUMENT . '" Target="ppt/presentation.xml"/>'
                . '</Relationships>',
            'PPT/Presentation.xml' => '<presentation/>',
        ]);

        self::assertFalse(
            OpenXmlPackage::open($this->filename, expecting: self::PRESENTATION_CONTENT_TYPE)->hasChanges(),
        );
    }

    public function testExpectedTypeRejectsAMainDocumentPartWithoutADeclaredContentType(): void
    {
        $this->writeZip([
            self::CONTENT_TYPES_ENTRY => self::CONTENT_TYPES_XML,
            '_rels/.rels' => '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                . '<Relationship Id="rId1" Type="' . RelationshipType::OFFICE_DOCUMENT . '" Target="ppt/presentation.bin"/>'
                . '</Relationships>',
            'ppt/presentation.bin' => 'binary',
        ]);

        $this->expectException(UnsupportedFileFormatException::class);
        $this->expectExceptionMessage('has no declared content type');
        OpenXmlPackage::open($this->filename, expecting: self::PRESENTATION_CONTENT_TYPE);
    }

    public function writePresentation(): void
    {
        $this->writeZip([
            self::CONTENT_TYPES_ENTRY => '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
                . '<Override PartName="/ppt/presentation.xml" ContentType="' . self::PRESENTATION_CONTENT_TYPE . '"/>'
                . '</Types>',
            '_rels/.rels' => '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                . '<Relationship Id="rId1" Type="' . RelationshipType::OFFICE_DOCUMENT . '" Target="ppt/presentation.xml"/>'
                . '</Relationships>',
            'ppt/presentation.xml' => '<presentation/>',
        ]);
    }

    /** @param array<string, string> $entries */
    public function writeZip(array $entries): void
    {
        $archive = new \ZipArchive();
        self::assertTrue($archive->open($this->filename, \ZipArchive::OVERWRITE) === true);

        foreach ($entries as $name => $contents) {
            self::assertTrue($archive->addFromString($name, $contents));
        }

        self::assertTrue($archive->close());
    }

    public function writeBytes(string $contents): void
    {
        self::assertNotFalse(file_put_contents($this->filename, $contents));
    }

    public function writeEncryptedContainer(): void
    {
        CompoundFileWriter::create()
            ->setStreamContents('EncryptionInfo', str_repeat("\0", 8))
            ->setStreamContents('EncryptedPackage', str_repeat("\0", 9))
            ->save($this->filename);
    }
}
