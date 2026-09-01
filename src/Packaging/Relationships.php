<?php

declare(strict_types=1);

namespace DK\OpenXml\Packaging;

use DK\OpenXml\Exception\OpenXmlException;
use DK\OpenXml\Internal\XmlDocument;
use DK\OpenXml\OpenXmlPackage;

/** @implements \IteratorAggregate<string, RelationshipInterface> */
final class Relationships implements \IteratorAggregate, \Countable
{
    public const CONTENT_TYPE = 'application/vnd.openxmlformats-package.relationships+xml';

    private const XML_NAMESPACE = 'http://schemas.openxmlformats.org/package/2006/relationships';

    /** @var array<string, RelationshipInterface> */
    private array $relationships = [];

    /**
     * @param null|\Closure(self): void $onChange
     */
    public function __construct(
        private ?OpenXmlPackage $package = null,
        private ?string $sourcePartName = null,
        private ?\Closure $onChange = null,
    ) {}

    /**
     * @param null|\Closure(self): void $onChange
     */
    public static function fromXml(
        string $xml,
        ?OpenXmlPackage $package = null,
        ?string $sourcePartName = null,
        ?\Closure $onChange = null,
        int $maximumXmlBytes = XmlDocument::DEFAULT_MAXIMUM_BYTES,
    ): self {
        $document = XmlDocument::load(
            $xml,
            'Relationships',
            self::XML_NAMESPACE,
            $maximumXmlBytes,
        );

        $collection = new self($package, $sourcePartName);
        $nodes = $document->getElementsByTagNameNS(self::XML_NAMESPACE, 'Relationship');

        foreach ($nodes as $node) {
            $targetMode = $node->getAttribute('TargetMode');
            if ($targetMode !== '' && strcasecmp($targetMode, 'External') !== 0) {
                throw new OpenXmlException(sprintf('Invalid relationship TargetMode "%s".', $targetMode));
            }

            $collection->add(new Relationship(
                $node->getAttribute('Id'),
                $node->getAttribute('Type'),
                $node->getAttribute('Target'),
                strcasecmp($targetMode, 'External') === 0,
                $package,
                $sourcePartName,
            ));
        }

        $collection->onChange = $onChange;

        return $collection;
    }

    public function create(
        string $type,
        string $target,
        bool $external = false,
        ?string $id = null,
    ): RelationshipInterface {
        $relationship = new Relationship(
            $id ?? $this->nextId(),
            $type,
            $target,
            $external,
            $this->package,
            $this->sourcePartName,
        );

        $this->add($relationship);

        return $relationship;
    }

    public function add(RelationshipInterface $relationship): void
    {
        $id = $relationship->getId();
        if ($id === '' || isset($this->relationships[$id])) {
            throw new OpenXmlException(sprintf('Duplicate or empty relationship id "%s".', $id));
        }

        if ($relationship->getType() === '' || $relationship->getTarget() === '') {
            throw new OpenXmlException('Relationship type and target must not be empty.');
        }

        $this->relationships[$id] = $relationship;
        $this->notifyChanged();
    }

    public function get(string $id): RelationshipInterface
    {
        return $this->relationships[$id]
            ?? throw new OpenXmlException(sprintf('Relationship "%s" does not exist.', $id));
    }

    public function firstByType(string $type): ?RelationshipInterface
    {
        foreach ($this->relationships as $relationship) {
            if ($relationship->getType() === $type) {
                return $relationship;
            }
        }

        return null;
    }

    /** @return list<RelationshipInterface> */
    public function getByType(string $type): array
    {
        $matching = array_filter(
            $this->relationships,
            static fn(RelationshipInterface $relationship): bool => $relationship->getType() === $type,
        );

        return array_values($matching);
    }

    public function firstByTarget(string $target): ?RelationshipInterface
    {
        foreach ($this->relationships as $relationship) {
            if ($relationship->getTarget() === $target) {
                return $relationship;
            }
        }

        return null;
    }

    /** @return list<RelationshipInterface> */
    public function getByTarget(string $target): array
    {
        $matching = array_filter(
            $this->relationships,
            static fn(RelationshipInterface $relationship): bool => $relationship->getTarget() === $target,
        );

        return array_values($matching);
    }

    /** @return list<RelationshipInterface> */
    public function getByTargetPart(string $partName): array
    {
        $partName = PartName::normalize($partName);
        $matching = array_filter(
            $this->relationships,
            static fn(RelationshipInterface $relationship): bool => !$relationship->isExternal()
                && $relationship->getTargetPartName() === $partName,
        );

        return array_values($matching);
    }

    public function remove(string $id): void
    {
        if (!isset($this->relationships[$id])) {
            throw new OpenXmlException(sprintf('Relationship "%s" does not exist.', $id));
        }

        unset($this->relationships[$id]);
        $this->notifyChanged();
    }

    public function retarget(string $id, string $target): RelationshipInterface
    {
        $current = $this->get($id);
        if ($target === '') {
            throw new OpenXmlException('Relationship target must not be empty.');
        }
        if (!$current->isExternal()) {
            PartName::resolveTarget($this->sourcePartName, $target);
        }

        $replacement = new Relationship(
            $current->getId(),
            $current->getType(),
            $target,
            $current->isExternal(),
            $this->package,
            $this->sourcePartName,
        );

        $this->relationships[$id] = $replacement;
        $this->notifyChanged();

        return $replacement;
    }

    public function removeByTargetPart(string $partName): int
    {
        $matching = $this->getByTargetPart($partName);
        foreach ($matching as $relationship) {
            unset($this->relationships[$relationship->getId()]);
        }

        if ($matching !== []) {
            $this->notifyChanged();
        }

        return count($matching);
    }

    public function getIterator(): \Traversable
    {
        yield from $this->relationships;
    }

    public function count(): int
    {
        return count($this->relationships);
    }

    public function toXml(): string
    {
        $document = new \DOMDocument('1.0', 'UTF-8');
        $document->formatOutput = true;

        $root = $document->createElementNS(self::XML_NAMESPACE, 'Relationships');
        $document->appendChild($root);

        foreach ($this->relationships as $relationship) {
            $node = $document->createElementNS(self::XML_NAMESPACE, 'Relationship');
            $node->setAttribute('Id', $relationship->getId());
            $node->setAttribute('Type', $relationship->getType());
            $node->setAttribute('Target', $relationship->getTarget());

            if ($relationship->isExternal()) {
                $node->setAttribute('TargetMode', 'External');
            }

            $root->appendChild($node);
        }

        return (string) $document->saveXML();
    }

    private function nextId(): string
    {
        $number = 1;
        while (isset($this->relationships['rId' . $number])) {
            ++$number;
        }

        return 'rId' . $number;
    }

    private function notifyChanged(): void
    {
        if ($this->onChange !== null) {
            ($this->onChange)($this);
        }
    }
}
