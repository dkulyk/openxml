<?php

declare(strict_types=1);

namespace DK\OpenXml\Tests;

use DK\OpenXml\OpenXmlPackage;
use DK\OpenXml\Repair\PackageRepairOptions;
use DK\OpenXml\Repair\RepairAction;
use PHPUnit\Framework\TestCase;

final class PackageRepairTest extends TestCase
{
    private string $filename;

    protected function setUp(): void
    {
        $filename = tempnam(sys_get_temp_dir(), 'openxml-repair-');
        self::assertNotFalse($filename);
        $this->filename = $filename;
    }

    protected function tearDown(): void
    {
        if (is_file($this->filename)) {
            unlink($this->filename);
        }
    }

    public function testRepairsAreAnalyzedBeforeTheyAreExplicitlyApplied(): void
    {
        $this->writeRepairablePackage();
        $package = OpenXmlPackage::open($this->filename);
        $options = new PackageRepairOptions(
            removeDanglingRelationships: true,
            removeInvalidRelationships: true,
            removeOrphanRelationshipParts: true,
            removeStaleContentTypeOverrides: true,
            correctRelationshipContentTypes: true,
        );

        $analysis = $package->analyzeRepairs($options);

        self::assertCount(5, $analysis);
        self::assertFalse($package->hasChanges());
        self::assertNotSame([], $package->validate());
        self::assertSame([
            RepairAction::CORRECT_RELATIONSHIP_CONTENT_TYPE,
            RepairAction::REMOVE_ORPHAN_RELATIONSHIP_PART,
            RepairAction::REMOVE_STALE_CONTENT_TYPE_OVERRIDE,
            RepairAction::REMOVE_DANGLING_RELATIONSHIP,
            RepairAction::REMOVE_INVALID_RELATIONSHIP,
        ], array_map(
            static fn(RepairAction $action): string => $action->type,
            $analysis->getActions(),
        ));

        $applied = $package->applyRepairs($options);

        self::assertCount(5, $applied);
        self::assertTrue($package->hasChanges());
        self::assertSame([], $package->validate());
        self::assertTrue($package->analyzeRepairs($options)->isEmpty());

        $package->save();
        self::assertSame([], OpenXmlPackage::open($this->filename)->validate());
    }

    public function testOnlySelectedRepairsAreApplied(): void
    {
        $this->writeRepairablePackage();
        $package = OpenXmlPackage::open($this->filename);

        $report = $package->applyRepairs(new PackageRepairOptions(
            removeStaleContentTypeOverrides: true,
        ));

        self::assertCount(1, $report);
        self::assertSame(RepairAction::REMOVE_STALE_CONTENT_TYPE_OVERRIDE, $report->getActions()[0]->type);
        self::assertNotSame([], $package->validate());
    }

    private function writeRepairablePackage(): void
    {
        $archive = new \ZipArchive();
        self::assertTrue($archive->open($this->filename, \ZipArchive::OVERWRITE));
        self::assertTrue($archive->addFromString(
            '[Content_Types].xml',
            '<?xml version="1.0" encoding="UTF-8"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Default Extension="rels" ContentType="application/xml"/>'
            . '<Override PartName="/missing-override.xml" ContentType="application/xml"/>'
            . '</Types>',
        ));
        self::assertTrue($archive->addFromString('document.xml', '<document/>'));
        self::assertTrue($archive->addFromString(
            '_rels/.rels',
            '<?xml version="1.0" encoding="UTF-8"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="urn:missing" Target="missing.xml"/>'
            . '<Relationship Id="rId2" Type="urn:invalid" Target="../outside.xml"/>'
            . '<Relationship Id="rId3" Type="urn:external" Target="https://example.com" TargetMode="External"/>'
            . '</Relationships>',
        ));
        self::assertTrue($archive->addFromString(
            '_rels/ghost.xml.rels',
            '<?xml version="1.0" encoding="UTF-8"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"/>',
        ));
        self::assertTrue($archive->close());
    }
}
