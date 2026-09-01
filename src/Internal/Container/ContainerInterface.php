<?php

declare(strict_types=1);

namespace DK\OpenXml\Internal\Container;

/** @internal */
interface ContainerInterface
{
    public function has(string $name): bool;

    public function read(string $name): string;

    /** @return iterable<string> */
    public function entries(): iterable;

    public function write(string $name, string $contents): void;

    public function remove(string $name): void;

    public function saveAs(string $filename): void;
}
