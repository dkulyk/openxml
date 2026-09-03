<?php

declare(strict_types=1);

namespace DK\OpenXml\Packaging;

interface PartInterface
{
    public function getName(): string;

    public function getContentType(): string;

    public function getContents(): string;

    public function setContents(string $contents): void;

    /** @return resource */
    public function openStream();

    /** Return a PHP-readable ZIP URI or a package-owned local path valid for the package lifetime. */
    public function getReadablePath(): string;

    /** Return a package-owned local filesystem path valid for the package lifetime. */
    public function getLocalPath(): string;

    /** @param resource $stream */
    public function setContentsFromStream($stream): void;

    public function setContentsFromPath(string $path): void;

    public function getRelationships(): Relationships;

    public function addRelationship(string $type, string $target, bool $external = false, ?string $id = null): RelationshipInterface;

    public function removeRelationship(string $id): void;
}
