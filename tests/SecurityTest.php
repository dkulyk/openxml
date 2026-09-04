<?php

declare(strict_types=1);

namespace DK\OpenXml\Tests;

use DK\OpenXml\Exception\OpenXmlException;
use DK\OpenXml\Exception\PackageLimitException;
use DK\OpenXml\Exception\PackageValidationException;
use DK\OpenXml\Exception\UnsupportedFileFormatException;
use DK\OpenXml\OfficeFileDetector;
use DK\OpenXml\OfficeFileFormat;
use DK\OpenXml\OpenXmlPackage;
use DK\OpenXml\Packaging\ContentTypes;
use DK\OpenXml\Packaging\PartName;
use DK\OpenXml\Packaging\Relationships;
use DK\OpenXml\Security\PackageLimits;
use PHPUnit\Framework\TestCase;

final class SecurityTest extends TestCase
{
    private string $filename;

    protected function setUp(): void
    {
        $filename = tempnam(sys_get_temp_dir(), 'openxml-security-');
        self::assertNotFalse($filename);
        $this->filename = $filename;
    }

    protected function tearDown(): void
    {
        if (is_file($this->filename)) {
            unlink($this->filename);
        }
    }

    public function testDtdIsRejectedBeforeParsing(): void
    {
        $xml = <<<'XML'
            <?xml version="1.0"?>
            <!DOCTYPE Types [<!ENTITY payload SYSTEM "file:///etc/passwd">]>
            <Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>
            XML;

        $this->expectException(OpenXmlException::class);
        $this->expectExceptionMessage('DTD declarations are not allowed');
        ContentTypes::fromXml($xml);
    }

    public function testUnexpectedXmlRootIsRejected(): void
    {
        $xml = '<Wrong xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>';

        $this->expectException(OpenXmlException::class);
        $this->expectExceptionMessage('Expected');
        ContentTypes::fromXml($xml);
    }

    public function testDuplicateContentTypeDeclarationsAreRejected(): void
    {
        $xml = <<<'XML'
            <Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
                <Default Extension="xml" ContentType="application/xml"/>
                <Default Extension="XML" ContentType="application/xml"/>
            </Types>
            XML;

        $this->expectException(OpenXmlException::class);
        $this->expectExceptionMessage('Duplicate content type default');
        ContentTypes::fromXml($xml);
    }

    public function testUnknownRelationshipTargetModeIsRejected(): void
    {
        $xml = <<<'XML'
            <Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
                <Relationship Id="rId1" Type="urn:test" Target="part.xml" TargetMode="Unknown"/>
            </Relationships>
            XML;

        $this->expectException(OpenXmlException::class);
        $this->expectExceptionMessage('TargetMode');
        Relationships::fromXml($xml);
    }

    public function testPartSizeLimitAppliesBeforeWriting(): void
    {
        $limits = new PackageLimits(maximumPartBytes: 512);
        $package = OpenXmlPackage::create($limits);

        $this->expectException(PackageLimitException::class);
        $package->addPart('/large.bin', 'application/octet-stream', str_repeat('x', 513));
    }

    public function testPackageLimitsReleaseReplacedAndRemovedParts(): void
    {
        $package = OpenXmlPackage::create(new PackageLimits(maximumPackageBytes: 100_000, maximumEntries: 3));
        $package->addPart('/first.bin', 'application/octet-stream', str_repeat('a', 60_000));

        try {
            $package->addPart('/second.bin', 'application/octet-stream', str_repeat('b', 60_000));
            self::fail('The second part should exceed the package byte limit.');
        } catch (PackageLimitException) {
        }

        $package->getPart('/first.bin')->setContents('small');
        $package->addPart('/second.bin', 'application/octet-stream', str_repeat('b', 60_000));

        try {
            $package->addPart('/third.bin', 'application/octet-stream', 'c');
            self::fail('The third part should exceed the entry limit.');
        } catch (PackageLimitException) {
        }

        $package->removePart('/second.bin');
        $package->addPart('/third.bin', 'application/octet-stream', str_repeat('c', 60_000));

        self::assertSame(['/first.bin', '/third.bin'], array_map(
            static fn($part) => $part->getName(),
            iterator_to_array($package->getParts(), false),
        ));
    }

    public function testZipArchiveWithoutContentTypesIsNotAnOpcPackage(): void
    {
        $this->writeZip(['readme.txt' => 'not a package']);

        self::assertSame(OfficeFileFormat::Unknown, OfficeFileDetector::detect($this->filename));

        $this->expectException(UnsupportedFileFormatException::class);
        OpenXmlPackage::open($this->filename);
    }

    public function testOpenDocumentArchiveIsNotAnOpcPackage(): void
    {
        $this->writeZip([
            'mimetype' => 'application/vnd.oasis.opendocument.presentation',
            'META-INF/manifest.xml' => '<manifest:manifest/>',
            'content.xml' => '<office:document-content/>',
        ]);

        self::assertSame(OfficeFileFormat::Unknown, OfficeFileDetector::detect($this->filename));

        $this->expectException(UnsupportedFileFormatException::class);
        OpenXmlPackage::open($this->filename);
    }

    public function testUnsafeZipEntryNameIsRejected(): void
    {
        $this->writeZip(['../outside.xml' => '<outside/>']);

        $this->expectException(OpenXmlException::class);
        $this->expectExceptionMessage('Unsafe ZIP entry name');
        OpenXmlPackage::open($this->filename);
    }

    /** @dataProvider conflictingZipPartNamesProvider */
    public function testConflictingOpcPartNamesInZipAreRejected(string $first, string $second): void
    {
        $this->writeZip([
            '[Content_Types].xml' => '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>',
            $first => 'first',
            $second => 'second',
        ]);

        $this->expectException(OpenXmlException::class);
        OpenXmlPackage::open($this->filename);
    }

    /** @return iterable<string, array{string, string}> */
    public static function conflictingZipPartNamesProvider(): iterable
    {
        yield 'ASCII case-equivalent' => ['word/document.xml', 'WORD/DOCUMENT.XML'];
        yield 'derived prefix' => ['custom/data.xml', 'custom/data.xml/child'];
    }

    public function testInvalidOpcPartNameInZipIsRejected(): void
    {
        $this->writeZip([
            '[Content_Types].xml' => '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>',
            'word/document.' => 'invalid',
        ]);

        $this->expectException(OpenXmlException::class);
        $this->expectExceptionMessage('Invalid OPC part name');
        OpenXmlPackage::open($this->filename);
    }

    public function testValidatedPartNamesDoNotVouchForTheirVariants(): void
    {
        self::assertSame('/word/document.xml', PartName::normalize('/word/document.xml'));
        self::assertSame('/word/document.xml', PartName::normalize('/word/document.xml'));

        $this->expectException(OpenXmlException::class);
        $this->expectExceptionMessage('Invalid OPC part name');
        PartName::normalize('/word/document.xml/');
    }

    public function testValidationStaysCorrectPastTheMemoisationBound(): void
    {
        // Read the bound rather than restating it, so the test keeps exercising
        // eviction if the bound moves.
        $bound = (new \ReflectionClassConstant(PartName::class, 'CACHE_LIMIT'))->getValue();
        self::assertIsInt($bound);
        $names = $bound + 1;
        for ($index = 0; $index < $names; ++$index) {
            PartName::normalize('/word/media/image' . $index . '.png');
        }

        // The first name has been evicted by now and the last one is still cached.
        self::assertSame('/word/media/image0.png', PartName::normalize('/word/media/image0.png'));
        self::assertSame(
            '/word/media/image' . ($names - 1) . '.png',
            PartName::normalize('/word/media/image' . ($names - 1) . '.png'),
        );

        $this->expectException(OpenXmlException::class);
        $this->expectExceptionMessage('Invalid OPC part name');
        PartName::normalize('/word/media/image0.png/');
    }

    public function testSuspiciousCompressionRatioIsRejectedBeforeExtraction(): void
    {
        $this->writeZip(['large.txt' => str_repeat('A', 100_000)]);
        $limits = new PackageLimits(maximumCompressionRatio: 2.0);

        $this->expectException(PackageLimitException::class);
        $this->expectExceptionMessage('compression ratio');
        OpenXmlPackage::open($this->filename, $limits);
    }

    public function testDigitallySignedPackageCannotBeSaved(): void
    {
        $package = OpenXmlPackage::create();
        $package->addPart(
            '/_xmlsignatures/sig1.xml',
            'application/vnd.openxmlformats-package.digital-signature-xmlsignature+xml',
            '<Signature/>',
        );

        $this->expectException(PackageValidationException::class);
        $this->expectExceptionMessage('signature preservation is not supported');
        $package->saveAs($this->filename);
    }

    /** @param array<string, string> $entries */
    private function writeZip(array $entries): void
    {
        $archive = new \ZipArchive();
        self::assertTrue($archive->open($this->filename, \ZipArchive::OVERWRITE) === true);

        foreach ($entries as $name => $contents) {
            self::assertTrue($archive->addFromString($name, $contents));
        }

        self::assertTrue($archive->close());
    }
}
