<?php

declare(strict_types=1);

namespace DK\OpenXml\Tests;

use DK\OpenXml\Exception\ConcurrentModificationException;
use DK\OpenXml\Exception\OpenXmlException;
use DK\OpenXml\Exception\PackageValidationException;
use DK\OpenXml\Exception\PartNotFoundException;
use DK\OpenXml\OpenXmlPackage;
use DK\OpenXml\Packaging\RelationshipType;
use PHPUnit\Framework\TestCase;

final class OpenXmlPackageTest extends TestCase
{
    private string $filename;

    protected function setUp(): void
    {
        $filename = tempnam(sys_get_temp_dir(), 'openxml-');
        self::assertNotFalse($filename);

        $this->filename = $filename;
    }

    protected function tearDown(): void
    {
        if (is_file($this->filename)) {
            unlink($this->filename);
        }
    }

    public function testPackageAndPartRelationshipsSurviveRoundTrip(): void
    {
        $package = OpenXmlPackage::create();
        $document = $package->addPart('/word/document.xml', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml', '<document/>');
        $package->addPart('/word/styles.xml', 'application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml', '<styles/>');
        $package->addRelationship(RelationshipType::OFFICE_DOCUMENT, 'word/document.xml');
        $document->addRelationship('urn:styles', 'styles.xml');
        $document->addRelationship(RelationshipType::HYPERLINK, 'https://example.com', true);
        self::assertSame([], $package->validate());
        $package->saveAs($this->filename);

        $reopened = OpenXmlPackage::open($this->filename);
        $officeDocument = $reopened->getRelationships()->firstByType(RelationshipType::OFFICE_DOCUMENT);
        self::assertNotNull($officeDocument);
        self::assertSame('/word/document.xml', $officeDocument->getTargetPartName());

        $documentPart = $officeDocument->getTargetPart();
        self::assertNotNull($documentPart);
        self::assertSame('<document/>', $documentPart->getContents());

        $styles = $documentPart->getRelationships()->firstByType('urn:styles');
        self::assertNotNull($styles);
        self::assertSame('/word/styles.xml', $styles->getTargetPartName());

        $stylesPart = $styles->getTargetPart();
        self::assertNotNull($stylesPart);
        self::assertSame('<styles/>', $stylesPart->getContents());
        self::assertCount(2, iterator_to_array($reopened->getParts()));
    }

    public function testPartContentsCanBeUpdated(): void
    {
        $package = OpenXmlPackage::create();
        $part = $package->addPart('/doc.xml', 'application/xml', 'old');
        $part->setContents('new');
        self::assertSame('new', $package->getPart('/doc.xml')->getContents());
    }

    public function testRelationshipCollectionMutationsPersist(): void
    {
        $package = OpenXmlPackage::create();
        $package->addPart('/document.xml', 'application/xml', '<document/>');
        $relationships = $package->getRelationships();
        $relationships->create('urn:document', 'document.xml');
        self::assertCount(1, $package->getRelationships());
        $relationships->remove('rId1');
        self::assertCount(0, $package->getRelationships());
    }

    public function testRemovingPartAlsoRemovesItsRelationshipPart(): void
    {
        $package = OpenXmlPackage::create();
        $part = $package->addPart('/word/document.xml', 'application/xml', 'x');
        $part->addRelationship('urn:missing', 'styles.xml');
        $package->removePart('/word/document.xml');
        self::assertFalse($package->hasPart('/word/document.xml'));
        $this->expectException(PartNotFoundException::class);
        $package->getRelationships('/word/document.xml');
    }

    public function testValidationReportsDanglingInternalTargetButIgnoresExternalTarget(): void
    {
        $package = OpenXmlPackage::create();
        $package->addRelationship('urn:missing', 'missing.xml');
        $package->addRelationship(RelationshipType::HYPERLINK, 'https://example.com', true);
        self::assertCount(1, $package->validate());
        self::assertStringContainsString('/missing.xml', $package->validate()[0]);
    }

    public function testRelationshipPartsCannotBeAddedAsOrdinaryParts(): void
    {
        $this->expectException(OpenXmlException::class);
        OpenXmlPackage::create()->addPart('/_rels/.rels', 'application/xml', '<Relationships/>');
    }

    public function testMissingPartThrowsDomainException(): void
    {
        $this->expectException(PartNotFoundException::class);
        OpenXmlPackage::create()->getPart('/missing.xml');
    }

    public function testChangesCanBeSavedToTheOriginalFile(): void
    {
        $package = $this->createSavedPackage('<document/>');
        self::assertFalse($package->hasChanges());

        $package->getPart('/document.xml')->setContents('<updated/>');
        self::assertTrue($package->hasChanges());

        $package->save();

        self::assertFalse($package->hasChanges());
        self::assertSame(
            '<updated/>',
            OpenXmlPackage::open($this->filename)->getPart('/document.xml')->getContents(),
        );
    }

    public function testChangesCanBeDiscarded(): void
    {
        $package = $this->createSavedPackage('<original/>');
        $package->getPart('/document.xml')->setContents('<changed/>');

        $package->discardChanges();

        self::assertFalse($package->hasChanges());
        self::assertSame('<original/>', $package->getPart('/document.xml')->getContents());
    }

    public function testDiscardResetsANewPackage(): void
    {
        $package = OpenXmlPackage::create();
        $package->addPart('/temporary.xml', 'application/xml', 'temporary');

        $package->discardChanges();

        self::assertFalse($package->hasChanges());
        self::assertFalse($package->hasPart('/temporary.xml'));
    }

    public function testEditSavesOnlyAfterTheCallbackCompletes(): void
    {
        $this->createSavedPackage('<original/>');

        OpenXmlPackage::edit($this->filename, static function (OpenXmlPackage $package): void {
            $package->getPart('/document.xml')->setContents('<edited/>');
        });

        self::assertSame(
            '<edited/>',
            OpenXmlPackage::open($this->filename)->getPart('/document.xml')->getContents(),
        );
    }

    public function testEditLeavesTheOriginalUntouchedWhenTheCallbackFails(): void
    {
        $this->createSavedPackage('<original/>');

        try {
            OpenXmlPackage::edit($this->filename, static function (OpenXmlPackage $package): void {
                $package->getPart('/document.xml')->setContents('<never-saved/>');

                throw new \RuntimeException('Editing failed.');
            });
            self::fail('The edit callback was expected to fail.');
        } catch (\RuntimeException $exception) {
            self::assertSame('Editing failed.', $exception->getMessage());
        }

        self::assertSame(
            '<original/>',
            OpenXmlPackage::open($this->filename)->getPart('/document.xml')->getContents(),
        );
    }

    public function testValidationFailureLeavesTheOriginalUntouched(): void
    {
        $package = $this->createSavedPackage('<original/>');
        $package->addRelationship('urn:missing', 'missing.xml');

        try {
            $package->save();
            self::fail('Saving an invalid package was expected to fail.');
        } catch (PackageValidationException $exception) {
            self::assertCount(1, $exception->getIssues());
        }

        self::assertCount(0, OpenXmlPackage::open($this->filename)->getRelationships());
    }

    public function testConcurrentModificationIsRejected(): void
    {
        $firstEditor = $this->createSavedPackage('<original/>');
        $secondEditor = OpenXmlPackage::open($this->filename);

        $firstEditor->getPart('/document.xml')->setContents('<first/>');
        $firstEditor->save();

        $secondEditor->getPart('/document.xml')->setContents('<second/>');

        $this->expectException(ConcurrentModificationException::class);
        $secondEditor->save();
    }

    public function testNewPackageRequiresSaveAs(): void
    {
        $package = OpenXmlPackage::create();
        $package->addPart('/document.xml', 'application/xml', '<document/>');

        $this->expectException(OpenXmlException::class);
        $package->save();
    }

    private function createSavedPackage(string $contents): OpenXmlPackage
    {
        $package = OpenXmlPackage::create();
        $package->addPart('/document.xml', 'application/xml', $contents);
        $package->saveAs($this->filename);

        return $package;
    }
}
