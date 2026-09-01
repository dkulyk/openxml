<?php

declare(strict_types=1);

namespace DK\OpenXml;

use DK\OpenXml\Exception\ConcurrentModificationException;
use DK\OpenXml\Exception\OpenXmlException;
use DK\OpenXml\Exception\PackageValidationException;
use DK\OpenXml\Exception\PartNotFoundException;
use DK\OpenXml\Internal\AtomicFileWriter;
use DK\OpenXml\Internal\Container\ContainerInterface;
use DK\OpenXml\Internal\Container\ZipContainer;
use DK\OpenXml\Packaging\ContentTypes;
use DK\OpenXml\Packaging\PackageInterface;
use DK\OpenXml\Packaging\Part;
use DK\OpenXml\Packaging\PartInterface;
use DK\OpenXml\Packaging\PartName;
use DK\OpenXml\Packaging\RelationshipInterface;
use DK\OpenXml\Packaging\Relationships;
use DK\OpenXml\Security\PackageLimits;

final class OpenXmlPackage implements PackageInterface
{
    private function __construct(
        private ContainerInterface $container,
        private ContentTypes $contentTypes,
        private ?string $sourceFilename = null,
        private ?string $sourceFingerprint = null,
        private bool $changed = false,
        private PackageLimits $limits = new PackageLimits(),
    ) {}

    public static function create(?PackageLimits $limits = null): self
    {
        $limits ??= new PackageLimits();
        $contentTypes = new ContentTypes();
        $contentTypes->setDefault('rels', Relationships::CONTENT_TYPE);

        return new self(
            new ZipContainer($limits),
            $contentTypes,
            limits: $limits,
        );
    }

    public static function open(string $filename, ?PackageLimits $limits = null): self
    {
        $limits ??= new PackageLimits();
        $sourceFilename = self::resolveExistingFilename($filename);
        $fingerprintBeforeReading = self::fingerprint($sourceFilename);
        $container = ZipContainer::open($sourceFilename, $limits);

        if (!$container->has('[Content_Types].xml')) {
            throw new OpenXmlException('Package has no [Content_Types].xml.');
        }

        $contentTypes = ContentTypes::fromXml(
            $container->read('[Content_Types].xml'),
            $limits->maximumXmlBytes,
        );
        $fingerprintAfterReading = self::fingerprint($sourceFilename);

        if (!hash_equals($fingerprintBeforeReading, $fingerprintAfterReading)) {
            throw new ConcurrentModificationException(sprintf(
                'Package "%s" changed while it was being opened.',
                $sourceFilename,
            ));
        }

        return new self(
            $container,
            $contentTypes,
            $sourceFilename,
            $fingerprintAfterReading,
            limits: $limits,
        );
    }

    /**
     * Opens a package, applies an edit, and atomically saves the result.
     *
     * @param callable(self): void $edit
     */
    public static function edit(
        string $filename,
        callable $edit,
        ?PackageLimits $limits = null,
    ): void {
        $package = self::open($filename, $limits);
        $edit($package);
        $package->save();
    }

    public function hasPart(string $name): bool
    {
        return $this->container->has(PartName::entry($name));
    }

    public function getPart(string $name): PartInterface
    {
        $name = PartName::normalize($name);
        if (PartName::isRelationshipsPart($name) || !$this->hasPart($name)) {
            throw new PartNotFoundException(sprintf('Package part does not exist: %s', $name));
        }

        $contentType = $this->contentTypes->getForPart($name);
        if ($contentType === null) {
            throw new OpenXmlException(sprintf('No content type is registered for part "%s".', $name));
        }

        $contents = $this->container->read(PartName::entry($name));

        return new Part($this, $name, $contentType, $contents);
    }

    public function getParts(): \Traversable
    {
        foreach ($this->container->entries() as $entryName) {
            if ($entryName === '[Content_Types].xml') {
                continue;
            }

            $partName = '/' . $entryName;
            if (!PartName::isRelationshipsPart($partName)) {
                yield $this->getPart($partName);
            }
        }
    }

    public function addPart(string $name, string $contentType, string $contents): PartInterface
    {
        $name = PartName::normalize($name);
        if (PartName::isRelationshipsPart($name)) {
            throw new OpenXmlException('Relationship parts are managed through the relationship API.');
        }

        $this->container->write(PartName::entry($name), $contents);
        $this->contentTypes->setOverride($name, $contentType);
        $this->changed = true;

        return $this->getPart($name);
    }

    public function writePart(string $name, string $contents): void
    {
        if (!$this->hasPart($name)) {
            throw new PartNotFoundException(sprintf('Package part does not exist: %s', $name));
        }

        $this->container->write(PartName::entry($name), $contents);
        $this->changed = true;
    }

    public function removePart(string $name): void
    {
        $name = PartName::normalize($name);
        $relationshipPartName = PartName::relationshipsName($name);

        $this->container->remove(PartName::entry($name));
        $this->container->remove(PartName::entry($relationshipPartName));
        $this->contentTypes->removeOverride($name);
        $this->changed = true;
    }

    public function getRelationships(?string $sourcePartName = null): Relationships
    {
        if ($sourcePartName !== null && !$this->hasPart($sourcePartName)) {
            throw new PartNotFoundException(sprintf(
                'Relationship source part does not exist: %s',
                $sourcePartName,
            ));
        }

        $relationshipPartName = PartName::relationshipsName($sourcePartName);
        $relationshipEntryName = PartName::entry($relationshipPartName);
        $onChange = fn(Relationships $relationships) => $this->writeRelationships(
            $relationships,
            $sourcePartName,
        );

        if (!$this->container->has($relationshipEntryName)) {
            return new Relationships($this, $sourcePartName, $onChange);
        }

        return Relationships::fromXml(
            $this->container->read($relationshipEntryName),
            $this,
            $sourcePartName,
            $onChange,
            $this->limits->maximumXmlBytes,
        );
    }

    public function addRelationship(
        string $type,
        string $target,
        bool $external = false,
        ?string $id = null,
        ?string $sourcePartName = null,
    ): RelationshipInterface {
        if (!$external) {
            PartName::resolveTarget($sourcePartName, $target);
        }

        return $this->getRelationships($sourcePartName)->create($type, $target, $external, $id);
    }

    public function removeRelationship(string $id, ?string $sourcePartName = null): void
    {
        $this->getRelationships($sourcePartName)->remove($id);
    }

    public function validate(): array
    {
        $issues = [];

        if ($this->hasDigitalSignatures()) {
            $issues[] = 'Digitally signed packages cannot be saved because signature preservation is not supported.';
        }

        $partNames = $this->collectPartNames($issues);

        foreach ([null, ...$partNames] as $sourcePartName) {
            foreach ($this->getRelationships($sourcePartName) as $relationship) {
                if ($relationship->isExternal()) {
                    continue;
                }

                $targetPartName = (string) $relationship->getTargetPartName();
                if (!$this->hasPart($targetPartName)) {
                    $sourceDescription = $sourcePartName ?? 'the package';
                    $issues[] = sprintf(
                        'Relationship "%s" from %s targets missing part "%s".',
                        $relationship->getId(),
                        $sourceDescription,
                        $targetPartName,
                    );
                }
            }
        }

        return $issues;
    }

    private function hasDigitalSignatures(): bool
    {
        foreach ($this->container->entries() as $entryName) {
            if (str_starts_with(strtolower($entryName), '_xmlsignatures/')) {
                return true;
            }
        }

        return false;
    }

    public function hasChanges(): bool
    {
        return $this->changed;
    }

    public function discardChanges(): void
    {
        $freshPackage = $this->sourceFilename === null
            ? self::create($this->limits)
            : self::open($this->sourceFilename, $this->limits);

        $this->container = $freshPackage->container;
        $this->contentTypes = $freshPackage->contentTypes;
        $this->sourceFilename = $freshPackage->sourceFilename;
        $this->sourceFingerprint = $freshPackage->sourceFingerprint;
        $this->changed = false;
    }

    public function save(): void
    {
        if ($this->sourceFilename === null) {
            throw new OpenXmlException('A newly created package must first be saved with saveAs().');
        }

        if (!$this->changed) {
            return;
        }

        $this->persist($this->sourceFilename, $this->sourceFingerprint);
    }

    public function saveAs(string $filename): void
    {
        $resolvedDestination = realpath($filename);
        $overwritesSource = $resolvedDestination !== false
            && $resolvedDestination === $this->sourceFilename;

        $expectedFingerprint = $overwritesSource
            ? $this->sourceFingerprint
            : null;

        $this->persist($filename, $expectedFingerprint);
    }

    /**
     * @param list<string> $issues
     *
     * @return list<string>
     */
    private function collectPartNames(array &$issues): array
    {
        $partNames = [];

        foreach ($this->container->entries() as $entryName) {
            if ($entryName === '[Content_Types].xml' || PartName::isRelationshipsPart('/' . $entryName)) {
                continue;
            }

            $partName = '/' . $entryName;
            $partNames[] = $partName;

            if ($this->contentTypes->getForPart($partName) === null) {
                $issues[] = sprintf('Part "%s" has no registered content type.', $partName);
            }
        }

        return $partNames;
    }

    private function writeRelationships(
        Relationships $relationships,
        ?string $sourcePartName,
    ): void {
        $relationshipPartName = PartName::relationshipsName($sourcePartName);
        $relationshipEntryName = PartName::entry($relationshipPartName);

        if (count($relationships) === 0) {
            $this->container->remove($relationshipEntryName);
            $this->changed = true;

            return;
        }

        $this->contentTypes->setDefault('rels', Relationships::CONTENT_TYPE);
        $this->container->write($relationshipEntryName, $relationships->toXml());
        $this->changed = true;
    }

    private function persist(string $filename, ?string $expectedFingerprint = null): void
    {
        $issues = $this->validate();
        if ($issues !== []) {
            throw new PackageValidationException($issues);
        }

        $this->container->write('[Content_Types].xml', $this->contentTypes->toXml());

        $beforeReplace = $expectedFingerprint === null
            ? null
            : function () use ($filename, $expectedFingerprint): void {
                $currentFingerprint = self::fingerprint($filename);
                if (!hash_equals($expectedFingerprint, $currentFingerprint)) {
                    throw new ConcurrentModificationException(sprintf(
                        'Package "%s" changed on disk after it was opened.',
                        $filename,
                    ));
                }
            };

        AtomicFileWriter::replace(
            $filename,
            function (string $temporaryFilename): void {
                $this->container->saveAs($temporaryFilename);
                $this->verifyWrittenPackage($temporaryFilename);
            },
            $beforeReplace,
        );

        $this->sourceFilename = self::resolveExistingFilename($filename);
        $this->sourceFingerprint = self::fingerprint($this->sourceFilename);
        $this->changed = false;
    }

    private function verifyWrittenPackage(string $filename): void
    {
        $container = ZipContainer::open($filename, $this->limits);
        if (!$container->has('[Content_Types].xml')) {
            throw new OpenXmlException('Written package has no [Content_Types].xml.');
        }

        ContentTypes::fromXml(
            $container->read('[Content_Types].xml'),
            $this->limits->maximumXmlBytes,
        );
    }

    private static function resolveExistingFilename(string $filename): string
    {
        $resolvedFilename = realpath($filename);
        if ($resolvedFilename === false || !is_file($resolvedFilename)) {
            throw new OpenXmlException(sprintf('Package "%s" does not exist.', $filename));
        }

        return $resolvedFilename;
    }

    private static function fingerprint(string $filename): string
    {
        $fingerprint = @hash_file('sha256', $filename);
        if ($fingerprint === false) {
            throw new OpenXmlException(sprintf('Unable to fingerprint package "%s".', $filename));
        }

        return $fingerprint;
    }
}
