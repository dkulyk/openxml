<?php

declare(strict_types=1);

namespace DK\OpenXml;

use DK\OpenXml\Exception\ConcurrentModificationException;
use DK\OpenXml\Exception\EncryptedPackageException;
use DK\OpenXml\Exception\OpenXmlException;
use DK\OpenXml\Exception\PackageValidationException;
use DK\OpenXml\Exception\PartInUseException;
use DK\OpenXml\Exception\PartNotFoundException;
use DK\OpenXml\Exception\UnsupportedFileFormatException;
use DK\OpenXml\Internal\AtomicFileWriter;
use DK\OpenXml\Internal\Container\ContainerInterface;
use DK\OpenXml\Internal\Container\ZipContainer;
use DK\OpenXml\Internal\MaterializationPool;
use DK\OpenXml\Internal\PackageRepairer;
use DK\OpenXml\Internal\SignatureInspector;
use DK\OpenXml\Packaging\ContentTypes;
use DK\OpenXml\Packaging\PackageInterface;
use DK\OpenXml\Packaging\Part;
use DK\OpenXml\Packaging\PartInterface;
use DK\OpenXml\Packaging\PartName;
use DK\OpenXml\Packaging\PartRemovalResult;
use DK\OpenXml\Packaging\RelationshipInterface;
use DK\OpenXml\Packaging\RelationshipReference;
use DK\OpenXml\Packaging\Relationships;
use DK\OpenXml\Packaging\RelationshipType;
use DK\OpenXml\Repair\PackageRepairOptions;
use DK\OpenXml\Repair\RepairReport;
use DK\OpenXml\Security\PackageLimits;
use DK\OpenXml\Signature\SignatureContentType;
use DK\OpenXml\Signature\SignatureInspection;
use DK\OpenXml\Signature\SignatureRemovalResult;
use DK\OpenXml\Signature\SignatureStatus;

final class OpenXmlPackage implements PackageInterface
{
    /** @var array<string, string> Lowercase part name => stored part name. */
    private array $partNames = [];

    /** @var array<string, Relationships> Source part name ('' for the package) => live relationship collection. */
    private array $relationships = [];

    private MaterializationPool $materializations;

    private int $contentRevision = 0;

    private function __construct(
        private ContainerInterface $container,
        private ContentTypes $contentTypes,
        private ?string $sourceFilename = null,
        private ?string $sourceFingerprint = null,
        private bool $changed = false,
        private PackageLimits $limits = new PackageLimits(),
    ) {
        $this->materializations = new MaterializationPool();
        $this->rebuildPartNameIndex();
    }

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
        $format = OfficeFileDetector::detect($sourceFilename);

        if ($format === OfficeFileFormat::EncryptedOpcPackage) {
            throw new EncryptedPackageException(
                'This is an encrypted Office Open XML package. Open it through the encryption API before reading it as OPC.',
            );
        }

        if ($format === OfficeFileFormat::CompoundFile) {
            throw new UnsupportedFileFormatException(
                'This is a valid CFBF/OLE file, but it is not an encrypted Office Open XML package.',
            );
        }

        if ($format === OfficeFileFormat::Unknown) {
            throw new UnsupportedFileFormatException(
                'The file is neither a recognized OPC ZIP package nor a supported Office compound file.',
            );
        }

        $fingerprintBeforeReading = self::fingerprint($sourceFilename);
        $container = ZipContainer::open($sourceFilename, $limits);
        self::assertPartNameIntegrity($container);

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
        return $this->findPartName(PartName::normalize($name)) !== null;
    }

    public function getPart(string $name): PartInterface
    {
        $requestedName = PartName::normalize($name);
        $name = $this->findPartName($requestedName);
        if ($name === null || PartName::isRelationshipsPart($name)) {
            throw new PartNotFoundException(sprintf('Package part does not exist: %s', $requestedName));
        }

        $contentType = $this->contentTypes->getForPart($name);
        if ($contentType === null) {
            throw new OpenXmlException(sprintf('No content type is registered for part "%s".', $name));
        }

        return new Part($this, $name, $contentType);
    }

    public function getParts(): \Traversable
    {
        foreach ($this->container->entries() as $entryName) {
            if ($entryName === '[Content_Types].xml') {
                continue;
            }

            $partName = '/' . $entryName;
            if (!PartName::isRelationshipsPart($partName)) {
                $contentType = $this->contentTypes->getForPart($partName);
                if ($contentType === null) {
                    throw new OpenXmlException(sprintf('No content type is registered for part "%s".', $partName));
                }

                yield new Part($this, $partName, $contentType);
            }
        }
    }

    public function addPart(string $name, string $contentType, string $contents): PartInterface
    {
        $name = PartName::normalize($name);
        if (PartName::isRelationshipsPart($name)) {
            throw new OpenXmlException('Relationship parts are managed through the relationship API.');
        }
        $this->assertPartNameAvailable($name, true);

        $this->container->write(PartName::entry($name), $contents);
        $this->contentTypes->setOverride($name, $contentType);
        $this->partNames[strtolower($name)] = $name;
        ++$this->contentRevision;
        $this->changed = true;

        return $this->getPart($name);
    }

    public function addPartFromStream(string $name, string $contentType, $stream): PartInterface
    {
        $name = PartName::normalize($name);
        if (PartName::isRelationshipsPart($name)) {
            throw new OpenXmlException('Relationship parts are managed through the relationship API.');
        }
        $this->assertPartNameAvailable($name, true);

        $this->container->writeStream(PartName::entry($name), $stream);
        $this->contentTypes->setOverride($name, $contentType);
        $this->partNames[strtolower($name)] = $name;
        ++$this->contentRevision;
        $this->changed = true;

        return $this->getPart($name);
    }

    public function addPartFromPath(string $name, string $contentType, string $path): PartInterface
    {
        $stream = self::openReadableFile($path);

        try {
            return $this->addPartFromStream($name, $contentType, $stream);
        } finally {
            fclose($stream);
        }
    }

    public function readPart(string $name): string
    {
        $requestedName = PartName::normalize($name);
        $name = $this->findPartName($requestedName);
        if ($name === null) {
            throw new PartNotFoundException(sprintf('Package part does not exist: %s', $requestedName));
        }

        return $this->container->read(PartName::entry($name));
    }

    /** @return resource */
    public function openPartStream(string $name)
    {
        $requestedName = PartName::normalize($name);
        $name = $this->findPartName($requestedName);
        if ($name === null) {
            throw new PartNotFoundException(sprintf('Package part does not exist: %s', $requestedName));
        }

        return $this->container->openStream(PartName::entry($name));
    }

    public function getPartReadablePath(string $name): string
    {
        $name = $this->existingPartName($name);

        return $this->container->getReadablePath(PartName::entry($name))
            ?? $this->getPartLocalPath($name);
    }

    public function getPartLocalPath(string $name): string
    {
        $name = $this->existingPartName($name);
        $key = $this->contentRevision . "\0" . strtolower($name);

        return $this->materializations->materialize(
            $key,
            PartName::entry($name),
            fn() => $this->container->openStream(PartName::entry($name)),
        );
    }

    public function writePart(string $name, string $contents): void
    {
        $requestedName = PartName::normalize($name);
        $name = $this->findPartName($requestedName);
        if ($name === null) {
            throw new PartNotFoundException(sprintf('Package part does not exist: %s', $requestedName));
        }

        $this->container->write(PartName::entry($name), $contents);
        ++$this->contentRevision;
        $this->changed = true;
    }

    /** @param resource $stream */
    public function writePartFromStream(string $name, $stream): void
    {
        $requestedName = PartName::normalize($name);
        $name = $this->findPartName($requestedName);
        if ($name === null) {
            throw new PartNotFoundException(sprintf('Package part does not exist: %s', $requestedName));
        }

        $this->container->writeStream(PartName::entry($name), $stream);
        ++$this->contentRevision;
        $this->changed = true;
    }

    public function writePartFromPath(string $name, string $path): void
    {
        $stream = self::openReadableFile($path);

        try {
            $this->writePartFromStream($name, $stream);
        } finally {
            fclose($stream);
        }
    }

    public function removePart(string $name): void
    {
        $requestedName = PartName::normalize($name);
        $name = $this->findPartName($requestedName);
        if ($name === null || PartName::isRelationshipsPart($name)) {
            throw new PartNotFoundException(sprintf('Package part does not exist: %s', $requestedName));
        }

        $references = $this->getInboundRelationships($name);
        if ($references !== []) {
            throw new PartInUseException($name, $references);
        }

        $this->removePartContents($name);
    }

    public function getInboundRelationships(string $partName): array
    {
        $requestedName = PartName::normalize($partName);
        $partName = $this->findPartName($requestedName);
        if ($partName === null || PartName::isRelationshipsPart($partName)) {
            throw new PartNotFoundException(sprintf('Package part does not exist: %s', $requestedName));
        }

        $sourcePartNames = [];
        foreach ($this->getParts() as $part) {
            $sourcePartNames[] = $part->getName();
        }

        $references = [];
        foreach ([null, ...$sourcePartNames] as $sourcePartName) {
            foreach ($this->getRelationships($sourcePartName)->getByTargetPart($partName) as $relationship) {
                $references[] = new RelationshipReference($sourcePartName, $relationship);
            }
        }

        return $references;
    }

    public function removePartAndRelationships(string $name): PartRemovalResult
    {
        $requestedName = PartName::normalize($name);
        $name = $this->findPartName($requestedName);
        if ($name === null || PartName::isRelationshipsPart($name)) {
            throw new PartNotFoundException(sprintf('Package part does not exist: %s', $requestedName));
        }
        $references = $this->getInboundRelationships($name);

        foreach ($references as $reference) {
            $this->getRelationships($reference->sourcePartName)->remove(
                $reference->relationship->getId(),
            );
        }

        $this->removePartContents($name);

        return new PartRemovalResult($name, $references);
    }

    private function removePartContents(string $name): void
    {
        $relationshipPartName = PartName::relationshipsName($name);

        $this->container->remove(PartName::entry($name));
        $this->container->remove(PartName::entry($relationshipPartName));
        $this->contentTypes->removeOverride($name);
        unset(
            $this->partNames[strtolower($name)],
            $this->partNames[strtolower($relationshipPartName)],
            $this->relationships[$name],
        );
        ++$this->contentRevision;
        $this->changed = true;
    }

    public function movePart(string $source, string $destination): PartInterface
    {
        $requestedSource = PartName::normalize($source);
        $source = $this->findPartName($requestedSource);
        $destination = PartName::normalize($destination);
        if (($source !== null && PartName::isRelationshipsPart($source)) || PartName::isRelationshipsPart($destination)) {
            throw new OpenXmlException('Relationship parts are managed through the relationship API.');
        }
        if ($source === null) {
            throw new PartNotFoundException(sprintf('Package part does not exist: %s', $requestedSource));
        }
        if (PartName::equivalent($source, $destination)) {
            return $this->getPart($source);
        }
        if ($this->hasPart($destination)) {
            throw new OpenXmlException(sprintf('Package part already exists: %s', $destination));
        }
        $this->assertPartNameAvailable($destination, false, $source);

        $contentType = $this->getPart($source)->getContentType();
        $relationshipChanges = $this->relationshipChangesForMove($source, $destination);
        $sourceRelationshipPart = PartName::relationshipsName($source);
        $destinationRelationshipPart = PartName::relationshipsName($destination);
        if (
            $this->container->has(PartName::entry($sourceRelationshipPart))
            && $this->container->has(PartName::entry($destinationRelationshipPart))
        ) {
            throw new OpenXmlException(sprintf(
                'Destination relationship part already exists: %s',
                $destinationRelationshipPart,
            ));
        }

        $this->container->move(PartName::entry($source), PartName::entry($destination));
        if ($this->container->has(PartName::entry($sourceRelationshipPart))) {
            $this->container->move(
                PartName::entry($sourceRelationshipPart),
                PartName::entry($destinationRelationshipPart),
            );
            unset($this->partNames[strtolower($sourceRelationshipPart)]);
            $this->partNames[strtolower($destinationRelationshipPart)] = $destinationRelationshipPart;
        }

        $this->contentTypes->removeOverride($source);
        $this->contentTypes->setOverride($destination, $contentType);
        unset($this->partNames[strtolower($source)], $this->relationships[$source], $this->relationships[$destination]);
        $this->partNames[strtolower($destination)] = $destination;

        foreach ($relationshipChanges as [$relationshipSource, $id, $target]) {
            $this->getRelationships($relationshipSource)->retarget($id, $target);
        }

        ++$this->contentRevision;
        $this->changed = true;

        return $this->getPart($destination);
    }

    public function getRelationships(?string $sourcePartName = null): Relationships
    {
        if ($sourcePartName !== null) {
            $requestedSourcePartName = PartName::normalize($sourcePartName);
            $sourcePartName = $this->findPartName($requestedSourcePartName);
            if ($sourcePartName === null || PartName::isRelationshipsPart($sourcePartName)) {
                throw new PartNotFoundException(sprintf(
                    'Relationship source part does not exist: %s',
                    $requestedSourcePartName,
                ));
            }
        }

        // One live collection per source: separate handles would otherwise
        // overwrite each other's changes when they persist.
        $cacheKey = $sourcePartName ?? '';
        if (isset($this->relationships[$cacheKey])) {
            return $this->relationships[$cacheKey];
        }

        $relationshipEntryName = PartName::entry(PartName::relationshipsName($sourcePartName));
        $onChange = fn(Relationships $relationships) => $this->writeRelationships(
            $relationships,
            $sourcePartName,
        );

        return $this->relationships[$cacheKey] = $this->container->has($relationshipEntryName)
            ? Relationships::fromXml(
                $this->container->read($relationshipEntryName),
                $this,
                $sourcePartName,
                $onChange,
                $this->limits->maximumXmlBytes,
            )
            : new Relationships($this, $sourcePartName, $onChange);
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

    public function inspectSignatures(): SignatureInspection
    {
        return (new SignatureInspector(
            $this->container,
            $this->contentTypes,
            $this->limits->maximumXmlBytes,
        ))->inspect();
    }

    public function removeSignatures(): SignatureRemovalResult
    {
        $sourcePartNames = [];
        $partsToRemove = [];
        foreach ($this->partNames as $partName) {
            if (PartName::isRelationshipsPart($partName)) {
                continue;
            }

            $sourcePartNames[] = $partName;
            if ($this->isSignaturePart($partName)) {
                $partsToRemove[strtolower($partName)] = $partName;
            }
        }

        /** @var array<string, Relationships> $relationshipsBySource */
        $relationshipsBySource = [];
        foreach ([null, ...$sourcePartNames] as $sourcePartName) {
            $key = $sourcePartName ?? '';
            $relationshipsBySource[$key] = $this->getRelationships($sourcePartName);

            foreach ($relationshipsBySource[$key] as $relationship) {
                if (!in_array($relationship->getType(), self::signatureRelationshipTypes(), true)) {
                    continue;
                }

                if (!$relationship->isExternal()) {
                    $targetPartName = $relationship->getTargetPartName();
                    if ($targetPartName !== null) {
                        $storedPartName = $this->findPartName($targetPartName);
                        if ($storedPartName !== null && !PartName::isRelationshipsPart($storedPartName)) {
                            $partsToRemove[strtolower($storedPartName)] = $storedPartName;
                        }
                    }
                }
            }
        }

        /** @var array<string, array{?string, string}> $relationshipsToRemove */
        $relationshipsToRemove = [];
        $removedRelationships = 0;
        foreach ($relationshipsBySource as $sourceKey => $relationships) {
            $sourcePartName = $sourceKey === '' ? null : $sourceKey;
            foreach ($relationships as $relationship) {
                if ($sourcePartName !== null && isset($partsToRemove[strtolower($sourcePartName)])) {
                    ++$removedRelationships;

                    continue;
                }

                $remove = in_array($relationship->getType(), self::signatureRelationshipTypes(), true);
                if (!$remove && !$relationship->isExternal()) {
                    $targetPartName = $relationship->getTargetPartName();
                    $remove = $targetPartName !== null && isset($partsToRemove[strtolower($targetPartName)]);
                }

                if ($remove) {
                    ++$removedRelationships;
                    $relationshipsToRemove[$sourceKey . "\0" . $relationship->getId()] = [
                        $sourcePartName,
                        $relationship->getId(),
                    ];
                }
            }
        }

        foreach ($relationshipsToRemove as [$sourcePartName, $relationshipId]) {
            $this->getRelationships($sourcePartName)->remove($relationshipId);
        }

        $removedPartNames = array_values($partsToRemove);
        sort($removedPartNames);
        foreach ($removedPartNames as $partName) {
            $this->removePartContents($partName);
        }

        return new SignatureRemovalResult($removedPartNames, $removedRelationships);
    }

    private function isSignaturePart(string $partName): bool
    {
        return str_starts_with(strtolower($partName), '/_xmlsignatures/')
            || in_array(
                $this->contentTypes->getForPart($partName),
                [SignatureContentType::ORIGIN, SignatureContentType::XML_SIGNATURE, SignatureContentType::CERTIFICATE],
                true,
            );
    }

    /** @return list<string> */
    private static function signatureRelationshipTypes(): array
    {
        return [
            RelationshipType::DIGITAL_SIGNATURE_ORIGIN,
            RelationshipType::DIGITAL_SIGNATURE,
            RelationshipType::DIGITAL_SIGNATURE_CERTIFICATE,
        ];
    }

    public function validate(): array
    {
        $issues = [];

        $signatureInspection = $this->inspectSignatures();
        if ($signatureInspection->status !== SignatureStatus::Unsigned) {
            $issues[] = 'Digitally signed packages cannot be saved because signature preservation is not supported.';
        }
        foreach ($signatureInspection->getIssues() as $signatureIssue) {
            $issues[] = 'Digital signature: ' . $signatureIssue;
        }

        $partNames = $this->collectPartNames($issues);

        foreach ([null, ...$partNames] as $sourcePartName) {
            try {
                $relationships = $this->getRelationships($sourcePartName);
            } catch (OpenXmlException $exception) {
                $sourceDescription = $sourcePartName ?? 'the package';
                $issues[] = sprintf(
                    'Relationship part for %s is invalid: %s',
                    $sourceDescription,
                    $exception->getMessage(),
                );

                continue;
            }

            foreach ($relationships as $relationship) {
                if ($relationship->isExternal()) {
                    continue;
                }

                try {
                    $targetPartName = (string) $relationship->getTargetPartName();
                } catch (OpenXmlException $exception) {
                    $sourceDescription = $sourcePartName ?? 'the package';
                    $issues[] = sprintf(
                        'Relationship "%s" from %s has an invalid internal target "%s": %s',
                        $relationship->getId(),
                        $sourceDescription,
                        $relationship->getTarget(),
                        $exception->getMessage(),
                    );

                    continue;
                }

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

    public function analyzeRepairs(PackageRepairOptions $options): RepairReport
    {
        return $this->packageRepairer()->run($options, false);
    }

    public function applyRepairs(PackageRepairOptions $options): RepairReport
    {
        return $this->packageRepairer()->run($options, true);
    }

    private function packageRepairer(): PackageRepairer
    {
        return new PackageRepairer(
            $this->container,
            $this->contentTypes,
            fn(?string $sourcePartName): Relationships => $this->getRelationships($sourcePartName),
            function (): void {
                $this->rebuildPartNameIndex();
                $this->changed = true;
            },
        );
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
        $this->partNames = $freshPackage->partNames;
        $this->relationships = [];
        ++$this->contentRevision;
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
            if ($entryName === '[Content_Types].xml') {
                continue;
            }

            $partName = '/' . $entryName;
            if (PartName::isRelationshipsPart($partName)) {
                if ($this->contentTypes->getForPart($partName) !== Relationships::CONTENT_TYPE) {
                    $issues[] = sprintf(
                        'Relationship part "%s" does not use content type "%s".',
                        $partName,
                        Relationships::CONTENT_TYPE,
                    );
                }

                $sourcePartName = PartName::relationshipSourceName($partName);
                if ($sourcePartName !== null && !$this->hasPart($sourcePartName)) {
                    $issues[] = sprintf(
                        'Relationship part "%s" belongs to missing source part "%s".',
                        $partName,
                        $sourcePartName,
                    );
                }

                continue;
            }

            $partNames[] = $partName;

            if ($this->contentTypes->getForPart($partName) === null) {
                $issues[] = sprintf('Part "%s" has no registered content type.', $partName);
            }
        }

        foreach ($this->contentTypes->getOverrides() as $partName => $contentType) {
            if (!$this->hasPart($partName)) {
                $issues[] = sprintf(
                    'Content type override for "%s" has no matching part.',
                    $partName,
                );
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
            unset($this->partNames[strtolower($relationshipPartName)]);
            $this->changed = true;

            return;
        }

        $this->contentTypes->setDefault('rels', Relationships::CONTENT_TYPE);
        $this->container->write($relationshipEntryName, $relationships->toXml());
        $this->partNames[strtolower($relationshipPartName)] = $relationshipPartName;
        $this->changed = true;
    }

    /**
     * @return list<array{?string, string, string}>
     */
    private function relationshipChangesForMove(string $source, string $destination): array
    {
        $partNames = [];
        foreach ($this->getParts() as $part) {
            $partNames[] = $part->getName();
        }

        $changes = [];
        foreach ([null, ...$partNames] as $relationshipSource) {
            foreach ($this->getRelationships($relationshipSource) as $relationship) {
                if ($relationship->isExternal()) {
                    continue;
                }

                $targetPartName = (string) $relationship->getTargetPartName();
                $newRelationshipSource = $relationshipSource === $source
                    ? $destination
                    : $relationshipSource;

                if (PartName::equivalent($targetPartName, $source)) {
                    $newTarget = str_starts_with($relationship->getTarget(), '/')
                        ? $destination
                        : PartName::relativeTarget($newRelationshipSource, $destination);
                } elseif ($relationshipSource === $source && !str_starts_with($relationship->getTarget(), '/')) {
                    $newTarget = PartName::relativeTarget($destination, $targetPartName);
                } else {
                    continue;
                }

                $changes[] = [$newRelationshipSource, $relationship->getId(), $newTarget];
            }
        }

        return $changes;
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
        $this->container = ZipContainer::open($this->sourceFilename, $this->limits);
        $this->rebuildPartNameIndex();
        ++$this->contentRevision;
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

    private function assertPartNameAvailable(
        string $partName,
        bool $allowExactMatch,
        ?string $excludedPartName = null,
    ): void {
        foreach ($this->container->entries() as $entryName) {
            if ($entryName === '[Content_Types].xml') {
                continue;
            }

            $existingPartName = '/' . $entryName;
            if ($excludedPartName !== null && PartName::equivalent($existingPartName, $excludedPartName)) {
                continue;
            }
            if ($allowExactMatch && $existingPartName === $partName) {
                continue;
            }
            if (PartName::conflicts($existingPartName, $partName)) {
                throw new OpenXmlException(sprintf(
                    'OPC part name "%s" conflicts with existing part "%s".',
                    $partName,
                    $existingPartName,
                ));
            }
        }
    }

    private static function assertPartNameIntegrity(ContainerInterface $container): void
    {
        /** @var array<string, string> $partNamesByKey */
        $partNamesByKey = [];
        foreach ($container->entries() as $entryName) {
            if ($entryName === '[Content_Types].xml') {
                continue;
            }

            $partName = PartName::normalize('/' . $entryName);
            $comparisonKey = strtolower($partName);
            if (isset($partNamesByKey[$comparisonKey])) {
                throw new OpenXmlException(sprintf(
                    'OPC part name "%s" conflicts with part "%s".',
                    $partName,
                    $partNamesByKey[$comparisonKey],
                ));
            }
            $partNamesByKey[$comparisonKey] = $partName;
        }

        foreach ($partNamesByKey as $comparisonKey => $partName) {
            $prefix = $comparisonKey;
            while (($separator = strrpos($prefix, '/')) !== false && $separator > 0) {
                $prefix = substr($prefix, 0, $separator);
                if (isset($partNamesByKey[$prefix])) {
                    throw new OpenXmlException(sprintf(
                        'OPC part name "%s" is derivable from part "%s".',
                        $partName,
                        $partNamesByKey[$prefix],
                    ));
                }
            }
        }
    }

    private function findPartName(string $name): ?string
    {
        return $this->partNames[strtolower($name)] ?? null;
    }

    private function existingPartName(string $name): string
    {
        $requestedName = PartName::normalize($name);
        $name = $this->findPartName($requestedName);
        if ($name === null) {
            throw new PartNotFoundException(sprintf('Package part does not exist: %s', $requestedName));
        }

        return $name;
    }

    private function rebuildPartNameIndex(): void
    {
        $this->partNames = [];
        $this->relationships = [];
        foreach ($this->container->entries() as $entryName) {
            if ($entryName === '[Content_Types].xml') {
                continue;
            }

            $partName = '/' . $entryName;
            $this->partNames[strtolower($partName)] = $partName;
        }
    }

    private static function resolveExistingFilename(string $filename): string
    {
        $resolvedFilename = realpath($filename);
        if ($resolvedFilename === false || !is_file($resolvedFilename)) {
            throw new OpenXmlException(sprintf('Package "%s" does not exist.', $filename));
        }

        return $resolvedFilename;
    }

    /** @return resource */
    private static function openReadableFile(string $path)
    {
        $resolvedPath = realpath($path);
        if ($resolvedPath === false || !is_file($resolvedPath) || !is_readable($resolvedPath)) {
            throw new OpenXmlException(sprintf('Local file "%s" is not readable.', $path));
        }

        $stream = @fopen($resolvedPath, 'rb');
        if ($stream === false) {
            throw new OpenXmlException(sprintf('Unable to open local file "%s".', $path));
        }

        return $stream;
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
