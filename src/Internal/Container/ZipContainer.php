<?php

declare(strict_types=1);

namespace DK\OpenXml\Internal\Container;

use DK\OpenXml\Exception\OpenXmlException;
use DK\OpenXml\Exception\PackageLimitException;
use DK\OpenXml\Security\PackageLimits;

/** @internal */
final class ZipContainer implements ContainerInterface
{
    /** @var array<string, string> */
    private array $entries = [];

    public function __construct(private PackageLimits $limits = new PackageLimits()) {}

    public static function open(string $filename, ?PackageLimits $limits = null): self
    {
        $limits ??= new PackageLimits();
        $archive = new \ZipArchive();

        if ($archive->open($filename) !== true) {
            throw new OpenXmlException(sprintf('Unable to open package "%s".', $filename));
        }

        try {
            if ($archive->numFiles > $limits->maximumEntries) {
                throw new PackageLimitException(sprintf(
                    'Package contains %d entries; the configured maximum is %d.',
                    $archive->numFiles,
                    $limits->maximumEntries,
                ));
            }

            $container = new self($limits);
            $totalUncompressedBytes = 0;

            for ($index = 0; $index < $archive->numFiles; ++$index) {
                $entry = $archive->statIndex($index);
                if ($entry === false) {
                    throw new OpenXmlException(sprintf('Unable to inspect ZIP entry at index %d.', $index));
                }

                $entryName = $entry['name'];
                if (str_ends_with($entryName, '/')) {
                    continue;
                }

                self::assertSafeEntryName($entryName);
                if (isset($container->entries[$entryName])) {
                    throw new OpenXmlException(sprintf('Duplicate ZIP entry "%s".', $entryName));
                }

                $uncompressedBytes = $entry['size'];
                $compressedBytes = $entry['comp_size'];
                self::assertEntryWithinLimits(
                    $entryName,
                    $uncompressedBytes,
                    $compressedBytes,
                    $limits,
                );

                $totalUncompressedBytes += $uncompressedBytes;
                if ($totalUncompressedBytes > $limits->maximumPackageBytes) {
                    throw new PackageLimitException(sprintf(
                        'Package expands beyond the configured maximum of %d bytes.',
                        $limits->maximumPackageBytes,
                    ));
                }

                $contents = $archive->getFromIndex($index);
                if ($contents === false) {
                    throw new OpenXmlException(sprintf('Unable to read ZIP entry "%s".', $entryName));
                }

                $container->entries[$entryName] = $contents;
            }

            return $container;
        } finally {
            $archive->close();
        }
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
        self::assertSafeEntryName($name);

        $contentsBytes = strlen($contents);
        if ($contentsBytes > $this->limits->maximumPartBytes) {
            throw new PackageLimitException(sprintf(
                'Part "%s" exceeds the configured maximum of %d bytes.',
                $name,
                $this->limits->maximumPartBytes,
            ));
        }

        $existingBytes = isset($this->entries[$name]) ? strlen($this->entries[$name]) : 0;
        $packageBytes = $this->currentSize() - $existingBytes + $contentsBytes;

        if ($packageBytes > $this->limits->maximumPackageBytes) {
            throw new PackageLimitException(sprintf(
                'Package exceeds the configured maximum of %d bytes.',
                $this->limits->maximumPackageBytes,
            ));
        }

        if (!isset($this->entries[$name]) && count($this->entries) >= $this->limits->maximumEntries) {
            throw new PackageLimitException(sprintf(
                'Package exceeds the configured maximum of %d entries.',
                $this->limits->maximumEntries,
            ));
        }

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

    private function currentSize(): int
    {
        return array_sum(array_map(strlen(...), $this->entries));
    }

    private static function assertSafeEntryName(string $name): void
    {
        $segments = explode('/', $name);
        if (
            $name === ''
            || str_starts_with($name, '/')
            || str_contains($name, '\\')
            || str_contains($name, "\0")
            || in_array('', $segments, true)
            || in_array('.', $segments, true)
            || in_array('..', $segments, true)
        ) {
            throw new OpenXmlException(sprintf('Unsafe ZIP entry name "%s".', $name));
        }
    }

    private static function assertEntryWithinLimits(
        string $entryName,
        int $uncompressedBytes,
        int $compressedBytes,
        PackageLimits $limits,
    ): void {
        if ($uncompressedBytes > $limits->maximumPartBytes) {
            throw new PackageLimitException(sprintf(
                'Part "%s" expands to %d bytes; the configured maximum is %d.',
                $entryName,
                $uncompressedBytes,
                $limits->maximumPartBytes,
            ));
        }

        $compressionRatio = $compressedBytes === 0
            ? ($uncompressedBytes === 0 ? 1.0 : INF)
            : $uncompressedBytes / $compressedBytes;

        if ($compressionRatio > $limits->maximumCompressionRatio) {
            throw new PackageLimitException(sprintf(
                'Part "%s" has a suspicious compression ratio of %.2f.',
                $entryName,
                $compressionRatio,
            ));
        }
    }
}
