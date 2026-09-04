<?php

declare(strict_types=1);

namespace DK\OpenXml\Tests;

use DK\OpenXml\Exception\OpenXmlException;
use DK\OpenXml\Packaging\ContentTypes;
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

    /** @dataProvider validPartNameProvider */
    public function testValidOpcPartNamesAreAccepted(string $name): void
    {
        self::assertSame($name, PartName::normalize($name));
    }

    /** @return iterable<string, array{string}> */
    public static function validPartNameProvider(): iterable
    {
        yield 'ordinary ASCII' => ['/word/document.xml'];
        yield 'raw Unicode' => ['/дані/é.xml'];
        yield 'encoded space' => ['/custom/a%20b.xml'];
        yield 'encoded Unicode' => ['/word/%C3%A9.xml'];
        yield 'reserved segment characters' => ["/custom/a;b@c!$&'()+,=.xml"];
    }

    /** @dataProvider invalidPartNameProvider */
    public function testInvalidOpcPartNamesAreRejected(string $name): void
    {
        $this->expectException(OpenXmlException::class);
        PartName::normalize($name);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidPartNameProvider(): iterable
    {
        yield 'not absolute' => ['word/document.xml'];
        yield 'package root' => ['/'];
        yield 'trailing slash' => ['/word/'];
        yield 'empty segment' => ['/word//document.xml'];
        yield 'dot segment' => ['/word/./document.xml'];
        yield 'parent segment' => ['/word/../document.xml'];
        yield 'segment ending in dot' => ['/word/document.'];
        yield 'raw space' => ['/word/my document.xml'];
        yield 'raw bracket' => ['/word/[document].xml'];
        yield 'malformed percent encoding' => ['/word/%2.xml'];
        yield 'encoded slash' => ['/word%2Fdocument.xml'];
        yield 'encoded backslash' => ['/word%5cdocument.xml'];
        yield 'encoded unreserved ASCII' => ['/word/%41.xml'];
        yield 'query' => ['/word/document.xml?x'];
        yield 'fragment' => ['/word/document.xml#x'];
    }

    public function testPartNameConflictsFollowOpcEquivalenceAndDerivationRules(): void
    {
        self::assertTrue(PartName::equivalent('/Word/Document.xml', '/word/document.XML'));
        self::assertTrue(PartName::conflicts('/a', '/A/b.xml'));
        self::assertTrue(PartName::conflicts('/a/b.xml', '/A/B.XML'));
        self::assertFalse(PartName::conflicts('/a/b.xml', '/a/c.xml'));
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

    public function testSerializedAttributesAreEscaped(): void
    {
        $items = new Relationships();
        $items->add(new Relationship('rId1', 'urn:t', 'a<b&c"d'));
        $items->add(new Relationship('rId2', 'urn:t', 'https://example.com/?a=1&b=2', true));
        $xml = $items->toXml();

        self::assertStringContainsString('Target="a&lt;b&amp;c&quot;d"', $xml);
        self::assertStringContainsString('Target="https://example.com/?a=1&amp;b=2" TargetMode="External"', $xml);

        $copy = Relationships::fromXml($xml);
        self::assertSame('a<b&c"d', $copy->get('rId1')->getTarget());
        self::assertSame('https://example.com/?a=1&b=2', $copy->get('rId2')->getTarget());
    }

    public function testEmptyCollectionSerializesToAnEmptyRoot(): void
    {
        self::assertCount(0, Relationships::fromXml((new Relationships())->toXml()));
    }

    public function testContentTypesAreSortedInTheXmlWithoutReorderingTheirOwner(): void
    {
        $types = new ContentTypes();
        $types->setOverride('/zeta.xml', 'app/z+xml');
        $types->setOverride('/alpha.xml', 'app/a+xml');
        $xml = $types->toXml();

        self::assertLessThan(strpos($xml, '/zeta.xml'), strpos($xml, '/alpha.xml'));
        // Serializing is a read: it used to sort the collection's own arrays.
        self::assertSame(['/zeta.xml', '/alpha.xml'], array_keys($types->getOverrides()));
    }

    public function testGeneratedIdsFillFirstGap(): void
    {
        $items = new Relationships();
        $items->create('urn:a', 'a.xml', false, 'rId1');
        $items->create('urn:c', 'c.xml', false, 'rId3');
        self::assertSame('rId2', $items->create('urn:b', 'b.xml')->getId());
    }

    public function testRelationshipCanBeRetargeted(): void
    {
        $items = new Relationships();
        $items->create('urn:a', 'old.xml', false, 'rId1');

        $replacement = $items->retarget('rId1', 'new.xml');

        self::assertSame('new.xml', $replacement->getTarget());
        self::assertSame('new.xml', $items->get('rId1')->getTarget());
    }

    public function testRelationshipsCanBeFoundByRawAndResolvedTarget(): void
    {
        $items = new Relationships(sourcePartName: '/word/document.xml');
        $items->create('urn:image', 'media/image.png', false, 'rId1');
        $items->create('urn:image', '/word/media/image.png', false, 'rId2');
        $items->create('urn:link', 'https://example.com', true, 'rId3');

        self::assertSame('rId1', $items->firstByTarget('media/image.png')?->getId());
        self::assertCount(1, $items->getByTarget('/word/media/image.png'));
        self::assertCount(2, $items->getByTargetPart('/word/media/image.png'));
        self::assertSame([], $items->getByTargetPart('/example.com'));
    }

    public function testRelationshipsToResolvedPartCanBeRemovedTogether(): void
    {
        $items = new Relationships(sourcePartName: '/word/document.xml');
        $items->create('urn:image', 'media/image.png', false, 'rId1');
        $items->create('urn:image', '/word/media/image.png', false, 'rId2');
        $items->create('urn:other', 'styles.xml', false, 'rId3');

        self::assertSame(2, $items->removeByTargetPart('/word/media/image.png'));
        self::assertCount(1, $items);
        self::assertSame('rId3', $items->get('rId3')->getId());
        self::assertSame(0, $items->removeByTargetPart('/word/media/image.png'));
    }

    public function testRetargetRejectsInvalidInternalTargetWithoutChangingRelationship(): void
    {
        $items = new Relationships(sourcePartName: '/word/document.xml');
        $items->create('urn:a', 'old.xml', false, 'rId1');

        try {
            $items->retarget('rId1', '../../outside.xml');
            self::fail('An invalid internal target was expected to fail.');
        } catch (OpenXmlException) {
            self::assertSame('old.xml', $items->get('rId1')->getTarget());
        }
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
        yield 'current-directory target' => ['/word/document.xml', './styles.xml', '/word/styles.xml'];
    }

    /** @dataProvider invalidInternalTargetProvider */
    public function testInvalidInternalRelationshipTargetsAreRejected(string $target): void
    {
        $this->expectException(OpenXmlException::class);
        PartName::resolveTarget('/word/document.xml', $target);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidInternalTargetProvider(): iterable
    {
        yield 'escapes root' => ['../../outside.xml'];
        yield 'URI scheme' => ['https://example.com/file.xml'];
        yield 'empty path segment' => ['media//image.png'];
        yield 'invalid resolved part name' => ['media/image.'];
        yield 'encoded slash alias' => ['media%2Fimage.png'];
    }
}
