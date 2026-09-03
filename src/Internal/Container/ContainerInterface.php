<?php

declare(strict_types=1);

namespace DK\OpenXml\Internal\Container;

/** @internal */
interface ContainerInterface
{
    public function has(string $name): bool;

    public function read(string $name): string;

    /** @return resource */
    public function openStream(string $name);

    /** Return a PHP-readable native URI when the current contents have one. */
    public function getReadablePath(string $name): ?string;

    /** @return iterable<string> */
    public function entries(): iterable;

    public function write(string $name, string $contents): void;

    /** @param resource $stream */
    public function writeStream(string $name, $stream): void;

    public function remove(string $name): void;

    public function move(string $source, string $destination): void;

    public function prepareForSourceReplacement(): void;

    public function saveAs(string $filename): void;
}
