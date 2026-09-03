<?php

declare(strict_types=1);

namespace DK\OpenXml\Tests;

use DK\OpenXml\Exception\OpenXmlException;
use DK\OpenXml\Exception\PackageLimitException;
use DK\OpenXml\Exception\PackageValidationException;
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
