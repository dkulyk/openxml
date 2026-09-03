<?php

declare(strict_types=1);

namespace DK\OpenXml\Internal\Container;

use DK\OpenXml\Exception\OpenXmlException;

/** @internal Coordinates source handles held by package instances in this process. */
final class SourceArchiveRegistry
{
    /** @var array<string, array<int, \WeakReference<ZipContainer>>> */
    private static array $containers = [];

    private function __construct() {}

    public static function register(string $filename, ZipContainer $container): void
    {
        $filename = self::canonicalFilename($filename);
        self::$containers[$filename][spl_object_id($container)] = \WeakReference::create($container);
    }

    public static function unregister(string $filename, ZipContainer $container): void
    {
        $filename = self::canonicalFilename($filename);
        unset(self::$containers[$filename][spl_object_id($container)]);
        if (isset(self::$containers[$filename]) && self::$containers[$filename] === []) {
            unset(self::$containers[$filename]);
        }
    }

    public static function assertCanReplace(string $filename): void
    {
        foreach (self::liveContainers(self::canonicalFilename($filename)) as $container) {
            if ($container->hasOpenSourceStreams()) {
                throw new OpenXmlException('Close all open part streams before replacing the source package.');
            }
        }
    }

    public static function prepareForReplacement(string $filename): void
    {
        $filename = self::canonicalFilename($filename);
        self::assertCanReplace($filename);
        $containers = self::liveContainers($filename);
        foreach ($containers as $container) {
            $container->releaseSourceArchive();
        }
    }

    /** @return list<ZipContainer> */
    private static function liveContainers(string $filename): array
    {
        $containers = [];
        foreach (self::$containers[$filename] ?? [] as $id => $reference) {
            $container = $reference->get();
            if ($container === null) {
                unset(self::$containers[$filename][$id]);
            } else {
                $containers[] = $container;
            }
        }
        if (isset(self::$containers[$filename]) && self::$containers[$filename] === []) {
            unset(self::$containers[$filename]);
        }

        return $containers;
    }

    private static function canonicalFilename(string $filename): string
    {
        $resolvedFilename = realpath($filename);

        return $resolvedFilename !== false ? $resolvedFilename : $filename;
    }
}
