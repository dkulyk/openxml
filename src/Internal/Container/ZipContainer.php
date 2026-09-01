<?php

declare(strict_types=1);

namespace DK\OpenXml\Internal\Container;

use DK\OpenXml\Exception\OpenXmlException;

/** @internal */
final class ZipContainer implements ContainerInterface
{
    /** @var array<string, string> */
    private array $entries = [];

    public static function open(string $filename): self
    {
        $archive = new \ZipArchive();
        if ($archive->open($filename) !== true) {
            throw new OpenXmlException(sprintf('Unable to open package "%s".', $filename));
        }

        $container = new self();
        for ($index = 0; $index < $archive->numFiles; ++$index) {
            $entryName = $archive->getNameIndex($index);
            if ($entryName === false || str_ends_with($entryName, '/')) {
                continue;
            }

            $contents = $archive->getFromIndex($index);
            if ($contents === false) {
                $archive->close();

                throw new OpenXmlException(sprintf('Unable to read ZIP entry "%s".', $entryName));
            }

            $container->entries[$entryName] = $contents;
        }

        $archive->close();

        return $container;
    }

    public function has(string $name): bool
    {
        return array_key_exists($name, $this->entries);
    }

    public function read(string $name): string
    {
        if (!$this->has($name)) {
            throw new OpenXmlException(sprintf('ZIP entry "%s" does not exist.', $name));
        }

        return $this->entries[$name];
    }

    public function entries(): iterable
    {
        yield from array_keys($this->entries);
    }

    public function write(string $name, string $contents): void
    {
        $this->entries[$name] = $contents;
    }

    public function remove(string $name): void
    {
        unset($this->entries[$name]);
    }

    public function saveAs(string $filename): void
    {
        $archive = new \ZipArchive();
        $openResult = $archive->open($filename, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);

        if ($openResult !== true) {
            throw new OpenXmlException(sprintf('Unable to create package "%s".', $filename));
        }

        foreach ($this->entries as $entryName => $contents) {
            if (!$archive->addFromString($entryName, $contents)) {
                $archive->close();

                throw new OpenXmlException(sprintf('Unable to write ZIP entry "%s".', $entryName));
            }
        }

        if (!$archive->close()) {
            throw new OpenXmlException(sprintf('Unable to finalize package "%s".', $filename));
        }
    }
}
