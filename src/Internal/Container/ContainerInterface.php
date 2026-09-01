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

    /** @return iterable<string> */
    public function entries(): iterable;

    public function write(string $name, string $contents): void;

    /** @param resource $stream */
    public function writeStream(string $name, $stream): void;

    public function remove(string $name): void;

    public function saveAs(string $filename): void;
}
