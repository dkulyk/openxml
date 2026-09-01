<?php

declare(strict_types=1);

namespace DK\OpenXml\Tests;

use DK\OpenXml\Exception\OpenXmlException;
use DK\OpenXml\Packaging\PartName;
use DK\OpenXml\Packaging\Relationship;
use DK\OpenXml\Packaging\Relationships;
use PHPUnit\Framework\TestCase;

final class RelationshipsTest extends TestCase
{
    public function testPartUriOperationsUseOpcSeparators(): void
    {
        self::assertSame('/word/_rels/document.xml.rels', PartName::relationshipsName('/word/document.xml'));
        self::assertSame('/_rels/document.xml.rels', PartName::relationshipsName('/document.xml'));
        self::assertSame('/word/media/image.png', PartName::resolveTarget('/word/document.xml', 'media/image.png'));
        self::assertSame('/styles.xml', PartName::resolveTarget('/document.xml', 'styles.xml'));
        self::assertNull(PartName::relationshipSourceName('/_rels/.rels'));
        self::assertSame('/word/document.xml', PartName::relationshipSourceName('/word/_rels/document.xml.rels'));
        self::assertSame('/document.xml', PartName::relationshipSourceName('/_rels/document.xml.rels'));
        self::assertSame('media/image.png', PartName::relativeTarget('/word/document.xml', '/word/media/image.png'));
        self::assertSame('../styles.xml', PartName::relativeTarget('/word/nested/document.xml', '/word/styles.xml'));
        self::assertSame('word/document.xml', PartName::relativeTarget(null, '/word/document.xml'));
    }

    public function testRelationshipSourceRejectsAnOrdinaryPart(): void
    {
        $this->expectException(OpenXmlException::class);
        PartName::relationshipSourceName('/word/document.xml');
    }

    public function testRoundTripAndLookup(): void
    {
        $items = new Relationships();
        $items->add(new Relationship('rId1', 'urn:office-document', 'word/document.xml'));
        $items->add(new Relationship('rId2', 'urn:hyperlink', 'https://example.com', true));
        $copy = Relationships::fromXml($items->toXml());
        self::assertCount(2, $copy);
        self::assertSame('word/document.xml', $copy->firstByType('urn:office-document')?->getTarget());
        self::assertTrue($copy->get('rId2')->isExternal());
    }

    public function testGeneratedIdsFillFirstGap(): void
    {
        $items = new Relationships();
        $items->create('urn:a', 'a.xml', false, 'rId1');
        $items->create('urn:c', 'c.xml', false, 'rId3');
        self::assertSame('rId2', $items->create('urn:b', 'b.xml')->getId());
    }

    public function testRelationshipTargetCanBeReplaced(): void
    {
        $items = new Relationships();
        $items->create('urn:a', 'old.xml', false, 'rId1');

        $replacement = $items->replaceTarget('rId1', 'new.xml');

        self::assertSame('new.xml', $replacement->getTarget());
        self::assertSame('new.xml', $items->get('rId1')->getTarget());
    }

    public function testDuplicateIdIsRejected(): void
    {
        $items = new Relationships();
        $items->create('urn:a', 'a.xml', false, 'rId1');
        $this->expectException(OpenXmlException::class);
        $items->create('urn:b', 'b.xml', false, 'rId1');
    }

    /** @dataProvider targetProvider */
    public function testTargetResolution(?string $source, string $target, string $expected): void
    {
        self::assertSame($expected, PartName::resolveTarget($source, $target));
    }

    /** @return iterable<string, array{?string, string, string}> */
    public static function targetProvider(): iterable
    {
        yield 'package target' => [null, 'word/document.xml', '/word/document.xml'];
        yield 'sibling target' => ['/word/document.xml', 'styles.xml', '/word/styles.xml'];
        yield 'parent target' => ['/word/header/header1.xml', '../media/image.png', '/word/media/image.png'];
        yield 'absolute target' => ['/word/document.xml', '/docProps/core.xml', '/docProps/core.xml'];
    }
}
