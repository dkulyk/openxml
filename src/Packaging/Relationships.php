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
     * Where nextId() resumes scanning. Every lower rId number is taken. The last
     * issued number is deliberately rechecked so a failed add() can reuse it.
     * Reset to 1 whenever an id is removed.
     */
    private int $nextIdNumber = 1;

    /** @var null|\WeakReference<OpenXmlPackage> */
    private ?\WeakReference $package;

    /**
     * @param null|\Closure(self): void $onChange
     */
    public function __construct(
        ?OpenXmlPackage $package = null,
        private ?string $sourcePartName = null,
        private ?\Closure $onChange = null,
    ) {
        $this->package = $package === null ? null : \WeakReference::create($package);
    }

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
            $this->package(),
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
                && PartName::equivalent((string) $relationship->getTargetPartName(), $partName),
        );

        return array_values($matching);
    }

    public function remove(string $id): void
    {
        if (!isset($this->relationships[$id])) {
            throw new OpenXmlException(sprintf('Relationship "%s" does not exist.', $id));
        }

        unset($this->relationships[$id]);
        $this->nextIdNumber = 1;
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
            $this->package(),
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
            $this->nextIdNumber = 1;
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
        $body = '';
        foreach ($this->relationships as $relationship) {
            $body .= sprintf(
                "  <Relationship Id=\"%s\" Type=\"%s\" Target=\"%s\"%s/>\n",
                XmlDocument::attributeValue($relationship->getId(), 'Id'),
                XmlDocument::attributeValue($relationship->getType(), 'Type'),
                XmlDocument::attributeValue($relationship->getTarget(), 'Target'),
                $relationship->isExternal() ? ' TargetMode="External"' : '',
            );
        }

        return XmlDocument::serialize('Relationships', self::XML_NAMESPACE, $body);
    }

    private function nextId(): string
    {
        $number = $this->nextIdNumber;
        while (isset($this->relationships['rId' . $number])) {
            ++$number;
        }
        $this->nextIdNumber = $number;

        return 'rId' . $number;
    }

    private function package(): ?OpenXmlPackage
    {
        if ($this->package === null) {
            return null;
        }

        return $this->package->get()
            ?? throw new OpenXmlException('The package owning these relationships has been released.');
    }

    private function notifyChanged(): void
    {
        if ($this->onChange !== null) {
            ($this->onChange)($this);
        }
    }
}
