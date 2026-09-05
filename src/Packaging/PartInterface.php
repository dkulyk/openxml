<?php

declare(strict_types=1);

namespace DK\OpenXml\Packaging;

interface PartInterface
{
    public function getName(): string;

    public function getContentType(): string;

    public function getContents(): string;

    /**
     * @param ?bool $compress Whether to deflate the part, overriding what its content
     *                        type implies. Null leaves the decision to ContentCompression.
     */
    public function setContents(string $contents, ?bool $compress = null): void;

    /** @return resource A readable stream that keeps its backing storage alive until closed. */
    public function openStream();

    /**
     * Return a PHP-readable ZIP URI or a package-owned local path valid for the package lifetime.
     *
     * A consumer reading the ZIP URI reads it directly, so the size the archive
     * declares for the part does not bound it; getLocalPath() does.
     */
    public function getReadablePath(): string;

    /** Return a package-owned local filesystem path valid for the package lifetime. */
    public function getLocalPath(): string;

    /** @param resource $stream */
    public function setContentsFromStream($stream, ?bool $compress = null): void;

    public function setContentsFromPath(string $path, ?bool $compress = null): void;

    public function getRelationships(): Relationships;

    public function addRelationship(string $type, string $target, bool $external = false, ?string $id = null): RelationshipInterface;

    public function removeRelationship(string $id): void;
}
